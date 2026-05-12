<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Controller;

use OCA\AppAPI\Controller\TalkBotController;
use OCA\AppAPI\Db\TalkBot;
use OCA\AppAPI\Db\TalkBotMapper;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppService;
use OCA\AppAPI\Service\TalkBotsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Integration test for TalkBotController. Replaces the registration coverage from
 * nc_py_api/tests/actual_tests/talk_bot_test.py (test_register_unregister_talk_bot, test_list_bots).
 *
 * The end-to-end message-receive test (test_chat_bot_receive_message) is intentionally not ported — it exercises
 * Talk's delivery, not AppAPI's registration plumbing, and would require an HMAC-signed callback simulator.
 *
 * Skipped automatically when Talk (`spreed`) is not present.
 */
#[Group('DB')]
class TalkBotControllerTest extends TestCase {
	use RegistersFakeExAppTrait;

	private const TEST_APP_ID = 'phpunit_talkbot_fake';
	private const TEST_PORT = 19003;
	private const ROUTE = '/talk_bot_phpunit';

	private TalkBotController $controller;
	/** @var IRequest&MockObject */
	private $request;
	private TalkBotsService $talkBotsService;
	private ExAppService $exAppService;
	private bool $exAppInserted = false;

	protected function setUp(): void {
		parent::setUp();
		// Resolve services before any code that might bail (markTestSkipped throws) — tearDown reads
		// $this->exAppService and the typed property must be initialized even if the rest of setUp aborts.
		$this->exAppService = Server::get(ExAppService::class);
		$this->talkBotsService = Server::get(TalkBotsService::class);

		if (!class_exists(\OCA\Talk\Events\BotInstallEvent::class)) {
			self::markTestSkipped('Talk (spreed) BotInstallEvent class not available');
		}

		$this->insertFakeExApp(self::TEST_APP_ID, self::TEST_PORT);
		$this->exAppInserted = true;

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $name): string => $name === 'EX-APP-ID' ? self::TEST_APP_ID : ''
		);

		$this->controller = new TalkBotController(
			$this->request,
			$this->exAppService,
			Server::get(AppAPIService::class),
			$this->talkBotsService,
			Server::get(LoggerInterface::class),
			Server::get(IThrottler::class),
		);

		$this->safeUnregisterBot();
	}

	protected function tearDown(): void {
		if ($this->exAppInserted) {
			$this->safeUnregisterBot();
			$this->deleteFakeExApp(self::TEST_APP_ID);
		}
		parent::tearDown();
	}

	private function safeUnregisterBot(): void {
		$exApp = $this->exAppService->getExApp(self::TEST_APP_ID);
		if ($exApp !== null) {
			$this->talkBotsService->unregisterExAppBot($exApp, ltrim(self::ROUTE, '/'));
		}
	}

	public function testRegisterReturnsIdAndSecret(): void {
		$response = $this->controller->registerExAppTalkBot(
			name: 'PHPUnit Bot',
			route: self::ROUTE,
			description: 'Integration test bot',
		);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertArrayHasKey('id', $data);
		self::assertArrayHasKey('secret', $data);
		self::assertNotEmpty($data['id']);
		self::assertNotEmpty($data['secret']);

		$stored = $this->talkBotsService->getTalkBotSecret(self::TEST_APP_ID, ltrim(self::ROUTE, '/'));
		self::assertSame($data['secret'], $stored);
	}

	public function testUnregisterRemovesSecret(): void {
		$this->controller->registerExAppTalkBot('PHPUnit Bot', self::ROUTE, 'desc');
		self::assertNotNull(
			$this->talkBotsService->getTalkBotSecret(self::TEST_APP_ID, ltrim(self::ROUTE, '/'))
		);

		$response = $this->controller->unregisterExAppTalkBot(self::ROUTE);
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		self::assertNull(
			$this->talkBotsService->getTalkBotSecret(self::TEST_APP_ID, ltrim(self::ROUTE, '/'))
		);
	}

	public function testUnregisterMissingThrowsNotFound(): void {
		$this->expectException(OCSNotFoundException::class);
		$this->controller->unregisterExAppTalkBot('/never_registered_phpunit');
	}

	public function testRegisterTwiceReusesSecret(): void {
		// TalkBotsService keeps one row per (appid, route) and reuses its stored secret on re-register
		// so existing Talk-side bots stay valid. Both register calls must return the SAME id/secret.
		$first = $this->controller->registerExAppTalkBot('PHPUnit Bot', self::ROUTE, 'desc')->getData();
		$second = $this->controller->registerExAppTalkBot('PHPUnit Bot', self::ROUTE, 'desc')->getData();
		self::assertSame($first['id'], $second['id']);
		self::assertSame($first['secret'], $second['secret']);
	}

	public function testRegisterReturnsBadRequestWhenStoredSecretIsCorrupted(): void {
		// Insert a TalkBot row directly with bogus secret data that ICrypto::decrypt cannot parse.
		// The service must refuse to auto-recover (re-minting against the same URL would leave Talk
		// wedged with a "different secret" rejection) and the controller surfaces a 400 to the caller.
		$mapper = Server::get(TalkBotMapper::class);
		$bot = new TalkBot();
		$bot->setAppid(self::TEST_APP_ID);
		$bot->setRoute(ltrim(self::ROUTE, '/'));
		$bot->setSecret('NOT_A_VALID_CRYPTO_PAYLOAD');
		$bot->setCreatedTime(time());
		$mapper->insert($bot);

		try {
			$this->expectException(OCSBadRequestException::class);
			$this->controller->registerExAppTalkBot('PHPUnit Bot', self::ROUTE, 'desc');
		} finally {
			// Clean up the manually-inserted corrupted row so tearDown's safeUnregister works.
			try {
				$mapper->delete($mapper->findByAppidAndRoute(self::TEST_APP_ID, ltrim(self::ROUTE, '/')));
			} catch (DoesNotExistException) {
			}
		}
	}

	public function testFanOutUnregisterClearsAllAppBots(): void {
		// unregisterExAppTalkBots is invoked by ExAppService::unregisterExApp. It must enumerate via
		// TalkBotMapper::findAllByAppid and dispatch a BotUninstallEvent per bot.
		$exApp = $this->exAppService->getExApp(self::TEST_APP_ID);
		self::assertNotNull($exApp);

		$this->talkBotsService->registerExAppBot($exApp, 'PHPUnit Bot 1', 'fanout_route_one', 'desc');
		$this->talkBotsService->registerExAppBot($exApp, 'PHPUnit Bot 2', 'fanout_route_two', 'desc');
		self::assertNotNull($this->talkBotsService->getTalkBotSecret(self::TEST_APP_ID, 'fanout_route_one'));
		self::assertNotNull($this->talkBotsService->getTalkBotSecret(self::TEST_APP_ID, 'fanout_route_two'));

		$this->talkBotsService->unregisterExAppTalkBots($exApp);

		self::assertNull($this->talkBotsService->getTalkBotSecret(self::TEST_APP_ID, 'fanout_route_one'));
		self::assertNull($this->talkBotsService->getTalkBotSecret(self::TEST_APP_ID, 'fanout_route_two'));
	}
}
