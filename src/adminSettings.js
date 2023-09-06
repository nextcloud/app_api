import Vue from 'vue'
import './bootstrap.js'
import AdminSettings from './components/AdminSettings.vue'

// eslint-disable-next-line
'use strict'

// eslint-disable-next-line
new Vue({
	el: '#app_api_settings',
	render: h => h(AdminSettings),
})
