<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Service;

use OCA\AppEcosystemV2\Db\ExApp;
use OCA\Talk\Events\BotInstallEvent;
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

	/**
	 * @param ExApp $exApp
	 * @param string $id
	 *
	 * @return bool
	 */
	public function unregisterExAppBot(ExApp $exApp, string $id): bool {
		// TODO: Not possible to unregister for now
		return false;
	}

	public function registerExAppBot(ExApp $exApp, string $name, string $route, string $description): ?array {
		if (!class_exists(BotInstallEvent::class)) {
			return null;
		}

		$url = $this->service->getExAppUrl($exApp->getProtocol(), $exApp->getHost(), $exApp->getPort()) . $route;
		$id = sha1($url);

		$exAppConfig = $this->exAppConfigService->getAppConfig($exApp->getAppid(), $id);
		if ($exAppConfig === null) {
			$secret = $this->random->generate(64, ISecureRandom::CHAR_HUMAN_READABLE);
		} else {
			$botConfig = json_decode($exAppConfig->getConfigvalue(), true);
			$secret = $botConfig['secret']; // Do not regenerate already registered bot secret
		}

		$event = new BotInstallEvent(
			$name,
			$secret,
			$url,
			$description,
		);

		$this->dispatcher->dispatchTyped($event);

		$this->exAppConfigService->setAppConfigValue($exApp->getAppid(), $id, json_encode([
			'secret' => $secret
		]));

		return [
			'id' => $id,
			'secret' => $secret,
		];
	}
}
