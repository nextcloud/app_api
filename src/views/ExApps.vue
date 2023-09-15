<template>
	<NcContent app-name="app_api">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem
					id="ex-app-category-your-apps"
					:to="{ name: 'apps' }"
					:exact="true"
					icon="icon-category-installed"
					:name="t('app_api', 'Your ExApps')" />
				<NcAppNavigationItem
					id="app-category-enabled"
					:to="{ name: 'apps-category', params: { category: 'enabled' } }"
					icon="icon-category-enabled"
					:name="t('app_api', 'Enabled')" />
				<NcAppNavigationItem
					id="app-category-disabled"
					:to="{ name: 'apps-category', params: { category: 'disabled' } }"
					icon="icon-category-disabled"
					:name="t('app_api', 'Disabled')" />
				<template v-if="state.appstore_enabled">
					<NcAppNavigationItem
						v-for="category in state.categories"
						:key="category.id"
						:icon="'icon-category-' + category.id"
						:to="{ name: 'apps-category', params: { category: category.id } }"
						:name="category.name" />
				</template>
				<NcAppNavigationItem
					id="admin-section"
					:href="linkToAdminSettings()"
					icon="icon-settings-dark"
					:name="t('app_api', 'Admin settings')" />
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<div class="ex-apps">
				<h2>{{ rootTitle }}</h2>
				<ExAppsList :apps="state.apps" />
			</div>
		</NcAppContent>

		<NcAppSidebar
			v-if="app"
			:title="t('app_api', 'ExApp details')"
			name="ExApp details">
			<template #header>
				{{ t('app_api', 'AppInfoHeader') }}
			</template>
		</NcAppSidebar>
	</NcContent>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import NcContent from '@nextcloud/vue/dist/Components/NcContent.js'
import NcAppNavigation from '@nextcloud/vue/dist/Components/NcAppNavigation.js'
import NcAppNavigationItem from '@nextcloud/vue/dist/Components/NcAppNavigationItem.js'
import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js'
import NcAppSidebar from '@nextcloud/vue/dist/Components/NcAppSidebar.js'

import ExAppsList from '../components/ExApp/ExAppsList.vue'

export default {
	name: 'ExApps',
	components: {
		NcContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppContent,
		NcAppSidebar,
		ExAppsList,
	},
	props: {
		rootTitle: {
			type: String,
			required: true,
			default: () => '',
		},
	},
	data() {
		return {
			state: loadState('app_api', 'apps'),
			app: null,
		}
	},
	methods: {
		linkToAdminSettings() {
			return generateUrl('/settings/admin/app_api')
		},
	},
}
</script>

<style scoped lang="scss">
.ex-apps {
	padding: 10px;

	h2 {
		padding-left: 60px;
	}
}
</style>
