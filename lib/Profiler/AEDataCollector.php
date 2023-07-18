<?php

declare(strict_types=1);

/**
 *
 * Nextcloud - App Ecosystem V2
 *
 * @copyright Copyright (c) 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @copyright Copyright (c) 2023 Alexander Piskun <bigcat88@icloud.com>
 *
 * @author 2023 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\AppEcosystemV2\Profiler;

use OC\AppFramework\Http\Request;
use OCP\AppFramework\Http\Response;
use OCP\DataCollector\AbstractDataCollector;

use OCA\AppEcosystemV2\AppInfo\Application;

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
