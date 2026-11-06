<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Db\UI;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A reference provider registered by an ExApp.
 *
 * @method string getAppid()
 * @method string getProviderId()
 * @method string getTitle()
 * @method string getIconUrl()
 * @method int getOrder()
 * @method string|null getSearchProvidersIds()
 * @method string|null getMatchPatterns()
 * @method string getScriptPath()
 * @method void setAppid(string $appid)
 * @method void setProviderId(string $providerId)
 * @method void setTitle(string $title)
 * @method void setIconUrl(string $iconUrl)
 * @method void setOrder(int $order)
 * @method void setSearchProvidersIds(string|null $searchProvidersIds)
 * @method void setMatchPatterns(string|null $matchPatterns)
 * @method void setScriptPath(string $scriptPath)
 *
 * @psalm-import-type AppAPIReferenceProvider from \OCA\AppAPI\ResponseDefinitions
 */
class ReferenceProvider extends Entity implements JsonSerializable {
	protected $appid;
	protected $providerId;
	protected $title;
	protected $iconUrl;
	protected $order;
	protected $searchProvidersIds;
	protected $matchPatterns;
	protected $scriptPath;

	public function __construct(array $params = []) {
		$this->addType('appid', 'string');
		$this->addType('provider_id', 'string');
		$this->addType('title', 'string');
		$this->addType('icon_url', 'string');
		$this->addType('order', 'integer');
		$this->addType('search_providers_ids', 'string');
		$this->addType('match_patterns', 'string');
		$this->addType('script_path', 'string');

		if (isset($params['id'])) {
			$this->setId($params['id']);
		}
		if (isset($params['appid'])) {
			$this->setAppid($params['appid']);
		}
		if (isset($params['provider_id'])) {
			$this->setProviderId($params['provider_id']);
		}
		if (isset($params['title'])) {
			$this->setTitle($params['title']);
		}
		if (isset($params['icon_url'])) {
			$this->setIconUrl($params['icon_url']);
		}
		if (isset($params['order'])) {
			$this->setOrder((int)$params['order']);
		}
		if (isset($params['search_providers_ids'])) {
			$this->setSearchProvidersIds($params['search_providers_ids']);
		}
		if (isset($params['match_patterns'])) {
			$this->setMatchPatterns($params['match_patterns']);
		}
		if (isset($params['script_path'])) {
			$this->setScriptPath($params['script_path']);
		}
	}

	/**
	 * @return list<string>
	 */
	public function getSearchProvidersIdsList(): array {
		return self::decodeList($this->getSearchProvidersIds());
	}

	/**
	 * @return list<string>
	 */
	public function getMatchPatternsList(): array {
		return self::decodeList($this->getMatchPatterns());
	}

	/**
	 * @return list<string>
	 */
	private static function decodeList(?string $json): array {
		if ($json === null || $json === '') {
			return [];
		}
		$list = json_decode($json, true);
		if (!is_array($list)) {
			return [];
		}
		return array_values(array_filter($list, 'is_string'));
	}

	/**
	 * @return AppAPIReferenceProvider
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'appid' => $this->getAppid(),
			'provider_id' => $this->getProviderId(),
			'title' => $this->getTitle(),
			'icon_url' => $this->getIconUrl() ?? '',
			'order' => $this->getOrder() ?? 0,
			'search_providers_ids' => $this->getSearchProvidersIdsList(),
			'match_patterns' => $this->getMatchPatternsList(),
			'script_path' => $this->getScriptPath() ?? '',
		];
	}
}
