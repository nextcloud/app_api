<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Db\SpeechToText\SpeechToTextProviderQueueMapper;
use OCA\AppAPI\Service\ProvidersAI\SpeechToTextService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\DB\Exception;
use OCP\IConfig;
use OCP\IRequest;

class SpeechToTextController extends OCSController {
	protected $request;

	public function __construct(
		IRequest $request,
		private readonly SpeechToTextService $speechToTextService,
		private readonly IConfig             $config,
		private readonly SpeechToTextProviderQueueMapper $mapper,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AppAPIAuth]
	public function registerProvider(string $name, string $displayName, string $actionHandler): DataResponse {
		$ncVersion = $this->config->getSystemValueString('version', '0.0.0');
		if (version_compare($ncVersion, '29.0', '<')) {
			return new DataResponse([], Http::STATUS_NOT_IMPLEMENTED);
		}
		$provider = $this->speechToTextService->registerSpeechToTextProvider(
			$this->request->getHeader('EX-APP-ID'), $name, $displayName, $actionHandler);
		if ($provider === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse();
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AppAPIAuth]
	public function unregisterProvider(string $name): DataResponse {
		$ncVersion = $this->config->getSystemValueString('version', '0.0.0');
		if (version_compare($ncVersion, '29.0', '<')) {
			return new DataResponse([], Http::STATUS_NOT_IMPLEMENTED);
		}
		$unregistered = $this->speechToTextService->unregisterSpeechToTextProvider(
			$this->request->getHeader('EX-APP-ID'), $name);
		if ($unregistered === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse();
	}

	#[AppAPIAuth]
	#[PublicPage]
	#[NoCSRFRequired]
	public function getProvider(string $name): DataResponse {
		$ncVersion = $this->config->getSystemValueString('version', '0.0.0');
		if (version_compare($ncVersion, '29.0', '<')) {
			return new DataResponse([], Http::STATUS_NOT_IMPLEMENTED);
		}
		$result = $this->speechToTextService->getExAppSpeechToTextProvider(
			$this->request->getHeader('EX-APP-ID'), $name);
		if (!$result) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($result, Http::STATUS_OK);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AppAPIAuth]
	public function reportResult(int $taskId, string $result, string $error = ""): DataResponse {
		$ncVersion = $this->config->getSystemValueString('version', '0.0.0');
		if (version_compare($ncVersion, '29.0', '<')) {
			return new DataResponse([], Http::STATUS_NOT_IMPLEMENTED);
		}
		try {
			$taskResult = $this->mapper->getById($taskId);
		} catch (DoesNotExistException) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		} catch (MultipleObjectsReturnedException | Exception) {
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		$taskResult->setResult($result);
		$taskResult->setError($error);
		$taskResult->setFinished(1);
		try {
			$this->mapper->update($taskResult);
		} catch (Exception) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse([], Http::STATUS_OK);
	}
}
