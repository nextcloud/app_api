/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import AdminSettings from './components/AdminSettings.vue'
import { generateFilePath } from '@nextcloud/router'

// eslint-disable-next-line
__webpack_public_path__ = generateFilePath('app_api', '', 'js/')

const app = createApp(AdminSettings)
app.mixin({ methods: { t, n } })
app.mount('#app_api_settings')
