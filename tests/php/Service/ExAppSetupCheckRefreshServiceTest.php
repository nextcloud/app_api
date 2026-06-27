<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Service;

use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppService;
use OCA\AppAPI\Service\ExAppSetupCheckRefreshService;
use OCA\AppAPI\Service\ExAppSetupCheckService;
use OCP\Http\Client\IResponse;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExAppSetupCheckRefreshServiceTest extends TestCase {
	private IL10N&MockObject $l10n;
	private LoggerInterface&MockObject $logger;
	private ExAppSetupCheckService&MockObject $setupCheckService;
	private ExAppService&MockObject $exAppService;
	private AppAPIService&MockObject $appAPIService;
	private ExAppSetupCheckRefreshService $refreshService;

	protected function setUp(): void {
		parent::setUp();
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(fn (string $text, array $p = []): string => $text);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->setupCheckService = $this->createMock(ExAppSetupCheckService::class);
		$this->exAppService = $this->createMock(ExAppService::class);
		$this->appAPIService = $this->createMock(AppAPIService::class);

		$this->refreshService = $this->makeRefreshService();
	}

	private function makeRefreshService(float $budget = 120.0): ExAppSetupCheckRefreshService {
		return new ExAppSetupCheckRefreshService(
			$this->l10n, $this->logger, $this->setupCheckService, $this->exAppService, $this->appAPIService, $budget,
		);
	}

	private function makeExApp(string $appId, int $enabled = 1, array $status = ['init' => 100, 'action' => '']): ExApp {
		$exApp = new ExApp();
		$exApp->setAppid($appId);
		$exApp->setName('App ' . $appId);
		$exApp->setEnabled($enabled);
		$exApp->setStatus($status);
		return $exApp;
	}

	private function response(int $statusCode, string $body, ?int $contentLength = null): IResponse&MockObject {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($statusCode);
		$response->method('getBody')->willReturn($body);
		// default to a realistic Content-Length matching the body
		$response->method('getHeader')->willReturn((string)($contentLength ?? strlen($body)));
		return $response;
	}

	private function responseNoContentLength(int $statusCode, string $body): IResponse&MockObject {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($statusCode);
		$response->method('getBody')->willReturn($body);
		$response->method('getHeader')->willReturn(''); // no Content-Length header
		return $response;
	}

	private function down(): array {
		return ['error' => 'connection refused'];
	}

	private function responseWithHeader(int $statusCode, string $body, string $contentLengthHeader): IResponse&MockObject {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($statusCode);
		$response->method('getBody')->willReturn($body);
		$response->method('getHeader')->willReturn($contentLengthHeader);
		return $response;
	}

	/**
	 * @param list<string> $optedIn opted-in app ids
	 * @param array<string, ExApp> $appsById
	 * @param array<string, IResponse|array> $resultsByAppId
	 * @param array<string, list<array<string, mixed>>> $previousState the prior stored state (carry-over source)
	 * @return array<string, list<array<string, mixed>>> the state passed to storeState()
	 */
	private function runRefresh(array $optedIn, array $appsById, array $resultsByAppId, array $previousState = [], ?ExAppSetupCheckRefreshService $service = null): array {
		$stored = [];
		$this->setupCheckService->method('getOptedInAppIds')->willReturn($optedIn);
		$this->setupCheckService->method('getState')->willReturn(['apps' => $previousState, 'updatedAt' => 1]);
		$this->setupCheckService->method('storeState')->willReturnCallback(function (array $apps) use (&$stored): void {
			$stored = $apps;
		});
		$this->exAppService->method('getExApp')->willReturnCallback(fn (string $id): ?ExApp => $appsById[$id] ?? null);
		$this->appAPIService->method('requestToExApp')->willReturnCallback(
			fn (ExApp $exApp) => $resultsByAppId[$exApp->getAppid()]
		);
		($service ?? $this->refreshService)->refresh();
		return $stored;
	}

	public function testWarningIsStored(): void {
		$state = $this->runRefresh(
			['a'],
			['a' => $this->makeExApp('a')],
			['a' => $this->response(200, json_encode(['c1' => ['status' => 'warning', 'text' => 'bad config']]))],
		);
		self::assertArrayHasKey('a', $state);
		self::assertSame('warning', $state['a'][0]['severity']);
		self::assertSame('bad config', $state['a'][0]['text']);
	}

	public function testSuccessIsNotStored(): void {
		$state = $this->runRefresh(
			['a'],
			['a' => $this->makeExApp('a')],
			['a' => $this->response(200, json_encode(['c1' => ['status' => 'success', 'text' => 'ok']]))],
		);
		self::assertArrayNotHasKey('a', $state);
	}

	public function testInfoStatusIsNotStored(): void {
		// only error/warning are surfaced; info is ignored like success/unknown.
		$state = $this->runRefresh(
			['a'],
			['a' => $this->makeExApp('a')],
			['a' => $this->response(200, json_encode(['c1' => ['status' => 'info', 'text' => 'fyi']]))],
		);
		self::assertArrayNotHasKey('a', $state);
	}

	public function testDownIsStoredAsNotResponding(): void {
		$state = $this->runRefresh(
			['a'],
			['a' => $this->makeExApp('a')],
			['a' => $this->down()],
		);
		self::assertSame('warning', $state['a'][0]['severity']);
		self::assertSame('not responding', $state['a'][0]['text']);
	}

	public function testNon2xxIsNotResponding(): void {
		$state = $this->runRefresh(
			['a'],
			['a' => $this->makeExApp('a')],
			['a' => $this->response(500, 'oops')],
		);
		self::assertSame('not responding', $state['a'][0]['text']);
	}

	public function testOversizedResponseIsNotResponding(): void {
		$state = $this->runRefresh(
			['a'],
			['a' => $this->makeExApp('a')],
			['a' => $this->response(200, json_encode(['c1' => ['status' => 'warning', 'text' => 'x']]), 999999999)],
		);
		self::assertSame('not responding', $state['a'][0]['text']);
	}

	public function testDisabledAppIsNotProbed(): void {
		$this->appAPIService->expects(self::never())->method('requestToExApp');
		$state = $this->runRefresh(
			['a'],
			['a' => $this->makeExApp('a', 0)],
			[],
		);
		self::assertArrayNotHasKey('a', $state);
	}

	/** Each initializing predicate independently must exclude the app (guards against `||` -> `&&`). */
	#[DataProvider('initializingStatusProvider')]
	public function testInitializingAppIsNotProbed(array $status): void {
		$this->appAPIService->expects(self::never())->method('requestToExApp');
		$state = $this->runRefresh(
			['a'],
			['a' => $this->makeExApp('a', 1, $status)],
			[],
		);
		self::assertArrayNotHasKey('a', $state);
	}

	public static function initializingStatusProvider(): array {
		return [
			'init below 100 only' => [['init' => 40, 'action' => '']],
			'action is init only' => [['init' => 100, 'action' => 'init']],
			'both' => [['init' => 40, 'action' => 'init']],
		];
	}

	public function testEmptyTextFallsBackToReportedAProblem(): void {
		$state = $this->runRefresh(
			['a'],
			['a' => $this->makeExApp('a')],
			['a' => $this->response(200, json_encode(['c1' => ['status' => 'error', 'text' => '']]))],
		);
		self::assertSame('reported a problem', $state['a'][0]['text']);
	}

	public function testLongTextIsTruncated(): void {
		$state = $this->runRefresh(
			['a'],
			['a' => $this->makeExApp('a')],
			['a' => $this->response(200, json_encode(['c1' => ['status' => 'warning', 'text' => str_repeat('A', 20000)]]))],
		);
		self::assertLessThanOrEqual(4096, mb_strlen($state['a'][0]['text']));
	}

	public function testValidHttpsLinkIsStored(): void {
		$state = $this->runRefresh(
			['a'],
			['a' => $this->makeExApp('a')],
			['a' => $this->response(200, json_encode(['c1' => ['status' => 'warning', 'text' => 'fix', 'link_url' => 'https://x.test/d', 'link_label' => 'Docs']]))],
		);
		self::assertSame('https://x.test/d', $state['a'][0]['linkUrl']);
		self::assertSame('Docs', $state['a'][0]['linkLabel']);
	}

	public function testJavascriptLinkIsDropped(): void {
		$state = $this->runRefresh(
			['a'],
			['a' => $this->makeExApp('a')],
			['a' => $this->response(200, json_encode(['c1' => ['status' => 'warning', 'text' => 'x', 'link_url' => 'javascript:alert(1)']]))],
		);
		self::assertSame('', $state['a'][0]['linkUrl']);
	}

	public function testControlCharUrlIsDropped(): void {
		$state = $this->runRefresh(
			['a'],
			['a' => $this->makeExApp('a')],
			['a' => $this->response(200, json_encode(['c1' => ['status' => 'warning', 'text' => 'x', 'link_url' => "https://x.test/a\nb"]]))],
		);
		self::assertSame('', $state['a'][0]['linkUrl']);
	}

	public function testMalformedItemIsSkipped(): void {
		$state = $this->runRefresh(
			['a'],
			['a' => $this->makeExApp('a')],
			['a' => $this->response(200, json_encode(['bad' => 'not-an-array', 'good' => ['status' => 'warning', 'text' => 'real']]))],
		);
		self::assertCount(1, $state['a']);
		self::assertSame('real', $state['a'][0]['text']);
	}

	public function testMissingContentLengthIsNotResponding(): void {
		$state = $this->runRefresh(
			['a'],
			['a' => $this->makeExApp('a')],
			['a' => $this->responseNoContentLength(200, json_encode(['c1' => ['status' => 'warning', 'text' => 'x']]))],
		);
		self::assertSame('not responding', $state['a'][0]['text']);
	}

	public function testMaxChecksCapPerApp(): void {
		$checks = [];
		for ($i = 0; $i < 25; $i++) {
			$checks['c' . $i] = ['status' => 'warning', 'text' => 'w' . $i];
		}
		$state = $this->runRefresh(
			['a'],
			['a' => $this->makeExApp('a')],
			['a' => $this->response(200, json_encode($checks))],
		);
		self::assertCount(ExAppSetupCheckService::MAX_CHECKS, $state['a']);
	}

	/** Content-Length must be digits only; is_numeric would have accepted these. */
	#[DataProvider('malformedContentLengthProvider')]
	public function testMalformedContentLengthIsNotResponding(string $contentLength): void {
		$state = $this->runRefresh(
			['a'],
			['a' => $this->makeExApp('a')],
			['a' => $this->responseWithHeader(200, json_encode(['c1' => ['status' => 'warning', 'text' => 'x']]), $contentLength)],
		);
		self::assertSame('not responding', $state['a'][0]['text']);
	}

	public static function malformedContentLengthProvider(): array {
		return [['-1'], ['12.5'], ['1e3'], ['0x10'], [' 12'], ['abc'], ['']];
	}

	public function testBudgetBreakKeepsPreviousResultsForUnvisitedApps(): void {
		// negative budget -> the break fires before any probe (deterministic, no wall-clock dependency):
		// every opted-in app is "unvisited" and must keep its previous stored issues, while an app with
		// no previous entry just gets none.
		$service = $this->makeRefreshService(-1.0);
		$previous = [
			'a' => [['severity' => 'error', 'appName' => 'A', 'text' => 'old error', 'linkUrl' => '', 'linkLabel' => '']],
			'b' => [['severity' => 'warning', 'appName' => 'B', 'text' => 'old warning', 'linkUrl' => '', 'linkLabel' => '']],
		];
		// budget hit before any probe; carried-over (old) values prove nothing was freshly fetched.
		$state = $this->runRefresh(
			['a', 'b', 'c'],
			['a' => $this->makeExApp('a'), 'b' => $this->makeExApp('b'), 'c' => $this->makeExApp('c')],
			[],
			$previous,
			$service,
		);
		self::assertSame('old error', $state['a'][0]['text']);   // carried over
		self::assertSame('old warning', $state['b'][0]['text']); // carried over
		self::assertArrayNotHasKey('c', $state);                 // no previous -> no entry
	}

	public function testBudgetBreakDropsDisabledAppEvenWithPreviousResults(): void {
		// a disabled app must NOT be carried over (down-ness belongs on the management page)
		$service = $this->makeRefreshService(-1.0);
		$previous = ['a' => [['severity' => 'error', 'appName' => 'A', 'text' => 'old error', 'linkUrl' => '', 'linkLabel' => '']]];
		$state = $this->runRefresh(['a'], ['a' => $this->makeExApp('a', 0)], [], $previous, $service);
		self::assertArrayNotHasKey('a', $state);
	}
}
