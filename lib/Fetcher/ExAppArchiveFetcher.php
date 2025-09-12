<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Fetcher;

use Exception;
use OC\Archive\TAR;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\ITempManager;
use phpseclib\File\X509;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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
	public function downloadInfoXml(array $exAppAppstoreData, string &$extractedDir): ?SimpleXMLElement {
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
		if (openssl_verify(file_get_contents($tempFile), base64_decode($releaseSignature), $certificate, OPENSSL_ALGO_SHA512) !== 1) {
			return null;
		}

		$extractDir = $this->tempManager->getTemporaryFolder();
		$archive = new TAR($tempFile);

		if (!$archive->extract($extractDir)) {
			return null;
		}

		$allFiles = scandir($extractDir);
		$folders = array_values(array_diff($allFiles, ['.', '..']));
		if (count($folders) > 1) {
			return null;
		}

		// 3. Parse info.xml and return its object
		$infoXml = simplexml_load_string(file_get_contents($extractDir . '/' . $folders[0] . '/appinfo/info.xml'));
		if ((string) $infoXml->id !== $exAppAppstoreData['id']) {
			return null;
		}
		$extractedDir = $extractDir . '/' . $folders[0];
		return $infoXml;
	}

	public function installTranslations(string $appId, string $dirTranslations): string {
		if (!file_exists($dirTranslations)) {
			return sprintf('Can not access directory: %s', $dirTranslations);
		}
		$writableAppPath = $this->getExAppFolder($appId);
		if (!$writableAppPath) {
			return 'Can not find writable apps path to perform installation.';
		}

		$installL10NPath = $writableAppPath . '/l10n';
		if (file_exists($installL10NPath)) {
			$this->rmdirr($installL10NPath);  // Remove old l10n folder and files if exists
		}
		$this->copyr($dirTranslations, $installL10NPath);
		return '';
	}

	public function getExAppFolder(string $appId): ?string {
		$appsPaths = $this->config->getSystemValue('apps_paths', []);
		foreach ($appsPaths as $appPath) {
			if ($appPath['writable']) {
				$fullAppPath = $appPath['path'] . '/' . $appId;
				if (is_dir($fullAppPath) || mkdir($fullAppPath)) {
					return $fullAppPath;
				}
			}
		}
		// Fallback to default ExApp folder
		$defaultExAppFolder = \OC::$SERVERROOT . '/apps/' . $appId;
		if (is_dir($defaultExAppFolder)) {
			return $defaultExAppFolder;
		}
		return null;
	}

	public function removeExAppFolder(string $appId): void {
		$appsPaths = $this->config->getSystemValue('apps_paths', []);
		if (empty($appsPaths)) {
			// fallback check of default ExApp folder
			$defaultExAppFolder = \OC::$SERVERROOT . '/apps/' . $appId;
			if (is_dir($defaultExAppFolder)) {
				$this->rmdirr($defaultExAppFolder);
			}
			return;
		}
		foreach ($appsPaths as $appPath) {
			if ($appPath['writable'] && is_dir($appPath['path'] . '/' . $appId)) {
				$this->rmdirr($appPath['path'] . '/' . $appId);
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

	public function rmdirr(string $dir, bool $deleteSelf = true): bool {
		if (is_dir($dir)) {
			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
				RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ($files as $fileInfo) {
				if ($fileInfo->isLink()) {
					unlink($fileInfo->getPathname());
				} elseif ($fileInfo->isDir()) {
					rmdir($fileInfo->getRealPath());
				} else {
					unlink($fileInfo->getRealPath());
				}
			}
			if ($deleteSelf) {
				rmdir($dir);
			}
		} elseif (file_exists($dir)) {
			if ($deleteSelf) {
				unlink($dir);
			}
		}
		if (!$deleteSelf) {
			return true;
		}

		return !file_exists($dir);
	}

	public function copyr(string $src, string $dest): void {
		if (is_dir($src)) {
			if (!is_dir($dest)) {
				mkdir($dest);
			}
			$files = scandir($src);
			foreach ($files as $file) {
				if ($file != "." && $file != "..") {
					self::copyr("$src/$file", "$dest/$file");
				}
			}
		} elseif (file_exists($src)) {
			copy($src, $dest);
		}
	}
}
