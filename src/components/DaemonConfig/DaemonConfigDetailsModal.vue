<!--
  - SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="daemon-config-modal">
		<NcModal :show="show"
			:name="t('app_api', 'Deploy daemon configuration') + ' - ' + daemon.display_name"
			@close="closeModal">
			<div class="daemon-config-modal-details" :aria-label="t('app_api', 'Deploy daemon configuration details')">
				<h2>{{ t('app_api', 'Deploy daemon') }} - {{ daemon.display_name }}</h2>

				<NcNoteCard v-if="isDefault" type="success">
					{{ t('app_api', 'Default daemon. ExApps will be installed on it') }}
				</NcNoteCard>

				<NcNoteCard v-if="daemon.accepts_deploy_id === 'manual-install'" type="warning">
					{{ t('app_api', 'The "Manual install" daemon is usually used for development. It cannot be set as the default daemon.') }}
				</NcNoteCard>

				<p><b>{{ t('app_api', 'ExApps installed') }}: </b>{{ daemon.exAppsCount }}</p>
				<p><b>{{ t('app_api', 'Name') }}: </b>{{ daemon.name }}</p>
				<p><b>{{ t('app_api', 'Protocol') }}: </b>{{ daemon.protocol }}</p>
				<p><b>{{ t('app_api', 'Host') }}: </b>{{ daemon.host }}</p>
				<p v-if="daemon.deploy_config.harp">
					<b>{{ t('app_api', 'ExApp direct communication (FRP disabled)') }}: </b>
					{{ daemon.deploy_config.harp.exapp_direct ?? false }}
				</p>

				<h3>{{ t('app_api', 'Deploy options') }}</h3>
				<p><b>{{ t('app_api', 'Docker network') }}: </b>{{ daemon.deploy_config.net }}</p>
				<p><b>{{ t('app_api', 'Nextcloud URL') }}: </b>{{ daemon.deploy_config.nextcloud_url }}</p>
				<p v-if="daemon.deploy_config.haproxy_password" class="external-label">
					<label for="haproxy_password"><b>{{ t('app_api', 'HaProxy password') }}: </b></label>
					<NcPasswordField
						id="haproxy_password"
						:model-value="daemon.deploy_config?.haproxy_password"
						:disable="true"
						style="width: fit-content;"
						readonly
						autocomplete="off" />
				</p>
				<p>
					<b>{{ t('app_api', 'GPU support') }}:</b> {{ daemon.deploy_config.computeDevice && daemon.deploy_config?.computeDevice?.id !== 'cpu' || false }}
				</p>
				<p v-if="daemon.deploy_config.computeDevice">
					<b>{{ t('app_api', 'Computation device') }}:</b> {{ daemon.deploy_config?.computeDevice?.label }}
				</p>
				<p><b>{{ t('app_api', 'Memory limit') }}:</b> {{ formatMemoryLimit(daemon.deploy_config?.resourceLimits?.memory) }}</p>

				<p><b>{{ t('app_api', 'CPU limit') }}:</b> {{ formatCpuLimit(daemon.deploy_config?.resourceLimits?.nanoCPUs) }}</p>

				<div v-if="daemon.deploy_config.additional_options" class="additional-options">
					<h3>{{ t('app_api', 'Additional options') }}</h3>
					<p v-for="option_key in Object.keys(daemon.deploy_config.additional_options)" :key="option_key">
						<b>{{ option_key }}:</b> {{ daemon.deploy_config.additional_options[option_key] }}
					</p>
				</div>

				<div class="actions">
					<NcButton v-if="daemon.accepts_deploy_id !== 'manual-install'" @click="verifyConnection">
						{{ t('app_api', 'Check connection') }}
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

import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'

import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
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
						showError(t('app_api', 'Failed to connect to the daemon. Check the logs'))
					}
					this.verifying = false
				})
				.catch(err => {
					this.verifying = false
					showError(t('app_api', 'Failed to check connection to the daemon. Check the logs'))
					console.debug(err)
				})
		},
		formatMemoryLimit(memoryBytes) {
			if (!memoryBytes) {
				return t('app_api', 'Unlimited')
			}
			const memoryMiB = memoryBytes / (1024 * 1024)
			if (memoryMiB >= 1024) {
				const memoryGiB = memoryMiB / 1024
				return t('app_api', '{size} GiB', { size: memoryGiB.toFixed(1) })
			}
			return t('app_api', '{size} MiB', { size: Math.round(memoryMiB) })
		},
		formatCpuLimit(nanoCpus) {
			if (!nanoCpus) {
				return t('app_api', 'Unlimited')
			}
			const cpus = nanoCpus / 1000000000
			return n('app_api', '{n} CPU', '{n} CPUs', cpus, { n: cpus.toFixed(2) })
		},
	},
}
</script>

<style scoped lang="scss">
.daemon-config-modal-details {
	padding: 20px;

	h2 {
		margin-top: 0;
	}
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
