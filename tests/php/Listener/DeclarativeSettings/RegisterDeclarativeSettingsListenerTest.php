<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Listener\DeclarativeSettings;

use OCA\AppAPI\Db\UI\SettingsForm;
use OCA\AppAPI\Listener\DeclarativeSettings\RegisterDeclarativeSettingsListener;
use OCA\AppAPI\Service\UI\SettingsFormValidator;
use OCA\AppAPI\Service\UI\SettingsService;
use OCP\Settings\Events\DeclarativeSettingsRegisterFormEvent;
use OCP\Settings\IDeclarativeManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RegisterDeclarativeSettingsListenerTest extends TestCase {
	public function testRegistersRemainingFormsAfterOneIsRejected(): void {
		$service = $this->createStub(SettingsService::class);
		$service->method('getRegisteredForms')->willReturn([
			new SettingsForm(['appid' => 'first_app', 'formid' => 'first', 'scheme' => ['id' => 'first']]),
			new SettingsForm(['appid' => 'second_app', 'formid' => 'second', 'scheme' => ['id' => 'second']]),
		]);

		$registered = [];
		$manager = $this->createStub(IDeclarativeManager::class);
		$manager->method('registerSchema')->willReturnCallback(static function (string $app, array $schema) use (&$registered): void {
			if ($app === 'first_app') {
				throw new \Exception('Invalid schema. Please check the logs for more details.');
			}
			$registered[$app][] = $schema['id'];
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(self::anything(), self::callback(
			static fn (array $context): bool => $context['appId'] === 'first_app'
				&& $context['formId'] === 'first'
				&& $context['reason'] === 'Invalid schema. Please check the logs for more details.'
		));
		// This test covers a rejection by Core after AppAPI validation has passed.
		$validator = $this->createStub(SettingsFormValidator::class);
		$validator->method('validate')->willReturn(null);
		$listener = new RegisterDeclarativeSettingsListener($service, $logger, $validator);
		$listener->handle(new DeclarativeSettingsRegisterFormEvent($manager));

		self::assertSame(['second_app' => ['second']], $registered);
	}
}
