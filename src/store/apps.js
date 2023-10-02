import api from './api.js'
import Vue from 'vue'
import { generateUrl } from '@nextcloud/router'
import { showError, showInfo } from '@nextcloud/dialogs'
import '@nextcloud/dialogs/dist/index.css'

const state = {
	apps: [],
	categories: [],
	updateCount: 0,
	loading: {},
	loadingList: false,
	gettingCategoriesPromise: null,
	defaultDaemonName: '',
	daemon: null,
}

const mutations = {

	APPS_API_FAILURE(state, error) {
		showError(t('app_api', 'An error occurred during the request. Unable to proceed.') + '<br>' + error.error.response.data.data.message, { isHTML: true })
		console.error(state, error)
	},

	initCategories(state, { categories, updateCount }) {
		state.categories = categories
		state.updateCount = updateCount
	},

	updateCategories(state, categoriesPromise) {
		state.gettingCategoriesPromise = categoriesPromise
	},

	setUpdateCount(state, updateCount) {
		state.updateCount = updateCount
	},

	addCategory(state, category) {
		state.categories.push(category)
	},

	appendCategories(state, categoriesArray) {
		// convert obj to array
		state.categories = categoriesArray
	},

	setAllApps(state, apps) {
		state.apps = apps
	},

	setError(state, { appId, error }) {
		if (!Array.isArray(appId)) {
			appId = [appId]
		}
		appId.forEach((_id) => {
			const app = state.apps.find(app => app.id === _id)
			app.error = error
		})
	},

	clearError(state, { appId, error }) {
		const app = state.apps.find(app => app.id === appId)
		app.error = null
	},

	enableApp(state, { appId, groups, daemon, systemApp, exAppUrl }) {
		const app = state.apps.find(app => app.id === appId)
		if (daemon) {
			app.installed = true
			app.needsDownload = false
			app.daemon = daemon
		}
		if (systemApp) {
			app.systemApp = systemApp
		}
		if (exAppUrl) {
			app.exAppUrl = exAppUrl
		}
		app.active = true
		app.groups = groups
		app.canUnInstall = false
	},

	disableApp(state, appId) {
		const app = state.apps.find(app => app.id === appId)
		app.active = false
		app.groups = []
		if (app.removable) {
			app.canUnInstall = true
		}
	},

	uninstallApp(state, appId) {
		state.apps.find(app => app.id === appId).active = false
		state.apps.find(app => app.id === appId).groups = []
		state.apps.find(app => app.id === appId).needsDownload = true
		state.apps.find(app => app.id === appId).installed = false
		state.apps.find(app => app.id === appId).canUnInstall = false
		state.apps.find(app => app.id === appId).canInstall = true
		state.apps.find(app => app.id === appId).daemon = null
		if (state.apps.find(app => app.id === appId).update !== null) {
			state.updateCount--
		}
		state.apps.find(app => app.id === appId).update = null
	},

	updateApp(state, { appId, systemApp, exAppUrl }) {
		const app = state.apps.find(app => app.id === appId)
		const version = app.update
		app.update = null
		app.version = version
		app.systemApp = systemApp
		app.exAppUrl = exAppUrl
		state.updateCount--
	},

	resetApps(state) {
		state.apps = []
	},

	reset(state) {
		state.apps = []
		state.categories = []
		state.updateCount = 0
	},

	startLoading(state, id) {
		if (Array.isArray(id)) {
			id.forEach((_id) => {
				Vue.set(state.loading, _id, true) // eslint-disable-line
			})
		} else {
			Vue.set(state.loading, id, true) // eslint-disable-line
		}
	},

	stopLoading(state, id) {
		if (Array.isArray(id)) {
			id.forEach((_id) => {
				Vue.set(state.loading, _id, false) // eslint-disable-line
			})
		} else {
			Vue.set(state.loading, id, false) // eslint-disable-line
		}
	},
}

const getters = {
	loading(state) {
		return function(id) {
			return state.loading[id]
		}
	},
	getCategories(state) {
		return state.categories
	},
	getAllApps(state) {
		return state.apps
	},
	getUpdateCount(state) {
		return state.updateCount
	},
	getCategoryById: (state) => (selectedCategoryId) => {
		return state.categories.find((category) => category.id === selectedCategoryId)
	},
}

const actions = {

	enableApp(context, { appId, groups }) {
		let apps
		if (Array.isArray(appId)) {
			apps = appId
		} else {
			apps = [appId]
		}
		return api.requireAdmin().then((response) => {
			context.commit('startLoading', apps)
			context.commit('startLoading', 'install')
			return api.post(generateUrl('/apps/app_api/apps/enable'), { appIds: apps, groups })
				.then((response) => {
					context.commit('stopLoading', apps)
					context.commit('stopLoading', 'install')
					apps.forEach(_appId => {
						context.commit('enableApp', {
							appId: _appId,
							groups,
							daemon: response.data.data?.daemon_config,
							systemApp: response.data.data?.systemApp,
							exAppUrl: response.data.data?.exAppUrl,
						})
					})

					// check for server health
					return api.get(generateUrl('apps/files'))
						.then(() => {
							if (response.data.update_required) {
								showInfo(
									t(
										'app_api',
										'The app has been enabled but needs to be updated. You will be redirected to the update page in 5 seconds.'
									),
									{
										onClick: () => window.location.reload(),
										close: false,

									}
								)
								setTimeout(function() {
									location.reload()
								}, 5000)
							}
						})
						.catch(() => {
							if (!Array.isArray(appId)) {
								context.commit('setError', {
									appId: apps,
									error: t('app_api', 'Error: This app cannot be enabled because it makes the server unstable'),
								})
							}
						})
				})
				.catch((error) => {
					context.commit('stopLoading', apps)
					context.commit('stopLoading', 'install')
					context.commit('setError', {
						appId: apps,
						error: error.response.data.data.message,
					})
					context.commit('APPS_API_FAILURE', { appId, error })
				})
		}).catch((error) => context.commit('API_FAILURE', { appId, error }))
	},
	forceEnableApp(context, { appId, groups }) {
		let apps
		if (Array.isArray(appId)) {
			apps = appId
		} else {
			apps = [appId]
		}
		return api.requireAdmin().then(() => {
			context.commit('startLoading', apps)
			context.commit('startLoading', 'install')
			return api.post(generateUrl('/apps/app_api/apps/force'), { appId })
				.then((response) => {
					location.reload()
				})
				.catch((error) => {
					context.commit('stopLoading', apps)
					context.commit('stopLoading', 'install')
					context.commit('setError', {
						appId: apps,
						error: error.response.data.data.message,
					})
					context.commit('APPS_API_FAILURE', { appId, error })
				})
		}).catch((error) => context.commit('API_FAILURE', { appId, error }))
	},
	disableApp(context, { appId }) {
		let apps
		if (Array.isArray(appId)) {
			apps = appId
		} else {
			apps = [appId]
		}
		return api.requireAdmin().then((response) => {
			context.commit('startLoading', apps)
			return api.post(generateUrl('apps/app_api/apps/disable'), { appIds: apps })
				.then((response) => {
					context.commit('stopLoading', apps)
					apps.forEach(_appId => {
						context.commit('disableApp', _appId)
					})
					return true
				})
				.catch((error) => {
					context.commit('stopLoading', apps)
					context.commit('APPS_API_FAILURE', { appId, error })
				})
		}).catch((error) => context.commit('API_FAILURE', { appId, error }))
	},
	uninstallApp(context, { appId }) {
		return api.requireAdmin().then((response) => {
			context.commit('startLoading', appId)
			return api.get(generateUrl(`/apps/app_api/apps/uninstall/${appId}`))
				.then((response) => {
					context.commit('stopLoading', appId)
					context.commit('uninstallApp', appId)
					return true
				})
				.catch((error) => {
					context.commit('stopLoading', appId)
					context.commit('APPS_API_FAILURE', { appId, error })
				})
		}).catch((error) => context.commit('API_FAILURE', { appId, error }))
	},

	updateApp(context, { appId }) {
		return api.requireAdmin().then((response) => {
			context.commit('startLoading', appId)
			context.commit('startLoading', 'install')
			return api.get(generateUrl(`/apps/app_api/apps/update/${appId}`))
				.then((response) => {
					context.commit('stopLoading', 'install')
					context.commit('stopLoading', appId)
					context.commit('updateApp', {
						appId,
						systemApp: response.data.data?.systemApp,
						exAppUrl: response.data.data?.exAppUrl,
					})
					return true
				})
				.catch((error) => {
					context.commit('stopLoading', appId)
					context.commit('stopLoading', 'install')
					context.commit('APPS_API_FAILURE', { appId, error })
				})
		}).catch((error) => context.commit('API_FAILURE', { appId, error }))
	},

	getAllApps(context) {
		context.commit('startLoading', 'list')
		return api.get(generateUrl('/apps/app_api/apps/list'))
			.then((response) => {
				context.commit('setAllApps', response.data.apps)
				context.commit('stopLoading', 'list')
				return true
			})
			.catch((error) => context.commit('API_FAILURE', error))
	},

	async getCategories(context, { shouldRefetchCategories = false } = {}) {
		if (shouldRefetchCategories || !context.state.gettingCategoriesPromise) {
			context.commit('startLoading', 'categories')
			try {
				const categoriesPromise = api.get(generateUrl('/apps/app_api/apps/categories'))
				context.commit('updateCategories', categoriesPromise)
				const categoriesPromiseResponse = await categoriesPromise
				if (categoriesPromiseResponse.data.length > 0) {
					context.commit('appendCategories', categoriesPromiseResponse.data)
					context.commit('stopLoading', 'categories')
					return true
				}
				context.commit('stopLoading', 'categories')
				return false
			} catch (error) {
				context.commit('API_FAILURE', error)
			}
		}
		return context.state.gettingCategoriesPromise
	},

}

export default { state, mutations, getters, actions }
