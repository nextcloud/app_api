<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Controller;

use OCA\AppAPI\Controller\OccCommandController;
use OCA\AppAPI\Service\ExAppOccService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for OccCommandController. Replaces nc_py_api/tests/actual_tests/occ_commands_test.py.
 *
 * NOTE: ExAppOccService::getOccCommand() reads through getOccCommands() which uses findAllEnabled(), an inner-join on
 * ex_apps WHERE enabled=1. The test inserts a fake enabled ex_apps row in setUp so the GET round-trip works without
 * depending on any external fixture.
 */
#[Group('DB')]
class OccCommandControllerTest extends TestCase {
	use RegistersFakeExAppTrait;

	private const TEST_APP_ID = 'phpunit_occ_fake';
	private const TEST_PORT = 19002;
	private const CMD_NAME = 'phpunit:test:cmd';

	private OccCommandController $controller;
	/** @var IRequest&MockObject */
	private $request;
	private ExAppOccService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->insertFakeExApp(self::TEST_APP_ID, self::TEST_PORT);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $name): string => $name === 'EX-APP-ID' ? self::TEST_APP_ID : ''
		);
		$this->service = Server::get(ExAppOccService::class);
		$this->controller = new OccCommandController($this->request, $this->service);
		$this->service->unregisterCommand(self::TEST_APP_ID, self::CMD_NAME);
	}

	protected function tearDown(): void {
		$this->service->unregisterCommand(self::TEST_APP_ID, self::CMD_NAME);
		$this->deleteFakeExApp(self::TEST_APP_ID);
		parent::tearDown();
	}

	public function testRegisterMinimalGetUnregister(): void {
		$response = $this->controller->registerCommand(
			name: self::CMD_NAME,
			description: '',
			execute_handler: '/handler',
		);
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$response = $this->controller->getCommand(self::CMD_NAME);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame(self::CMD_NAME, $data->getName());
		// Service strips the leading slash from execute_handler.
		self::assertSame('handler', $data->getExecuteHandler());

		self::assertSame(Http::STATUS_OK, $this->controller->unregisterCommand(self::CMD_NAME)->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->getCommand(self::CMD_NAME)->getStatus());
	}

	public function testRegisterFullPayloadPersistsArguments(): void {
		$arguments = [[
			'name' => 'argument_name',
			'mode' => 'required',
			'description' => 'Description',
			'default' => 'default_value',
		]];
		$this->controller->registerCommand(
			name: self::CMD_NAME,
			description: 'desc',
			execute_handler: 'some_url2',
			hidden: 0,
			arguments: $arguments,
			options: [],
			usages: [],
		);
		$data = $this->controller->getCommand(self::CMD_NAME)->getData();
		self::assertSame('desc', $data->getDescription());
		self::assertSame('some_url2', $data->getExecuteHandler());
		self::assertSame($arguments, $data->getArguments());
	}

	public function testRegisterReplacesExistingCommand(): void {
		// ExAppOccService::registerCommand uses insertOrUpdate — re-registering the same name must update in place.
		$this->controller->registerCommand(self::CMD_NAME, 'first desc', 'handler_v1');
		$this->controller->registerCommand(self::CMD_NAME, 'updated desc', 'handler_v2');
		$data = $this->controller->getCommand(self::CMD_NAME)->getData();
		self::assertSame('updated desc', $data->getDescription());
		self::assertSame('handler_v2', $data->getExecuteHandler());
	}

	public function testGetMissingReturns404(): void {
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->getCommand('does_not_exist')->getStatus());
	}

	public function testUnregisterMissingReturns404(): void {
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->unregisterCommand('does_not_exist')->getStatus());
	}
}
