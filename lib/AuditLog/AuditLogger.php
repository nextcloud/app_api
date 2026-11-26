<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\AuditLog;

use InvalidArgumentException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;

/**
 * Writes entries to the admin audit log
 */
class AuditLogger {
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
	) {
	}

	/**
	 * @param string $message message with a `%s` placeholder for each of the first parameters
	 * @param array<string, mixed> $parameters named values for the placeholders of $message, in order, optionally followed by more context
	 */
	public function log(Operation $operation, string $message, array $parameters = []): void {
		$this->dispatch($operation->value, $message, $parameters);
	}

	/**
	 * Writes an entry on behalf of an ExApp, whose ID is added as the `appId` parameter
	 *
	 * @param string $operation operation in the form `<appId>.entity.action`, the first part being the ID of the ExApp
	 * @param string $message message with a `%s` placeholder for each of the first parameters
	 * @param array<string, scalar|null> $parameters named values for the placeholders of $message, in order, optionally followed by more context
	 * @throws InvalidArgumentException if the app ID is empty, the operation does not start with the app ID or is not in the form `<appId>.entity.action`, a parameter is not a scalar or null, or the message does not fit the parameters
	 */
	public function logForExApp(string $appId, string $operation, string $message, array $parameters = []): void {
		if ($appId === '') {
			throw new InvalidArgumentException('App ID must not be empty');
		}
		if (!str_starts_with($operation, $appId . '.')) {
			throw new InvalidArgumentException(sprintf('Operation must be in the form %s.entity.action', $appId));
		}
		if (array_key_exists('appId', $parameters)) {
			throw new InvalidArgumentException('Parameter appId is reserved');
		}
		foreach ($parameters as $name => $value) {
			if (!is_string($name) || ($value !== null && !is_scalar($value))) {
				throw new InvalidArgumentException('Parameters must be named and be scalars or null');
			}
		}
		$parameters['appId'] = $appId;
		try {
			$this->dispatch($operation, self::escape($message), $parameters);
		} catch (\ValueError $e) {
			throw new InvalidArgumentException('Message does not fit the parameters: ' . $e->getMessage(), 0, $e);
		}
	}

	private function dispatch(string $operation, string $message, array $parameters): void {
		// The logger replaces `{placeholders}` in the message and admin_audit formats it again, so values are escaped here
		$values = array_map(static fn (mixed $value): mixed => is_string($value) ? self::escape($value) : $value, array_values($parameters));
		$message = str_replace('%', '%%', vsprintf($message, $values));
		$this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent($message, $parameters, false, $operation));
	}

	private static function escape(string $value): string {
		return strtr($value, ['{' => '(', '}' => ')']);
	}
}
