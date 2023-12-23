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
				<NcActionButton icon="icon-delete" :close-after-click="true" @click="deleteDaemonConfig(daemon)">
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
		<NcDialog
			v-show="showDeleteDialog"
			style="padding: 20px;"
			:open.sync="showDeleteDialog"
			:content-classes="'confirm-delete-dialog'"
			:name="t('app_api', 'Confirm deletion')">
			<template #actions>
				<NcDialogButton :label="t('app_api', 'Cancel')" :callback="() => showDeleteDialog = false">
					<template #icon>
						<Cancel :size="20" />
					</template>
				</NcDialogButton>
				<NcDialogButton :label="t('app_api', 'Ok')" :callback="() => _deleteDaemonConfig(daemon)">
					<template #icon>
						<Check :size="20" />
					</template>
				</NcDialogButton>
			</template>
			<template #default>
				<div class="confirm-delete-dialog">
					<p>{{ t('app_api', 'Are you sure you want delete Deploy Daemon?') }}</p>
					<NcNoteCard>
						<template #default>
							{{ t('app_api', 'This action will not remove installed ExApps on this daemon') }}
						</template>
					</NcNoteCard>
				</div>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import NcListItem from '@nextcloud/vue/dist/Components/NcListItem.js'
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'
import NcDialogButton from '@nextcloud/vue/dist/Components/NcDialogButton.js'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Check from 'vue-material-design-icons/Check.vue'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
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
		NcDialog,
		NcDialogButton,
		Cancel,
		Check,
		NcNoteCard,
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
			showDeleteDialog: false,
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
		deleteDaemonConfig(daemon) {
			this.showDeleteDialog = true
		},
		_deleteDaemonConfig(daemon) {
			this.deleting = true
			return axios.delete(generateUrl(`/apps/app_api/daemons/${daemon.name}`))
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
	},
}
</script>

<style scoped lang="scss">
.daemon-default {
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius-pill);
}

.confirm-delete-dialog {
	padding: 20px;
}
</style>
