<?php

declare(strict_types=1);

namespace OCA\AppAPI\Service;

use OCA\AppAPI\Db\ExApp;
use OCA\Talk\Events\BotInstallEvent;
use OCA\Talk\Events\BotUninstallEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Security\ISecureRandom;

class TalkBotsService {
	private ExAppConfigService $exAppConfigService;
	private IEventDispatcher $dispatcher;
	private ISecureRandom $random;

	public function __construct(
		ExAppConfigService $exAppConfigService,
		IEventDispatcher   $dispatcher,
		ISecureRandom      $random,
	) {
		$this->exAppConfigService = $exAppConfigService;
		$this->dispatcher = $dispatcher;
		$this->random = $random;
	}

	public function registerExAppBot(ExApp $exApp, string $name, string $route, string $description): ?array {
		if (!class_exists(BotInstallEvent::class)) {
			return null;
		}

		[$id, $url, $secret] = $this->getExAppTalkBotConfig($exApp, $route);

		$event = new BotInstallEvent(
			$name,
			$secret,
			$url,
			$description,
		);
		$this->dispatcher->dispatchTyped($event);

		$this->exAppConfigService->setAppConfigValue($exApp->getAppid(), $id, $secret);
		$this->exAppConfigService->setAppConfigValue($exApp->getAppid(), 'talk_bot_route_' . $secret, $route);

		return [
			'id' => $id,
			'secret' => $secret,
		];
	}

	public function unregisterExAppBot(ExApp $exApp, string $route): ?bool {
		if (!class_exists(BotUninstallEvent::class)) {
			return null;
		}

		[$id, $url, $secret] = $this->getExAppTalkBotConfig($exApp, $route);

		$event = new BotUninstallEvent($secret, $url);
		$this->dispatcher->dispatchTyped($event);

		if ($this->exAppConfigService->deleteAppConfigValues([$id], $exApp->getAppid()) !== 1) {
			return null;
		}

		return true;
	}

	private function getExAppTalkBotConfig(ExApp $exApp, string $route): array {
		$url = sprintf('%s://%s:%s', $exApp->getProtocol(), $exApp->getHost(), $exApp->getPort()) . $route;
		$id = $this->getExAppTalkBotHash($exApp, $route);

		$exAppConfig = $this->exAppConfigService->getAppConfig($exApp->getAppid(), $id);
		if ($exAppConfig === null) {
			$secret = $this->random->generate(64, ISecureRandom::CHAR_HUMAN_READABLE);
		} else {
			$secret = $exAppConfig->getConfigvalue(); // Do not regenerate already registered bot secret
		}
		return [$id, $url, $secret];
	}

	public function unregisterExAppTalkBots(ExApp $exApp): void {
		$exAppConfigs = $this->exAppConfigService->getAllAppConfig($exApp->getAppid());
		foreach ($exAppConfigs as $exAppConfig) {
			if (str_starts_with($exAppConfig->getConfigkey(), 'talk_bot_route_')) {
				$route = $exAppConfig->getConfigvalue();
				$id = $this->getExAppTalkBotHash($exApp, $route);
				$configHash = substr($exAppConfig->getConfigkey(), 15);
				if ($id === $configHash) {
					$this->unregisterExAppBot($exApp, $route);
					$this->exAppConfigService->deleteAppConfig($exAppConfig);
				}
			}
		}
	}

	private function getExAppTalkBotHash(ExApp $exApp, string $route): string {
		return sha1($exApp->getAppid() . '_' . $route);
	}
}
