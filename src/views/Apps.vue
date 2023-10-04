<template>
	<NcContent app-name="app_api"
		:class="{ 'with-app-sidebar': app}"
		:content-class="{ 'icon-loading': loadingList }"
		:navigation-class="{ 'icon-loading': loading }">
		<!-- Categories & filters -->
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem id="app-category-your-apps"
					:to="{ name: 'apps' }"
					:exact="true"
					icon="icon-category-installed"
					:name="t('app_api', 'Your apps')" />
				<NcAppNavigationItem id="app-category-enabled"
					:to="{ name: 'apps-category', params: { category: 'enabled' } }"
					icon="icon-category-enabled"
					:name="$options.APPS_SECTION_ENUM.enabled" />
				<NcAppNavigationItem id="app-category-disabled"
					:to="{ name: 'apps-category', params: { category: 'disabled' } }"
					icon="icon-category-disabled"
					:name="$options.APPS_SECTION_ENUM.disabled" />
				<NcAppNavigationItem v-if="updateCount > 0"
					id="app-category-updates"
					:to="{ name: 'apps-category', params: { category: 'updates' } }"
					icon="icon-download"
					:name="$options.APPS_SECTION_ENUM.updates">
					<template #counter>
						<NcCounterBubble>{{ updateCount }}</NcCounterBubble>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem v-if="isSubscribed"
					id="app-category-supported"
					:to="{ name: 'apps-category', params: { category: 'supported' } }"
					:name="$options.APPS_SECTION_ENUM.supported">
					<template #icon>
						<IconStarShooting :size="20" />
					</template>
				</NcAppNavigationItem>

				<!-- App store categories -->
				<template v-if="state.appstoreEnabled">
					<NcAppNavigationItem id="app-category-featured"
						:to="{ name: 'apps-category', params: { category: 'featured' } }"
						icon="icon-favorite"
						:name="$options.APPS_SECTION_ENUM.featured" />

					<NcAppNavigationItem v-for="cat in categories"
						:key="'icon-category-' + cat.ident"
						:icon="'icon-category-' + cat.ident"
						:to="{
							name: 'apps-category',
							params: { category: cat.ident },
						}"
						:name="cat.displayName" />
				</template>

				<NcAppNavigationItem
					id="admin-section"
					:href="linkToAdminSettings"
					:name="t('app_api', 'Admin settings') + ' ↗'" />
			</template>
		</NcAppNavigation>

		<!-- Apps list -->
		<NcAppContent class="app-settings-content" :class="{ 'icon-loading': loadingList }">
			<AppList :category="$route.params.category ?? 'installed'" :app="app" :search="searchQuery" />
		</NcAppContent>

		<!-- Selected app details -->
		<NcAppSidebar v-if="$route.params.id && app"
			v-bind="appSidebar"
			:class="{'app-sidebar--without-background': !appSidebar.background}"
			:title="appSidebar.name"
			:subtitle="appSidebar.subname"
			@close="hideAppDetails">
			<template v-if="!appSidebar.background" #header>
				<div class="app-sidebar-header__figure--default-app-icon icon-settings-dark" />
			</template>

			<template #description>
				<!-- Featured/Supported badges -->
				<div v-if="app.level === 300 || app.level === 200 || hasRating" class="app-level">
					<span v-if="app.level === 300"
						:title="t('app_api', 'This app is supported via your current Nextcloud subscription.')"
						class="supported icon-checkmark-color">
						{{ t('app_api', 'Supported') }}</span>
					<span v-if="app.level === 200"
						:title="t('app_api', 'Featured apps are developed by and within the community. They offer central functionality and are ready for production use.')"
						class="official icon-checkmark">
						{{ t('app_api', 'Featured') }}</span>
					<AppScore v-if="hasRating" :score="app.appstoreData.ratingOverall" />
				</div>
				<div class="app-version">
					<p>{{ app.version }}</p>
				</div>
			</template>

			<!-- Tab content -->

			<NcAppSidebarTab id="desc"
				icon="icon-category-office"
				:name="t('app_api', 'Details')"
				:order="0">
				<AppDetails :app="app" />
			</NcAppSidebarTab>
			<NcAppSidebarTab v-if="app.appstoreData && app.releases.length > 0 && app.releases[0].translations.en.changelog"
				id="desca"
				icon="icon-category-organization"
				:name="t('app_api', 'Changelog')"
				:order="1">
				<div v-for="release in app.releases" :key="release.version" class="app-sidebar-tabs__release">
					<h2>{{ release.version }}</h2>
					<Markdown v-if="changelog(release)" :text="changelog(release)" />
				</div>
			</NcAppSidebarTab>
			<NcAppSidebarTab v-if="app.daemon"
				id="daemon"
				icon="icon-category-monitoring"
				:name="t('app_api', 'Daemon')"
				:order="1">
				<DaemonDetails :app="app" />
			</NcAppSidebarTab>
			<NcAppSidebarTab v-if="app.scopes"
				id="scopes"
				icon="icon-category-security"
				:name="t('app_api', 'Scopes')"
				:order="2">
				<ScopesDetails :app="app" />
			</NcAppSidebarTab>
		</NcAppSidebar>
	</NcContent>
</template>

<script>
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import Vue from 'vue'
import VueLocalStorage from 'vue-localstorage'

import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js'
import NcAppNavigation from '@nextcloud/vue/dist/Components/NcAppNavigation.js'
import NcAppNavigationItem from '@nextcloud/vue/dist/Components/NcAppNavigationItem.js'
import NcAppSidebar from '@nextcloud/vue/dist/Components/NcAppSidebar.js'
import NcAppSidebarTab from '@nextcloud/vue/dist/Components/NcAppSidebarTab.js'
import NcCounterBubble from '@nextcloud/vue/dist/Components/NcCounterBubble.js'
import NcContent from '@nextcloud/vue/dist/Components/NcContent.js'
import IconStarShooting from 'vue-material-design-icons/StarShooting.vue'

import AppList from '../components/Apps/AppList.vue'
import AppDetails from '../components/Apps/AppDetails.vue'
import AppManagement from '../mixins/AppManagement.js'
import AppScore from '../components/Apps/AppScore.vue'
import Markdown from '../components/Apps/Markdown.vue'
import DaemonDetails from '../components/Apps/DaemonDetails.vue'
import ScopesDetails from '../components/Apps/ScopesDetails.vue'

import { APPS_SECTION_ENUM } from '../constants/AppsConstants.js'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'

Vue.use(VueLocalStorage)

export default {
	name: 'Apps',
	APPS_SECTION_ENUM,
	components: {
		NcAppContent,
		AppDetails,
		AppList,
		IconStarShooting,
		NcAppNavigation,
		NcAppNavigationItem,
		NcCounterBubble,
		AppScore,
		NcAppSidebar,
		NcAppSidebarTab,
		NcContent,
		Markdown,
		DaemonDetails,
		ScopesDetails,
	},

	mixins: [AppManagement],

	props: {
		category: {
			type: String,
			default: 'installed',
		},
		id: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			searchQuery: '',
			screenshotLoaded: false,
			state: loadState('app_api', 'apps'),
		}
	},

	computed: {
		loading() {
			return this.$store.getters.loading('categories')
		},
		loadingList() {
			return this.$store.getters.loading('list')
		},
		app() {
			return this.apps.find(app => app.id === this.$route.params.id)
		},
		daemon() {
			return this.$store.state.daemon
		},
		categories() {
			return this.$store.getters.getCategories
		},
		apps() {
			return this.$store.getters.getAllApps
		},
		updateCount() {
			return this.$store.getters.getUpdateCount
		},
		settings() {
			return this.$store.getters.getServerData
		},

		hasRating() {
			return this.app.appstoreData && this.app.appstoreData.ratingNumOverall > 5
		},

		// sidebar app binding
		appSidebar() {
			const authorName = (xmlNode) => {
				if (xmlNode['@value']) {
					// Complex node (with email or homepage attribute)
					return xmlNode['@value']
				}

				// Simple text node
				return xmlNode
			}

			const author = Array.isArray(this.app.author)
				? this.app.author.map(authorName).join(', ')
				: authorName(this.app.author)
			const license = t('app_api', '{license}-licensed', { license: ('' + this.app.licence).toUpperCase() })

			const subname = author && license ? t('app_api', 'by {author}\n{license}', { author, license }) : ''

			return {
				background: this.app.screenshot && this.screenshotLoaded
					? this.app.screenshot
					: this.app.preview,
				compact: !(this.app.screenshot && this.screenshotLoaded),
				name: this.app.name,
				subname,
			}
		},
		changelog() {
			return (release) => release.translations.en.changelog
		},
		/**
		 * Check if the current instance has a support subscription from the Nextcloud GmbH
		 */
		isSubscribed() {
			// For customers of the Nextcloud GmbH the app level will be set to `300` for apps that are supported in their subscription
			return this.apps.some(app => app.level === 300)
		},
		linkToAdminSettings() {
			return generateUrl('/settings/admin/app_api')
		},
	},

	watch: {
		category() {
			this.searchQuery = ''
		},

		app() {
			this.screenshotLoaded = false
			if (this.app?.releases && this.app?.screenshot) {
				const image = new Image()
				image.onload = (e) => {
					this.screenshotLoaded = true
				}
				image.src = this.app.screenshot
			}
		},
	},

	beforeMount() {
		this.$store.dispatch('getCategories', { shouldRefetchCategories: true })
		this.$store.dispatch('getAllApps')
		this.$store.commit('setUpdateCount', this.state.updateCount)
	},

	mounted() {
		subscribe('nextcloud:unified-search.search', this.setSearch)
		subscribe('nextcloud:unified-search.reset', this.resetSearch)
	},
	beforeDestroy() {
		unsubscribe('nextcloud:unified-search.search', this.setSearch)
		unsubscribe('nextcloud:unified-search.reset', this.resetSearch)
	},

	methods: {
		setSearch({ query }) {
			this.searchQuery = query
		},
		resetSearch() {
			this.searchQuery = ''
		},

		hideAppDetails() {
			this.$router.push({
				name: 'apps-category',
				params: { category: this.$route.params.category ?? 'installed' },
			})
		},
	},
}
</script>

<style lang="scss" scoped>
.app-sidebar::v-deep {
	&:not(.app-sidebar--without-background) {
		// with full screenshot, let's fill the figure
		:not(.app-sidebar-header--compact) .app-sidebar-header__figure {
			background-size: cover;
		}
		// revert sidebar app icon so it is black
		.app-sidebar-header--compact .app-sidebar-header__figure {
			background-size: 32px;

			filter: var(--background-invert-if-bright);
		}
	}

	.app-sidebar-header__description {
		.app-version {
			padding-left: 10px;
		}
	}

	// default icon slot styling
	&.app-sidebar--without-background {
		.app-sidebar-header__figure {
			display: flex;
			align-items: center;
			justify-content: center;
			&--default-app-icon {
				width: 32px;
				height: 32px;
				background-size: 32px;
			}
		}
	}

	// TODO: migrate to components
	.app-sidebar-header__desc {
		// allow multi line subtitle for the license
		.app-sidebar-header__subtitle {
			overflow: visible !important;
			height: auto;
			white-space: normal !important;
			line-height: 16px;
		}
	}

	.app-sidebar-header__action {
		// align with tab content
		margin: 0 20px;
		input {
			margin: 3px;
		}
	}
}

// Align the appNavigation toggle with the apps header toolbar
.app-navigation::v-deep button.app-navigation-toggle {
	top: 8px;
	right: -8px;
}

.app-sidebar-tabs__release {
	h2 {
		border-bottom: 1px solid var(--color-border);
	}

	// Overwrite changelog heading styles
	::v-deep {
		h3 {
			font-size: 20px;
		}
		h4 {
			font-size: 17px;
		}
	}
}
</style>
