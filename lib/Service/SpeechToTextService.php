<?php

declare(strict_types=1);

namespace OCA\AppAPI\Service;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\Db\SpeechToText\ExAppSpeechToTextProvider;
use OCA\AppAPI\Db\SpeechToText\ExAppSpeechToTextProviderMapper;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http;
use OCP\DB\Exception;
use OCP\Files\File;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\SpeechToText\ISpeechToTextProviderWithId;
use Psr\Log\LoggerInterface;

class SpeechToTextService {
	private ICache $cache;

	public function __construct(
		ICacheFactory $cacheFactory,
		private readonly AppAPIService $service,
		private readonly ExAppSpeechToTextProviderMapper $speechToTextProviderMapper,
		private readonly LoggerInterface $logger,
		private readonly ?string $userId,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_apps_speech_to_text');
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
	 * Register ExApp anonymous providers implementations of ISpeechToTextProviderWithId
	 * so that they can be used as regular providers in DI container
	 *
	 * @param IRegistrationContext $context
	 *
	 * @return void
	 */
	public function registerExAppSpeechToTextProviders(IRegistrationContext &$context): void {
		$exAppsProviders = $this->getSpeechToTextProviders();
		/** @var ExAppSpeechToTextProvider $exAppProvider */
		foreach ($exAppsProviders as $exAppProvider) {
			$class = '\\OCA\\AppAPI\\' . $exAppProvider->getAppid() . '_' . $exAppProvider->getName();
			$sttProvider = $this->getAnonymousExAppProvider($exAppProvider, $class);
			$context->registerService($class, function () use ($sttProvider) {
				return $sttProvider;
			});
			$context->registerSpeechToTextProvider($class);
		}
	}

	/**
	 * @psalm-suppress UndefinedClass, MissingDependency, InvalidReturnStatement, InvalidReturnType
	 */
	private function getAnonymousExAppProvider(ExAppSpeechToTextProvider $provider, string $class): ?ISpeechToTextProviderWithId {
		return new class($this->service, $provider, $this->userId, $class) implements ISpeechToTextProviderWithId {
			public function __construct(
				private AppAPIService             $service,
				private ExAppSpeechToTextProvider $sttProvider,
				private readonly ?string          $userId,
				private readonly string           $class,
			) {
			}

			public function getId(): string {
				return $this->class;
			}

			public function getName(): string {
				return $this->sttProvider->getDisplayName();
			}

			public function transcribeFile(File $file): string {
				$route = $this->sttProvider->getActionHandlerRoute();
				$exApp = $this->service->getExApp($this->sttProvider->getAppid());

				$response = $this->service->requestToExApp($exApp, $route, $this->userId, 'POST', [
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
