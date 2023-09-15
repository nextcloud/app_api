import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const state = {
	exapps: [],
	categories: [],
}

const mutations = {
	setAllExApps(state, apps) {
		state.exapps = apps
	},
	setAllCategories(state, categories) {
		state.categories = categories
	},
}

const getters = {
	apps: state => state.exapps,
}

const actions = {
	getAllExApps(context) {
		return axios.get(generateUrl('/apps/app_api/ex-apps'))
			.then(res => {
				context.commit('setAllExApps', res.data.apps)
			})
	},

	getAllCategories(context) {
		return axios.get(generateUrl('/apps/app_api/ex-apps/categories'))
			.then(res => {
				context.commit('setAllCategories', res.data.categories)
			})
	},
}

export default { state, mutations, getters, actions }
