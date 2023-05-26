<?php

declare(strict_types=1);

/**
 *
 * Nextcloud - App Ecosystem V2
 *
 * @copyright Copyright (c) 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @copyright Copyright (c) 2023 Alexander Piskun <bigcat88@icloud.com>
 *
 * @author 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\AppEcosystemV2\Service;

use OCA\AppEcosystemV2\Db\ExApp;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;

use OCA\AppEcosystemV2\Db\ExAppMapper;

class AppEcosystemV2Service {
	/** @var IConfig */
	private $config;

	/** @var IClient */
	private $client;

	/** @var ExAppMapper */
	private $exAppMapper;

	public function __construct(
		IConfig $config,
		IClientService $clientService,
		ExAppMapper $exAppMapper,
	) {
		$this->config = $config;
		$this->client = $clientService->newClient();
		$this->exAppMapper = $exAppMapper;
	}
	
	public function detectDefaultExApp() {
		// TODO: Check default ex app host and port connection and register it if not exists yet
		$protocol = 'https';
		$host = 'localhost';
		$port = '8063';
		$exAppUrl = $protocol . '://' . $host . ':' . $port;
		$result = $this->checkExAppConnection($exAppUrl);
		if ($result) {
			$this->registerExApp([
				'name' => 'Default Ex App',
				'appid' => $result['appid'] ?? '',
				'config' => [
					'protocol' => $protocol,
					'host' => $host,
					'port' => $port,
				],
				'secret' => '',
				'status' => 'active',
				'created_time' => time(),
				'last_response_time' => time(),
			]);
		}
		return $result;
	}

	public function checkExAppConnection(
		string $exAppUrl,
		string $exAppToken = '', // TODO: temporal app token to receive valid response from ex app
		string $exAppSecret = '' // TODO: one-time created secret
	) {
		$response = $this->client->post($exAppUrl, [
			'headers' => [
				'Authorization' => 'Bearer ' . $exAppToken,
				'X-App-Secret' => $exAppSecret
			]
		]);
		if ($response->getStatusCode() === 200) {
			return true;
		}
		return false;
	}

	public function registerExApp(array $params) {
		return $this->exAppMapper->insert(new ExApp([
			'name' => $params['name'],
			'appid' => $params['appid'],
			'config' => $params['config'],
			'secret' => $params['secret'],
			'status' => $params['status'],
			'created_time' => $params['created_time'],
			'last_response_time' => $params['last_response_time'],
		]));
	}

	public function unregisterExApp(int $id) {
		$exApp = $this->exAppMapper->find($id);
		return $exApp->delete();
	}

	// getExFilesActions
	public function getExFilesActions() {
		// TODO
	}
}
