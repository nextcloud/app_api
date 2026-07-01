<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\SetupChecks;

use OCA\AppAPI\BackgroundJob\ExAppSetupChecksRefreshOnceJob;
use OCA\AppAPI\Service\ExAppService;
use OCA\AppAPI\Service\ExAppSetupCheckService;
use OCP\BackgroundJob\IJobList;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;
use Psr\Log\LoggerInterface;

/**
 * Surfaces setup checks reported by ExApps in the admin "Security & setup warnings" panel.
 *
 * A SetupResult carries a single severity, and the Overview styles the whole result (all its lines)
 * by that severity. So one aggregate check would force every warning line into the "error" styling as
 * soon as a single error exists. Instead this is split per severity: {@see ExAppsErrorSetupCheck} and
 * {@see ExAppsWarningSetupCheck} each read the same stored results but surface only their own severity,
 * so errors and warnings land in their own entries.
 *
 * IMPORTANT: this NEVER does any HTTP. The results are fetched from the ExApps entirely in the
 * background ({@see \OCA\AppAPI\Service\ExAppSetupCheckRefreshService} via a TimedJob + an on-demand
 * QueuedJob), so a slow / down / errored ExApp can never slow down this page. run() just reads the
 * last stored results and, stale-while-revalidate style, enqueues a one-off refresh (idempotent, so
 * it is a no-op if one is already queued or running) to warm the results for the next view/Recheck.
 *
 * Security: every ExApp-provided string is neutralized at this render boundary, because a SetupResult
 * is consumed by two sinks - the web panel (unescaped v-html) AND `occ setupchecks` (a TTY).
 * Description text is control-char-stripped + `{`/`}`-stripped (rich-object placeholder) +
 * html-escaped; the link label is control-char + `<`/`>` stripped (it reaches the TTY verbatim and is
 * html-escaped only by the web renderer); the link url is re-validated to http(s) without control
 * chars. Response size/length/count are bounded upstream in the refresh service.
 */
abstract class AbstractExAppsSetupCheck implements ISetupCheck {
	public function __construct(
		protected readonly IL10N $l10n,
		protected readonly LoggerInterface $logger,
		protected readonly ExAppSetupCheckService $setupCheckService,
		protected readonly ExAppService $exAppService,
		protected readonly IJobList $jobList,
	) {
	}

	/** The single severity ('error' | 'warning') this check surfaces. */
	abstract protected function severity(): string;

	public function getCategory(): string {
		return 'system';
	}

	public function run(): SetupResult {
		// Warm the results for the next view. add() is idempotent, so this is a no-op when a refresh
		// is already queued or running - exactly the "only if not already running" guard we want.
		try {
			$this->jobList->add(ExAppSetupChecksRefreshOnceJob::class);
		} catch (\Throwable $e) {
			$this->logger->warning('ExAppsSetupCheck: could not enqueue refresh job', ['exception' => $e]);
		}

		// Pure read of the stored results - never blocks, can't be slowed by an ExApp.
		try {
			$issues = array_values(array_filter(
				$this->collectIssues(),
				fn (array $issue): bool => (is_string($issue['severity'] ?? null) ? $issue['severity'] : 'warning') === $this->severity(),
			));
			if ($issues === []) {
				return SetupResult::success();
			}
			return $this->buildResult($issues);
		} catch (\Throwable $e) {
			$this->logger->error('ExAppsSetupCheck: failed to read stored state', ['exception' => $e]);
			return SetupResult::success();
		}
	}

	/**
	 * All stored issues across the currently opted-in + enabled ExApps (every severity).
	 *
	 * @return list<array<string, mixed>>
	 */
	private function collectIssues(): array {
		$optedIn = $this->setupCheckService->getOptedInAppIds();
		if ($optedIn === []) {
			return [];
		}
		$state = $this->setupCheckService->getState();
		$issues = [];
		foreach ($state['apps'] as $appId => $appIssues) {
			$appId = (string)$appId; // json_decode can coerce a numeric-looking key to int
			if (!in_array($appId, $optedIn, true) || !is_array($appIssues)) {
				continue; // opted out since the last refresh
			}
			$exApp = $this->exAppService->getExApp($appId);
			if ($exApp === null || $exApp->getEnabled() !== 1) {
				continue; // gone / disabled since the last refresh
			}
			foreach ($appIssues as $issue) {
				if (is_array($issue)) {
					$issues[] = $issue;
				}
			}
		}
		return $issues;
	}

	/**
	 * @param list<array<string, mixed>> $issues issues of this check's severity (untrusted - escaped here)
	 */
	private function buildResult(array $issues): SetupResult {
		$lines = [];
		$parameters = [];
		$linkIndex = 0;
		foreach ($issues as $issue) {
			$appName = is_string($issue['appName'] ?? null) ? $issue['appName'] : '';
			$text = is_string($issue['text'] ?? null) ? $issue['text'] : '';
			$line = $this->sanitizeText($appName) . ': ' . $this->sanitizeText($text);
			$linkUrl = $this->safeUrl(is_string($issue['linkUrl'] ?? null) ? $issue['linkUrl'] : '');
			if ($linkUrl !== '') {
				$placeholder = 'link' . $linkIndex;
				$linkLabel = is_string($issue['linkLabel'] ?? null) ? $issue['linkLabel'] : '';
				$parameters[$placeholder] = [
					'type' => 'highlight',
					'id' => $placeholder,
					'name' => $this->sanitizeLabel($linkLabel !== '' ? $linkLabel : $linkUrl),
					'link' => $linkUrl,
				];
				$line .= ' {' . $placeholder . '}';
				$linkIndex++;
			}
			$lines[] = $line;
		}
		$description = implode('<br />', $lines);

		try {
			return $this->makeResult($description, $parameters !== [] ? $parameters : null);
		} catch (\Throwable $e) {
			// A malformed rich object would otherwise blank the whole check (the manager replaces a
			// throwing check with a generic error). Fall back to a plain, param-free description.
			$this->logger->error('ExAppsSetupCheck: failed to build rich result, falling back to plain text', ['exception' => $e]);
			$plain = implode('<br />', array_map(
				fn (array $issue): string => $this->sanitizeText(is_string($issue['appName'] ?? null) ? $issue['appName'] : '')
					. ': ' . $this->sanitizeText(is_string($issue['text'] ?? null) ? $issue['text'] : ''),
				$issues,
			));
			return $this->makeResult($plain, null);
		}
	}

	/**
	 * Neutralize anything that could break a renderer or the rich-object validator. Control chars are
	 * stripped (an `occ setupchecks` TTY would otherwise interpret ANSI escapes); `{`/`}` are dropped
	 * (the description is scanned for `{placeholder}` tokens); html is escaped (with ENT_SUBSTITUTE so
	 * invalid UTF-8 degrades instead of blanking the line) because the web renderer injects the base
	 * description with unescaped v-html.
	 */
	private function sanitizeText(string $text): string {
		$text = preg_replace('/[\x00-\x1f\x7f]/', '', $text) ?? '';
		$text = str_replace(['{', '}'], '', $text);
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/**
	 * The rich-object `name` (link label) is html-escaped by the web renderer but rendered VERBATIM by
	 * `occ setupchecks`, so strip control chars and the `<`/`>` that would form console tags. The link
	 * url itself is already validated by {@see safeUrl}.
	 */
	private function sanitizeLabel(string $label): string {
		return preg_replace('/[\x00-\x1f\x7f<>]/', '', $label) ?? '';
	}

	/** Defence in depth: re-validate the stored URL before it reaches the rendered href. */
	private function safeUrl(string $url): string {
		if ($url === '' || strlen($url) > 2048) {
			return '';
		}
		if (preg_match('#^https?://#i', $url) !== 1) {
			return '';
		}
		if (preg_match('/[\x00-\x20\x7f"\'<>]/', $url) === 1) {
			return '';
		}
		return $url;
	}

	private function makeResult(string $description, ?array $parameters): SetupResult {
		return $this->severity() === 'error'
			? SetupResult::error($description, null, $parameters)
			: SetupResult::warning($description, null, $parameters);
	}
}
