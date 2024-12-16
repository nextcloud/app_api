<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Controller;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Attribute\AppAPIAuth;
use OCA\AppAPI\Db\TextProcessing\TextProcessingProviderQueueMapper;
use OCA\AppAPI\Service\ProvidersAI\TextProcessingService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCSController;
use OCP\DB\Exception;
use OCP\IConfig;
use OCP\IRequest;

class TextProcessingController extends OCSController {
	protected $request;

	public function __construct(
		IRequest                               			   $request,
		private readonly IConfig               			   $config,
		private readonly TextProcessingService			   $textProcessingService,
		private readonly TextProcessingProviderQueueMapper $mapper,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->request = $request;
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AppAPIAuth]
	public function registerProvider(
		string $name,
		string $displayName,
		string $actionHandler,
		string $taskType
	): DataResponse {
		$ncVersion = $this->config->getSystemValueString('version', '0.0.0');
		if (version_compare($ncVersion, '29.0', '<')) {
			return new DataResponse([], Http::STATUS_NOT_IMPLEMENTED);
		}

		$provider = $this->textProcessingService->registerTextProcessingProvider(
			$this->request->getHeader('EX-APP-ID'), $name, $displayName, $actionHandler, $taskType,
		);

		if ($provider === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse();
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AppAPIAuth]
	public function unregisterProvider(string $name): Response {
		$ncVersion = $this->config->getSystemValueString('version', '0.0.0');
		if (version_compare($ncVersion, '29.0', '<')) {
			return new DataResponse([], Http::STATUS_NOT_IMPLEMENTED);
		}

		$unregistered = $this->textProcessingService->unregisterTextProcessingProvider(
			$this->request->getHeader('EX-APP-ID'), $name
		);

		if ($unregistered === null) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse();
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[AppAPIAuth]
	public function getProvider(string $name): DataResponse {
		$ncVersion = $this->config->getSystemValueString('version', '0.0.0');
		if (version_compare($ncVersion, '29.0', '<')) {
			return new DataResponse([], Http::STATUS_NOT_IMPLEMENTED);
		}
		$result = $this->textProcessingService->getExAppTextProcessingProvider(
			$this->request->getHeader('EX-APP-ID'), $name
		);
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
