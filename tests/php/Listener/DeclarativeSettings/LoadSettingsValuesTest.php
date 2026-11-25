<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Tests\php\Listener\DeclarativeSettings;

use OC\AppFramework\Bootstrap\Coordinator;
use OC\Settings\DeclarativeManager;
use OCA\AppAPI\Db\UI\SettingsForm;
use OCA\AppAPI\Listener\DeclarativeSettings\GetValueListener;
use OCA\AppAPI\Listener\DeclarativeSettings\RegisterDeclarativeSettingsListener;
use OCA\AppAPI\Service\ExAppConfigService;
use OCA\AppAPI\Service\ExAppPreferenceService;
use OCA\AppAPI\Service\UI\SettingsFormValidator;
use OCA\AppAPI\Service\UI\SettingsService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Security\ICrypto;
use OCP\Server;
use OCP\Settings\Events\DeclarativeSettingsRegisterFormEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class LoadSettingsValuesTest extends TestCase {
	public static function invalidStoredFieldProperties(): array {
		return [
			'missing default' => [[]],
			'null default' => [['default' => null]],
			'unknown field storage' => [['default' => 'Available', 'storage_type' => 'relay']],
			'oversized personal internal key' => [['id' => str_repeat('x', 65), 'default' => '', 'storage_type' => 'internal']],
			'non-string sensitive internal default' => [['default' => false, 'sensitive' => true, 'storage_type' => 'internal']],
		];
	}

	#[DataProvider('invalidStoredFieldProperties')]
	public function testLoadsHealthyFormAlongsideInvalidStoredForm(array $legacyProperties): void {
		$forms = [];
		foreach (['legacy', 'healthy'] as $id) {
			$field = ['id' => $id . '_field', 'title' => 'Label', 'type' => 'text'];
			if ($id === 'healthy') {
				$field['default'] = 'Available';
			} else {
				$field = array_replace($field, $legacyProperties);
			}
			$forms[$id] = new SettingsForm(['appid' => 'test_app', 'formid' => $id, 'scheme' => [
				'id' => $id, 'title' => $id, 'section_type' => 'personal', 'section_id' => 'test_section',
				'priority' => 50, 'storage_type' => 'external', 'fields' => [$field],
			]]);
		}
		$service = $this->createStub(SettingsService::class);
		$service->method('getRegisteredForms')->willReturn(array_values($forms));
		$service->method('getForm')->willReturnMap([
			['test_app', 'legacy', $forms['legacy']], ['test_app', 'healthy', $forms['healthy']],
		]);
		$logger = new NullLogger();
		$crypto = $this->createStub(ICrypto::class);
		$preferences = $this->createStub(ExAppPreferenceService::class);
		$preferences->method('getUserConfigValues')->willReturn([]);
		$values = new GetValueListener($service, $preferences, $this->createStub(ExAppConfigService::class), $crypto, $logger);
		$dispatcher = $this->createStub(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback($values->handle(...));
		$manager = new DeclarativeManager($dispatcher, $this->createStub(IGroupManager::class), Server::get(Coordinator::class),
			Server::get(IConfig::class), $this->createStub(IAppConfig::class), $logger, $crypto);

		(new RegisterDeclarativeSettingsListener($service, $logger, new SettingsFormValidator()))->handle(new DeclarativeSettingsRegisterFormEvent($manager));
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('phpunit_settings_user');
		$loaded = $manager->getFormsWithValues($user, 'personal', 'test_section');

		self::assertSame(['healthy'], array_column($loaded, 'id'));
		self::assertSame('Available', $loaded[0]['fields'][0]['value']);
	}
}
