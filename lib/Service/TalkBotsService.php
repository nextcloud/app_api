<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Service;

use OCA\AppEcosystemV2\Db\ExApp;
use OCA\Talk\Events\BotInstallEvent;
use OCA\Talk\Events\BotUninstallEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Security\ISecureRandom;

class TalkBotsService {
	private ExAppConfigService $exAppConfigService;
	private AppEcosystemV2Service $service;
	private IEventDispatcher $dispatcher;
	private ISecureRandom $random;

	public function __construct(
		ExAppConfigService $exAppConfigService,
		AppEcosystemV2Service $service,
		IEventDispatcher $dispatcher,
		ISecureRandom $random,
	) {
		$this->exAppConfigService = $exAppConfigService;
		$this->service = $service;
		$this->dispatcher = $dispatcher;
		$this->random = $random;
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

		return [
			'id' => $id,
			'secret' => $secret,
		];
	}

	private function getExAppTalkBotConfig(ExApp $exApp, string $route): array {
		$url = $this->service->getExAppUrl($exApp->getProtocol(), $exApp->getHost(), $exApp->getPort()) . $route;
		$id = sha1($exApp->getAppid() . '_' . $route);

		$exAppConfig = $this->exAppConfigService->getAppConfig($exApp->getAppid(), $id);
		if ($exAppConfig === null) {
			$secret = $this->random->generate(64, ISecureRandom::CHAR_HUMAN_READABLE);
		} else {
			$secret = $exAppConfig->getConfigvalue(); // Do not regenerate already registered bot secret
		}
		return [$id, $url, $secret];
	}

	public function unregisterExAppTalkBots(ExApp $exApp): void {
		// TODO: Find out a way to get registered ExApp talk bots
	}
}
