<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Service;

use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\Db\TalkBot;
use OCA\AppAPI\Db\TalkBotMapper;
use OCA\Talk\Events\BotInstallEvent;
use OCA\Talk\Events\BotUninstallEvent;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Throwable;

readonly class TalkBotsService {

	public function __construct(
		private TalkBotMapper $mapper,
		private IEventDispatcher $dispatcher,
		private ISecureRandom $random,
		private IURLGenerator $urlGenerator,
		private ICrypto $crypto,
		private LoggerInterface $logger,
	) {
	}

	public function registerExAppBot(ExApp $exApp, string $name, string $route, string $description): ?array {
		// Require both events up front — the compensation path on mapper-insert failure dispatches
		// BotUninstallEvent, so we must not enter the function unless both Talk classes are loadable.
		if (!class_exists(BotInstallEvent::class) || !class_exists(BotUninstallEvent::class)) {
			return null;
		}

		$appId = $exApp->getAppid();
		$url = $this->buildProxyUrl($appId, $route);

		$bot = $this->findBot($appId, $route);
		if ($bot !== null) {
			$secret = $this->decryptSecret($bot);
			if ($secret === null) {
				// Auto-recovery would mint a new secret against the same URL, but Talk's BotListener
				// rejects an install event whose URL already exists with a different secret. Surface
				// the failure so an operator can clear the bad row (and Talk's matching one) by hand.
				return null;
			}
		} else {
			$secret = $this->random->generate(64, ISecureRandom::CHAR_HUMAN_READABLE);
		}

		$this->dispatcher->dispatchTyped(new BotInstallEvent($name, $secret, $url, $description));

		// Re-register is a no-op on our side: name+description live in spreed's talk_bots_server and
		// Talk's listener reconciles its row from the BotInstallEvent above. We only insert when
		// minting a fresh bot — the secret and the (appid, route) pair are all we own.
		if ($bot === null) {
			$bot = new TalkBot();
			$bot->setAppid($appId);
			$bot->setRoute($route);
			$bot->setSecret($this->crypto->encrypt($secret));
			$bot->setCreatedTime(time());

			try {
				$this->mapper->insert($bot);
			} catch (Throwable $e) {
				// Talk already persisted the bot via the install event above; without compensation
				// it would dangle as a row pointing at our proxy URL with a secret we never stored.
				try {
					$this->dispatcher->dispatchTyped(new BotUninstallEvent($secret, $url));
				} catch (Throwable $compensationError) {
					$this->logger->error(sprintf(
						'TalkBot register: failed to compensate Talk install for app=%s route=%s after mapper insert error: %s',
						$appId, $route, $compensationError->getMessage(),
					));
				}
				throw $e;
			}
		}

		return [
			'id' => self::getExAppTalkBotHash($appId, $route),
			'secret' => $secret,
		];
	}

	public function unregisterExAppBot(ExApp $exApp, string $route): ?bool {
		if (!class_exists(BotUninstallEvent::class)) {
			return null;
		}

		$bot = $this->findBot($exApp->getAppid(), $route);
		if ($bot === null) {
			return null;
		}

		$secret = $this->decryptSecret($bot);
		if ($secret !== null) {
			$url = $this->buildProxyUrl($exApp->getAppid(), $route);
			$this->dispatcher->dispatchTyped(new BotUninstallEvent($secret, $url));
		}

		$this->mapper->delete($bot);
		return true;
	}

	public function unregisterExAppTalkBots(ExApp $exApp): void {
		try {
			$bots = $this->mapper->findAllByAppid($exApp->getAppid());
		} catch (Throwable $e) {
			$this->logger->error(sprintf('Failed to enumerate TalkBots for ExApp %s: %s', $exApp->getAppid(), $e->getMessage()), ['exception' => $e]);
			return;
		}
		// Isolate per-bot failures so a single deadlock / DB blip / dispatcher throw doesn't leave
		// remaining bots stranded in our table (with Talk's matching row still live) once the
		// owning ExApp row is gone.
		foreach ($bots as $bot) {
			try {
				$this->unregisterExAppBot($exApp, $bot->getRoute());
			} catch (Throwable $e) {
				$this->logger->error(sprintf(
					'Failed to unregister TalkBot for ExApp %s route %s: %s',
					$exApp->getAppid(), $bot->getRoute(), $e->getMessage(),
				), ['exception' => $e]);
			}
		}
	}

	public function getTalkBotSecret(string $appId, string $route): ?string {
		$bot = $this->findBot($appId, $route);
		return $bot === null ? null : $this->decryptSecret($bot);
	}

	/**
	 * Stable opaque ID exposed in the OCS register response. Kept on the sha1
	 * scheme that nc_py_api and existing ExApps already persist as `bot_id`.
	 */
	private static function getExAppTalkBotHash(string $appId, string $route): string {
		return sha1($appId . '_' . $route);
	}

	private function findBot(string $appId, string $route): ?TalkBot {
		try {
			return $this->mapper->findByAppidAndRoute($appId, $route);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	private function decryptSecret(TalkBot $bot): ?string {
		try {
			return $this->crypto->decrypt($bot->getSecret());
		} catch (Throwable $e) {
			$this->logger->error(sprintf(
				'Failed to decrypt TalkBot secret for app=%s route=%s: %s',
				$bot->getAppid(), $bot->getRoute(), $e->getMessage(),
			));
			return null;
		}
	}

	private function buildProxyUrl(string $appId, string $route): string {
		return $this->urlGenerator->linkToOCSRouteAbsolute(
			'app_api.TalkBot.proxyTalkMessage', ['appId' => $appId, 'route' => $route],
		);
	}
}
