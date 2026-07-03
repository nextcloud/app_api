<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\SetupChecks;

/**
 * Surfaces ExApp setup checks reported with severity "warning" (including a "not responding" ExApp).
 * {@see AbstractExAppsSetupCheck}
 */
class ExAppsWarningSetupCheck extends AbstractExAppsSetupCheck {
	public function getName(): string {
		return $this->l10n->t('External Apps (Warnings)');
	}

	protected function severity(): string {
		return 'warning';
	}
}
