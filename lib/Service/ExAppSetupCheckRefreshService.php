<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service;

use OCA\AppAPI\Db\ExApp;
use OCP\Http\Client\IResponse;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Polls each declaring ExApp's `/setup_checks` endpoint and stores the computed issues, so the
 * admin "Security & setup warnings" page (see {@see \OCA\AppAPI\SetupChecks\ExAppsSetupCheck}) never
 * does any HTTP itself. Runs only in the background (a TimedJob + an on-demand QueuedJob fired when an
 * admin opens the page), so a slow/down/errored ExApp can never slow the page - it only costs time
 * here. It catches all its own errors and never throws.
 *
 * Text produced here is in the server-default language (there is no viewer in a background job);
 * ExApp-provided text is monolingual either way. All ExApp-provided values are length-capped and the
 * link URL is validated; HTML-escaping happens at render time in the SetupCheck.
 */
class ExAppSetupCheckRefreshService {
	private const PER_APP_TIMEOUT_SECONDS = 5;
	private const MAX_RESPONSE_BYTES = 262144; // 256 KiB
	private const MAX_RESPONSE_ENTRIES = 1000;
	private const MAX_TEXT_LENGTH = 4096;

	public function __construct(
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly ExAppSetupCheckService $setupCheckService,
		private readonly ExAppService $exAppService,
		private readonly AppAPIService $appAPIService,
		// wall-clock budget for the whole sweep; injectable so tests can force a partial sweep
		private readonly float $totalBudgetSeconds = 120.0,
	) {
	}

	public function refresh(): void {
		try {
			// Carry-over source: a partial sweep (time budget) must NOT clear the issues of apps it did
			// not reach this run, or the admin Overview would briefly flip them to healthy.
			$previous = $this->setupCheckService->getState()['apps'];
			$appsState = [];
			$start = microtime(true);
			$budgetHit = false;
			foreach ($this->setupCheckService->getOptedInAppIds() as $appId) {
				$exApp = $this->exAppService->getExApp($appId);
				if ($exApp === null || $exApp->getEnabled() !== 1 || $this->isInitializing($exApp)) {
					continue; // disabled / deploying -> dropped (down-ness shown on the management page)
				}
				if ($budgetHit || (microtime(true) - $start) > $this->totalBudgetSeconds) {
					if (!$budgetHit) {
						$this->logger->info('ExApp setup-check refresh budget exceeded; unvisited apps keep their previous results this run');
						$budgetHit = true;
					}
					if (isset($previous[$appId]) && is_array($previous[$appId])) {
						$appsState[$appId] = $previous[$appId]; // stale-but-present beats vanishing
					}
					continue;
				}
				$issues = $this->probe($exApp);
				if ($issues !== []) {
					$appsState[$appId] = $issues;
				}
			}
			$this->setupCheckService->storeState($appsState);
		} catch (\Throwable $e) {
			$this->logger->error('ExApp setup-check refresh failed', ['exception' => $e]);
		}
	}

	private function isInitializing(ExApp $exApp): bool {
		$status = $exApp->getStatus();
		return ((int)($status['init'] ?? 100)) < 100 || (($status['action'] ?? '') === 'init');
	}

	/**
	 * @return list<array{severity: string, appName: string, text: string, linkUrl: string, linkLabel: string}>
	 */
	private function probe(ExApp $exApp): array {
		try {
			$result = $this->appAPIService->requestToExApp(
				$exApp,
				'/setup_checks',
				null,
				'GET',
				[],
				['timeout' => self::PER_APP_TIMEOUT_SECONDS],
			);
		} catch (\Throwable $e) {
			$this->logger->warning('ExApp setup-check: error probing ExApp ' . $exApp->getAppid(), ['exception' => $e]);
			return [$this->notRespondingIssue($exApp)];
		}

		if (is_array($result)) {
			return [$this->notRespondingIssue($exApp)]; // transport error -> ['error' => ...]
		}
		/** @var IResponse $result */
		$status = $result->getStatusCode();
		if ($status < 200 || $status >= 300) {
			return [$this->notRespondingIssue($exApp)];
		}
		// Require a sane, numeric Content-Length and refuse to read the body otherwise. A missing /
		// forged / non-numeric length is treated as not-responding: it is the only way to keep an
		// opted-in ExApp from streaming an unbounded (e.g. chunked) body that getBody() would buffer
		// whole and OOM the cron worker. With a declared length the HTTP client reads at most that
		// many bytes, so the materialization below is bounded.
		$contentLength = $result->getHeader('Content-Length');
		// ctype_digit (not is_numeric, which accepts -1 / 12.5 / 1e3, and not '' which is false here):
		// Content-Length must be digits only.
		if (!ctype_digit($contentLength) || (int)$contentLength > self::MAX_RESPONSE_BYTES) {
			$this->logger->warning('ExApp setup-check: missing or oversized Content-Length from ExApp ' . $exApp->getAppid());
			return [$this->notRespondingIssue($exApp)];
		}
		$bodyStr = (string)$result->getBody();
		if (strlen($bodyStr) > self::MAX_RESPONSE_BYTES) {
			$this->logger->warning('ExApp setup-check: oversized response body from ExApp ' . $exApp->getAppid());
			return [$this->notRespondingIssue($exApp)];
		}
		$body = json_decode($bodyStr, true);
		if (!is_array($body)) {
			return [$this->notRespondingIssue($exApp)];
		}
		return $this->parseResponse($exApp, $body);
	}

	/**
	 * @param array<array-key, mixed> $body map of `{checkId: {status, text, link_url?, link_label?}}`
	 * @return list<array{severity: string, appName: string, text: string, linkUrl: string, linkLabel: string}>
	 */
	private function parseResponse(ExApp $exApp, array $body): array {
		$issues = [];
		$scanned = 0;
		foreach ($body as $result) {
			if (count($issues) >= ExAppSetupCheckService::MAX_CHECKS || $scanned >= self::MAX_RESPONSE_ENTRIES) {
				break;
			}
			$scanned++;
			if (!is_array($result)) {
				continue;
			}
			$severity = $this->mapSeverity(is_string($result['status'] ?? null) ? $result['status'] : '');
			if ($severity === null) {
				continue; // success / unknown -> not an issue
			}
			$text = $this->capLength(is_string($result['text'] ?? null) ? $result['text'] : '');
			$issues[] = [
				'severity' => $severity,
				'appName' => $this->appName($exApp),
				'text' => $text !== '' ? $text : $this->l10n->t('reported a problem'),
				'linkUrl' => $this->safeUrl(is_string($result['link_url'] ?? null) ? $result['link_url'] : ''),
				'linkLabel' => $this->capLength(is_string($result['link_label'] ?? null) ? $result['link_label'] : ''),
			];
		}
		return $issues;
	}

	/**
	 * @return array{severity: string, appName: string, text: string, linkUrl: string, linkLabel: string}
	 */
	private function notRespondingIssue(ExApp $exApp): array {
		return [
			'severity' => 'warning',
			'appName' => $this->appName($exApp),
			'text' => $this->l10n->t('not responding'),
			'linkUrl' => '',
			'linkLabel' => '',
		];
	}

	private function appName(ExApp $exApp): string {
		$name = $exApp->getName();
		return $name !== '' ? $name : $exApp->getAppid();
	}

	private function mapSeverity(string $status): ?string {
		return match (strtolower(trim($status))) {
			'warning' => 'warning',
			'error' => 'error',
			default => null, // success / info / ok / empty / unknown -> not surfaced
		};
	}

	private function safeUrl(string $url): string {
		if (preg_match('#^https?://#i', $url) !== 1) {
			return '';
		}
		if (preg_match('/[\x00-\x20\x7f"\'<>]/', $url) === 1) {
			return '';
		}
		return $url;
	}

	private function capLength(string $text): string {
		return mb_strlen($text) > self::MAX_TEXT_LENGTH ? mb_substr($text, 0, self::MAX_TEXT_LENGTH) : $text;
	}
}
