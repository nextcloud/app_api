<template>
	<div class="daemon" :class="{'daemon-default': isDefault }">
		<NcListItem
			:title="itemTitle"
			:details="isDefault ? t('app_api', 'Default') : ''"
			:force-display-actions="true"
			:counter-number="daemon.exAppsCount"
			counter-type="hightlighet"
			@click="showDaemonConfigDetailsModal(daemon)">
			<template #subtitle>
				{{ daemon.accepts_deploy_id }}
			</template>
			<template #actions>
				<NcActionButton :disabled="isDefault || settingDefault || daemon.accepts_deploy_id === 'manual-install'" @click="setDaemonDefault(daemon)">
					<template #icon>
						<CheckBold v-if="!settingDefault" :size="20" />
						<NcLoadingIcon v-else :size="20" />
					</template>
					{{ !isDefault ? t('app_api', 'Set as default') : t('app_api', 'Default') }}
				</NcActionButton>
				<NcActionButton icon="icon-delete" @click="deleteDaemonConfig(daemon)">
					{{ t('app_api', 'Delete') }}
					<template #icon>
						<NcLoadingIcon v-if="deleting" :size="20" />
					</template>
				</NcActionButton>
			</template>
		</NcListItem>
		<DaemonConfigDetailsModal
			v-show="showDetailsModal"
			:show.sync="showDetailsModal"
			:daemon="daemon"
			:is-default="isDefault" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import NcListItem from '@nextcloud/vue/dist/Components/NcListItem.js'
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

import CheckBold from 'vue-material-design-icons/CheckBold.vue'

import DaemonConfigDetailsModal from './DaemonConfigDetailsModal.vue'

export default {
	name: 'DaemonConfig',
	components: {
		NcListItem,
		NcActionButton,
		NcLoadingIcon,
		CheckBold,
		DaemonConfigDetailsModal,
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
		saveOptions: {
			type: Function,
			required: true,
		},
		getAllDaemons: {
			type: Function,
			required: true,
		},
	},
	data() {
		return {
			showDetailsModal: false,
			settingDefault: false,
			deleting: false,
		}
	},
	computed: {
		itemTitle() {
			return this.daemon.name + ' - ' + this.daemon.display_name
		},
	},
	methods: {
		showDaemonConfigDetailsModal() {
			this.showDetailsModal = true
		},
		setDaemonDefault(daemon) {
			if (this.daemon.accepts_deploy_id === 'manual-install') {
				showError(t('app_api', '"manual-install" Deploy Daemon can not be set as default'))
				return
			}
			this.settingDefault = true
			this.saveOptions({ default_daemon_config: daemon.name })
				.then(() => {
					this.getAllDaemons()
					this.settingDefault = false
				})
				.catch(err => {
					console.debug(err)
					showError(t('app_api', 'Failed to save admin options. Check the logs'))
					this.settingDefault = false
				})
		},
		deleteDaemonConfig(daemon) {
			const self = this
			OC.dialogs.confirm(
				t('app_api', 'Are you sure you want delete Deploy Daemon?'),
				t('app_api', 'Confirm Deploy daemon deletion'),
				function(success) {
					if (success) {
						self._deleteDaemonConfig(daemon)
					}
				}
			)
		},
		_deleteDaemonConfig(daemon) {
			this.deleting = true
			return axios.delete(generateUrl(`/apps/app_api/daemons/${daemon.name}`))
				.then(res => {
					if (res.data.success) {
						this.getAllDaemons()
					}
					this.deleting = false
				})
				.catch(err => {
					console.debug(err)
					this.deleting = false
				})
		},
	},
}
</script>

<style scoped lang="scss">
.daemon-default {
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius-pill);
}
</style>
