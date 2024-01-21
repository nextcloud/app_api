<?php

declare(strict_types=1);

namespace OCA\AppAPI\Controller;

use OC\Security\CSP\ContentSecurityPolicyNonceManager;
use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\ProxyResponse;
use OCA\AppAPI\Service\AppAPIService;
use OCA\AppAPI\Service\ExAppService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\IMimeTypeDetector;
use OCP\Http\Client\IResponse;
use OCP\IRequest;

class ExAppProxyController extends Controller {

	public function __construct(
		IRequest                                           $request,
		private readonly AppAPIService                     $service,
		private readonly ExAppService					   $exAppService,
		private readonly IMimeTypeDetector                 $mimeTypeHelper,
		private readonly ContentSecurityPolicyNonceManager $nonceManager,
		private readonly ?string                           $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function createProxyResponse(string $path, IResponse $response, $cache = true): ProxyResponse {
		$content = $response->getBody();
		$isHTML = pathinfo($path, PATHINFO_EXTENSION) === 'html';
		if ($isHTML) {
			$nonce = $this->nonceManager->getNonce();
			$content = str_replace(
				'<script',
				"<script nonce=\"$nonce\"",
				$content
			);
		}

		$mime = $response->getHeader('content-type');
		if (empty($mime)) {
			$mime = $this->mimeTypeHelper->detectPath($path);
			if (pathinfo($path, PATHINFO_EXTENSION) === 'wasm') {
				$mime = 'application/wasm';
			}
		}

		$proxyResponse = new ProxyResponse(
			data: $content,
			length: strlen($content),
			mimeType: $mime,
		);

		$headersToCopy = ['Content-Disposition', 'Last-Modified', 'Etag'];
		foreach ($headersToCopy as $element) {
			$headerValue = $response->getHeader($element);
			if (empty($headerValue)) {
				$proxyResponse->addHeader($element, $headerValue);
			}
		}

		if ($cache && !$isHTML) {
			$proxyResponse->cacheFor(3600);
		}
		return $proxyResponse;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ExAppGet(string $appId, string $other): Response {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null) {
			return new NotFoundResponse();
		}
		if (!$exApp->getEnabled()) {
			return new NotFoundResponse();
		}

		$response = $this->service->requestToExApp(
			$exApp, '/' . $other, $this->userId, 'GET', request: $this->request
		);
		if (is_array($response)) {
			$error_response = new Response();
			return $error_response->setStatus(500);
		}
		return $this->createProxyResponse($other, $response);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ExAppPost(string $appId, string $other): Response {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null) {
			return new NotFoundResponse();
		}
		if (!$exApp->getEnabled()) {
			return new NotFoundResponse();
		}

		$response = $this->service->aeRequestToExApp(
			$exApp, '/' . $other, $this->userId,
			params: $this->request->getParams(),
			request: $this->request
		);
		if (is_array($response)) {
			$error_response = new Response();
			return $error_response->setStatus(500);
		}
		return $this->createProxyResponse($other, $response);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ExAppPut(string $appId, string $other): Response {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null) {
			return new NotFoundResponse();
		}
		if (!$exApp->getEnabled()) {
			return new NotFoundResponse();
		}

		$response = $this->service->aeRequestToExApp(
			$exApp, '/' . $other, $this->userId, 'PUT',
			params: $this->request->getParams(),
			request: $this->request
		);
		if (is_array($response)) {
			$error_response = new Response();
			return $error_response->setStatus(500);
		}
		return $this->createProxyResponse($other, $response);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ExAppDelete(string $appId, string $other): Response {
		$exApp = $this->exAppService->getExApp($appId);
		if ($exApp === null) {
			return new NotFoundResponse();
		}
		if (!$exApp->getEnabled()) {
			return new NotFoundResponse();
		}

		$response = $this->service->aeRequestToExApp(
			$exApp, '/' . $other, $this->userId, 'DELETE',
			params: $this->request->getParams(),
			request: $this->request
		);
		if (is_array($response)) {
			$error_response = new Response();
			return $error_response->setStatus(500);
		}
		return $this->createProxyResponse($other, $response);
	}
}
