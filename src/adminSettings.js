/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import Vue from 'vue'
import './bootstrap.js'
import AdminSettings from './components/AdminSettings.vue'
import { generateFilePath } from '@nextcloud/router'
import { Tooltip } from '@nextcloud/vue'

Vue.directive('tooltip', Tooltip)

// eslint-disable-next-line
__webpack_public_path__ = generateFilePath(appName, '', 'js/')

// eslint-disable-next-line
'use strict'

// eslint-disable-next-line
new Vue({
	el: '#app_api_settings',
	render: h => h(AdminSettings),
})
