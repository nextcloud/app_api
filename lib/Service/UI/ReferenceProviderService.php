<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service\UI;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\UI\ReferenceProvider;
use OCA\AppAPI\Db\UI\ReferenceProviderMapper;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\Collaboration\Reference\ADiscoverableReferenceProvider;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\ISearchableReferenceProvider;
use OCP\Collaboration\Reference\Reference;
use OCP\DB\Exception;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reference providers registered by ExApps.
 *
 * Each row becomes one anonymous {@see ADiscoverableReferenceProvider}, registered under a synthetic
 * class name, so the number of providers is not limited. Link matching runs in PHP against the stored
 * patterns; only a matching link costs a request to the ExApp (`POST /reference/resolve`), and the
 * server caches the result.
 */
class ReferenceProviderService {
	public const RESOLVE_ROUTE = '/reference/resolve';
	private const CACHE_KEY = '/ex_ui_reference_providers';

	private ?ICache $cache = null;

	public function __construct(
		private readonly ReferenceProviderMapper $mapper,
		private readonly LoggerInterface $logger,
		ICacheFactory $cacheFactory,
	) {
		if ($cacheFactory->isAvailable()) {
			$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_ui_reference_providers');
		}
	}

	/**
	 * @param list<string> $searchProvidersIds
	 * @param list<string> $matchPatterns
	 *
	 * @throws \InvalidArgumentException on invalid input or a provider ID owned by another ExApp
	 */
	public function registerReferenceProvider(
		string $appId, string $providerId, string $title, string $iconUrl, int $order,
		array $searchProvidersIds, array $matchPatterns, string $scriptPath,
	): ?ReferenceProvider {
		if (!preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $providerId)) {
			throw new \InvalidArgumentException('providerId must be 1-64 characters of [a-zA-Z0-9_.-]');
		}
		if (trim($title) === '' || mb_strlen($title) > 128) {
			throw new \InvalidArgumentException('title must be 1-128 characters');
		}
		foreach ($matchPatterns as $pattern) {
			if (!is_string($pattern) || @preg_match($pattern, '') === false) {
				throw new \InvalidArgumentException(sprintf('Invalid match pattern: %s', (string)$pattern));
			}
		}
		foreach ($searchProvidersIds as $searchProviderId) {
			if (!is_string($searchProviderId) || $searchProviderId === '') {
				throw new \InvalidArgumentException('searchProvidersIds must be a list of non-empty strings');
			}
		}
		$scriptPath = ltrim($scriptPath, '/');
		if ($scriptPath !== '' && !self::isSafeRelativePath($scriptPath)) {
			throw new \InvalidArgumentException('scriptPath must be a relative path inside the ExApp, without ".." segments or a scheme');
		}
		if ($iconUrl !== '' && !preg_match('#^https?://#i', $iconUrl) && !self::isSafeRelativePath(ltrim($iconUrl, '/'))) {
			throw new \InvalidArgumentException('iconUrl must be an http(s) URL or a relative path inside the ExApp, without ".." segments');
		}

		$existing = $this->findByProviderId($providerId);
		if ($existing !== null && $existing->getAppid() !== $appId) {
			throw new \InvalidArgumentException(sprintf('Provider ID "%s" belongs to another app', $providerId));
		}

		try {
			$entity = new ReferenceProvider([
				'appid' => $appId,
				'provider_id' => $providerId,
				'title' => $title,
				'icon_url' => $iconUrl,
				'order' => $order,
				'search_providers_ids' => json_encode($searchProvidersIds, JSON_THROW_ON_ERROR),
				'match_patterns' => json_encode($matchPatterns, JSON_THROW_ON_ERROR),
				'script_path' => $scriptPath,
			]);
			if ($existing !== null) {
				$entity->setId($existing->getId());
			}
			$entity = $this->mapper->insertOrUpdate($entity);
			$this->resetCacheEnabled();
			return $entity;
		} catch (Exception|\JsonException $e) {
			$this->logger->error(
				sprintf('Failed to register ExApp %s reference provider %s. Error: %s', $appId, $providerId, $e->getMessage()),
				['exception' => $e],
			);
			return null;
		}
	}

	public function unregisterReferenceProvider(string $appId, string $providerId): bool {
		try {
			$deleted = $this->mapper->removeByAppIdProviderId($appId, $providerId);
		} catch (Exception $e) {
			$this->logger->error(sprintf('Failed to unregister ExApp %s reference provider %s', $appId, $providerId), ['exception' => $e]);
			return false;
		}
		$this->resetCacheEnabled();
		return $deleted > 0;
	}

	public function unregisterExAppReferenceProviders(string $appId): int {
		try {
			$result = $this->mapper->removeAllByAppId($appId);
		} catch (Exception) {
			$result = -1;
		}
		$this->resetCacheEnabled();
		return $result;
	}

	public function getReferenceProvider(string $appId, string $providerId): ?ReferenceProvider {
		$provider = $this->findByProviderId($providerId);
		return ($provider !== null && $provider->getAppid() === $appId) ? $provider : null;
	}

	/**
	 * A path that stays inside the ExApp once appended to its base URL: browsers resolve `..`
	 * (plain or percent-encoded) and `\` like `/`, so `../other_app/x.js` would load another ExApp's
	 * script and `\host` could leave the origin. Allowed: `js/picker.js`, `js/fonts/a.woff2`.
	 */
	public static function isSafeRelativePath(string $path): bool {
		if ($path === '' || strlen($path) > 255) {
			return false;
		}
		if (!preg_match('#^[A-Za-z0-9._~/-]+$#', $path)) {
			return false; // no scheme (":"), query, fragment, backslash or percent-encoding
		}
		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				return false;
			}
		}
		return true;
	}

	private function findByProviderId(string $providerId): ?ReferenceProvider {
		try {
			return $this->mapper->findByProviderId($providerId);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return null;
		}
	}

	/**
	 * Reference providers of enabled ExApps.
	 *
	 * @return ReferenceProvider[]
	 */
	public function getEnabledReferenceProviders(): array {
		try {
			$records = $this->cache?->get(self::CACHE_KEY);
			if ($records === null) {
				$records = $this->mapper->findAllEnabled();
				$this->cache?->set(self::CACHE_KEY, $records);
			}
			return array_map(static fn (array $record) => new ReferenceProvider($record), $records);
		} catch (Exception) {
			return [];
		}
	}

	public function resetCacheEnabled(): void {
		$this->cache?->remove(self::CACHE_KEY);
	}

	/**
	 * Register one server reference provider for each enabled ExApp provider.
	 *
	 * The server takes a class name, so each provider gets a synthetic one in the AppAPI namespace;
	 * the server container sends `OCA\AppAPI\...` lookups to this app's container, where the
	 * anonymous object is registered.
	 */
	public function registerExAppReferenceProviders(IRegistrationContext $context): void {
		foreach ($this->getEnabledReferenceProviders() as $row) {
			/** @var class-string<ADiscoverableReferenceProvider> $className */
			$className = 'OCA\\AppAPI\\ExAppReferenceProvider\\' . $row->getAppid() . '\\' . $row->getProviderId();
			$provider = $this->getAnonymousReferenceProvider($row);
			$context->registerService($className, static fn () => $provider);
			$context->registerReferenceProvider($className);
		}
	}

	/**
	 * @psalm-suppress MissingDependency
	 */
	public function getAnonymousReferenceProvider(ReferenceProvider $row): ADiscoverableReferenceProvider {
		return new class($row, $this->logger) extends ADiscoverableReferenceProvider implements ISearchableReferenceProvider {
			public function __construct(
				private readonly ReferenceProvider $row,
				private readonly LoggerInterface $logger,
			) {
			}

			public function getId(): string {
				return $this->row->getProviderId();
			}

			public function getTitle(): string {
				return $this->row->getTitle();
			}

			public function getOrder(): int {
				return $this->row->getOrder() ?? 0;
			}

			public function getIconUrl(): string {
				$icon = $this->row->getIconUrl() ?? '';
				$urlGenerator = Server::get(IURLGenerator::class);
				if ($icon === '') {
					return $urlGenerator->getAbsoluteURL($urlGenerator->imagePath(Application::APP_ID, 'app.svg'));
				}
				if (preg_match('#^https?://#i', $icon)) {
					return $icon;
				}
				return $urlGenerator->linkToRouteAbsolute(
					'app_api.ExAppProxy.ExAppGet', ['appId' => $this->row->getAppid(), 'other' => ltrim($icon, '/')]
				);
			}

			public function getSupportedSearchProviderIds(): array {
				return $this->row->getSearchProvidersIdsList();
			}

			public function matchReference(string $referenceText): bool {
				foreach ($this->row->getMatchPatternsList() as $pattern) {
					if (@preg_match($pattern, $referenceText) === 1) {
						return true;
					}
				}
				return false;
			}

			public function resolveReference(string $referenceText): ?IReference {
				if (!$this->matchReference($referenceText)) {
					return null;
				}
				$exApp = Server::get(ExAppService::class)->getExApp($this->row->getAppid());
				if ($exApp === null || !$exApp->getEnabled()) {
					return null;
				}
				$userId = Server::get(IUserSession::class)->getUser()?->getUID();
				try {
					$response = Server::get(AppAPIService::class)->requestToExApp(
						$exApp, ReferenceProviderService::RESOLVE_ROUTE, $userId, 'POST',
						['provider_id' => $this->getId(), 'reference' => $referenceText],
					);
				} catch (Throwable $e) {
					$this->logger->warning('ExApp reference resolve failed', ['exception' => $e]);
					return null;
				}
				if (!$response instanceof IResponse || $response->getStatusCode() !== 200) {
					return null;
				}
				$data = json_decode((string)$response->getBody(), true);
				if (!is_array($data)) {
					return null;
				}
				return self::buildReference($referenceText, $data);
			}

			private static function buildReference(string $referenceText, array $data): Reference {
				$reference = new Reference($referenceText);
				if (isset($data['title']) && is_string($data['title'])) {
					$reference->setTitle($data['title']);
				}
				if (isset($data['description']) && is_string($data['description'])) {
					$reference->setDescription($data['description']);
				}
				if (isset($data['image_url']) && is_string($data['image_url'])) {
					$reference->setImageUrl($data['image_url']);
				}
				$reference->setUrl(isset($data['url']) && is_string($data['url']) ? $data['url'] : $referenceText);
				if (isset($data['rich_object_type']) && is_string($data['rich_object_type']) && $data['rich_object_type'] !== '') {
					$richObject = isset($data['rich_object']) && is_array($data['rich_object']) ? $data['rich_object'] : [];
					$reference->setRichObject($data['rich_object_type'], $richObject);
				}
				return $reference;
			}

			public function getCachePrefix(string $referenceId): string {
				return $referenceId;
			}

			public function getCacheKey(string $referenceId): ?string {
				// The ExApp answers per user, so the result is cached per user.
				return Server::get(IUserSession::class)->getUser()?->getUID() ?? '';
			}
		};
	}
}
