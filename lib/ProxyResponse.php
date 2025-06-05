<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI;

use OCP\AppFramework\Http as HttpAlias;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

/** @template-extends Response<HttpAlias::STATUS_*, array<string, mixed>> */
class ProxyResponse extends Response implements ICallbackResponse {
	private mixed $data;

	public function __construct(int $status = HttpAlias::STATUS_OK,
		array $headers = [], mixed $data = null, int $lastModified = 0) {
		parent::__construct();
		$this->data = $data;
		$this->setStatus($status);
		$this->setHeaders(array_merge($this->getHeaders(), $headers));
		if ($lastModified !== 0) {
			$lastModifiedDate = new \DateTime();
			$lastModifiedDate->setTimestamp($lastModified);
			$this->setLastModified($lastModifiedDate);
		}
	}

	public function callback(IOutput $output): void {
		if ($output->getHttpResponseCode() !== HttpAlias::STATUS_NOT_MODIFIED) {
			if (is_resource($this->data)) {
				fpassthru($this->data);
			} else {
				print $this->data;
			}
		}
	}
}
