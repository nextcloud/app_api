<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Service;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Db\ExApp;
use OCA\AppEcosystemV2\Db\ExAppTextProcessingProvider;
use OCA\AppEcosystemV2\Db\ExAppTextProcessingProviderMapper;
use OCA\AppEcosystemV2\Db\ExAppTextProcessingTaskType;
use OCA\AppEcosystemV2\Db\ExAppTextProcessingTaskTypeMapper;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\IAppContainer;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\TextProcessing\IProvider;
use OCP\TextProcessing\ITaskType;

class TextProcessingService {
	private ICache $cache;
	private ExAppTextProcessingProviderMapper $textProcessingProviderMapper;
	private AppEcosystemV2Service $service;
	private ExAppTextProcessingTaskTypeMapper $textProcessingTaskTypeMapper;

	public function __construct(
		AppEcosystemV2Service $service,
		ICacheFactory $cacheFactory,
		ExAppTextProcessingProviderMapper $textProcessingProviderMapper,
		ExAppTextProcessingTaskTypeMapper $textProcessingTaskTypeMapper,
	) {
		$this->service = $service;
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_apps_text_processing');
		$this->textProcessingProviderMapper = $textProcessingProviderMapper;
		$this->textProcessingTaskTypeMapper = $textProcessingTaskTypeMapper;
	}

	public function getTextProcessingProviders(): array {
		$cacheKey = '/ex_app_text_processing_providers';
		$cached = $this->cache->get($cacheKey);
		if ($cached !== null) {
			return array_map(function ($cachedEntry) {
				return $cachedEntry instanceof ExAppTextProcessingProvider ? $cachedEntry : new ExAppTextProcessingProvider($cachedEntry);
			}, $cached);
		}

		$providers = $this->textProcessingProviderMapper->findAll();
		$this->cache->set($cacheKey, $providers);
		return $providers;
	}

	public function getExAppTextProcessingProvider(ExApp $exApp, string $name): ?ExAppTextProcessingProvider {
		$cacheKey = '/ex_app_text_processing_providers/' . $exApp->getAppid() . '/' . $name;
		$cached = $this->cache->get($cacheKey);
		if ($cached !== null) {
			return $cached instanceof ExAppTextProcessingProvider ? $cached : new ExAppTextProcessingProvider($cached);
		}

		$provider = $this->textProcessingProviderMapper->findByAppidName($exApp->getAppid(), $name);
		$this->cache->set($cacheKey, $provider);
		return $provider;
	}

	public function registerTextProcessingProvider(
		ExApp $exApp,
		string $name,
		string $displayName,
		string $description,
		string $actionHandlerRoute,
		string $actionType
	): ?ExAppTextProcessingProvider {
		$provider = $this->textProcessingProviderMapper->findByAppidName($exApp->getAppid(), $name);
		if ($provider !== null) {
			return null;
		}

		$provider = new ExAppTextProcessingProvider([
			'appid' => $exApp->getAppid(),
			'name' => $name,
			'display_name' => $displayName,
			'description' => $description,
			'action_handler_route' => $actionHandlerRoute,
			'action_type' => $actionType,
		]);
		$this->textProcessingProviderMapper->insert($provider);

		$this->cache->remove('/ex_app_text_processing_providers');
		$this->cache->remove('/ex_app_text_processing_providers/' . $exApp->getAppid() . '/' . $name);

		return $provider;
	}

	/**
	 * Register dynamic text processing providers anonymous classes.
	 * For each text processing provider register anonymous class for IProvider and ITaskType in DI container.
	 *
	 * @param IAppContainer $container
	 * @param IRegistrationContext $context
	 *
	 * @return void
	 */
	public function registerExAppTextProcessingProviders(IAppContainer $container, IRegistrationContext &$context): void {
		$exAppsProviders = $this->getTextProcessingProviders();
		/** @var ExAppTextProcessingProvider $exAppProvider */
		foreach ($exAppsProviders as $exAppProvider) {
			$exApp = $this->service->getExApp($exAppProvider->getAppid());
			$tpTaskType = $this->getExAppTextProcessingTaskType($exApp, $exAppProvider->getActionType());
			$taskType = $this->getAnonymousTaskType($exAppProvider, $tpTaskType);
			$taskTypeClassName = get_class($taskType) . $tpTaskType->getAppid() . $tpTaskType->getName();
			$container->getServer()->registerService($taskTypeClassName, function () use ($taskType) {
				return $taskType;
			});

			$provider = $this->getAnonymousExAppProvider($exAppProvider, $taskTypeClassName);
			$className = get_class($provider) . $exAppProvider->getAppid() . $exAppProvider->getName();
			$container->getServer()->registerService($className, function () use ($provider) {
				return $provider;
			});
			$context->registerTextProcessingProvider($className);
		}
	}

	/**
	 *
	 *
	 * @param ExAppTextProcessingProvider $provider
	 * @param string $taskTypeClassName
	 *
	 * @return IProvider
	 */
	private function getAnonymousExAppProvider(ExAppTextProcessingProvider $provider, string $taskTypeClassName): IProvider {
		return new class ($this->service, $provider, $taskTypeClassName) implements IProvider {
			private AppEcosystemV2Service $service;
			private ExAppTextProcessingProvider $provider;
			private string $taskTypeClassName;

			public function __construct(
				AppEcosystemV2Service $service,
				ExAppTextProcessingProvider $provider,
				string $taskTypeClassName,
			) {
				$this->service = $service;
				$this->provider = $provider;
				$this->taskTypeClassName = $taskTypeClassName;
			}

			public function getName(): string {
				return $this->provider->getDisplayName();
			}

			public function process(string $prompt): string {
				$exApp = $this->service->getExApp($this->provider->getAppid());
				$route = $this->provider->getActionHandlerRoute();

				$response = $this->service->requestToExApp(null, null, $exApp, $route, 'POST', [
					'prompt' => $prompt,
				]);

				if ($response->getStatusCode() !== 200) {
					throw new \Exception('Failed to process prompt');
				}

				return $response->getBody();
			}

			public function getTaskType(): string {
				return $this->taskTypeClassName;
			}
		};
	}

	/**
	 * Build dynamic anonymous class implementing ITaskType
	 * for given ExAppTextProcessingProvider and ExAppTextProcessingTaskType data.
	 *
	 * @param ExAppTextProcessingProvider $provider
	 * @param ExAppTextProcessingTaskType $tpTaskType
	 *
	 * @return ITaskType
	 */
	private function getAnonymousTaskType(ExAppTextProcessingProvider $provider, ExAppTextProcessingTaskType $tpTaskType): ITaskType {
		return new class ($tpTaskType) implements ITaskType {
			private ExAppTextProcessingTaskType $tpTaskType;
			public function __construct(
				ExAppTextProcessingTaskType $tpTaskType,
			) {
				$this->tpTaskType = $tpTaskType;
			}

			public function getName(): string {
				return $this->tpTaskType->getDisplayName();
			}

			public function getDescription(): string {
				return $this->tpTaskType->getDescription();
			}
		};
	}

	public function registerTextProcessingTaskType(ExApp $exApp, string $name, string $displayName, string $description): ?ExAppTextProcessingTaskType {
		$taskType = $this->textProcessingTaskTypeMapper->findByAppidName($exApp->getAppid(), $name);
		if ($taskType !== null) {
			return null;
		}

		$taskType = new ExAppTextProcessingTaskType([
			'appid' => $exApp->getAppid(),
			'name' => $name,
			'display_name' => $displayName,
			'description' => $description,
		]);
		$this->textProcessingTaskTypeMapper->insert($taskType);
		$this->cache->set('/ex_app_text_processing_task_types/' . $exApp->getAppid() . '/' . $name, $taskType);
		return $taskType;
	}

	public function unregisterTextProcessingTaskType(ExApp $exApp, string $name): ?ExAppTextProcessingTaskType {
		$taskType = $this->getExAppTextProcessingTaskType($exApp, $name);
		if ($taskType === null) {
			return null;
		}

		$this->textProcessingTaskTypeMapper->delete($taskType);
		$this->cache->remove('/ex_app_text_processing_task_types/' . $exApp->getAppid() . '/' . $name);
		return $taskType;
	}

	public function getExAppTextProcessingTaskType(ExApp $exApp, string $name): ?ExAppTextProcessingTaskType {
		$cacheKey = '/ex_app_text_processing_task_types/' . $exApp->getAppid() . '/' . $name;
		$cached = $this->cache->get($cacheKey);
		if ($cached !== null) {
			return $cached instanceof ExAppTextProcessingTaskType ? $cached : new ExAppTextProcessingTaskType($cached);
		}

		$taskType = $this->textProcessingTaskTypeMapper->findByAppidName($exApp->getAppid(), $name);
		$this->cache->set($cacheKey, $taskType);
		return $taskType;
	}
}
