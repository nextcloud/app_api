<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\AuditLog;

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
		// The logger replaces `{placeholders}` in the message and admin_audit formats it again, so values are escaped here
		$values = array_map(static fn (mixed $value): mixed => is_string($value) ? strtr($value, ['{' => '(', '}' => ')']) : $value, array_values($parameters));
		$message = str_replace('%', '%%', vsprintf($message, $values));
		$this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent($message, $parameters, false, $operation->value));
	}
}
