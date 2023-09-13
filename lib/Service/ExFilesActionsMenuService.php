<?php

declare(strict_types=1);

namespace OCA\AppAPI\Service;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\ExFilesActionsMenu;
use OCA\AppAPI\Db\ExFilesActionsMenuMapper;
use OCP\AppFramework\Db\DoesNotExistException;

use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\DB\Exception;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ExFilesActionsMenuService {
	public const ICON_CACHE_TTL = 60 * 60 * 24; // 1 day
	private ICache $cache;
	private ExFilesActionsMenuMapper $mapper;
	private LoggerInterface $logger;
	private IClient $client;
	private AppAPIService $appAPIService;
	private IRequest $request;
	private IConfig $config;

	public function __construct(
		ICacheFactory            $cacheFactory,
		ExFilesActionsMenuMapper $mapper,
		LoggerInterface          $logger,
		IClientService           $clientService,
		AppAPIService            $appAPIService,
		IRequest                 $request,
		IConfig                  $config,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_files_actions_menu');
		$this->mapper = $mapper;
		$this->logger = $logger;
		$this->client = $clientService->newClient();
		$this->appAPIService = $appAPIService;
		$this->request = $request;
		$this->config = $config;
	}

	/**
	 * Register file action menu from ExApp
	 *
	 * @param string $appId
	 * @param array $params
	 *
	 * @return ExFilesActionsMenu|null
	 */
	public function registerFileActionMenu(string $appId, array $params): ?ExFilesActionsMenu {
		try {
			$fileActionMenu = $this->mapper->findByName($params['name']);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			$fileActionMenu = null;
		}
		try {
			$newFileActionMenu = new ExFilesActionsMenu([
				'appid' => $appId,
				'name' => $params['name'],
				'display_name' => $params['display_name'],
				'mime' => $params['mime'],
				'permissions' => $params['permissions'] ?? 31,
				'order' => $params['order'] ?? 0,
				'icon' => $params['icon'] ?? null,
				'icon_class' => $params['icon_class'] ?? 'icon-app-ecosystem-v2',
				'action_handler' => $params['action_handler'],
			]);
			if ($fileActionMenu !== null) {
				$newFileActionMenu->setId($fileActionMenu->getId());
			}
			$fileActionMenu = $this->mapper->insertOrUpdate($newFileActionMenu);
			$cacheKey = '/ex_files_actions_menu_' . $appId . '_' . $params['name'];
			$this->cache->remove('/ex_files_actions_menus');
			$this->cache->set($cacheKey, $fileActionMenu);
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to register ExApp %s FileActionMenu %s. Error: %s', $appId, $params['name'], $e->getMessage()), ['exception' => $e]);
			return null;
		}
		return $fileActionMenu;
	}

	public function unregisterFileActionMenu(string $appId, string $fileActionMenuName): ?ExFilesActionsMenu {
		try {
			$fileActionMenu = $this->getExAppFileAction($appId, $fileActionMenuName);
			if ($fileActionMenu === null) {
				return null;
			}
			$this->mapper->delete($fileActionMenu);
			$this->cache->remove('/ex_files_actions_menu_' . $appId . '_' . $fileActionMenuName);
			return $fileActionMenu;
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to unregister ExApp %s FileActionMenu %s. Error: %s', $appId, $fileActionMenuName, $e->getMessage()), ['exception' => $e]);
			return null;
		}
	}

	/**
	 * Get list of registered file actions (only for enabled ExApps)
	 *
	 * @return ExFilesActionsMenu[]|null
	 */
	public function getRegisteredFileActions(): ?array {
		try {
			$cacheKey = '/ex_files_actions_menus';
			$cached = $this->cache->get($cacheKey);
			if ($cached !== null) {
				return array_map(function ($cacheEntry) {
					return $cacheEntry instanceof ExFilesActionsMenu ? $cacheEntry : new ExFilesActionsMenu($cacheEntry);
				}, $cached);
			}

			$fileActions = $this->mapper->findAllEnabled();
			$this->cache->set($cacheKey, $fileActions);
			return $fileActions;
		} catch (Exception) {
			return null;
		}
	}

	public function getExAppFileAction(string $appId, string $fileActionName): ?ExFilesActionsMenu {
		$cacheKey = '/ex_files_actions_menu_' . $appId . '_' . $fileActionName;
		$cache = $this->cache->get($cacheKey);
		if ($cache !== null) {
			return $cache instanceof ExFilesActionsMenu ? $cache : new ExFilesActionsMenu($cache);
		}

		try {
			$fileAction = $this->mapper->findByAppidName($appId, $fileActionName);
			$this->cache->set($cacheKey, $fileAction);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception $e) {
			$this->logger->error(sprintf('ExApp %s FileAction %s not found. Error: %s', $appId, $fileActionName, $e->getMessage()), ['exception' => $e]);
			$fileAction = null;
		}
		return $fileAction;
	}

	public function handleFileAction(string $userId, string $appId, string $fileActionName, string $actionHandler, array $actionFile): bool {
		$exFileAction = $this->getExAppFileAction($appId, $fileActionName);
		if ($exFileAction !== null) {
			$handler = $exFileAction->getActionHandler(); // route on ex app
			$params = [
				'actionName' => $fileActionName,
				'actionHandler' => $actionHandler,
				'actionFile' => [
					'fileId' => $actionFile['fileId'],
					'name' => $actionFile['name'],
					'directory' => $actionFile['directory'],
					'etag' => $actionFile['etag'],
					'mime' => $actionFile['mime'],
					'fileType' => $actionFile['fileType'],
					'mtime' => $actionFile['mtime'] / 1000, // convert ms to s
					'size' => intval($actionFile['size']),
					'favorite' => $actionFile['favorite'] ?? "false",
					'permissions' => $actionFile['permissions'],
					'shareOwner' => $actionFile['shareOwner'] ?? null,
					'shareOwnerId' => $actionFile['shareOwnerId'] ?? null,
					'shareTypes' => $actionFile['shareTypes'] ?? null,
					'shareAttributes' => $actionFile['shareAttributes'] ?? null,
					'sharePermissions' => $actionFile['sharePermissions'] ?? null,
					'userId' => $userId,
					'instanceId' => $this->config->getSystemValue('instanceid', null),
				],
			];
			$exApp = $this->appAPIService->getExApp($appId);
			if ($exApp !== null) {
				$result = $this->appAPIService->aeRequestToExApp($this->request, $userId, $exApp, $handler, 'POST', $params);
				if ($result instanceof IResponse) {
					return $result->getStatusCode() === 200;
				}
				if (isset($result['error'])) {
					$this->logger->error(sprintf('Failed to handle ExApp %s FileAction %s. Error: %s', $appId, $fileActionName, $result['error']));
					return false;
				}
			}
		}
		return false;
	}

	/**
	 * @param string $appId
	 * @param string $exFileActionName
	 *
	 * @return array|null
	 */
	public function loadFileActionIcon(string $appId, string $exFileActionName): ?array {
		$exFileAction = $this->getExAppFileAction($appId, $exFileActionName);
		if ($exFileAction === null) {
			return null;
		}
		$iconUrl = $exFileAction->getIcon();
		if (!isset($iconUrl) || $iconUrl === '') {
			return null;
		}
		try {
			$thumbnailResponse = $this->client->get($iconUrl);
			if ($thumbnailResponse->getStatusCode() === Http::STATUS_OK) {
				return [
					'body' => $thumbnailResponse->getBody(),
					'headers' => $thumbnailResponse->getHeaders(),
				];
			}
		} catch (\Exception $e) {
			$this->logger->error(sprintf('Failed to load ExApp %s FileAction icon %s. Error: %s', $appId, $exFileActionName, $e->getMessage()), ['exception' => $e]);
			return null;
		}
		return null;
	}
}
