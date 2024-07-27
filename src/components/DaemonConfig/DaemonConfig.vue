<template>
	<div class="daemon" :class="{'daemon-default': isDefault }">
		<NcListItem
			:name="itemTitle"
			:details="isDefault ? t('app_api', 'Default') : ''"
			:force-display-actions="true"
			:counter-number="daemon.exAppsCount"
			counter-type="highlighted"
			@click="showDaemonConfigDetailsModal(daemon)">
			<template #subname>
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
				<NcActionButton v-if="daemon.accepts_deploy_id !== 'manual-install'" :close-after-click="true" @click="showTestDeployModal()">
					{{ t('app_api', 'Test deploy') }}
					<template #icon>
						<TestTube :size="20" />
					</template>
				</NcActionButton>
				<NcActionButton :close-after-click="true" @click="showEditModal()">
					{{ t('app_api', 'Edit') }}
					<template #icon>
						<Pencil :size="20" />
					</template>
				</NcActionButton>
				<NcActionButton icon="icon-delete" :close-after-click="true" @click="deleteDaemonConfig()">
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
		<ConfirmDaemonDeleteModal
			v-show="showDeleteDialog"
			:daemon="daemon"
			:deleting="deleting"
			:delete-daemon-config="_deleteDaemonConfig"
			:show.sync="showDeleteDialog" />
		<template v-if="daemon.accepts_deploy_id !== 'manual-install'">
			<DaemonTestDeploy
				v-if="showTestDeployDialog"
				:show.sync="showTestDeployDialog"
				:get-all-daemons="getAllDaemons"
				:daemon="daemon" />
		</template>
		<RegisterDaemonConfigModal
			:show.sync="showEditDialog"
			:daemons="daemons"
			:get-all-daemons="getAllDaemons"
			:daemon="daemon"
			:is-default-daemon="isDefault" />
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
import TestTube from 'vue-material-design-icons/TestTube.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'

import DaemonConfigDetailsModal from './DaemonConfigDetailsModal.vue'
import ConfirmDaemonDeleteModal from './ConfirmDaemonDeleteModal.vue'
import DaemonTestDeploy from './DaemonTestDeploy.vue'
import RegisterDaemonConfigModal from './RegisterDaemonConfigModal.vue'

export default {
	name: 'DaemonConfig',
	components: {
		RegisterDaemonConfigModal,
		NcListItem,
		NcActionButton,
		CheckBold,
		DaemonConfigDetailsModal,
		ConfirmDaemonDeleteModal,
		DaemonTestDeploy,
		NcLoadingIcon,
		TestTube,
		Pencil,
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
		daemons: {
			type: Array,
			required: true,
			default: () => [],
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
			showDeleteDialog: false,
			removeExAppsOnDaemonDelete: false,
			showTestDeployDialog: false,
			showEditDialog: false,
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
				showError(t('app_api', '"manual-install" Deploy Daemon cannot be set as default'))
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
		deleteDaemonConfig() {
			this.showDeleteDialog = true
		},
		_deleteDaemonConfig(daemon) {
			this.deleting = true
			return axios.delete(generateUrl(`/apps/app_api/daemons/${daemon.name}?removeExApps=${this.removeExAppsOnDaemonDelete}`))
				.then(res => {
					if (res.data.success) {
						this.getAllDaemons()
					}
					this.deleting = false
					this.showDetailsModal = false
				})
				.catch(err => {
					console.debug(err)
					this.deleting = false
					this.showDetailsModal = false
				})
		},
		showTestDeployModal() {
			this.showTestDeployDialog = true
		},
		showEditModal() {
			this.showEditDialog = true
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
