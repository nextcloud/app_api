<template>
	<div class="register-daemon-config">
		<NcModal :show="show" @close="closeModal">
			<div class="register-daemon-config-body">
				<h2>{{ t('app_api', 'Register Deploy Daemon') }}</h2>
				<div class="templates">
					<div class="external-label">
						<label for="daemon-template">{{ t('app_api', 'Daemon configuration template') }}</label>
						<NcSelect
							id="daemon-template"
							v-model="configurationTab"
							:label-outside="true"
							:options="configurationTemplateOptions"
							:placeholder="t('app_api', 'Select daemon configuration template')" />
					</div>
				</div>
				<form class="daemon-register-form">
					<div class="external-label">
						<label for="daemon-name">{{ t('app_api', 'Name') }}</label>
						<NcInputField
							id="daemon-name"
							:value.sync="name"
							:placeholder="t('app_api', 'Unique Deploy Daemon Name')"
							:aria-label="t('app_api', 'Unique Deploy Daemon Name')"
							:error="isDaemonNameValid === true"
							:helper-text="isDaemonNameValidHelperText" />
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
						<label for="daemon-deploy-id">{{ t('app_api', 'Deployment method') }}</label>
						<NcSelect
							id="daemon-deploy-id"
							v-model="acceptsDeployId"
							:options="deployIds"
							:placeholder="t('app_api', 'Select daemon deploy method')" />
					</div>
					<div v-if="isNotManualInstall" class="external-label">
						<label for="daemon-protocol">{{ t('app_api', 'Protocol') }}</label>
						<NcSelect
							id="daemon-protocol"
							v-model="protocol"
							:options="protocols"
							:placeholder="t('app_api', 'Select protocol')"
							@input="onProtocolChange" />
					</div>
					<div v-if="isNotManualInstall" class="external-label">
						<label for="daemon-host">{{ t('app_api', 'Daemon host') }}</label>
						<NcInputField
							id="daemon-host"
							:value.sync="host"
							:placeholder="t('app_api', 'Daemon host (e.g. /var/run/docker.sock, proxy-domain.com:2375)')"
							:aria-label="t('app_api', 'Daemon host (e.g. /var/run/docker.sock, proxy-domain.com:2375)')"
							:helper-text="daemonHostHelperText" />
					</div>
					<div v-if="isNotManualInstall" class="external-label">
						<label for="nextcloud-url">{{ t('app_api', 'Nextcloud URL') }}</label>
						<NcInputField
							id="nextcloud-url"
							:value.sync="nextcloud_url"
							:placeholder="t('app_api', 'Nextcloud URL')"
							:aria-label="t('app_api', 'Nextcloud URL')" />
					</div>
					<NcCheckboxRadioSwitch
						v-if="isNotManualInstall"
						id="default-deploy-config"
						:checked.sync="defaultDaemon"
						:placeholder="t('app_api', 'Set daemon as default')"
						:aria-label="t('app_api', 'Set daemon as default')"
						style="margin-top: 1rem;">
						{{ t('app_api', 'Set as default daemon') }}
					</NcCheckboxRadioSwitch>
					<template v-if="isNotManualInstall">
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
									:placeholder="t('app_api', 'Docker network name')"
									:aria-label="t('app_api', 'Docker network name')"
									:helper-text="t('app_api', 'Docker network name')" />
							</div>
							<div v-if="['http', 'https'].includes(protocol)" class="external-label">
								<label for="deploy-config-haproxy-password">{{ t('app_api', 'HaProxy password') }}</label>
								<NcInputField
									id="deploy-config-haproxy-password"
									:value.sync="deployConfig.haproxy_password"
									:placeholder="t('app_api', 'AppAPI Docker Socket Proxy authentication password')"
									:aria-label="t('app_api', 'AppAPI Docker Socket Proxy authentication password')"
									:helper-text="t('app_api', 'AppAPI Docker Socket Proxy authentication password')" />
							</div>
							<NcCheckboxRadioSwitch
								id="deploy-config-gpus"
								:checked.sync="deployConfig.gpu"
								:placeholder="t('app_api', 'Enable gpus support (attach gpu to ExApp containers)')"
								:aria-label="t('app_api', 'Enable gpus support (attach gpu to ExApp containers)')"
								style="margin-top: 1rem;">
								{{ t('app_api', 'Enable GPUs support') }}
							</NcCheckboxRadioSwitch>
							<p v-if="deployConfig.gpu" class="hint">
								{{ t('app_api', 'All available GPU devices on daemon host will be requested to be enabled in ExApp containers by Docker.') }}
								<NcNoteCard>
									{{ t('app_api', 'Only NVIDIA GPUs are supported for now.') }}
								</NcNoteCard>
							</p>
						</div>
					</template>
					<div class="actions">
						<NcButton type="primary" :disabled="isDaemonNameValid === true" @click="registerDaemon">
							{{ t('app_api', 'Register') }}
							<template #icon>
								<NcLoadingIcon v-if="registeringDaemon" :size="20" />
								<Check v-else :size="20" />
							</template>
						</NcButton>
						<NcButton v-if="isNotManualInstall" type="secondary" @click="verifyDaemonConnection">
							{{ t('app_api', 'Check connection') }}
							<template #icon>
								<NcLoadingIcon v-if="verifyingDaemonConnection" :size="20" />
								<Connection v-else :size="20" />
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
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'

import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import Check from 'vue-material-design-icons/Check.vue'
import Connection from 'vue-material-design-icons/Connection.vue'
import UnfoldLessHorizontal from 'vue-material-design-icons/UnfoldLessHorizontal.vue'
import UnfoldMoreHorizontal from 'vue-material-design-icons/UnfoldMoreHorizontal.vue'
import { DAEMON_TEMPLATES } from '../../constants/daemonTemplates.js'

export default {
	name: 'RegisterDaemonConfigModal',
	components: {
		NcLoadingIcon,
		NcModal,
		NcInputField,
		UnfoldLessHorizontal,
		UnfoldMoreHorizontal,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSelect,
		NcButton,
		Check,
		Connection,
	},
	props: {
		show: {
			type: Boolean,
			required: true,
			default: false,
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
			name: 'docker_local',
			displayName: 'Docker Local',
			acceptsDeployId: 'docker-install',
			deployIds: ['docker-install', 'manual-install'],
			protocol: 'unix-socket',
			protocols: ['unix-socket', 'http', 'https'],
			host: '/var/run/docker.sock',
			// replace last slash with empty string
			nextcloud_url: window.location.origin + generateUrl('').slice(0, -1),
			deployConfigSettingsOpened: false,
			deployConfig: {
				net: 'host',
				haproxy_password: null,
				gpu: false,
			},
			defaultDaemon: false,
			registeringDaemon: false,
			registerInOneClickLoading: false,
			configurationTab: { id: 'custom', label: 'Custom' },
			configurationTemplateOptions: [
				{ id: 'custom', label: 'Custom' },
				...DAEMON_TEMPLATES.map(template => { return { id: template.name, label: template.displayName } }),
			],
			verifyingDaemonConnection: false,
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
		isNotManualInstall() {
			return this.acceptsDeployId !== 'manual-install'
		},
		isDaemonNameValid() {
			return this.daemons.some(daemon => daemon.name === this.name)
		},
		isDaemonNameValidHelperText() {
			return this.isDaemonNameValid === true ? t('app_api', 'Daemon with this name already exists') : ''
		},
	},
	watch: {
		configurationTab(newConfigurationTab) {
			this.setupFormConfiguration(newConfigurationTab)
		},
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
				daemonConfigParams: this._buildDaemonParams(),
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
		verifyDaemonConnection() {
			this.verifyingDaemonConnection = true
			axios.post(generateUrl('/apps/app_api/daemons/verify_connection'), {
				daemonParams: this._buildDaemonParams(),
			})
				.then(res => {
					if (res.data.success) {
						showSuccess(t('app_api', 'Daemon connection successful'))
					} else {
						showError(t('app_api', 'Failed to connect to Daemon. Check the logs'))
					}
					this.verifyingDaemonConnection = false
				})
				.catch(err => {
					this.verifyingDaemonConnection = false
					showError(t('app_api', 'Failed to check connection to Daemon. Check the logs'))
					console.debug(err)
				})
		},
		_buildDaemonParams() {
			return {
				name: this.name,
				display_name: this.displayName,
				accepts_deploy_id: this.acceptsDeployId,
				protocol: this.acceptsDeployId === 'docker-install' ? this.protocol : 0,
				host: this.acceptsDeployId === 'docker-install' ? this.host : 0,
				deploy_config: {
					net: this.deployConfig.net,
					nextcloud_url: this.nextcloud_url,
					gpu: this.deployConfig.gpu,
					haproxy_password: this.deployConfig.haproxy_password ?? null,
				},
			}
		},
		setupFormConfiguration(templateName) {
			if (templateName.id === 'custom') {
				this.setFormDefaults()
				return
			}
			const template = DAEMON_TEMPLATES.find(template => template.name === templateName.id)
			if (!template) {
				return
			}
			this.name = template.name
			this.displayName = template.displayName
			this.acceptsDeployId = template.acceptsDeployId
			this.protocol = template.protocol
			this.host = template.host
			this.nextcloud_url = template.nextcloud_url ?? window.location.origin + generateUrl('').slice(0, -1)
			this.deployConfigSettingsOpened = template.deployConfigSettingsOpened
			this.deployConfig.net = template.deployConfig.net
			this.deployConfig.haproxy_password = template.deployConfig.haproxy_password
			this.deployConfig.gpu = template.deployConfig.gpu
			this.defaultDaemon = template.defaultDaemon
		},
		onProtocolChange() {
			// Prefill default value
			if (this.host === 'unix-socket') {
				this.host = '/var/run/docker.sock'
			} else {
				if (this.configurationTab.id === 'custom') {
					this.host = ''
				} else {
					this.host = DAEMON_TEMPLATES.find(template => template.name === this.configurationTab.id).host
				}
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
				haproxy_password: null,
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
		color: var(--color-warning-text);
		padding: 10px;
	}

	.templates {
		display: flex;
		margin: 0 auto;
		width: fit-content;
		border-bottom: 1px solid var(--color-border-dark);
		padding-bottom: 20px;
	}
}
</style>
