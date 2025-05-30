<!--
  - SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="daemon-config-modal">
		<NcModal :show="show" @close="closeModal">
			<div class="daemon-config-modal-details" :aria-label="t('app_api', 'Deploy daemon config details')">
				<h2>{{ t('app_api', 'Deploy Daemon') }} - {{ daemon.display_name }}</h2>

				<NcNoteCard v-if="isDefault" type="success">
					{{ t('app_api', 'Default daemon. ExApps will be installed on it') }}
				</NcNoteCard>

				<NcNoteCard v-if="daemon.accepts_deploy_id === 'manual-install'" type="warning">
					{{ t('app_api', 'Manual install daemon usually used for development. It cannot be set as default daemon.') }}
				</NcNoteCard>

				<p><b>{{ t('app_api', 'ExApps installed') }}: </b>{{ daemon.exAppsCount }}</p>
				<p><b>{{ t('app_api', 'Name') }}: </b>{{ daemon.name }}</p>
				<p><b>{{ t('app_api', 'Protocol') }}: </b>{{ daemon.protocol }}</p>
				<p><b>{{ t('app_api', 'Host') }}: </b>{{ daemon.host }}</p>
				<p v-if="daemon.deploy_config.harp"><b>{{ t('app_api', 'ExApp direct communication (FRP disabled)') }}: </b>{{ daemon.deploy_config.harp.exapp_direct ?? false }}</p>

				<h3>{{ t('app_api', 'Deploy config') }}</h3>
				<p><b>{{ t('app_api', 'Docker network') }}: </b>{{ daemon.deploy_config.net }}</p>
				<p><b>{{ t('app_api', 'Nextcloud URL') }}: </b>{{ daemon.deploy_config.nextcloud_url }}</p>
				<p v-if="daemon.deploy_config.haproxy_password" class="external-label">
					<label for="haproxy_password"><b>{{ t('app_api', 'HaProxy password') }}: </b></label>
					<NcPasswordField
						id="haproxy_password"
						:value="daemon.deploy_config?.haproxy_password"
						:disable="true"
						style="width: fit-content;"
						readonly
						autocomplete="off" />
				</p>
				<p>
					<b>{{ t('app_api', 'GPUs support') }}:</b> {{ daemon.deploy_config.computeDevice && daemon.deploy_config?.computeDevice?.id !== 'cpu' || false }}
				</p>
				<p v-if="daemon.deploy_config.computeDevice">
					<b>{{ t('app_api', 'Compute device') }}:</b> {{ daemon.deploy_config?.computeDevice?.label }}
				</p>

				<div v-if="daemon.deploy_config.additional_options" class="additional-options">
					<h3>{{ t('app_api', 'Additional options') }}</h3>
					<p v-for="option_key in Object.keys(daemon.deploy_config.additional_options)" :key="option_key">
						<b>{{ option_key }}:</b> {{ daemon.deploy_config.additional_options[option_key] }}
					</p>
				</div>

				<div class="actions">
					<NcButton v-if="daemon.accepts_deploy_id !== 'manual-install'" @click="verifyConnection">
						{{ t('app_api', 'Verify connection') }}
						<template #icon>
							<NcLoadingIcon v-if="verifying" :size="20" />
							<Connection v-else :size="20" />
						</template>
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import NcPasswordField from '@nextcloud/vue/dist/Components/NcPasswordField.js'

import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import Connection from 'vue-material-design-icons/Connection.vue'

export default {
	name: 'DaemonConfigDetailsModal',
	components: {
		NcModal,
		NcButton,
		NcNoteCard,
		NcLoadingIcon,
		NcPasswordField,
		Connection,
	},
	props: {
		daemon: {
			type: Object,
			required: true,
			default: () => {},
		},
		show: {
			type: Boolean,
			required: true,
			default: false,
		},
		isDefault: {
			type: Boolean,
			required: true,
			default: () => false,
		},
	},
	data() {
		return {
			verifying: false,
		}
	},
	methods: {
		closeModal() {
			this.$emit('update:show', false)
		},
		verifyConnection() {
			this.verifying = true
			axios.post(generateUrl(`/apps/app_api/daemons/${this.daemon.name}/check`))
				.then(res => {
					if (res.data.success) {
						showSuccess(t('app_api', 'Daemon connection successful'))
					} else {
						showError(t('app_api', 'Failed to connect to Daemon. Check the logs'))
					}
					this.verifying = false
				})
				.catch(err => {
					this.verifying = false
					showError(t('app_api', 'Failed to check connection to Daemon. Check the logs'))
					console.debug(err)
				})
		},
	},
}
</script>

<style scoped lang="scss">
.daemon-config-modal-details {
	padding: 20px;
}

.actions {
	display: flex;
	justify-content: space-between;
	margin: 20px 0;
}

.external-label {
	display: flex;
	align-items: center;
	width: 100%;

	label {
		margin-right: 5px;
	}
}
</style>
