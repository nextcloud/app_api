import Vue from 'vue'
import './bootstrap.js'
import AdminSettings from './components/AdminSettings.vue'
import { generateFilePath } from '@nextcloud/router'

// eslint-disable-next-line
__webpack_public_path__ = generateFilePath(appName, '', 'js/')

// eslint-disable-next-line
'use strict'

// eslint-disable-next-line
new Vue({
	el: '#app_api_settings',
	render: h => h(AdminSettings),
})
