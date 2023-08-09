<?php

declare(strict_types=1);

namespace OCA\AppEcosystemV2\Controller;

use OCA\AppEcosystemV2\AppInfo\Application;
use OCA\AppEcosystemV2\DeployActions\DockerActions;
use OCA\AppEcosystemV2\DeployActions\ManualActions;
use OCA\AppEcosystemV2\Service\AppEcosystemV2Service;

use OCA\AppEcosystemV2\Service\DaemonConfigService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;

/**
 * ExApp actions draft (for UI)
 */
class ExAppController extends ApiController {
	private IL10N $l10n;
	private AppEcosystemV2Service $service;
	private DaemonConfigService $daemonConfigService;
	private DockerActions $dockerActions;
	private ManualActions $manualActions;

	public function __construct(
		IRequest $request,
		IL10N $l10n,
		AppEcosystemV2Service $service,
		DaemonConfigService $daemonConfigService,
		DockerActions $dockerActions,
		ManualActions $manualActions,
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->l10n = $l10n;
		$this->service = $service;
		$this->daemonConfigService = $daemonConfigService;
		$this->dockerActions = $dockerActions;
		$this->manualActions = $manualActions;
	}

	/**
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param array $params
	 *
	 * @return Response
	 */
	#[NoCSRFRequired]
	public function registerExApp(string $appId, array $params): Response {
		if ($this->service->getExApp($appId) !== null) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('ExApp already registered'),
			], Http::STATUS_BAD_REQUEST);
		}

		$daemonConfig = $this->daemonConfigService->getDaemonConfigByName($params['daemon_config_name']);
		if ($daemonConfig === null) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('DaemonConfig not found'),
			], Http::STATUS_BAD_REQUEST);
		}

		if ($daemonConfig->getAcceptsDeployId() === $this->dockerActions->getAcceptsDeployId()) {
			$exAppInfo = $this->dockerActions->loadExAppInfo($appId, $daemonConfig);
		} elseif ($daemonConfig->getAcceptsDeployId() === $this->manualActions->getAcceptsDeployId()) {
			if (!isset($params['json-info'])) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('ExApp JSON info is required for manual deploy.'),
				], Http::STATUS_BAD_REQUEST);
			}
			// Assuming that ExApp info json already built (e.g. from UI form)
			$exAppInfo = $params['json_info'];
		}

		if (empty($exAppInfo)) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Failed to load ExApp info'),
			], Http::STATUS_BAD_REQUEST);
		}

		$exApp = $this->service->registerExApp($appId, [
			'version' => $exAppInfo['version'],
			'name' => $exAppInfo['name'],
			'daemon_config_name' => $params['daemon_config_name'],
			'protocol' => $exAppInfo['protocol'] ?? 'http',
			'host' => $exAppInfo['host'],
			'port' => (int) $exAppInfo['port'],
			'secret' => $exAppInfo['secret'],
		]);

		if ($exApp === null) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Failed to register ExApp'),
			], Http::STATUS_BAD_REQUEST);
		}

		// Assuming that further work with scopes approval is done in separate place and algorithm in UI
		return new JSONResponse([
			'success' => true,
			'message' => $this->l10n->t('ExApp successfully registered'),
		], Http::STATUS_OK);
	}

	/**
	 * @NoCSRFRequired
	 *
	 * @param string $appId
	 * @param bool $silent
	 *
	 * @return Response
	 */
	#[NoCSRFRequired]
	public function unregisterExApp(string $appId, bool $silent = false): Response {
		$exApp = $this->service->getExApp($appId);
		if ($exApp === null) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('ExApp not found'),
			]);
		}

		if (!$silent) {
			if (!$this->service->disableExApp($exApp)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Failed to disable ExApp'),
				], Http::STATUS_BAD_REQUEST);
			}
		}

		$exApp = $this->service->unregisterExApp($appId);
		if ($exApp === null) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Failed to unregister ExApp'),
			], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse([
			'success' => true,
			'message' => $this->l10n->t('ExApp successfully unregistered'),
		], Http::STATUS_OK);
	}

	/**
	 * @NoCSRFRequired
	 */
	#[NoCSRFRequired]
	public function updateExApp(string $appId): Response {
		$exApp = $this->service->getExApp($appId);
		// TODO: Replicate algorithm with adjustments for UI workflow
		return new DataResponse();
	}
}
