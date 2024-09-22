<template>
	<div class="daemon">
		<NcListItem
			:name="itemTitle"
			:details="isDefault ? t('app_api', 'Default') : ''"
			:force-display-actions="true"
			:counter-number="daemon.exAppsCount"
			:class="{'daemon-default': isDefault }"
			counter-type="highlighted"
			@click="enableApp(daemon)">
			<template #subname>
				{{ daemon.accepts_deploy_id }}
			</template>
		</NcListItem>
	</div>
</template>

<script>
import NcListItem from '@nextcloud/vue/dist/Components/NcListItem.js'

export default {
	name: 'DaemonEnableSelection',
	components: {
		NcListItem,
	},
	props: {
		daemon: {
			type: Object,
			required: true,
			default: () => {},
		},
		isDefault: {
			type: Boolean,
			required: true,
			default: () => false,
		},
		daemons: {
			type: Array,
			required: true,
			default: () => [],
		},
	},
	computed: {
		itemTitle() {
			return this.daemon.name + ' - ' + this.daemon.display_name
		},
	},
	methods: {
		enableApp() {
			// hier enable-Aufruf
			this.$emit('close')
		},
	},
}
</script>

<style lang="scss">
.daemon-default > .list-item {
	background-color: var(--color-background-dark);
}
</style>
