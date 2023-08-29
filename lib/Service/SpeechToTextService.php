<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Service;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Db\ExApp;
use OCA\AppEcosystemV2\Db\ExAppSpeechToTextProvider;
use OCA\AppEcosystemV2\Db\ExAppSpeechToTextProviderMapper;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http;
use OCP\AppFramework\IAppContainer;
use OCP\DB\Exception;
use OCP\Files\File;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\SpeechToText\ISpeechToTextProvider;
use Psr\Log\LoggerInterface;

class SpeechToTextService {
	private ICache $cache;
	private ExAppSpeechToTextProviderMapper $speechToTextProviderMapper;
	private AppEcosystemV2Service $service;
	private LoggerInterface $logger;

	public function __construct(
		ICacheFactory $cacheFactory,
		AppEcosystemV2Service $service,
		ExAppSpeechToTextProviderMapper $speechToTextProviderMapper,
		LoggerInterface $logger,
	) {
		$this->service = $service;
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_apps_speech_to_text');
		$this->speechToTextProviderMapper = $speechToTextProviderMapper;
		$this->logger = $logger;
	}

	public function getSpeechToTextProviders(): array {
		$cacheKey = '/ex_app_speech_to_text_providers';
		$cached = $this->cache->get($cacheKey);
		if ($cached !== null) {
			return array_map(function ($cachedEntry) {
				return $cachedEntry instanceof ExAppSpeechToTextProvider ? $cachedEntry : new ExAppSpeechToTextProvider($cachedEntry);
			}, $cached);
		}

		$providers = $this->speechToTextProviderMapper->findAll();
		$this->cache->set($cacheKey, $providers);
		return $providers;
	}

	public function getExAppSpeechToTextProvider(ExApp $exApp, string $name): ?ExAppSpeechToTextProvider {
		$cacheKey = '/ex_app_speech_to_text_providers/' . $exApp->getAppid() . '/' . $name;
		$cached = $this->cache->get($cacheKey);
		if ($cached !== null) {
			return $cached instanceof ExAppSpeechToTextProvider ? $cached : new ExAppSpeechToTextProvider($cached);
		}

		$provider = $this->speechToTextProviderMapper->findByAppidName($exApp->getAppid(), $name);
		$this->cache->set($cacheKey, $provider);
		return $provider;
	}

	public function registerSpeechToTextProvider(ExApp $exApp, string $name, string $displayName, string $actionHandlerRoute): ?ExAppSpeechToTextProvider {
		$provider = new ExAppSpeechToTextProvider([
			'appid' => $exApp->getAppid(),
			'name' => $name,
			'display_name' => $displayName,
			'action_handler_route' => $actionHandlerRoute,
		]);
		try {
			$this->speechToTextProviderMapper->insert($provider);
			$this->cache->remove('/ex_app_speech_to_text_providers');
			return $provider;
		} catch (Exception $e) {
			$this->logger->error('Failed to register SpeechToText provider', ['exception' => $e]);
			return null;
		}
	}

	public function unregisterSpeechToTextProvider(ExApp $exApp, string $name): ?ExAppSpeechToTextProvider {
		$provider = $this->getExAppSpeechToTextProvider($exApp, $name);
		if ($provider === null) {
			return null;
		}
		try {
			$this->speechToTextProviderMapper->delete($provider);
			$this->cache->remove('/ex_app_speech_to_text_providers');
			return $provider;
		} catch (Exception $e) {
			$this->logger->error('Failed to unregister STT provider', ['exception' => $e]);
			return null;
		}
	}

	/**
	 * Register ExApp anonymous providers implementations of ISpeechToTextProvider
	 * so that they can be used as regular providers in DI container
	 *
	 * @param IAppContainer $container
	 * @param IRegistrationContext $context
	 *
	 * @return void
	 */
	public function registerExAppSpeechToTextProviders(IAppContainer $container, IRegistrationContext &$context): void {
		$exAppsProviders = $this->getSpeechToTextProviders();
		/** @var ExAppSpeechToTextProvider $exAppProvider */
		foreach ($exAppsProviders as $exAppProvider) {
			$sttProvider = $this->getAnonymousExAppProvider($exAppProvider);
			$class = get_class($sttProvider) . $exAppProvider->getAppid() . $exAppProvider->getName();
			$container->getServer()->registerService($class, function () use ($sttProvider) {
				return $sttProvider;
			});
			$context->registerSpeechToTextProvider($class);
		}
	}

	private function getAnonymousExAppProvider(ExAppSpeechToTextProvider $provider): ?ISpeechToTextProvider {
		return new class ($this->service, $provider) implements ISpeechToTextProvider {
			private AppEcosystemV2Service $service;
			private ExAppSpeechToTextProvider $sttProvider;

			public function __construct(
				AppEcosystemV2Service $service,
				ExAppSpeechToTextProvider $sttProvider,
			) {
				$this->service = $service;
				$this->sttProvider = $sttProvider;
			}

			public function getName(): string {
				return $this->sttProvider->getDisplayName();
			}

			public function transcribeFile(File $file): string {
				$route = $this->sttProvider->getActionHandlerRoute();
				$exApp = $this->service->getExApp($this->sttProvider->getAppid());

				$response = $this->service->requestToExApp(null, null, $exApp, $route, 'POST', [
					'fileid' => $file->getId(),
				]);

				if ($response->getStatusCode() !== Http::STATUS_OK) {
					throw new \Exception('Failed to transcribe file');
				}

				return $response->getBody();
			}
		};
	}
}
