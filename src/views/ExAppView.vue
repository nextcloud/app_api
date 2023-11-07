<template>
	<NcContent app-name="app_api">
		<NcAppContent>
			<iframe :src="iframeSrc" />
		</NcAppContent>
	</NcContent>
</template>

<script>
import NcContent from '@nextcloud/vue/dist/Components/NcContent.js'
import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js'
import { loadState } from '@nextcloud/initial-state'

export default {
	name: 'ExAppView',
	components: {
		NcContent,
		NcAppContent,
	},
	data() {
		return {
			state: loadState('app_api', 'iframe-target'),
		}
	},
	computed: {
		iframeSrc() {
			return this.state.iframe_url
		},
	},
}
</script>

<style lang="scss" scoped>
iframe {
	width: 100%;
	height: 100%;
	border: none;
}
</style>
