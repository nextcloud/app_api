<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Controller;

use OCA\AppAPI\Controller\OCSUiController;
use OCA\AppAPI\Service\UI\FilesActionsMenuService;
use OCA\AppAPI\Service\UI\InitialStateService;
use OCA\AppAPI\Service\UI\ScriptsService;
use OCA\AppAPI\Service\UI\StylesService;
use OCA\AppAPI\Service\UI\TopMenuService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for OCSUiController.
 *
 * Pulls the real UI services out of the DI container and exercises the full register / get / unregister round-trip
 * against the real database. Only IRequest is mocked, to inject a deterministic EX-APP-ID header.
 *
 * Replaces coverage previously provided by nc_py_api's ui_settings/top_menu/file_actions/resources test modules.
 */
#[Group('DB')]
class OCSUiControllerTest extends TestCase {
	private const TEST_APP_ID = 'phpunit_ocs_ui_test';

	private OCSUiController $controller;
	/** @var IRequest&MockObject */
	private $request;
	private TopMenuService $topMenuService;
	private FilesActionsMenuService $filesActionsService;
	private InitialStateService $initialStateService;
	private ScriptsService $scriptsService;
	private StylesService $stylesService;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $name): string => $name === 'EX-APP-ID' ? self::TEST_APP_ID : ''
		);

		$this->topMenuService = Server::get(TopMenuService::class);
		$this->filesActionsService = Server::get(FilesActionsMenuService::class);
		$this->initialStateService = Server::get(InitialStateService::class);
		$this->scriptsService = Server::get(ScriptsService::class);
		$this->stylesService = Server::get(StylesService::class);

		$this->controller = new OCSUiController(
			$this->request,
			$this->filesActionsService,
			$this->topMenuService,
			$this->initialStateService,
			$this->scriptsService,
			$this->stylesService,
		);

		$this->cleanupTestRows();
	}

	protected function tearDown(): void {
		$this->cleanupTestRows();
		parent::tearDown();
	}

	private function cleanupTestRows(): void {
		// Best-effort cleanup. Each service's delete methods take (appId, ...identity) and return false if the row
		// does not exist — we don't care.
		foreach (['main_menu', 'second_menu'] as $name) {
			$this->topMenuService->unregisterExAppMenuEntry(self::TEST_APP_ID, $name);
		}
		foreach (['ui_action_v1', 'ui_action_v2'] as $name) {
			$this->filesActionsService->unregisterFileActionMenu(self::TEST_APP_ID, $name);
		}
		foreach ([
			['top_menu', 'page_a', 'state_a'],
			['top_menu', 'page_a', 'state_b'],
		] as [$type, $name, $key]) {
			$this->initialStateService->deleteExAppInitialState(self::TEST_APP_ID, $type, $name, $key);
		}
		foreach ([
			['top_menu', 'page_a', 'js/script_a.js'],
			['top_menu', 'page_a', 'js/script_b.js'],
			['top_menu', 'page_slash', 'js/script_slash.js'],
		] as [$type, $name, $path]) {
			$this->scriptsService->deleteExAppScript(self::TEST_APP_ID, $type, $name, $path);
		}
		foreach ([
			['top_menu', 'page_a', 'css/style_a.css'],
			['top_menu', 'page_slash', 'css/style_slash.css'],
		] as [$type, $name, $path]) {
			$this->stylesService->deleteExAppStyle(self::TEST_APP_ID, $type, $name, $path);
		}
	}

	/**
	 * Controller methods return entities wrapped in DataResponse without serializing — JSON conversion happens at
	 * HTTP-render time. In unit tests we read the entity through jsonSerialize() to mirror the wire format.
	 *
	 * @return array<string, mixed>
	 */
	private static function asArray(mixed $data): array {
		if ($data instanceof \JsonSerializable) {
			return $data->jsonSerialize();
		}
		if (is_array($data)) {
			return $data;
		}
		self::fail('Unexpected data type: ' . get_debug_type($data));
	}

	public function testTopMenuRegisterGetUnregister(): void {
		$response = $this->controller->registerExAppMenuEntry(
			name: 'main_menu', displayName: 'Main Menu', icon: '', adminRequired: 0
		);
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$response = $this->controller->getExAppMenuEntry('main_menu');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = self::asArray($response->getData());
		self::assertSame(self::TEST_APP_ID, $data['appid']);
		self::assertSame('main_menu', $data['name']);
		self::assertSame('Main Menu', $data['display_name']);
		self::assertSame(0, $data['admin_required']);

		$response = $this->controller->unregisterExAppMenuEntry('main_menu');
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		// Verify gone
		$this->expectException(OCSNotFoundException::class);
		$this->controller->getExAppMenuEntry('main_menu');
	}

	public function testTopMenuGetMissingThrowsNotFound(): void {
		$this->expectException(OCSNotFoundException::class);
		$this->controller->getExAppMenuEntry('does_not_exist');
	}

	public function testTopMenuUnregisterMissingThrowsNotFound(): void {
		$this->expectException(OCSNotFoundException::class);
		$this->controller->unregisterExAppMenuEntry('does_not_exist');
	}

	public function testTopMenuRegisterIsIdempotentReplace(): void {
		// Same name, different displayName — service uses insertOrUpdate semantics.
		$this->controller->registerExAppMenuEntry('main_menu', 'First', '', 0);
		$this->controller->registerExAppMenuEntry('main_menu', 'Second', '', 1);
		$data = self::asArray($this->controller->getExAppMenuEntry('main_menu')->getData());
		self::assertSame('Second', $data['display_name']);
		self::assertSame(1, $data['admin_required']);
	}

	public function testFileActionsV1RegisterGetUnregister(): void {
		// Pass leading-slash on action_handler — FilesActionsMenuService::registerFileActionMenu strips it via ltrim.
		$response = $this->controller->registerFileActionMenu(
			name: 'ui_action_v1', displayName: 'V1 Action', actionHandler: '/handler',
			icon: '', mime: 'file', permissions: 1, order: 1
		);
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$data = self::asArray($this->controller->getFileActionMenu('ui_action_v1')->getData());
		self::assertSame('ui_action_v1', $data['name']);
		self::assertSame('V1 Action', $data['display_name']);
		self::assertSame('handler', $data['action_handler'], 'leading slash should be stripped');
		self::assertSame('file', $data['mime']);
		self::assertSame('1', (string)$data['permissions']);
		self::assertSame(1, $data['order']);
		self::assertSame('1.0', $data['version']);

		$this->controller->unregisterFileActionMenu('ui_action_v1');
		$this->expectException(OCSNotFoundException::class);
		$this->controller->getFileActionMenu('ui_action_v1');
	}

	public function testFileActionsV2HasV2Version(): void {
		$this->controller->registerFileActionMenuV2(
			name: 'ui_action_v2', displayName: 'V2 Action', actionHandler: '/handler2',
			icon: '', mime: 'image', permissions: 31, order: 0
		);
		$data = self::asArray($this->controller->getFileActionMenu('ui_action_v2')->getData());
		self::assertSame('image', $data['mime']);
		self::assertSame('31', (string)$data['permissions']);
		self::assertSame('2.0', $data['version']);
	}

	public function testInitialStateRoundTrip(): void {
		$value = ['key1' => 1, 'key2' => 'two', 'nested' => ['a', 'b']];
		$this->controller->setExAppInitialState('top_menu', 'page_a', 'state_a', $value);

		$data = self::asArray($this->controller->getExAppInitialState('top_menu', 'page_a', 'state_a')->getData());
		self::assertSame(self::TEST_APP_ID, $data['appid']);
		self::assertSame('top_menu', $data['type']);
		self::assertSame('page_a', $data['name']);
		self::assertSame('state_a', $data['key']);
		self::assertSame($value, $data['value']);

		$this->controller->deleteExAppInitialState('top_menu', 'page_a', 'state_a');
		$this->expectException(OCSNotFoundException::class);
		$this->controller->getExAppInitialState('top_menu', 'page_a', 'state_a');
	}

	public function testInitialStateMultipleKeysCoexist(): void {
		$this->controller->setExAppInitialState('top_menu', 'page_a', 'state_a', ['v' => 1]);
		$this->controller->setExAppInitialState('top_menu', 'page_a', 'state_b', ['v' => 2]);

		$a = self::asArray($this->controller->getExAppInitialState('top_menu', 'page_a', 'state_a')->getData());
		$b = self::asArray($this->controller->getExAppInitialState('top_menu', 'page_a', 'state_b')->getData());
		self::assertSame(['v' => 1], $a['value']);
		self::assertSame(['v' => 2], $b['value']);
	}

	public function testScriptRoundTrip(): void {
		$this->controller->setExAppScript('top_menu', 'page_a', 'js/script_a.js', '');
		$data = self::asArray($this->controller->getExAppScript('top_menu', 'page_a', 'js/script_a.js')->getData());
		self::assertSame('js/script_a.js', $data['path']);
		self::assertSame('', $data['after_app_id']);

		$this->controller->deleteExAppScript('top_menu', 'page_a', 'js/script_a.js');
		$this->expectException(OCSNotFoundException::class);
		$this->controller->getExAppScript('top_menu', 'page_a', 'js/script_a.js');
	}

	public function testScriptAfterAppIdPersisted(): void {
		$this->controller->setExAppScript('top_menu', 'page_a', 'js/script_b.js', 'files');
		$data = self::asArray($this->controller->getExAppScript('top_menu', 'page_a', 'js/script_b.js')->getData());
		self::assertSame('files', $data['after_app_id']);
	}

	public function testStyleRoundTrip(): void {
		$this->controller->setExAppStyle('top_menu', 'page_a', 'css/style_a.css');
		$data = self::asArray($this->controller->getExAppStyle('top_menu', 'page_a', 'css/style_a.css')->getData());
		self::assertSame('css/style_a.css', $data['path']);

		$this->controller->deleteExAppStyle('top_menu', 'page_a', 'css/style_a.css');
		$this->expectException(OCSNotFoundException::class);
		$this->controller->getExAppStyle('top_menu', 'page_a', 'css/style_a.css');
	}

	public function testScriptPathLeadingSlashNormalized(): void {
		// Store with leading slash; service should ltrim it. Lookups must succeed both with and without it.
		$this->controller->setExAppScript('top_menu', 'page_slash', '/js/script_slash.js', '');
		$withSlash = self::asArray(
			$this->controller->getExAppScript('top_menu', 'page_slash', '/js/script_slash.js')->getData()
		);
		$withoutSlash = self::asArray(
			$this->controller->getExAppScript('top_menu', 'page_slash', 'js/script_slash.js')->getData()
		);
		self::assertSame('js/script_slash.js', $withSlash['path']);
		self::assertSame($withSlash, $withoutSlash);

		// Delete must also accept the slashed form.
		$this->controller->deleteExAppScript('top_menu', 'page_slash', '/js/script_slash.js');
		$this->expectException(OCSNotFoundException::class);
		$this->controller->getExAppScript('top_menu', 'page_slash', 'js/script_slash.js');
	}

	public function testStylePathLeadingSlashNormalized(): void {
		$this->controller->setExAppStyle('top_menu', 'page_slash', '/css/style_slash.css');
		$withSlash = self::asArray(
			$this->controller->getExAppStyle('top_menu', 'page_slash', '/css/style_slash.css')->getData()
		);
		$withoutSlash = self::asArray(
			$this->controller->getExAppStyle('top_menu', 'page_slash', 'css/style_slash.css')->getData()
		);
		self::assertSame('css/style_slash.css', $withSlash['path']);
		self::assertSame($withSlash, $withoutSlash);

		$this->controller->deleteExAppStyle('top_menu', 'page_slash', '/css/style_slash.css');
		$this->expectException(OCSNotFoundException::class);
		$this->controller->getExAppStyle('top_menu', 'page_slash', 'css/style_slash.css');
	}
}
