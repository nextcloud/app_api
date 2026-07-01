<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\SetupChecks;

use OCA\AppAPI\BackgroundJob\ExAppSetupChecksRefreshOnceJob;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\Service\ExAppService;
use OCA\AppAPI\Service\ExAppSetupCheckService;
use OCA\AppAPI\SetupChecks\AbstractExAppsSetupCheck;
use OCA\AppAPI\SetupChecks\ExAppsErrorSetupCheck;
use OCA\AppAPI\SetupChecks\ExAppsWarningSetupCheck;
use OCP\BackgroundJob\IJobList;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The setup checks only READ the stored state and enqueue a refresh; they never do HTTP. The fan-out
 * is covered by {@see ExAppSetupCheckRefreshServiceTest}. There is one check per severity so errors,
 * warnings and info land in their own Overview entries.
 */
class ExAppsSetupCheckTest extends TestCase {
	private IL10N&MockObject $l10n;
	private ExAppSetupCheckService&MockObject $setupCheckService;
	private ExAppService&MockObject $exAppService;
	private IJobList&MockObject $jobList;

	protected function setUp(): void {
		parent::setUp();
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(fn (string $text, array $p = []): string => $text);
		$this->setupCheckService = $this->createMock(ExAppSetupCheckService::class);
		$this->exAppService = $this->createMock(ExAppService::class);
		$this->jobList = $this->createMock(IJobList::class);
	}

	private function makeCheck(string $class): AbstractExAppsSetupCheck {
		return new $class($this->l10n, $this->createMock(LoggerInterface::class), $this->setupCheckService, $this->exAppService, $this->jobList);
	}

	private function makeExApp(string $appId, int $enabled = 1): ExApp {
		$exApp = new ExApp();
		$exApp->setAppid($appId);
		$exApp->setName('App ' . $appId);
		$exApp->setEnabled($enabled);
		return $exApp;
	}

	private function issue(string $severity, string $text, string $appName = 'App a', string $linkUrl = '', string $linkLabel = ''): array {
		return ['severity' => $severity, 'appName' => $appName, 'text' => $text, 'linkUrl' => $linkUrl, 'linkLabel' => $linkLabel];
	}

	/**
	 * @param class-string<AbstractExAppsSetupCheck> $class
	 * @param list<string> $optedIn
	 * @param array<string, list<array<string, mixed>>> $stateApps
	 * @param array<string, ExApp> $appsById
	 */
	private function runCheck(string $class, array $optedIn, array $stateApps, array $appsById): SetupResult {
		$this->setupCheckService->method('getOptedInAppIds')->willReturn($optedIn);
		$this->setupCheckService->method('getState')->willReturn(['apps' => $stateApps, 'updatedAt' => 123]);
		$this->exAppService->method('getExApp')->willReturnCallback(fn (string $id): ?ExApp => $appsById[$id] ?? null);
		return $this->makeCheck($class)->run();
	}

	/** Mixed state: each check must surface ONLY its own severity. */
	private function mixedState(): array {
		return ['apps' => [
			'a' => [$this->issue('error', 'E broke', 'App a'), $this->issue('warning', 'W warn', 'App a')],
			'b' => [$this->issue('info', 'I note', 'App b')],
		], 'byId' => ['a' => $this->makeExApp('a'), 'b' => $this->makeExApp('b')], 'optedIn' => ['a', 'b']];
	}

	public function testErrorCheckSurfacesOnlyErrors(): void {
		$s = $this->mixedState();
		$r = $this->runCheck(ExAppsErrorSetupCheck::class, $s['optedIn'], $s['apps'], $s['byId']);
		self::assertSame('error', $r->getSeverity());
		$desc = (string)$r->getDescription();
		self::assertStringContainsString('E broke', $desc);
		self::assertStringNotContainsString('W warn', $desc);
		self::assertStringNotContainsString('I note', $desc);
	}

	public function testWarningCheckSurfacesOnlyWarnings(): void {
		$s = $this->mixedState();
		$r = $this->runCheck(ExAppsWarningSetupCheck::class, $s['optedIn'], $s['apps'], $s['byId']);
		self::assertSame('warning', $r->getSeverity());
		$desc = (string)$r->getDescription();
		self::assertStringContainsString('W warn', $desc);
		self::assertStringNotContainsString('E broke', $desc);
		self::assertStringNotContainsString('I note', $desc);
	}

	public function testInfoIssueIsIgnoredByBothChecks(): void {
		// info is no longer surfaced; an info issue in the state must be dropped by error AND warning.
		$state = ['a' => [$this->issue('info', 'just an info note', 'App a')]];
		self::assertSame('success', $this->runCheck(ExAppsErrorSetupCheck::class, ['a'], $state, ['a' => $this->makeExApp('a')])->getSeverity());
		self::assertSame('success', $this->runCheck(ExAppsWarningSetupCheck::class, ['a'], $state, ['a' => $this->makeExApp('a')])->getSeverity());
	}

	public function testErrorCheckIsSuccessWhenNoErrors(): void {
		$r = $this->runCheck(
			ExAppsErrorSetupCheck::class,
			['a'],
			['a' => [$this->issue('warning', 'only a warning', 'App a')]],
			['a' => $this->makeExApp('a')],
		);
		self::assertSame('success', $r->getSeverity());
	}

	public function testNoOptedInAppsIsSuccess(): void {
		self::assertSame('success', $this->runCheck(ExAppsWarningSetupCheck::class, [], [], [])->getSeverity());
	}

	public function testNoStoredIssuesIsSuccess(): void {
		$r = $this->runCheck(ExAppsWarningSetupCheck::class, ['a'], [], ['a' => $this->makeExApp('a')]);
		self::assertSame('success', $r->getSeverity());
	}

	public function testEnqueuesRefreshOnRun(): void {
		$this->jobList->expects(self::once())->method('add')->with(ExAppSetupChecksRefreshOnceJob::class);
		$this->setupCheckService->method('getOptedInAppIds')->willReturn([]);
		$this->makeCheck(ExAppsErrorSetupCheck::class)->run();
	}

	public function testNotOptedInAppInStateIsIgnored(): void {
		$r = $this->runCheck(
			ExAppsErrorSetupCheck::class,
			['a'],
			['gone' => [$this->issue('error', 'stale', 'App gone')]],
			['gone' => $this->makeExApp('gone')],
		);
		self::assertSame('success', $r->getSeverity());
	}

	public function testDisabledAppInStateIsIgnored(): void {
		$r = $this->runCheck(
			ExAppsErrorSetupCheck::class,
			['a'],
			['a' => [$this->issue('error', 'was bad', 'App a')]],
			['a' => $this->makeExApp('a', 0)],
		);
		self::assertSame('success', $r->getSeverity());
	}

	public function testXssTextIsEscapedAtRender(): void {
		$r = $this->runCheck(
			ExAppsWarningSetupCheck::class,
			['a'],
			['a' => [$this->issue('warning', '<script>alert(1)</script>', 'App a')]],
			['a' => $this->makeExApp('a')],
		);
		$desc = (string)$r->getDescription();
		self::assertStringNotContainsString('<script>', $desc);
		self::assertStringContainsString('&lt;script&gt;', $desc);
	}

	public function testBracesStrippedAtRender(): void {
		$r = $this->runCheck(
			ExAppsWarningSetupCheck::class,
			['a'],
			['a' => [$this->issue('warning', 'use {placeholder} now', 'App a')]],
			['a' => $this->makeExApp('a')],
		);
		self::assertStringNotContainsString('{', (string)$r->getDescription());
	}

	public function testControlCharsStrippedAtRender(): void {
		$r = $this->runCheck(
			ExAppsWarningSetupCheck::class,
			['a'],
			['a' => [$this->issue('warning', "danger\x1b[2J\x07clear", 'App a')]],
			['a' => $this->makeExApp('a')],
		);
		$desc = (string)$r->getDescription();
		self::assertStringNotContainsString("\x1b", $desc);
		self::assertStringNotContainsString("\x07", $desc);
		self::assertStringContainsString('danger', $desc);
	}

	public function testValidLinkBecomesParameter(): void {
		$r = $this->runCheck(
			ExAppsWarningSetupCheck::class,
			['a'],
			['a' => [$this->issue('warning', 'fix it', 'App a', 'https://example.com/docs', 'Docs')]],
			['a' => $this->makeExApp('a')],
		);
		$params = $r->getDescriptionParameters();
		self::assertIsArray($params);
		self::assertSame('https://example.com/docs', $params['link0']['link']);
		self::assertStringContainsString('{link0}', (string)$r->getDescription());
	}

	public function testLabelConsoleTagsStrippedAtRender(): void {
		$r = $this->runCheck(
			ExAppsWarningSetupCheck::class,
			['a'],
			['a' => [$this->issue('warning', 'x', 'App a', 'https://example.com/d', '<error>FAKE</error>')]],
			['a' => $this->makeExApp('a')],
		);
		$params = $r->getDescriptionParameters();
		self::assertStringNotContainsString('<', $params['link0']['name']);
		self::assertStringNotContainsString('>', $params['link0']['name']);
		self::assertStringContainsString('FAKE', $params['link0']['name']);
	}

	public function testTamperedJsLinkIsDroppedAtRender(): void {
		$r = $this->runCheck(
			ExAppsWarningSetupCheck::class,
			['a'],
			['a' => [$this->issue('warning', 'x', 'App a', 'javascript:alert(1)', 'click')]],
			['a' => $this->makeExApp('a')],
		);
		self::assertNull($r->getDescriptionParameters());
		self::assertStringNotContainsString('{link', (string)$r->getDescription());
	}
}
