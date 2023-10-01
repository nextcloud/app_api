import Vue from 'vue'
import Vuex, { Store } from 'vuex'
import { showError } from '@nextcloud/dialogs'

import apps from './apps.js'
import settings from './settings.js'

Vue.use(Vuex)

const mutations = {
	API_FAILURE(state, error) {
		try {
			const message = error.error.response.data.ocs.meta.message
			showError(t('settings', 'An error occurred during the request. Unable to proceed.') + '<br>' + message, { isHTML: true })
		} catch (e) {
			showError(t('settings', 'An error occurred during the request. Unable to proceed.'))
		}
		console.error(state, error)
	},
}

export default new Store({
	modules: {
		apps,
		settings,
	},

	strict: process.env.NODE_ENV !== 'production',

	mutations,
})
