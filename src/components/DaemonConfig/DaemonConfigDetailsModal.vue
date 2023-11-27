<template>
	<div class="daemon-config-modal">
		<NcModal :show="show" @close="closeModal">
			<div class="daemon-config-modal-details">
				<h2>{{ t('app_api', 'Deploy Daemon') }} - {{ daemon.display_name }}</h2>

				<NcNoteCard v-if="isDefault" type="success">
					{{ t('app_api', 'Default daemon. ExApps will be installed on it') }}
				</NcNoteCard>

				<NcNoteCard v-if="daemon.accepts_deploy_id === 'manual-install'" type="warning">
					{{ t('app_api', 'Manual install daemon usually used for development. It cannot be set as default daemon.') }}
				</NcNoteCard>

				<p><b>{{ t('app_api', 'Name: ') }}</b>{{ daemon.name }}</p>
				<p><b>{{ t('app_api', 'Protocol: ') }}</b>{{ daemon.protocol }}</p>
				<p><b>{{ t('app_api', 'Host: ') }}</b>{{ daemon.host }}</p>

				<h3>{{ t('app_api', 'Deploy config') }}</h3>
				<p><b>{{ t('app_api', 'Docker network: ') }}</b>{{ daemon.deploy_config.net }}</p>
				<p><b>{{ t('app_api', 'Host: ') }}</b>{{ daemon.deploy_config.host || 'null' }}</p>
				<p><b>{{ t('app_api', 'Nextcloud url: ') }}</b>{{ daemon.deploy_config.nextcloud_url }}</p>

				<h3>{{ t('app_api', 'SSL params') }}</h3>
				<p><b>{{ t('app_api', 'SSL key: ') }}</b>{{ daemon.deploy_config.ssl_key || 'null' }}</p>
				<p><b>{{ t('app_api', 'SSL key pass: ') }}</b>{{ daemon.deploy_config.ssl_key_password || 'null' }}</p>
				<p><b>{{ t('app_api', 'SSL cert: ') }}</b>{{ daemon.deploy_config.ssl_cert || 'null' }}</p>
				<p><b>{{ t('app_api', 'SSL cert pass: ') }}</b>{{ daemon.deploy_config.ssl_cert_password || 'null' }}</p>
				<p><b>{{ t('app_api', 'GPUs support: ') }}</b>{{ daemon.deploy_config?.gpu || 'false' }}</p>

				<div class="actions">
					<NcButton v-if="daemon.accepts_deploy_id !== 'manual-install'" @click="verifyConnection">
						{{ t('app_api', 'Verify connection') }}
						<template #icon>
							<NcLoadingIcon v-if="verifying" :size="20" />
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
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'

export default {
	name: 'DaemonConfigDetailsModal',
	components: {
		NcModal,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
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
</style>
