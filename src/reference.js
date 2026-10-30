/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { loadState } from '@nextcloud/initial-state'
import { loadExAppPickers } from './referenceLoader.js'

loadExAppPickers(loadState('app_api', 'ex_reference_pickers', []), window, document)
