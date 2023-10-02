import { showError } from '@nextcloud/dialogs'
import rebuildNavigation from '../service/rebuild-navigation.js'

export default {
	computed: {
		installing() {
			return this.$store.getters.loading('install')
		},
		isLoading() {
			return this.app && this.$store.getters.loading(this.app.id)
		},
		enableButtonText() {
			if (this.app.needsDownload) {
				return t('settings', 'Deploy and Enable')
			}
			return t('settings', 'Enable')
		},
		forceEnableButtonText() {
			if (this.app.needsDownload) {
				return t('settings', 'Allow untested app')
			}
			return t('settings', 'Allow untested app')
		},
		enableButtonTooltip() {
			if (this.app.needsDownload) {
				return t('settings', 'The app will be downloaded from the App Store and deployed on default Deploy Daemon')
			}
			return ''
		},
		forceEnableButtonTooltip() {
			const base = t('settings', 'This app is not marked as compatible with your Nextcloud version. If you continue you will still be able to install the app. Note that the app might not work as expected.')
			if (this.app.needsDownload) {
				return base + ' ' + t('settings', 'The app will be downloaded from the App Store and deployed on default Deploy Daemon')
			}
			return base
		},
	},

	data() {
		return {
			groupCheckedAppsData: false,
		}
	},

	mounted() {
		if (this.app && this.app.groups && this.app.groups.length > 0) {
			this.groupCheckedAppsData = true
		}
	},

	methods: {
		forceEnable(appId) {
			this.$store.dispatch('forceEnableApp', { appId, groups: [] })
				.then((response) => { rebuildNavigation() })
				.catch((error) => { showError(error) })
		},
		enable(appId) {
			this.$store.dispatch('enableApp', { appId, groups: [] })
				.then((response) => { rebuildNavigation() })
				.catch((error) => { showError(error) })
		},
		disable(appId) {
			this.$store.dispatch('disableApp', { appId })
				.then((response) => { rebuildNavigation() })
				.catch((error) => { showError(error) })
		},
		remove(appId) {
			this.$store.dispatch('uninstallApp', { appId })
				.then((response) => { rebuildNavigation() })
				.catch((error) => { showError(error) })
		},
		install(appId) {
			this.$store.dispatch('enableApp', { appId })
				.then((response) => { rebuildNavigation() })
				.catch((error) => { showError(error) })
		},
		update(appId) {
			this.$store.dispatch('updateApp', { appId })
				.then((response) => { rebuildNavigation() })
				.catch((error) => { showError(error) })
		},
	},
}
