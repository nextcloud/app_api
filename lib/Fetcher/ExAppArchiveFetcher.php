<?php

declare(strict_types=1);

namespace OCA\AppAPI\Fetcher;

use Exception;
use OC\Archive\TAR;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\ITempManager;
use phpseclib\File\X509;
use SimpleXMLElement;

/**
 * ExApps release archive fetcher with the same logic as for default (signature check).
 */
class ExAppArchiveFetcher {

	public function __construct(
		private readonly ITempManager   $tempManager,
		private readonly IClientService $clientService,
		private readonly IConfig        $config,
	) {
	}

	/**
	 * Based on regular app download algorithm.
	 * Download ExApp release archive, verify signature extract info.xml and return its object
	 */
	public function downloadInfoXml(array $exAppAppstoreData, bool $extract_l10n = false): ?SimpleXMLElement {
		// 1. Signature check
		if (!$this->checkExAppSignature($exAppAppstoreData)) {
			return null;
		}

		// 2. Download release archive
		$downloadUrl = end($exAppAppstoreData['releases'])['download'];
		$releaseSignature = end($exAppAppstoreData['releases'])['signature'];
		$tempFile = $this->tempManager->getTemporaryFile('.tar.gz');
		$timeout = \OC::$CLI ? 0 : 120;
		$client = $this->clientService->newClient();
		$client->get($downloadUrl, ['sink' => $tempFile, 'timeout' => $timeout]);

		// Validate signature of downloaded archive
		$certificate = openssl_get_publickey($exAppAppstoreData['certificate']);
		$verified = (bool) openssl_verify(file_get_contents($tempFile), base64_decode($releaseSignature), $certificate, OPENSSL_ALGO_SHA512);

		if (!$verified) {
			return null;
		}

		$extractDir = $this->tempManager->getTemporaryFolder();
		$archive = new TAR($tempFile);

		if (!$archive->extract($extractDir)) {
			return null;
		}

		$allFiles = scandir($extractDir);
		$folders = array_diff($allFiles, ['.', '..']);
		$folders = array_values($folders);

		if (count($folders) > 1) {
			return null;
		}

		// 3. Parse info.xml and return its object
		$infoXml = simplexml_load_string(file_get_contents($extractDir . '/' . $folders[0] . '/appinfo/info.xml'));
		if ((string) $infoXml->id !== $exAppAppstoreData['id']) {
			return null;
		}

		if ($extract_l10n) {
			$writableAppPath = $this->getExAppL10NPath($exAppAppstoreData['id']);

			if ($writableAppPath !== null) {
				$extractedL10NPath = $writableAppPath . '/' . $exAppAppstoreData['id'] . '/l10n';
				// Remove old l10n folder and files if exists
				if (file_exists($extractedL10NPath)) {
					rmdir($extractedL10NPath);
				}
				// Move l10n folder from extracted temp to the app folder
				rename($extractDir . '/' . $folders[0] . '/l10n', $extractedL10NPath);
			}
		}

		return $infoXml;
	}

	public function getExAppL10NPath(string $appId): ?string {
		$appsPaths = $this->config->getSystemValue('apps_paths');
		$count = 0;
		$appPaths = [];
		foreach ($appsPaths as $appPath) {
			if (file_exists($appPath['path'] . '/' . $appId)) {
				$count++;
				$appPaths[] = $appPath['path'];
			}
		}

		// Check if there is already app folder with only l10n folder
		// Ensure that there is only one app with the same id in all apps-paths folders
		if ($count > 1) {
			throw new Exception(
				sprintf(
					'App with id %s exists in more than one apps-paths folder (%s)',
					$appId, json_encode($appPaths)
				)
			);
		} elseif ($count === 1) {
			return $appPaths[0] . '/' . $appId . '/l10n';
		} else {
			foreach ($appsPaths as $appPath) {
				if ($appPath['writable']) {
					return $appPath['path'] . '/' . $appId . '/l10n';
				}
			}
		}

		return null;
	}

	public function removeExAppL10NFolder(string $appId): void {
		$appL10NPath = $this->getExAppL10NPath($appId);
		if ($appL10NPath !== null) {
			$extractedL10NPath = $appL10NPath . '/' . $appId . '/l10n';
			if (file_exists($extractedL10NPath)) {
				rmdir($extractedL10NPath);
			}
		}
	}

	/**
	 * @psalm-suppress UndefinedClass
	 */
	private function checkExAppSignature(array $exAppAppstoreData): bool {
		$appId = $exAppAppstoreData['id'];

		$certificate = new X509();
		$rootCrt = file_get_contents(\OC::$SERVERROOT . '/resources/codesigning/root.crt');
		$rootCrts = $this->splitCerts($rootCrt);
		foreach ($rootCrts as $rootCrt) {
			$certificate->loadCA($rootCrt);
		}
		$loadedCertificate = $certificate->loadX509($exAppAppstoreData['certificate']);

		// Verify if the certificate has been revoked
		$crl = new X509();
		foreach ($rootCrts as $rootCrt) {
			$crl->loadCA($rootCrt);
		}
		$crl->loadCRL(file_get_contents(\OC::$SERVERROOT . '/resources/codesigning/root.crl'));
		if ($crl->validateSignature() !== true) {
			throw new Exception('Could not validate CRL signature');
		}
		$csn = $loadedCertificate['tbsCertificate']['serialNumber']->toString();
		$revoked = $crl->getRevoked($csn);
		if ($revoked !== false) {
			throw new Exception(
				sprintf(
					'Certificate "%s" has been revoked',
					$csn
				)
			);
		}

		// Verify if the certificate has been issued by the Nextcloud Code Authority CA
		if ($certificate->validateSignature() !== true) {
			throw new Exception(
				sprintf(
					'App with id %s has a certificate not issued by a trusted Code Signing Authority',
					$appId
				)
			);
		}

		// Verify if the certificate is issued for the requested app id
		$certInfo = openssl_x509_parse($exAppAppstoreData['certificate']);
		if (!isset($certInfo['subject']['CN'])) {
			throw new Exception(
				sprintf(
					'App with id %s has a cert with no CN',
					$appId
				)
			);
		}
		if ($certInfo['subject']['CN'] !== $appId) {
			throw new Exception(
				sprintf(
					'App with id %s has a cert issued to %s',
					$appId,
					$certInfo['subject']['CN']
				)
			);
		}

		return true;
	}

	/**
	 * Split the certificate file in individual certs
	 *
	 * @return string[]
	 */
	private function splitCerts(string $cert): array {
		preg_match_all('([\-]{3,}[\S\ ]+?[\-]{3,}[\S\s]+?[\-]{3,}[\S\ ]+?[\-]{3,})', $cert, $matches);

		return $matches[0];
	}
}
