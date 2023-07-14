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

namespace OCA\AppEcosystemV2\Command\ExApp;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\Docker\DockerActions;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use OCA\AppEcosystemV2\Service\DaemonConfigService;

class Deploy extends Command {
	private AppEcosystemV2Service $service;
	private DaemonConfigService $daemonConfigService;
	private DockerActions $dockerActions;
	private IAppManager $appManager;
	private ISecureRandom $random;
	private IURLGenerator $urlGenerator;

	public function __construct(
		AppEcosystemV2Service $service,
		DaemonConfigService $daemonConfigService,
		DockerActions $dockerActions,
		IAppManager $appManager,
		ISecureRandom $random,
		IURLGenerator $urlGenerator,
	) {
		parent::__construct();

		$this->service = $service;
		$this->daemonConfigService = $daemonConfigService;
		$this->dockerActions = $dockerActions;
		$this->appManager = $appManager;
		$this->random = $random;
		$this->urlGenerator = $urlGenerator;
	}

	protected function configure() {
		$this->setName('app_ecosystem_v2:app:deploy');
		$this->setDescription('Deploy ExApp on configured daemon');

		$this->addArgument('appid', InputArgument::REQUIRED);
		$this->addArgument('daemon-config-name', InputArgument::REQUIRED);

		$this->addOption('info-xml', null, InputOption::VALUE_REQUIRED, '[required] Path to ExApp info.xml file (url or local absolute path)');
		$this->addOption('env', 'e', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Docker container environment variables', []);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getArgument('appid');

		$pathToInfoXml = $input->getOption('info-xml');
		if ($pathToInfoXml === null) {
			$output->writeln(sprintf('No info.xml specified for %s', $appId));
			return 2;
		}

		$infoXml = simplexml_load_file($pathToInfoXml);
		if ($infoXml === false) {
			$output->writeln(sprintf('Failed to load info.xml from %s', $pathToInfoXml));
			return 2;
		}
		if ($appId !== (string) $infoXml->id) {
			$output->writeln(sprintf('ExApp appid %s does not match appid in info.xml (%s)', $appId, $infoXml->id));
			return 2;
		}

		$exApp = $this->service->getExApp($appId);
		if ($exApp !== null) {
			$output->writeln(sprintf('ExApp %s already deployed and registered.', $appId));
			return 2;
		}

		$daemonConfigName = $input->getArgument('daemon-config-name');
		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($daemonConfigName);
		if ($daemonConfig === null) {
			$output->writeln(sprintf('Daemon config %s not found.', $daemonConfigName));
			return 2;
		}
		$deployConfig = $daemonConfig->getDeployConfig();

		$imageParams = [
			'image_src' => (string) $infoXml->xpath('ex-app/docker-install/registry')[0] ?? 'docker.io',
			'image_name' => (string) $infoXml->xpath('ex-app/docker-install/image')[0] ?? $appId,
			'image_tag' => (string) $infoXml->xpath('ex-app/docker-install/image-tag')[0] ?? 'latest',
		];
		$containerParams = [
			'name' => $appId,
			'hostname' => $appId,
			'port' => $this->getRandomPort(),
			'net' => $deployConfig['net'] ?? 'host',
		];

		$envParams = $input->getOption('env');
		$envs = $this->buildDeployEnvParams([
			'appid' => $appId,
			'version' => (string) $infoXml->version,
			'host' => $this->buildExAppHost($deployConfig),
			'port' => $containerParams['port'],
		], $envParams, $deployConfig);
		$containerParams['env'] = $envs;

		[$pullResult, $createResult, $startResult] = $this->dockerActions->deployExApp($daemonConfig, $imageParams, $containerParams);

		if (isset($pullResult['error'])) {
			$output->writeln(sprintf('ExApp %s deployment failed. Error: %s', $appId, $pullResult['error']));
			return 1;
		}

		if (!isset($startResult['error']) && isset($createResult['Id'])) {
			$resultOutput = [
				'appid' => $appId,
				'name' => (string) $infoXml->name,
				'daemon_config_name' => $daemonConfigName,
				'version' => (string) $infoXml->version,
				'secret' => explode('=', $envs[1])[1],
				'host' => $this->service->resolveDeployExAppHost($appId, $daemonConfigName),
				'port' => explode('=', $envs[5])[1],
				'protocol' => (string) $infoXml->xpath('ex-app/protocol')[0] ?? 'http',
				'system_app' => (bool) $infoXml->xpath('ex-app/system')[0] ?? false,
			];
			if ($this->heartbeatExApp($resultOutput, $daemonConfig->getId())) {
				$output->writeln(json_encode($resultOutput, JSON_UNESCAPED_SLASHES));
				return 0;
			}

			$output->writeln(sprintf('ExApp %s heartbeat check failed. Make sure container started and initialized correctly.', $appId));
		} else {
			$output->writeln(sprintf('ExApp %s deployment failed. Error: %s', $appId, $startResult['error'] ?? $createResult['error']));
		}
		return 1;
	}

	private function buildDeployEnvParams(array $params, array $envOptions, array $deployConfig): array {
		$requiredEnvsNames = [
			'AE_VERSION',
			'APP_SECRET',
			'APP_ID',
			'APP_VERSION',
			'APP_HOST',
			'APP_PORT',
			'NEXTCLOUD_URL',
		];
		$autoEnvs = [
			sprintf('AE_VERSION=%s', $this->appManager->getAppVersion(Application::APP_ID, false)),
			sprintf('APP_SECRET=%s', $this->random->generate(128)),
			sprintf('APP_ID=%s', $params['appid']),
			sprintf('APP_VERSION=%s', $params['version']),
			sprintf('APP_HOST=%s', $params['host']),
			sprintf('APP_PORT=%s', $params['port']),
			sprintf('NEXTCLOUD_URL=%s', $deployConfig['nextcloud_url'] ?? str_replace('https', 'http', $this->urlGenerator->getAbsoluteURL(''))),
		];

		foreach ($envOptions as $envOption) {
			[$key, $value] = explode('=', $envOption, 2);
			// Do not overwrite required auto generated envs
			if (!in_array($key, $requiredEnvsNames, true)) {
				$autoEnvs[] = sprintf('%s=%s', $key, $value);
			}
		}

		return $autoEnvs;
	}

	private function buildExAppHost(array $deployConfig): string {
		if ((isset($deployConfig['net']) && $deployConfig['net'] !== 'host') || isset($deployConfig['host'])) {
			return '0.0.0.0';
		}
		return '127.0.0.1';
	}

	private function getRandomPort(): int {
		$port = 10000 + (int) $this->random->generate(4, ISecureRandom::CHAR_DIGITS);
		while ($this->service->getExAppsByPort($port) !== []) {
			$port = 10000 + (int) $this->random->generate(4, ISecureRandom::CHAR_DIGITS);
		}
		return $port;
	}

	private function heartbeatExApp(array $resultOutput, int $daemonConfigId): bool {
		// TODO: Extract to AppEcosystemV2 and make configurable
		$heartbeatAttempts = 0;
		$delay = 1;
		$maxHeartbeatAttempts = (60 * 60) / $delay; // 60 * 60 / delay = minutes for container initialization
		$heartbeatUrl = $this->service->getExAppUrl(
			$resultOutput['protocol'],
			$resultOutput['host'],
			(int) $resultOutput['port'],
		) . '/heartbeat';

		while ($heartbeatAttempts < $maxHeartbeatAttempts) {
			$heartbeatAttempts++;
			$ch = curl_init($heartbeatUrl);
			$headers = [
				'Accept: application/json',
				'Content-Type: application/json',
			];
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 5);
			$heartbeatResult = curl_exec($ch);
			$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);
			if ($statusCode === 200) {
				$result = json_decode($heartbeatResult, true);
				if (isset($result['status']) && $result['status'] === 'ok') {
					return true;
				}
			}
			sleep($delay);
		}

		return false;
	}
}
