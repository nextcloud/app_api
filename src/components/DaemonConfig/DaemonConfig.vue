<!--
  - SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="daemon">
		<NcListItem
			:name="itemTitle"
			:details="isDefault ? t('app_api', 'Default') : ''"
			:force-display-actions="true"
			:counter-number="daemon.exAppsCount"
			:class="{'daemon-default': isDefault }"
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
				<NcActionButton v-if="daemon.accepts_deploy_id === 'docker-install'" :close-after-click="true" @click="_showOverrideDockerRegistriesModal()">
					{{ t('app_api', 'Docker registries') }}
					<template #icon>
						<Docker :size="20" />
					</template>
				</NcActionButton>
				<NcActionButton :close-after-click="true" @click="showEditModal()">
					{{ t('app_api', 'Edit') }}
					<template #icon>
						<PencilOutline :size="20" />
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
		<ManageDaemonConfigModal
			:show.sync="showEditDialog"
			:daemons="daemons"
			:get-all-daemons="getAllDaemons"
			:daemon="daemon"
			:is-default-daemon="isDefault" />
		<DockerRegistriesModal
			:show.sync="showOverrideDockerRegistriesModal"
			:daemon="daemon"
			:is-default="isDefault"
			:get-all-daemons="getAllDaemons"
			@close="showOverrideDockerRegistriesModal = false" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { confirmPassword } from '@nextcloud/password-confirmation'
import NcListItem from '@nextcloud/vue/dist/Components/NcListItem.js'
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js'

import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import CheckBold from 'vue-material-design-icons/CheckBold.vue'
import TestTube from 'vue-material-design-icons/TestTube.vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import Docker from 'vue-material-design-icons/Docker.vue'

import DaemonConfigDetailsModal from './DaemonConfigDetailsModal.vue'
import ConfirmDaemonDeleteModal from './ConfirmDaemonDeleteModal.vue'
import DaemonTestDeploy from './DaemonTestDeploy.vue'
import ManageDaemonConfigModal from './ManageDaemonConfigModal.vue'
import DockerRegistriesModal from './DockerRegistriesModal.vue'

export default {
	name: 'DaemonConfig',
	components: {
		ManageDaemonConfigModal,
		NcListItem,
		NcActionButton,
		CheckBold,
		DaemonConfigDetailsModal,
		ConfirmDaemonDeleteModal,
		DockerRegistriesModal,
		DaemonTestDeploy,
		NcLoadingIcon,
		TestTube,
		PencilOutline,
		Docker,
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
			showOverrideDockerRegistriesModal: false,
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
			return confirmPassword().then(() => {
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
			}).catch(() => {
				this.deleting = false
				this.showDeleteDialog = false
				showError(t('app_api', 'Password confirmation failed'))
			})
		},
		showTestDeployModal() {
			this.showTestDeployDialog = true
		},
		showEditModal() {
			this.showEditDialog = true
		},
		_showOverrideDockerRegistriesModal() {
			this.showOverrideDockerRegistriesModal = true
		},
	},
}
</script>

<style lang="scss">
.daemon-default > .list-item {
	background-color: var(--color-background-dark);
}
</style>
