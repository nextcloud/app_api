<?php

declare(strict_types=1);

namespace OCA\AppAPI\Service\ProvidersAI;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\MachineTranslation\MachineTranslationProvider;
use OCA\AppAPI\Db\MachineTranslation\MachineTranslationProviderMapper;
use OCA\AppAPI\Db\MachineTranslation\MachineTranslationQueue;
use OCA\AppAPI\Db\MachineTranslation\MachineTranslationQueueMapper;
use OCA\AppAPI\Service\AppAPIService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IServerContainer;
use OCP\Translation\ITranslationProviderWithId;
use OCP\Translation\ITranslationProviderWithUserId;
use OCP\Translation\LanguageTuple;
use Psr\Log\LoggerInterface;

class MachineTranslationService {
	private ICache $cache;

	public function __construct(
		ICacheFactory                                     $cacheFactory,
		private readonly MachineTranslationProviderMapper $mapper,
		private readonly LoggerInterface                  $logger,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_machine_translation_providers');
	}

	public function registerMachineTranslationProvider(
		string $appId,
		string $name,
		string $displayName,
		string $fromLanguages,
		string $fromLanguagesLabels,
		string $toLanguages,
		string $toLanguagesLabels,
		string $actionHandler
	): ?MachineTranslationProvider {
		try {
			$speechToTextProvider = $this->mapper->findByAppidName($appId, $name);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			$speechToTextProvider = null;
		}
		try {
			$newSpeechToTextProvider = new MachineTranslationProvider([
				'appid' => $appId,
				'name' => $name,
				'display_name' => $displayName,
				'from_languages' => $fromLanguages,
				'from_languages_labels' => $fromLanguagesLabels,
				'to_languages' => $toLanguages,
				'to_languages_labels' => $toLanguagesLabels,
				'action_handler' => ltrim($actionHandler, '/'),
			]);
			if ($speechToTextProvider !== null) {
				$newSpeechToTextProvider->setId($speechToTextProvider->getId());
			}
			$speechToTextProvider = $this->mapper->insertOrUpdate($newSpeechToTextProvider);
			$this->cache->set('/ex_machine_translation_providers_' . $appId . '_' . $name, $speechToTextProvider);
			$this->resetCacheEnabled();
		} catch (Exception $e) {
			$this->logger->error(
				sprintf('Failed to register ExApp %s SpeechToTextProvider %s. Error: %s', $appId, $name, $e->getMessage()), ['exception' => $e]
			);
			return null;
		}
		return $speechToTextProvider;
	}

	public function unregisterMachineTranslationProvider(string $appId, string $name): ?MachineTranslationProvider {
		try {
			$machineTranslationProvider = $this->getExAppMachineTranslationProvider($appId, $name);
			if ($machineTranslationProvider === null) {
				return null;
			}
			$this->mapper->delete($machineTranslationProvider);
			$this->cache->remove('/ex_machine_translation_providers_' . $appId . '_' . $name);
			$this->resetCacheEnabled();
			return $machineTranslationProvider;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to unregister ExApp %s SpeechToTextProvider %s. Error: %s', $appId, $name, $e->getMessage()), ['exception' => $e]);
			return null;
		}
	}

	/**
	 * Get list of registered MachineTranslation providers (only for enabled ExApps)
	 *
	 * @return MachineTranslationProvider[]
	 */
	public function getRegisteredMachineTranslationProviders(): array {
		try {
			$cacheKey = '/ex_machine_translation_providers';
			$records = $this->cache->get($cacheKey);
			if ($records === null) {
				$records = $this->mapper->findAllEnabled();
				$this->cache->set($cacheKey, $records);
			}
			return array_map(function ($record) {
				return new MachineTranslationProvider($record);
			}, $records);
		} catch (Exception) {
			return [];
		}
	}

	public function getExAppMachineTranslationProvider(string $appId, string $name): ?MachineTranslationProvider {
		$cacheKey = '/ex_machine_translation_providers_' . $appId . '_' . $name;
		$cache = $this->cache->get($cacheKey);
		if ($cache !== null) {
			return $cache instanceof MachineTranslationProvider ? $cache : new MachineTranslationProvider($cache);
		}

		try {
			$machineTranslationProvider = $this->mapper->findByAppIdName($appId, $name);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
		$this->cache->set($cacheKey, $machineTranslationProvider);
		return $machineTranslationProvider;
	}

	public function unregisterExAppMachineTranslationProviders(string $appId): int {
		try {
			$result = $this->mapper->removeAllByAppId($appId);
		} catch (Exception) {
			$result = -1;
		}
		$this->cache->clear('/ex_machine_translation_providers_' . $appId);
		$this->resetCacheEnabled();
		return $result;
	}

	public function resetCacheEnabled(): void {
		$this->cache->remove('/ex_machine_translation_providers');
	}

	/**
	 * Register ExApp anonymous providers implementations of ITranslationProviderWithId and ITranslationProviderWithUserId
	 * so that they can be used as regular providers in DI container.
	 */
	public function registerExAppMachineTranslationProviders(IRegistrationContext &$context, IServerContainer $serverContainer): void {
		$exAppsProviders = $this->getRegisteredMachineTranslationProviders();
		foreach ($exAppsProviders as $exAppProvider) {
			$class = '\\OCA\\AppAPI\\' . $exAppProvider->getAppid() . '\\' . $exAppProvider->getName();
			$provider = $this->getAnonymousExAppProvider($exAppProvider, $serverContainer, $class);
			$context->registerService($class, function () use ($provider) {
				return $provider;
			});
			$context->registerSpeechToTextProvider($class);
		}
	}

	/**
	 * @psalm-suppress UndefinedClass, MissingDependency, InvalidReturnStatement, InvalidReturnType
	 */
	private function getAnonymousExAppProvider(MachineTranslationProvider $provider, IServerContainer $serverContainer, string $class): ?ITranslationProviderWithId {
		return new class($provider, $serverContainer, $class) implements ITranslationProviderWithId, ITranslationProviderWithUserId {
			private ?string $userId;

			public function __construct(
				private MachineTranslationProvider $provider,
				private IServerContainer     $serverContainer,
				private readonly string      $class,
			) {
			}

			public function getId(): string {
				return $this->class;
			}

			public function getName(): string {
				return $this->provider->getDisplayName();
			}

			public function getAvailableLanguages(): array {
				$fromLanguages = explode(',', $this->provider->getFromLanguages());
				$fromLanguagesLabels = explode(',', $this->provider->getFromLanguagesLabels());
				$toLanguages = explode(',', $this->provider->getToLanguages());
				$toLanguagesLabels = explode(',', $this->provider->getToLanguagesLabels());
				$availableLanguages = [];
				foreach ($fromLanguages as $index => $fromLanguage) {
					$availableLanguages[] = LanguageTuple::fromArray([
						'from' => $fromLanguage,
						'fromLabel' => $fromLanguagesLabels[$index],
						'to' => $toLanguages[$index],
						'toLabel' => $toLanguagesLabels[$index],
					]);
				}
				return $availableLanguages;
			}

			public function translate(?string $fromLanguage, string $toLanguage, string $text): string {
				/** @var AppAPIService $service */
				$service = $this->serverContainer->get(AppAPIService::class);
				/** @var MachineTranslationQueueMapper $mapper */
				$mapper = $this->serverContainer->get(MachineTranslationQueueMapper::class);
				$route = $this->provider->getActionHandler();
				$queueRecord = $mapper->insert(new MachineTranslationQueue(['created_time' => time()]));
				$taskId = $queueRecord->getId();

				$response = $service->requestToExAppById($this->provider->getAppid(),
					$route,
					$this->userId,
					params: [
						'from_language' => $fromLanguage,
						'to_language' => $toLanguage,
						'text' => $text,
					],
				);

				if (is_array($response)) {
					$mapper->delete($mapper->getById($taskId));
					throw new \Exception(sprintf('Failed to process translation task: %s:%s:%s-%s. Error: %s',
						$this->provider->getAppid(),
						$this->provider->getName(),
						$fromLanguage,
						$toLanguage,
						$response['error']
					));
				}

				do {
					$taskResults = $mapper->getById($taskId);
					usleep(300000); // 0.3s
				} while ($taskResults->getFinished() === 0);

				$mapper->delete($taskResults);
				if (!empty($taskResults->getError())) {
					throw new \Exception(sprintf('Translation task returned error: %s:%s:%s-%s. Error: %s',
						$this->provider->getAppid(),
						$this->provider->getName(),
						$fromLanguage,
						$toLanguage,
						$taskResults->getError(),
					));
				}
				return $taskResults->getResult();
			}

			public function setUserId(?string $userId): void {
				$this->userId = $userId;
			}
		};
	}
}
