<?php

declare(strict_types=1);

namespace OCA\AppAPI\Fetcher;

use OC\Archive\TAR;
use OCP\Http\Client\IClientService;
use OCP\ITempManager;
use phpseclib\File\X509;

/**
 * ExApps release archive fetcher with the same logic as for default (signature check).
 */
class ExAppArchiveFetcher {

	public function __construct(
		private ITempManager $tempManager,
		private IClientService $clientService,
	) {
	}

	/**
	 * Based on regular app download algorithm.
	 * Download ExApp release archive, verify signature extract info.xml and return its object
	 *
	 * @param array $exAppAppstoreData
	 *
	 * @return \SimpleXMLElement|null
	 */
	public function downloadInfoXml(array $exAppAppstoreData): ?\SimpleXMLElement {
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

		return $infoXml;
	}

	/**
	 * @psalm-suppress UndefinedClass
	 *
	 * @param array $exAppAppstoreData
	 *
	 * @return bool
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
			throw new \Exception('Could not validate CRL signature');
		}
		$csn = $loadedCertificate['tbsCertificate']['serialNumber']->toString();
		$revoked = $crl->getRevoked($csn);
		if ($revoked !== false) {
			throw new \Exception(
				sprintf(
					'Certificate "%s" has been revoked',
					$csn
				)
			);
		}

		// Verify if the certificate has been issued by the Nextcloud Code Authority CA
		if ($certificate->validateSignature() !== true) {
			throw new \Exception(
				sprintf(
					'App with id %s has a certificate not issued by a trusted Code Signing Authority',
					$appId
				)
			);
		}

		// Verify if the certificate is issued for the requested app id
		$certInfo = openssl_x509_parse($exAppAppstoreData['certificate']);
		if (!isset($certInfo['subject']['CN'])) {
			throw new \Exception(
				sprintf(
					'App with id %s has a cert with no CN',
					$appId
				)
			);
		}
		if ($certInfo['subject']['CN'] !== $appId) {
			throw new \Exception(
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
	 * @param string $cert
	 * @return string[]
	 */
	private function splitCerts(string $cert): array {
		preg_match_all('([\-]{3,}[\S\ ]+?[\-]{3,}[\S\s]+?[\-]{3,}[\S\ ]+?[\-]{3,})', $cert, $matches);

		return $matches[0];
	}
}
