<?php

declare(strict_types=1);

namespace OCA\AppAPI\Service;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\Db\ExAppMapper;
use OCA\AppAPI\Fetcher\ExAppArchiveFetcher;
use OCA\AppAPI\Fetcher\ExAppFetcher;
use OCA\AppAPI\Service\ProvidersAI\SpeechToTextService;
use OCA\AppAPI\Service\ProvidersAI\TextProcessingService;
use OCA\AppAPI\Service\ProvidersAI\TranslationService;
use OCA\AppAPI\Service\UI\FilesActionsMenuService;
use OCA\AppAPI\Service\UI\InitialStateService;
use OCA\AppAPI\Service\UI\ScriptsService;
use OCA\AppAPI\Service\UI\StylesService;
use OCA\AppAPI\Service\UI\TopMenuService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;

class ExAppService {
	public const CACHE_TTL = 60 * 60; // 1 hour
	private ICache $cache;

	public function __construct(
		private readonly LoggerInterface         $logger,
		ICacheFactory                            $cacheFactory,
		private readonly ISecureRandom           $random,
		private readonly IUserManager    		 $userManager,
		private readonly ExAppFetcher            $exAppFetcher,
		private readonly ExAppArchiveFetcher     $exAppArchiveFetcher,
		private readonly ExAppMapper             $exAppMapper,
		private readonly ExAppUsersService       $exAppUsersService,
		private readonly ExAppScopesService      $exAppScopesService,
		private readonly TopMenuService          $topMenuService,
		private readonly InitialStateService     $initialStateService,
		private readonly ScriptsService          $scriptsService,
		private readonly StylesService           $stylesService,
		private readonly FilesActionsMenuService $filesActionsMenuService,
		private readonly SpeechToTextService     $speechToTextService,
		private readonly TextProcessingService   $textProcessingService,
		private readonly TranslationService      $translationService,
		private readonly TalkBotsService         $talkBotsService,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/service');
	}

	public function getExApp(string $appId): ?ExApp {
		try {
			$cacheKey = '/exApp_' . $appId;
			$cached = $this->cache->get($cacheKey);
			if ($cached !== null) {
				return $cached instanceof ExApp ? $cached : new ExApp($cached);
			}

			$exApp = $this->exAppMapper->findByAppId($appId);
			$this->cache->set($cacheKey, $exApp, self::CACHE_TTL);
			return $exApp;
		} catch (Exception | MultipleObjectsReturnedException | DoesNotExistException $e) {
			$this->logger->debug(
				sprintf('Failed to get ExApp %s. Error: %s', $appId, $e->getMessage()), ['exception' => $e]
			);
		}
		return null;
	}

	/**
	 * Register ExApp or update if already exists
	 *
	 * @param string $appId
	 * @param array $appData [version, name, daemon_config_id, protocol, host, port, secret]
	 * @return ExApp|null
	 */
	public function registerExApp(string $appId, array $appData): ?ExApp {
		$exApp = new ExApp([
			'appid' => $appId,
			'version' => $appData['version'],
			'name' => $appData['name'],
			'daemon_config_name' => $appData['daemon_config_name'],
			'port' => $appData['port'],
			'secret' => $appData['secret'] !== '' ? $appData['secret'] : $this->random->generate(128),
			'status' => json_encode(['active' => false, 'progress' => 0]),
			'created_time' => time(),
			'last_check_time' => time(),
		]);
		try {
			$this->exAppMapper->insert($exApp);
			$exApp = $this->exAppMapper->findByAppId($appId);
			$this->cache->set('/exApp_' . $appId, $exApp, self::CACHE_TTL);
			return $exApp;
		} catch (Exception | MultipleObjectsReturnedException | DoesNotExistException $e) {
			$this->logger->error(sprintf('Error while registering ExApp %s: %s', $appId, $e->getMessage()));
			return null;
		}
	}

	public function unregisterExApp(string $appId): bool {
		$exApp = $this->getExApp($appId);
		if ($exApp === null) {
			return false;
		}
		try {
			// TODO: Do we need to remove app_config_ex, app_preferences_ex too
			$this->exAppScopesService->removeExAppScopes($appId);
			$this->exAppUsersService->removeExAppUsers($appId);
			$this->talkBotsService->unregisterExAppTalkBots($exApp); // TODO: Think about internal Events for clean and flexible unregister ExApp callbacks
			$this->filesActionsMenuService->unregisterExAppFileActions($appId);
			$this->topMenuService->unregisterExAppMenuEntries($appId);
			$this->initialStateService->deleteExAppInitialStates($appId);
			$this->scriptsService->deleteExAppScripts($appId);
			$this->stylesService->deleteExAppStyles($appId);
			$this->speechToTextService->unregisterExAppSpeechToTextProviders($appId);
			$this->textProcessingService->unregisterExAppTextProcessingProviders($appId);
			$this->translationService->unregisterExAppTranslationProviders($appId);
			if ($this->exAppMapper->deleteExApp($appId) === 1) {
				$this->cache->remove('/exApp_' . $appId);
				return true;
			}
			$this->logger->warning(sprintf('Error while unregistering %s ExApp from the database.', $appId));
		} catch (Exception $e) {
			$this->logger->error(sprintf('Error while unregistering ExApp: %s', $e->getMessage()), ['exception' => $e]);
		}
		return false;
	}

	public function getExAppFreePort(): int {
		try {
			$ports = $this->exAppMapper->getUsedPorts();
			for ($port = 23000; $port <= 23999; $port++) {
				if (!in_array($port, $ports)) {
					return $port;
				}
			}
		} catch (Exception) {
		}
		return 0;
	}

	public function enableExAppInternal(ExApp $exApp): bool {
		$exApp->setEnabled(1);
		$result = $this->updateExApp($exApp);
		$this->resetCaches();
		return $result;
	}

	public function disableExAppInternal(ExApp $exApp): void {
		$exApp->setEnabled(0);
		$exApp->setLastCheckTime(time());
		$this->updateExApp($exApp);
		$this->resetCaches();
	}

	public function getExAppsByDaemonName(string $daemonName): array {
		try {
			return array_filter($this->exAppMapper->findAll(), function (ExApp $exApp) use ($daemonName) {
				return $exApp->getDaemonConfigName() === $daemonName;
			});
		} catch (Exception $e) {
			$this->logger->error(sprintf('Error while getting ExApps list. Error: %s', $e->getMessage()), ['exception' => $e]);
			return [];
		}
	}

	public function getExAppsList(string $list = 'enabled'): array {
		try {
			$exApps = $this->exAppMapper->findAll();

			if ($list === 'enabled') {
				$exApps = array_values(array_filter($exApps, function (ExApp $exApp) {
					return $exApp->getEnabled() === 1;
				}));
			}

			$exApps = array_map(function (ExApp $exApp) {
				return [
					'id' => $exApp->getAppid(),
					'name' => $exApp->getName(),
					'version' => $exApp->getVersion(),
					'enabled' => filter_var($exApp->getEnabled(), FILTER_VALIDATE_BOOLEAN),
					'last_check_time' => $exApp->getLastCheckTime(),
					'system' => $this->exAppUsersService->exAppUserExists($exApp->getAppid(), ''),
				];
			}, $exApps);
		} catch (Exception $e) {
			$this->logger->error(sprintf('Error while getting ExApps list. Error: %s', $e->getMessage()), ['exception' => $e]);
			$exApps = [];
		}
		return $exApps;
	}

	public function getNCUsersList(): ?array {
		return array_map(function (IUser $user) {
			return $user->getUID();
		}, $this->userManager->searchDisplayName(''));
	}

	/**
	 * Update ExApp info (version, name, system app flag changes after update)
	 */
	public function updateExAppInfo(ExApp $exApp, array $exAppInfo): bool {
		$exApp->setVersion($exAppInfo['version']);
		$exApp->setName($exAppInfo['name']);
		if (!$this->updateExApp($exApp)) {
			return false;
		}

		// Update system app flag
		try {
			$isSystemApp = $this->exAppUsersService->exAppUserExists($exApp->getAppid(), '');
			if (filter_var($exAppInfo['system_app'], FILTER_VALIDATE_BOOLEAN) && !$isSystemApp) {
				$this->exAppUsersService->setupSystemAppFlag($exApp->getAppid());
			} else {
				$this->exAppUsersService->removeExAppUser($exApp->getAppid(), '');
			}
		} catch (Exception $e) {
			$this->logger->error(sprintf('Error while setting app system flag: %s', $e->getMessage()));
			return false;
		}
		return true;
	}

	public function updateExApp(ExApp $exApp, array $fields = ['version', 'name', 'port', 'status', 'enabled', 'last_check_time']): bool {
		try {
			$this->exAppMapper->updateExApp($exApp, $fields);
			$this->cache->set('/exApp_' . $exApp->getAppid(), $exApp, self::CACHE_TTL);
			return true;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to update "%s" ExApp info.', $exApp->getAppid(), ), ['exception' => $e]);
			$this->resetCaches();
		}
		return false;
	}

	public function getExAppRequestedScopes(ExApp $exApp, ?SimpleXMLElement $infoXml = null, array $jsonInfo = []): ?array {
		if (isset($jsonInfo['scopes'])) {
			return $jsonInfo['scopes'];
		}

		if ($infoXml === null) {
			$exAppInfo = $this->getExAppInfoFromAppstore($exApp);
			if (isset($exAppInfo)) {
				$infoXml = $exAppInfo;
			}
		}

		if (isset($infoXml)) {
			$scopes = $infoXml->xpath('external-app/scopes');
			if ($scopes !== false) {
				$scopes = (array) $scopes[0];
				$required = array_map(function (string $scopeGroup) {
					return $scopeGroup;
				}, (array) $scopes['required']->value);
				$optional = array_map(function (string $scopeGroup) {
					return $scopeGroup;
				}, (array) $scopes['optional']->value);
				return [
					'required' => array_values($required),
					'optional' => array_values($optional),
				];
			}
		}

		return ['error' => 'Failed to get ExApp requested scopes.'];
	}

	/**
	 * Get info from App Store releases for specific ExApp and its current version
	 */
	public function getExAppInfoFromAppstore(ExApp $exApp): ?SimpleXMLElement {
		$exApps = $this->exAppFetcher->get();
		$exAppAppstoreData = array_filter($exApps, function (array $exAppItem) use ($exApp) {
			return $exAppItem['id'] === $exApp->getAppid() && count(array_filter($exAppItem['releases'], function (array $release) use ($exApp) {
				return $release['version'] === $exApp->getVersion();
			})) === 1;
		});
		if (count($exAppAppstoreData) === 1) {
			return $this->exAppArchiveFetcher->downloadInfoXml($exAppAppstoreData);
		}
		return null;
	}

	/**
	 * Get latest ExApp release info by ExApp appid (in case of first installation or update)
	 */
	public function getLatestExAppInfoFromAppstore(string $appId): ?SimpleXMLElement {
		$exApps = $this->exAppFetcher->get();
		$exAppAppstoreData = array_filter($exApps, function (array $exAppItem) use ($appId) {
			return $exAppItem['id'] === $appId && count($exAppItem['releases']) > 0;
		});
		$exAppAppstoreData = end($exAppAppstoreData);
		$exAppReleaseInfo = end($exAppAppstoreData['releases']);
		if ($exAppReleaseInfo !== false) {
			return $this->exAppArchiveFetcher->downloadInfoXml($exAppAppstoreData);
		}
		return null;
	}

	private function resetCaches(): void {
		$this->topMenuService->resetCacheEnabled();
		$this->filesActionsMenuService->resetCacheEnabled();
		$this->textProcessingService->resetCacheEnabled();
		$this->speechToTextService->resetCacheEnabled();
		$this->translationService->resetCacheEnabled();
	}
}
