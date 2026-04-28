<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Controller;

use OCA\AppAPI\Controller\TaskProcessingController;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppService;
use OCA\AppAPI\Service\ProvidersAI\TaskProcessingService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for TaskProcessingController. Replaces nc_py_api/tests/actual_tests/taskprocessing_provider_test.py
 * (registration only; the next_task / set_progress / report_result wrappers were nc_py_api logic, not AppAPI).
 */
#[Group('DB')]
class TaskProcessingControllerTest extends TestCase {
	private const TEST_APP_ID = 'phpunit_tp_test';
	private const PROVIDER_ID = 'phpunit_tp_provider';

	private TaskProcessingController $controller;
	/** @var IRequest&MockObject */
	private $request;
	private TaskProcessingService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $name): string => $name === 'EX-APP-ID' ? self::TEST_APP_ID : ''
		);
		$this->service = Server::get(TaskProcessingService::class);
		$this->controller = new TaskProcessingController(
			$this->request,
			$this->service,
			Server::get(AppAPIService::class),
			Server::get(ExAppService::class),
		);
		$this->service->unregisterTaskProcessingProvider(self::TEST_APP_ID, self::PROVIDER_ID);
	}

	protected function tearDown(): void {
		$this->service->unregisterTaskProcessingProvider(self::TEST_APP_ID, self::PROVIDER_ID);
		parent::tearDown();
	}

	private function buildProvider(): array {
		return [
			'id' => self::PROVIDER_ID,
			'name' => 'Test Display Name',
			'task_type' => 'core:text2image',
			'expected_runtime' => 0,
			'optional_input_shape' => [],
			'optional_output_shape' => [],
			'input_shape_enum_values' => [],
			'input_shape_defaults' => [],
			'optional_input_shape_enum_values' => [],
			'optional_input_shape_defaults' => [],
			'output_shape_enum_values' => [],
			'optional_output_shape_enum_values' => [],
		];
	}

	public function testRegisterGetUnregister(): void {
		$response = $this->controller->registerProvider($this->buildProvider(), null);
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$response = $this->controller->getProvider(self::PROVIDER_ID);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame(self::PROVIDER_ID, $data->getName());
		self::assertSame('Test Display Name', $data->getDisplayName());
		self::assertSame('core:text2image', $data->getTaskType());

		$response = $this->controller->unregisterProvider(self::PROVIDER_ID);
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->getProvider(self::PROVIDER_ID)->getStatus()
		);
	}

	public function testGetMissingReturns404(): void {
		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->getProvider('does_not_exist')->getStatus()
		);
	}

	public function testUnregisterMissingReturns404(): void {
		self::assertSame(
			Http::STATUS_NOT_FOUND,
			$this->controller->unregisterProvider('does_not_exist')->getStatus()
		);
	}

	public function testRegisterRejectsMismatchedCustomTaskType(): void {
		// Service raises if custom_task_type id differs from provider task_type; controller turns null into 400.
		$customType = [
			'id' => 'different:custom', 'name' => 'X', 'description' => '',
			'input_shape' => [], 'output_shape' => [],
		];
		$response = $this->controller->registerProvider($this->buildProvider(), $customType);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
