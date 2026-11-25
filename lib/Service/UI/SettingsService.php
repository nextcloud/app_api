<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service\UI;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\UI\SettingsForm;
use OCA\AppAPI\Db\UI\SettingsFormMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Settings\DeclarativeSettingsTypes;
use Psr\Log\LoggerInterface;

class SettingsService {
	private ?ICache $cache = null;

	public function __construct(
		ICacheFactory $cacheFactory,
		private readonly SettingsFormMapper $mapper,
		private readonly LoggerInterface $logger,
		private readonly SettingsFormValidator $validator,
	) {
		if ($cacheFactory->isAvailable()) {
			$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_settings_forms');
		}
	}

	public function registerForm(
		string $appId,
		array $formScheme,
	): ?SettingsForm {
		$formScheme['storage_type'] = DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL;
		$error = $this->validator->validate($formScheme);
		if ($error !== null) {
			$this->logger->warning('Rejected settings form {formId} from ExApp {appId}: {reason}', [
				'formId' => is_string($formScheme['id'] ?? null) ? $formScheme['id'] : '(unknown)',
				'appId' => $appId,
				'reason' => $error,
			]);
			return null;
		}
		$formId = $formScheme['id'];
		try {
			$storedForms = $this->mapper->findByAppId($appId);
			$conflict = $this->findFieldIdConflict($storedForms, $formId, array_column($formScheme['fields'], 'id'));
			if ($conflict !== null) {
				$this->logger->warning('Rejected settings form {formId} from ExApp {appId}: field {fieldId} already belongs to form {otherFormId}', [
					'appId' => $appId,
					'formId' => $formId,
				] + $conflict);
				return null;
			}
			$newSettingsForm = new SettingsForm([
				'appid' => $appId,
				'formid' => $formId,
				'scheme' => $formScheme,
			]);
			foreach ($storedForms as $stored) {
				if ($stored->getFormid() === $formId) {
					$newSettingsForm->setId($stored->getId());
					break;
				}
			}
			$settingsForm = $this->mapper->insertOrUpdate($newSettingsForm);
			$this->resetCacheEnabled();
		} catch (Exception $e) {
			$this->logger->error(
				sprintf('Failed to register ExApp %s Settings Form %s. Error: %s', $appId, $formId, $e->getMessage()), ['exception' => $e]
			);
			return null;
		}
		return $settingsForm;
	}

	/**
	 * @param list<SettingsForm> $storedForms
	 * @param list<string> $fieldIds
	 * @return array{fieldId: string, otherFormId: string}|null
	 */
	private function findFieldIdConflict(array $storedForms, string $formId, array $fieldIds): ?array {
		$ids = array_fill_keys($fieldIds, true);
		foreach ($storedForms as $stored) {
			if ($stored->getFormid() === $formId) {
				continue;
			}
			$fields = $stored->getScheme()['fields'] ?? null;
			if (!is_array($fields)) {
				continue;
			}
			foreach (array_column($fields, 'id') as $fieldId) {
				if (is_string($fieldId) && isset($ids[$fieldId])) {
					return ['fieldId' => $fieldId, 'otherFormId' => $stored->getFormid()];
				}
			}
		}
		return null;
	}

	public function unregisterForm(string $appId, string $formId): ?SettingsForm {
		try {
			$settingsForm = $this->getForm($appId, $formId);
			if ($settingsForm !== null) {
				$this->mapper->delete($settingsForm);
				$this->resetCacheEnabled();
				return $settingsForm;
			}
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to unregister ExApp %s Settings Form %s. Error: %s', $appId, $formId, $e->getMessage()), ['exception' => $e]);
		}
		return null;
	}

	/**
	 * Get list of registered Settings Forms (only for enabled ExApps)
	 *
	 * @return SettingsForm[]
	 */
	public function getRegisteredForms(): array {
		try {
			$cacheKey = '/ex_settings_forms';
			$records = $this->cache?->get($cacheKey);
			if ($records === null) {
				$records = $this->mapper->findAllEnabled();
				$this->cache?->set($cacheKey, $records);
			}
			return array_map(function ($record) {
				return new SettingsForm($record);
			}, $records);
		} catch (Exception) {
			return [];
		}
	}

	public function getForm(string $appId, string $formId): ?SettingsForm {
		foreach ($this->getRegisteredForms() as $form) {
			if (($form->getAppid() === $appId) && ($form->getFormid() === $formId)) {
				return $form;
			}
		}
		try {
			return $this->mapper->findByAppidFormId($appId, $formId);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
	}

	public function unregisterExAppForms(string $appId): int {
		try {
			$result = $this->mapper->removeAllByAppId($appId);
		} catch (Exception) {
			$result = -1;
		}
		$this->resetCacheEnabled();
		return $result;
	}

	public function resetCacheEnabled(): void {
		$this->cache?->remove('/ex_settings_forms');
	}
}
