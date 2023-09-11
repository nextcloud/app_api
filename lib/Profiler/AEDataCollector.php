<?php

declare(strict_types=1);

namespace OCA\AppAPI\Profiler;

use OC\AppFramework\Http\Request;

use OCA\AppAPI\AppInfo\Application;

use OCP\AppFramework\Http\Response;
use OCP\DataCollector\AbstractDataCollector;

/**
 * @psalm-suppress UndefinedClass
 */
class AEDataCollector extends AbstractDataCollector {
	public function getName(): string {
		return Application::APP_ID;
	}

	public function collect(Request $request, Response $response, \Throwable $exception = null): void {
		//		TODO: Check why DAV requests missing AE headers data
		$headers = [];
		$aeHeadersList = [
			'AE-VERSION',
			'EX-APP-ID',
			'EX-APP-VERSION',
			'NC-USER-ID',
			'AE-DATA-HASH',
			'AE-SIGN-TIME',
			'AE-SIGNATURE',
			'AE-REQUEST-ID',
		];
		foreach ($aeHeadersList as $header) {
			if ($request->getHeader($header) !== '') {
				$headers[$header] = $request->getHeader($header);
			}
		}
		if (!empty($headers)) {
			$this->data = [
				'headers' => $headers,
			];
		}
	}
}
