<?php

declare(strict_types=1);

namespace OCA\AppAPI\Service;

use OCA\AppAPI\AppInfo\Application;
use OCA\AppAPI\Db\UI\StyleMapper;
use OCP\DB\Exception;
use OCP\IURLGenerator;
use OCP\Util;
use Psr\Log\LoggerInterface;

class ExAppStylesService {

	public function __construct(
		private StyleMapper $mapper,
		private IURLGenerator $url,
		private LoggerInterface $logger,
	) {
	}

	public function addExAppStyle(string $appId, string $type, string $path) {
		//	TODO
	}

	public function clearExAppStyles(string $appId, string $type) {
		//	TODO
	}

	/**
	 * @throws Exception
	 */
	public function applyExAppStyles(string $appId, string $type): void {
		// TODO: Add caching
		$styles = $this->mapper->findByAppIdType($appId, $type);
		foreach ($styles as $value) {
			if (str_starts_with($value['path'], '/')) {
				// in the future we should allow offload of styles to the NC instance if they start with '/'
				$path = 'proxy/'. $appId . $value['path'];
			} else {
				$path = 'proxy/'. $appId . '/' . $value['path'];
			}
			Util::addStyle(Application::APP_ID, $path);
		}
	}
}
