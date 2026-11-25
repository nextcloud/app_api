<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Controller;

use OC\AppFramework\Bootstrap\Coordinator;
use OC\Memcache\ArrayCache;
use OC\Settings\DeclarativeManager;
use OCA\AppAPI\Controller\OCSSettingsController;
use OCA\AppAPI\Db\UI\SettingsForm;
use OCA\AppAPI\Db\UI\SettingsFormMapper;
use OCA\AppAPI\Listener\DeclarativeSettings\RegisterDeclarativeSettingsListener;
use OCA\AppAPI\Service\UI\SettingsFormValidator;
use OCA\AppAPI\Service\UI\SettingsService;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\Security\ICrypto;
use OCP\Server;
use OCP\Settings\Events\DeclarativeSettingsRegisterFormEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Integration test for OCSSettingsController. Replaces nc_py_api/tests/actual_tests/ui_settings_test.py.
 */
#[Group('DB')]
class OCSSettingsControllerTest extends TestCase {
	use RegistersFakeExAppTrait;

	private const TEST_APP_ID = 'phpunit_settings_test';
	private const TEST_FORM_ID = 'phpunit_form_id';

	private OCSSettingsController $controller;
	/** @var IRequest&MockObject */
	private $request;
	private SettingsService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $name): string => strtoupper($name) === 'EX-APP-ID' ? self::TEST_APP_ID : ''
		);
		$this->service = Server::get(SettingsService::class);
		$this->controller = new OCSSettingsController($this->request, $this->service);
		$this->service->unregisterForm(self::TEST_APP_ID, self::TEST_FORM_ID);
	}

	protected function tearDown(): void {
		$this->service->unregisterExAppForms(self::TEST_APP_ID);
		$this->service->unregisterExAppForms(self::TEST_APP_ID . '_other');
		parent::tearDown();
	}

	private function buildScheme(string $title = 'Test form'): array {
		return [
			'id' => self::TEST_FORM_ID,
			'priority' => 50,
			'section_type' => 'admin',
			'section_id' => 'phpunit_section',
			'title' => $title,
			'description' => 'PHPUnit description',
			'doc_url' => '',
			'fields' => [[
				'id' => 'field_1',
				'title' => 'Multi-selection',
				'type' => 'multi-select',
				'default' => ['foo', 'bar'],
				'description' => 'pick some',
				'placeholder' => '',
				'label' => '',
				'notify' => false,
				'sensitive' => false,
				'options' => [
					['name' => 'foo', 'value' => 'foo'],
					['name' => 'bar', 'value' => 'bar'],
				],
			]],
		];
	}

	public function testRegisterGetUnregister(): void {
		$response = $this->controller->registerForm($this->buildScheme());
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$response = $this->controller->getForm(self::TEST_FORM_ID);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$scheme = $response->getData();
		self::assertSame(self::TEST_FORM_ID, $scheme['id']);
		self::assertSame('Test form', $scheme['title']);
		self::assertSame('admin', $scheme['section_type']);
		self::assertSame('multi-select', $scheme['fields'][0]['type']);
		// Service decorates with storage_type=external to mark it as ExApp-owned.
		self::assertSame('external', $scheme['storage_type']);

		$response = $this->controller->unregisterForm(self::TEST_FORM_ID);
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		// After unregister, GET returns 404 status code (controller returns DataResponse([], 404), no exception).
		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->getForm(self::TEST_FORM_ID)->getStatus()
		);
	}

	public function testGetMissingFormReturns404(): void {
		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->getForm('does_not_exist')->getStatus()
		);
	}

	public function testUnregisterMissingFormReturns404(): void {
		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->unregisterForm('does_not_exist')->getStatus()
		);
	}

	public function testRegisterReplacesExistingForm(): void {
		$this->controller->registerForm($this->buildScheme('First title'));
		$this->controller->registerForm($this->buildScheme('Second title'));

		$scheme = $this->controller->getForm(self::TEST_FORM_ID)->getData();
		self::assertSame('Second title', $scheme['title']);
	}

	public function testRejectsInvalidNewFormWithoutSavingIt(): void {
		$scheme = $this->buildScheme();
		$scheme['section_type'] = 'unsupported';

		$response = $this->controller->registerForm($scheme);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->getForm(self::TEST_FORM_ID)->getStatus());
	}

	public function testCanRepairPreviouslyStoredInvalidForm(): void {
		$this->insertFakeExApp(self::TEST_APP_ID, 19743);
		try {
			$this->assertRepairWithWarmCache();
		} finally {
			$this->deleteFakeExApp(self::TEST_APP_ID);
		}
	}

	private function assertRepairWithWarmCache(): void {
		$mapper = Server::get(SettingsFormMapper::class);
		$cache = new ArrayCache();
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($cache);
		$logger = new NullLogger();
		$validator = new SettingsFormValidator();
		$service = new SettingsService($factory, $mapper, $logger, $validator);
		$controller = new OCSSettingsController($this->request, $service);
		$listener = new RegisterDeclarativeSettingsListener($service, $logger, $validator);
		$user = $this->createStub(IUser::class);
		$loadFormIds = static function () use ($listener, $user): array {
			// A fresh request starts with an empty manager, but reads the same cache.
			$manager = new DeclarativeManager(
				Server::get(IEventDispatcher::class),
				Server::get(IGroupManager::class),
				Server::get(Coordinator::class),
				Server::get(IConfig::class),
				Server::get(IAppConfig::class),
				new NullLogger(),
				Server::get(ICrypto::class),
			);
			$listener->handle(new DeclarativeSettingsRegisterFormEvent($manager));
			return $manager->getFormIDs($user, 'personal', 'phpunit_section')[self::TEST_APP_ID] ?? [];
		};

		$scheme = $this->buildScheme();
		$scheme['section_type'] = 'personal';
		$neighbour = $scheme;
		$neighbour['id'] = 'healthy';
		$neighbour['fields'][0]['id'] = 'healthy_field';
		self::assertSame(Http::STATUS_OK, $controller->registerForm($neighbour)->getStatus());
		$legacy = $scheme;
		unset($legacy['section_type']);
		$record = $mapper->insert(new SettingsForm(['appid' => self::TEST_APP_ID, 'formid' => self::TEST_FORM_ID, 'scheme' => $legacy]));

		self::assertSame(['healthy'], $loadFormIds());
		self::assertTrue($cache->hasKey('/ex_settings_forms'));
		self::assertSame(Http::STATUS_OK, $controller->registerForm($scheme)->getStatus());
		$repaired = $mapper->findByAppidFormId(self::TEST_APP_ID, self::TEST_FORM_ID);
		self::assertSame($record->getId(), $repaired->getId());
		self::assertEqualsCanonicalizing(['healthy', self::TEST_FORM_ID], $loadFormIds());
	}

	public function testFieldIdCannotBeReusedAcrossFormsOfOneApp(): void {
		$scheme = $this->buildScheme();
		self::assertSame(Http::STATUS_OK, $this->controller->registerForm($scheme)->getStatus());
		$before = $this->controller->getForm(self::TEST_FORM_ID)->getData();
		$scheme['id'] = 'second_form';
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller->registerForm($scheme)->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->getForm('second_form')->getStatus());
		self::assertSame($before, $this->controller->getForm(self::TEST_FORM_ID)->getData());
		$scheme['fields'][0]['id'] = 'another_field';
		self::assertSame(Http::STATUS_OK, $this->controller->registerForm($scheme)->getStatus());
	}

	public function testDifferentAppsCanUseTheSameFieldId(): void {
		$scheme = $this->buildScheme();
		self::assertSame(Http::STATUS_OK, $this->controller->registerForm($scheme)->getStatus());
		$scheme['id'] = 'other_form';
		self::assertNotNull($this->service->registerForm(self::TEST_APP_ID . '_other', $scheme));
	}

	public static function fieldRegistrationCases(): array {
		return [
			'null default' => [['type' => 'text', 'default' => null], Http::STATUS_BAD_REQUEST],
			'unknown field storage' => [['type' => 'text', 'default' => '', 'storage_type' => 'relay'], Http::STATUS_BAD_REQUEST],
			'internal field storage' => [['type' => 'text', 'default' => '', 'storage_type' => 'internal'], Http::STATUS_OK],
			'explicit external storage and empty string' => [['type' => 'text', 'default' => '', 'storage_type' => 'external'], Http::STATUS_OK],
			'zero default' => [['type' => 'number', 'default' => 0], Http::STATUS_OK],
			'false default' => [['type' => 'checkbox', 'default' => false], Http::STATUS_OK],
		];
	}

	#[DataProvider('fieldRegistrationCases')]
	public function testRegistersOnlyFieldsWithUsableDefaultsAndStorage(array $properties, int $expectedStatus): void {
		$scheme = $this->buildScheme();
		$scheme['fields'] = [['id' => 'setting', 'title' => 'Setting'] + $properties];
		self::assertSame($expectedStatus, $this->controller->registerForm($scheme)->getStatus());
	}

	public function testInvalidUpdateIsRejectedAndPreservesExistingForm(): void {
		$original = $this->buildScheme('Original title');
		self::assertSame(Http::STATUS_OK, $this->controller->registerForm($original)->getStatus());
		$before = $this->controller->getForm(self::TEST_FORM_ID)->getData();

		$update = $original;
		$update['section_type'] = 'unsupported';
		$response = $this->controller->registerForm($update);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$stored = $this->controller->getForm(self::TEST_FORM_ID);
		self::assertSame(Http::STATUS_OK, $stored->getStatus());
		self::assertSame($before, $stored->getData());
	}
}
