<template>
	<div class="register-daemon-config">
		<NcModal :show="show" @close="closeModal">
			<div class="register-daemon-config-body">
				<h2>{{ t('app_api', 'Register Deploy Daemon') }}</h2>
				<NcNoteCard type="warning">
					{{ t('app_api', 'Supported daemon accepts-deploy-id:') }}
					<b>
						<a href="https://cloud-py-api.github.io/app_api/DeployConfigurations.html" target="_blank">docker-install</a>
					</b>,
					<b>
						<a href="https://cloud-py-api.github.io/app_api/tech_details/Deployment.html#manual-install-for-development" target="_blank">manual-install</a>
					</b>
				</NcNoteCard>
				<p>{{ t('app_api', 'This is default settings for regular DaemonConfig. You can change it as you wish, more info on that in docs.') }}</p>
				<form class="daemon-register-form">
					<div class="external-label">
						<label for="daemon-name">{{ t('app_api', 'Name') }}</label>
						<NcInputField
							id="daemon-name"
							:value.sync="name"
							:placeholder="t('app_api', 'Unique Deploy Daemon Name')"
							:aria-label="t('app_api', 'Unique Deploy Daemon Name')" />
					</div>
					<div class="external-label">
						<label for="daemon-display-name">{{ t('app_api', 'Display name') }}</label>
						<NcInputField
							id="daemon-display-name"
							:value.sync="displayName"
							:placeholder="t('app_api', 'Display name')"
							:aria-label="t('app_api', 'Display name')" />
					</div>
					<div class="external-label">
						<label for="daemon-deploy-id">{{ t('app_api', 'Accepts deploy ID') }}</label>
						<NcSelect
							id="daemon-deploy-id"
							v-model="acceptsDeployId"
							:options="deployIds"
							:placeholder="t('app_api', 'Select daemon accepts-deploy-id')" />
					</div>
					<div v-if="acceptsDeployId !== 'manual-install'" class="external-label">
						<label for="daemon-protocol">{{ t('app_api', 'Protocol') }}</label>
						<NcSelect
							id="daemon-protocol"
							v-model="protocol"
							:options="protocols"
							:placeholder="t('app_api', 'Select protocol')"
							@input="onProtocolChange" />
					</div>
					<div v-if="acceptsDeployId !== 'manual-install'" class="external-label">
						<label for="daemon-host">{{ t('app_api', 'Daemon host') }}</label>
						<NcInputField
							id="daemon-host"
							:value.sync="host"
							:placeholder="t('app_api', 'Daemon host (e.g. /var/run/docker.sock, proxy-domain.com:2375)')"
							:aria-label="t('app_api', 'Daemon host (e.g. /var/run/docker.sock, proxy-domain.com:2375)')"
							:helper-text="daemonHostHelperText" />
					</div>
					<div v-if="acceptsDeployId !== 'manual-install'" class="external-label">
						<label for="nextcloud-url">{{ t('app_api', 'Nextcloud url') }}</label>
						<NcInputField
							id="nextcloud-url"
							:value.sync="nextcloud_url"
							:placeholder="t('app_api', 'Nextcloud url')"
							:aria-label="t('app_api', 'Nextcloud url')" />
					</div>
					<NcCheckboxRadioSwitch
						v-if="acceptsDeployId !== 'manual-install'"
						id="default-deploy-config"
						:checked.sync="defaultDaemon"
						:placeholder="t('app_api', 'Set daemon as default')"
						:aria-label="t('app_api', 'Set daemon as default')"
						style="margin-top: 1rem;">
						{{ t('app_api', 'Default daemon') }}
					</NcCheckboxRadioSwitch>
					<template v-if="acceptsDeployId !== 'manual-install'">
						<NcButton :aria-label="t('app_api', 'Deploy config')" style="margin: 10px 0;" @click="deployConfigSettingsOpened = !deployConfigSettingsOpened">
							{{ !deployConfigSettingsOpened ? t('app_api', 'Show deploy config') : t('app_api', 'Hide deploy config') }}
							<template #icon>
								<UnfoldLessHorizontal v-if="deployConfigSettingsOpened" :size="20" />
								<UnfoldMoreHorizontal v-else :size="20" />
							</template>
						</NcButton>
						<div v-show="deployConfigSettingsOpened" class="deploy-config">
							<div class="external-label">
								<label for="deploy-config-net">{{ t('app_api', 'Network') }}</label>
								<NcInputField
									id="deploy-config-net"
									:value.sync="deployConfig.net"
									:placeholder="t('app_api', 'Docker network name (default: host)')"
									:aria-label="t('app_api', 'Docker network name (default: host)')"
									:helper-text="t('app_api', 'Docker network name (default: host)')" />
							</div>
							<NcCheckboxRadioSwitch
								id="deploy-config-gpus"
								:checked.sync="deployConfig.gpu"
								:placeholder="t('app_api', 'Enable gpus support (attach gpu to ExApp containers)')"
								:aria-label="t('app_api', 'Enable gpus support (attach gpu to ExApp containers))')"
								style="margin-top: 1rem;">
								{{ t('app_api', 'GPUs support') }}
							</NcCheckboxRadioSwitch>
							<p v-if="deployConfig.gpu" class="hint">
								{{ t('app_api', 'All GPU devices will be requested to be enabled in ExApp containers') }}
							</p>
						</div>
					</template>
					<div class="actions">
						<NcButton type="primary" @click="registerDaemon">
							{{ t('app_api', 'Register') }}
							<template #icon>
								<NcLoadingIcon v-if="registeringDaemon" :size="20" />
							</template>
						</NcButton>
					</div>
				</form>
			</div>
		</NcModal>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showMessage, showSuccess, showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'

import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcInputField from '@nextcloud/vue/dist/Components/NcInputField.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'

import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import UnfoldLessHorizontal from 'vue-material-design-icons/UnfoldLessHorizontal.vue'
import UnfoldMoreHorizontal from 'vue-material-design-icons/UnfoldMoreHorizontal.vue'

export default {
	name: 'RegisterDaemonConfigModal',
	components: {
		NcLoadingIcon,
		NcNoteCard,
		NcModal,
		NcInputField,
		UnfoldLessHorizontal,
		UnfoldMoreHorizontal,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcButton,
	},
	props: {
		show: {
			type: Boolean,
			required: true,
			default: false,
		},
		getAllDaemons: {
			type: Function,
			required: true,
		},
	},
	data() {
		return {
			name: 'docker_local',
			displayName: 'Docker Local',
			acceptsDeployId: 'docker-install',
			deployIds: ['docker-install', 'manual-install'],
			protocol: 'unix-socket',
			protocols: ['unix-socket', 'http', 'https'],
			host: '/var/run/docker.sock',
			nextcloud_url: window.location.origin + generateUrl(''),
			deployConfigSettingsOpened: false,
			deployConfig: {
				net: 'host',
				host: '',
				ssl_key: '',
				ssl_key_password: '',
				ssl_cert: '',
				ssl_cert_password: '',
				gpu: false,
			},
			defaultDaemon: false,
			registeringDaemon: false,
			registerInOneClickLoading: false,
		}
	},
	computed: {
		daemonHostHelperText() {
			if (this.protocol === 'unix-socket') {
				return t('app_api', 'Unix socket path (e.g. /var/run/docker.sock)')
			} else if (['http', 'https'].includes(this.protocol)) {
				return t('app_api', 'Host with port (e.g. proxy-domain.com:2375)')
			}
			return ''
		},
	},
	watch: {
		acceptsDeployId(newAcceptsDeployId) {
			if (newAcceptsDeployId === 'manual-install') {
				this.name = 'manual_install'
				this.displayName = 'Manual install'
			} else {
				this.name = 'docker_local'
				this.displayName = 'Docker Local'
			}
		},
	},
	methods: {
		registerDaemon() {
			this.registeringDaemon = true
			axios.post(generateUrl('/apps/app_api/daemons'), {
				daemonConfigParams: {
					name: this.name,
					display_name: this.displayName,
					accepts_deploy_id: this.acceptsDeployId,
					protocol: this.acceptsDeployId === 'docker-install' ? this.protocol : 0,
					host: this.acceptsDeployId === 'docker-install' ? this.host : 0,
					deploy_config: {
						net: this.deployConfig.net,
						host: this.deployConfig.host,
						nextcloud_url: this.nextcloud_url,
						ssl_key: this.deployConfig.ssl_key,
						ssl_key_password: this.deployConfig.ssl_key_password,
						ssl_cert: this.deployConfig.ssl_cert,
						ssl_cert_password: this.deployConfig.ssl_cert_password,
						gpu: this.deployConfig.gpu,
					},
				},
				defaultDaemon: this.acceptsDeployId === 'docker-install' ? this.defaultDaemon : false,
			})
				.then(res => {
					this.registeringDaemon = false
					if (res.data.success) {
						showSuccess(t('app_api', 'DaemonConfig successfully registered'))
						this.closeModal()
						this.getAllDaemons()
					} else {
						showError(t('app_api', 'Failed to register DaemonConfig. Check the logs'))
					}
				})
				.catch(err => {
					this.registeringDaemon = false
					console.debug(err)
					showError(t('app_api', 'Failed to register DaemonConfig. Check the logs'))
				})
		},
		onProtocolChange() {
			// Prefill default value
			if (this.host === 'unix-socket') {
				this.host = '/var/run/docker.sock'
			} else {
				this.host = ''
			}
		},
		closeModal() {
			this.setFormDefaults()
			this.$emit('update:show', false)
		},
		registerInOneClick() {
			showMessage('Register daemon automatically')
		},
		setFormDefaults() {
			this.name = 'docker_local'
			this.displayName = 'Docker Local'
			this.acceptsDeployId = 'docker-install'
			this.protocol = 'unix-socket'
			this.host = '/var/run/docker.sock'
			this.deployConfigSettingsOpened = false
			this.deployConfig = {
				net: 'host',
				host: '',
				ssl_key: '',
				ssl_key_password: '',
				ssl_cert: '',
				ssl_cert_password: '',
				gpu: false,
			}
			this.registeringDaemon = false
			this.registerInOneClickLoading = false
		},
	},
}
</script>

<style scoped lang="scss">
.register-daemon-config-body {
	padding: 20px;

	.external-label {
		display: flex;
		align-items: center;
		justify-content: space-between;
		width: 100%;
		margin-top: 1rem;

		label {
			flex: fit-content;
			margin-right: 10px;
		}

		.input-field {
			flex: fit-content;
		}
	}

	.note a {
		color: #fff;
		text-decoration: underline;
	}

	.actions {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-top: 10px;
	}

	.hint {
		color: var(--color-warning-text)
	}
}
</style>
