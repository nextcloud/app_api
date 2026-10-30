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
use OCA\AppAPI\Service\HarpService;
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
use OCP\IConfig;
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
	// A cache that is not shared between processes (APCu) is not cleared by `occ` or by another PHP-FPM worker,
	// so entries expire on their own. With a shared cache, writes clear the entry and the TTL is only a backstop.
	private const CACHE_TTL = 300;
	private const CACHE_TTL_PROCESS_LOCAL = 10;
	// A failed resolve is remembered so a broken ExApp is not called on every render.
	public const FAILURE_TTL = 300;
	public const RESOLVE_TIMEOUT = 3;

	public const MAX_MATCH_PATTERNS = 10;
	public const MAX_PATTERN_LENGTH = 256;
	public const MAX_SEARCH_PROVIDERS = 10;
	// Lengths of the columns in Version036000Date20261024120000.
	private const MAX_ICON_LENGTH = 255;
	private const MAX_SCRIPT_PATH_LENGTH = 255;
	private const MAX_SEARCH_PROVIDER_ID_LENGTH = 64;

	// The pattern body must start with `^https://` (or `^https?://`) and a literal host, so a provider only
	// ever sees links to hosts it names. `/.*/` would otherwise send every link a user pastes to the ExApp.
	private const HOST_ANCHOR = '~^\^https\??://((?:[a-z0-9-]+\\\.)+[a-z]{2,63})(?:$|/|\\/|:|\(|\$)~i';

	private ?ICache $cache = null;
	private int $cacheTtl = self::CACHE_TTL;

	public function __construct(
		private readonly ReferenceProviderMapper $mapper,
		private readonly LoggerInterface $logger,
		ICacheFactory $cacheFactory,
		IConfig $config,
	) {
		if ($cacheFactory->isAvailable()) {
			$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '/ex_ui_reference_providers');
			if (self::isProcessLocalCache($config)) {
				$this->cacheTtl = self::CACHE_TTL_PROCESS_LOCAL;
			}
		}
	}

	/**
	 * Whether the "distributed" cache is really APCu, which every PHP process keeps on its own.
	 * Same check as {@see ExAppService}.
	 */
	private static function isProcessLocalCache(IConfig $config): bool {
		$distributed = ltrim($config->getSystemValueString('memcache.distributed', ''), '\\');
		$local = ltrim($config->getSystemValueString('memcache.local', ''), '\\');
		return ($distributed === '' ? $local : $distributed) === \OC\Memcache\APCu::class;
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
		if (count($matchPatterns) > self::MAX_MATCH_PATTERNS) {
			throw new \InvalidArgumentException(sprintf('at most %d match patterns', self::MAX_MATCH_PATTERNS));
		}
		foreach ($matchPatterns as $pattern) {
			self::validateMatchPattern($pattern);
		}
		if (count($searchProvidersIds) > self::MAX_SEARCH_PROVIDERS) {
			throw new \InvalidArgumentException(sprintf('at most %d search providers', self::MAX_SEARCH_PROVIDERS));
		}
		foreach ($searchProvidersIds as $searchProviderId) {
			if (!is_string($searchProviderId) || $searchProviderId === '' || strlen($searchProviderId) > self::MAX_SEARCH_PROVIDER_ID_LENGTH) {
				throw new \InvalidArgumentException(sprintf('searchProvidersIds must be strings of 1-%d characters', self::MAX_SEARCH_PROVIDER_ID_LENGTH));
			}
		}
		$scriptPath = ltrim($scriptPath, '/');
		if ($scriptPath !== '' && !self::isSafeRelativePath($scriptPath, self::MAX_SCRIPT_PATH_LENGTH)) {
			throw new \InvalidArgumentException('scriptPath must be a relative path inside the ExApp, without ".." segments or a scheme');
		}
		// Only icons served by the ExApp: an outside URL would make every user's browser contact a third party.
		$iconUrl = ltrim($iconUrl, '/');
		if ($iconUrl !== '' && !self::isSafeRelativePath($iconUrl, self::MAX_ICON_LENGTH)) {
			throw new \InvalidArgumentException('iconUrl must be a relative path inside the ExApp, without ".." segments or a scheme');
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
		} catch (\InvalidArgumentException $e) {
			// Another ExApp inserted the same provider ID between the ownership check and this insert:
			// insertOrUpdate() then falls back to update() on an entity without ID.
			if ($existing === null && $this->findByProviderId($providerId)?->getAppid() !== $appId) {
				throw new \InvalidArgumentException(sprintf('Provider ID "%s" belongs to another app', $providerId), 0, $e);
			}
			throw $e;
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
	public static function isSafeRelativePath(string $path, int $maxLength = 255): bool {
		if ($path === '' || strlen($path) > $maxLength) {
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

	/**
	 * A valid PCRE, at most MAX_PATTERN_LENGTH long, anchored to `^https?://` and a literal host.
	 *
	 * Every pattern of every provider runs on each link a user pastes, and a matching link is sent to the
	 * ExApp, so a pattern that matches any host would hand the ExApp links to anything, share links included.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function validateMatchPattern(mixed $pattern): void {
		if (!is_string($pattern) || $pattern === '' || strlen($pattern) > self::MAX_PATTERN_LENGTH) {
			throw new \InvalidArgumentException(sprintf('match patterns must be strings of 1-%d characters', self::MAX_PATTERN_LENGTH));
		}
		if (@preg_match($pattern, '') === false) {
			throw new \InvalidArgumentException(sprintf('Invalid match pattern: %s', $pattern));
		}
		$body = self::patternBody($pattern);
		if ($body === null || preg_match(self::HOST_ANCHOR, $body) !== 1) {
			throw new \InvalidArgumentException(sprintf(
				'Match pattern must start with ^https:// and a literal host with escaped dots, like #^https://example\.com/#: %s', $pattern
			));
		}
	}

	/**
	 * The part of a PCRE between its delimiters, or null if it has none.
	 */
	private static function patternBody(string $pattern): ?string {
		$open = $pattern[0];
		$close = ['(' => ')', '{' => '}', '[' => ']', '<' => '>'][$open] ?? $open;
		$end = strrpos($pattern, $close);
		return ($end === false || $end === 0) ? null : substr($pattern, 1, $end - 1);
	}

	/**
	 * Base URL the browser uses to reach the ExApp: HaRP serves `/exapps/<appid>` directly, otherwise
	 * the request goes through the AppAPI PHP proxy.
	 */
	public static function getExAppBaseUrl(IURLGenerator $urlGenerator, string $appId, bool $isHarp): string {
		if ($isHarp) {
			return rtrim($urlGenerator->getWebroot(), '/') . '/exapps/' . $appId;
		}
		return rtrim($urlGenerator->linkToRoute('app_api.ExAppProxy.ExAppGet', ['appId' => $appId, 'other' => '']), '/');
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
				$this->cache?->set(self::CACHE_KEY, $records, $this->cacheTtl);
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
	 * The server takes a class name, so each provider gets a synthetic service key in the AppAPI namespace;
	 * the server container sends `OCA\AppAPI\...` lookups to this app's container, where the anonymous
	 * object is registered. The key is not a real class name and is never autoloaded: the container uses it
	 * as an opaque string. Its last segment is a hash so that `.` and `-` of a provider ID stay out of it.
	 *
	 * This runs in `Application::register()` on every request, because the server has no event to collect
	 * reference providers lazily (as `GetTaskProcessingProvidersEvent` does for task processing). The list
	 * comes from the cache, so the database is queried only when the cache is empty or expired.
	 */
	public function registerExAppReferenceProviders(IRegistrationContext $context): void {
		foreach ($this->getEnabledReferenceProviders() as $row) {
			/** @var class-string<ADiscoverableReferenceProvider> $className */
			$className = 'OCA\\AppAPI\\ExAppReferenceProvider\\P' . hash('xxh128', $row->getAppid() . '/' . $row->getProviderId());
			$provider = $this->getAnonymousReferenceProvider($row);
			$context->registerService($className, static fn () => $provider);
			$context->registerReferenceProvider($className);
		}
	}

	/**
	 * @psalm-suppress MissingDependency
	 */
	public function getAnonymousReferenceProvider(ReferenceProvider $row): ADiscoverableReferenceProvider {
		return new class($row, $this->logger, $this->cache) extends ADiscoverableReferenceProvider implements ISearchableReferenceProvider {
			private const MAX_TEXT_LENGTH = 1024;
			private const MAX_URL_LENGTH = 2048;
			private const MAX_RICH_OBJECT_TYPE_LENGTH = 64;
			private const MAX_RICH_OBJECT_BYTES = 16384;

			public function __construct(
				private readonly ReferenceProvider $row,
				private readonly LoggerInterface $logger,
				private readonly ?ICache $cache,
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
				$exApp = Server::get(ExAppService::class)->getExApp($this->row->getAppid());
				if ($exApp === null) {
					return $urlGenerator->getAbsoluteURL($urlGenerator->imagePath(Application::APP_ID, 'app.svg'));
				}
				// Same base URL as the picker script: HaRP's /exapps/ when available, the PHP proxy otherwise.
				$base = ReferenceProviderService::getExAppBaseUrl(
					$urlGenerator, $this->row->getAppid(), HarpService::isHarp($exApp->getDeployConfig())
				);
				return $urlGenerator->getAbsoluteURL($base . '/' . ltrim($icon, '/'));
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
				$failureKey = $this->failureKey($referenceText);
				if ($this->cache?->get($failureKey) !== null) {
					return null;
				}
				$reference = $this->requestReference($referenceText);
				if ($reference === null) {
					// The server caches only successes, so remember the failure for a while: a slow or broken
					// ExApp would otherwise be called, and waited for, on every render of the link.
					$this->cache?->set($failureKey, true, ReferenceProviderService::FAILURE_TTL);
				}
				return $reference;
			}

			private function failureKey(string $referenceText): string {
				return 'failed/' . md5($this->getCachePrefix($referenceText) . '/' . ($this->getCacheKey($referenceText) ?? ''));
			}

			private function requestReference(string $referenceText): ?IReference {
				$exApp = Server::get(ExAppService::class)->getExApp($this->row->getAppid());
				if ($exApp === null || !$exApp->getEnabled()) {
					return null;
				}
				$userId = Server::get(IUserSession::class)->getUser()?->getUID();
				try {
					$response = Server::get(AppAPIService::class)->requestToExApp(
						$exApp, ReferenceProviderService::RESOLVE_ROUTE, $userId, 'POST',
						['provider_id' => $this->getId(), 'reference' => $referenceText],
						['timeout' => ReferenceProviderService::RESOLVE_TIMEOUT],
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
					$reference->setTitle(mb_substr($data['title'], 0, self::MAX_TEXT_LENGTH));
				}
				if (isset($data['description']) && is_string($data['description'])) {
					$reference->setDescription(mb_substr($data['description'], 0, self::MAX_TEXT_LENGTH));
				}
				// The default widget binds these as href/src, so only http(s) URLs: no javascript: or data:.
				$imageUrl = self::httpUrlOrNull($data['image_url'] ?? null);
				if ($imageUrl !== null) {
					$reference->setImageUrl($imageUrl);
				}
				$reference->setUrl(self::httpUrlOrNull($data['url'] ?? null) ?? $referenceText);
				$type = $data['rich_object_type'] ?? null;
				if (is_string($type) && preg_match('/^[a-zA-Z0-9_.-]{1,' . self::MAX_RICH_OBJECT_TYPE_LENGTH . '}$/', $type)) {
					$richObject = isset($data['rich_object']) && is_array($data['rich_object']) ? $data['rich_object'] : [];
					// Cached for an hour per user and sent to every client: keep it small.
					if (strlen((string)json_encode($richObject)) <= self::MAX_RICH_OBJECT_BYTES) {
						$reference->setRichObject($type, $richObject);
					}
				}
				return $reference;
			}

			private static function httpUrlOrNull(mixed $url): ?string {
				if (!is_string($url) || strlen($url) > self::MAX_URL_LENGTH) {
					return null;
				}
				$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
				$host = parse_url($url, PHP_URL_HOST);
				return (($scheme === 'http' || $scheme === 'https') && is_string($host) && $host !== '') ? $url : null;
			}

			public function getCachePrefix(string $referenceId): string {
				// Namespaced, so a result cached for this provider is never served for another one.
				return $this->row->getAppid() . '/' . $this->row->getProviderId() . '/' . $referenceId;
			}

			public function getCacheKey(string $referenceId): ?string {
				// The ExApp answers per user, so the result is cached per user.
				return Server::get(IUserSession::class)->getUser()?->getUID() ?? '';
			}
		};
	}
}
