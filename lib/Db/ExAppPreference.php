<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Db;

use JsonSerializable;

/**
 * App per-user preference value object.
 *
 * Historically a database entity backed by the `preferences_ex` table. ExApp preferences now
 * live in the server's standard `oc_preferences` (via {@see \OCA\AppAPI\Service\ExAppPreferenceService});
 * this class remains as a plain serializable DTO for OCS responses and internal callers.
 *
 * The `id` field has no surrogate key anymore (the server table uses a composite primary key);
 * it is kept in the serialized shape as `0` for backwards compatibility and is unused.
 */
class ExAppPreference implements JsonSerializable {
	private int $id;
	private string $userid;
	private string $appid;
	private string $configkey;
	private string $configvalue;
	private int $sensitive;

	public function __construct(array $params = []) {
		$this->id = isset($params['id']) ? (int)$params['id'] : 0;
		$this->userid = (string)($params['userid'] ?? '');
		$this->appid = (string)($params['appid'] ?? '');
		$this->configkey = (string)($params['configkey'] ?? '');
		$this->configvalue = (string)($params['configvalue'] ?? '');
		$this->sensitive = (int)($params['sensitive'] ?? 0);
	}

	public function getId(): int {
		return $this->id;
	}

	public function getUserid(): string {
		return $this->userid;
	}

	public function getAppid(): string {
		return $this->appid;
	}

	public function getConfigkey(): string {
		return $this->configkey;
	}

	public function getConfigvalue(): string {
		return $this->configvalue;
	}

	public function setConfigvalue(string $configValue): void {
		$this->configvalue = $configValue;
	}

	public function getSensitive(): int {
		return $this->sensitive;
	}

	public function setSensitive(int $sensitive): void {
		$this->sensitive = $sensitive;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'user_id' => $this->userid,
			'appid' => $this->appid,
			'configkey' => $this->configkey,
			'configvalue' => $this->configvalue,
			'sensitive' => $this->sensitive,
		];
	}
}
