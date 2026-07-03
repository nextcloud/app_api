<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\SetupChecks;

/**
 * Surfaces ExApp setup checks reported with severity "error". {@see AbstractExAppsSetupCheck}
 */
class ExAppsErrorSetupCheck extends AbstractExAppsSetupCheck {
	public function getName(): string {
		return $this->l10n->t('External Apps (Errors)');
	}

	protected function severity(): string {
		return 'error';
	}
}
