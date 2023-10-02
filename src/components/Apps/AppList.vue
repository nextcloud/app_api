<template>
	<div id="app-content-inner">
		<div id="apps-list" class="apps-list" :class="{ installed: (useBundleView || useListView), store: useAppStoreView }">
			<template v-if="useListView">
				<div v-if="showUpdateAll" class="toolbar">
					{{ n('settings', '%n ExApp has an update available', '%n apps have an update available', counter) }}
					<NcButton v-if="showUpdateAll"
						id="app-list-update-all"
						type="primary"
						@click="updateAll">
						{{ n('settings', 'Update', 'Update all', counter) }}
					</NcButton>
				</div>

				<div v-if="!showUpdateAll" class="toolbar">
					{{ t('app_api', 'All ExApps are up-to-date.') }}
				</div>

				<transition-group name="app-list" tag="table" class="apps-list-container">
					<tr key="app-list-view-header" class="apps-header">
						<th class="app-image">
							<span class="hidden-visually">{{ t('app_api', 'Icon') }}</span>
						</th>
						<th class="app-name">
							<span class="hidden-visually">{{ t('app_api', 'Name') }}</span>
						</th>
						<th class="app-version">
							<span class="hidden-visually">{{ t('app_api', 'Version') }}</span>
						</th>
						<th class="app-daemon">
							<span class="hidden-visually">{{ t('app_api', 'Daemon') }}</span>
						</th>
						<th class="app-level">
							<span class="hidden-visually">{{ t('app_api', 'Level') }}</span>
						</th>
						<th class="actions">
							<span class="hidden-visually">{{ t('app_api', 'Actions') }}</span>
						</th>
					</tr>
					<AppItem v-for="_app in apps"
						:key="_app.id"
						:app="_app"
						:category="category" />
				</transition-group>
			</template>

			<table v-if="useBundleView"
				class="apps-list-container">
				<tr key="app-list-view-header" class="apps-header">
					<th id="app-table-col-icon" class="app-image">
						<span class="hidden-visually">{{ t('app_api', 'Icon') }}</span>
					</th>
					<th id="app-table-col-name" class="app-name">
						<span class="hidden-visually">{{ t('app_api', 'Name') }}</span>
					</th>
					<th id="app-table-col-version" class="app-version">
						<span class="hidden-visually">{{ t('app_api', 'Version') }}</span>
					</th>
					<th id="app-table-col-daemon" class="app-daemon">
						<span class="hidden-visually">{{ t('app_api', 'Daemon') }}</span>
					</th>
					<th id="app-table-col-level" class="app-level">
						<span class="hidden-visually">{{ t('app_api', 'Level') }}</span>
					</th>
					<th id="app-table-col-actions" class="actions">
						<span class="hidden-visually">{{ t('app_api', 'Actions') }}</span>
					</th>
				</tr>
			</table>
			<ul v-if="useAppStoreView" class="apps-store-view">
				<AppItem v-for="_app in apps"
					:key="_app.id"
					:app="_app"
					:category="category"
					:list-view="false" />
			</ul>
		</div>

		<div id="apps-list-search" class="apps-list installed">
			<div class="apps-list-container">
				<template v-if="search !== '' && searchApps.length > 0">
					<div class="section">
						<div />
						<td colspan="5">
							<h2>{{ t('app_api', 'Results from other categories') }}</h2>
						</td>
					</div>
					<AppItem v-for="search_app in searchApps"
						:key="search_app.id"
						:app="search_app"
						:category="category" />
				</template>
			</div>
		</div>

		<div v-if="search !== '' && !loading && searchApps.length === 0 && apps.length === 0" id="apps-list-empty" class="emptycontent emptycontent-search">
			<div id="app-list-empty-icon" class="icon-settings-dark" />
			<h2>{{ t('app_api', 'No apps found') }}</h2>
		</div>

		<div id="searchresults" />
	</div>
</template>

<script>
import AppItem from '../Apps/AppItem.vue'
import PrefixMixin from './PrefixMixin.vue'
import pLimit from 'p-limit'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

export default {
	name: 'AppList',
	components: {
		AppItem,
		NcButton,
	},
	mixins: [PrefixMixin],
	props: {
		category: {
			type: String,
			required: true,
			default: () => '',
		},
		// eslint-disable-next-line
		app: {},
		search: {
			type: String,
			required: false,
			default: () => '',
		},
	},
	computed: {
		counter() {
			return this.apps.filter(app => app.update).length
		},
		loading() {
			return this.$store.getters.loading('list')
		},
		hasPendingUpdate() {
			return this.apps.filter(app => app.update).length > 0
		},
		showUpdateAll() {
			return this.hasPendingUpdate && this.useListView
		},
		apps() {
			const apps = this.$store.getters.getAllApps
				.filter(app => app.name.toLowerCase().search(this.search.toLowerCase()) !== -1)
				.sort(function(a, b) {
					const sortStringA = '' + (a.active ? 0 : 1) + (a.update ? 0 : 1) + a.name
					const sortStringB = '' + (b.active ? 0 : 1) + (b.update ? 0 : 1) + b.name
					return OC.Util.naturalSortCompare(sortStringA, sortStringB)
				})

			if (this.category === 'installed') {
				return apps.filter(app => app.installed)
			}
			if (this.category === 'enabled') {
				return apps.filter(app => app.active && app.installed)
			}
			if (this.category === 'disabled') {
				return apps.filter(app => !app.active && app.installed)
			}
			if (this.category === 'app-bundles') {
				return apps.filter(app => app.bundles)
			}
			if (this.category === 'updates') {
				return apps.filter(app => app.update)
			}
			if (this.category === 'supported') {
				// For customers of the Nextcloud GmbH the app level will be set to `300` for apps that are supported in their subscription
				return apps.filter(app => app.level === 300)
			}
			if (this.category === 'featured') {
				// An app level of `200` will be set for apps featured on the app store
				return apps.filter(app => app.level === 200)
			}
			// filter app store categories
			return apps.filter(app => {
				return app.appstore && app.category !== undefined
					&& (app.category === this.category || app.category.indexOf(this.category) > -1)
			})
		},
		searchApps() {
			if (this.search === '') {
				return []
			}
			return this.$store.getters.getAllApps
				.filter(app => {
					if (app.name.toLowerCase().search(this.search.toLowerCase()) !== -1) {
						return (!this.apps.find(_app => _app.id === app.id))
					}
					return false
				})
		},
		useAppStoreView() {
			return !this.useListView && !this.useBundleView
		},
		useListView() {
			return (this.category === 'installed' || this.category === 'enabled' || this.category === 'disabled' || this.category === 'updates' || this.category === 'featured' || this.category === 'supported')
		},
		useBundleView() {
			return (this.category === 'app-bundles')
		},
		allBundlesEnabled() {
			return (id) => {
				return this.bundleApps(id).filter(app => !app.active).length === 0
			}
		},
	},
	methods: {
		updateAll() {
			const limit = pLimit(1)
			this.apps
				.filter(app => app.update)
				.map(app => limit(() => this.$store.dispatch('updateApp', { appId: app.id })),
				)
		},
	},
}
</script>

<style lang="scss" scoped>
.apps-store-view {
	width: 100%;
	display: flex;
	flex-wrap: wrap;
}

.toolbar {
	height: 60px;
	padding: 8px;
	padding-left: 60px;
	width: 100%;
	background-color: var(--color-main-background);
	position: sticky;
	top: 0;
	z-index: 1;
	display: flex;
	align-items: center;
}
</style>
