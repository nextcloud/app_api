<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Controller;

use OCA\AppAPI\Controller\OCSSettingsController;
use OCA\AppAPI\Service\UI\SettingsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for OCSSettingsController. Replaces nc_py_api/tests/actual_tests/ui_settings_test.py.
 */
#[Group('DB')]
class OCSSettingsControllerTest extends TestCase {
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
			fn (string $name): string => $name === 'EX-APP-ID' ? self::TEST_APP_ID : ''
		);
		$this->service = Server::get(SettingsService::class);
		$this->controller = new OCSSettingsController($this->request, $this->service);
		$this->service->unregisterForm(self::TEST_APP_ID, self::TEST_FORM_ID);
	}

	protected function tearDown(): void {
		$this->service->unregisterForm(self::TEST_APP_ID, self::TEST_FORM_ID);
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
}
