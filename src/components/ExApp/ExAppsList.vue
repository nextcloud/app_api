<template>
	<div class="ex-apps">
		<div v-if="apps.length > 0" class="ex-apps-list">
			<div v-for="exApp in apps"
				:key="exApp.id"
				class="ex-apps-list-item">
				<ExApp :app="exApp" />
			</div>
		</div>
		<NcEmptyContent v-else :description="t('app_api', 'No ExApps installed. Check out list and install some')">
			<template #icon>
				<FormatListBullet :size="20" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'

import FormatListBullet from 'vue-material-design-icons/FormatListBulleted.vue'

import ExApp from './ExApp.vue'

export default {
	name: 'ExAppsList',
	components: {
		NcEmptyContent,
		FormatListBullet,
		ExApp,
	},
	props: {
		apps: {
			type: Array,
			required: true,
			default: () => [],
		},
	},
	methods: {
	},
}
</script>

<style scoped lang="scss">
.ex-apps {
	width: 100%;
	margin: 10px 0;

	&-list {
		width: 100%;
		display: flex;
		flex-wrap: wrap;
		flex: 0 0 auto;
	}

	&-list-item {
		width: 33%;
	}
}
</style>
