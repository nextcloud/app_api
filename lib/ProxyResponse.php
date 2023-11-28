<?php

declare(strict_types=1);

namespace OCA\AppAPI;

use OCP\AppFramework\Http as HttpAlias;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

/** @template-extends Response<int, array<string, mixed>> */
class ProxyResponse extends Response implements ICallbackResponse {
	private mixed $data;

	public function __construct(int $status = HttpAlias::STATUS_OK,
								array $headers = [], mixed $data = null, int $length = 0,
								string $mimeType = '', int $lastModified = 0) {
		parent::__construct();
		$this->data = $data;
		$this->setStatus($status);
		$this->setHeaders(array_merge($this->getHeaders(), $headers));
		$this->addHeader('Content-Length', (string)$length);
		if (!empty($mimeType)) {
			$this->addHeader('Content-Type', $mimeType);
		}
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
