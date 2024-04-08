<template>
	<div class="register-daemon-config">
		<NcModal :show="show" @close="closeModal">
			<div class="register-daemon-config-body">
				<h2>{{ t('app_api', 'Register Deploy Daemon') }}</h2>
				<div class="templates">
					<div class="external-label" :aria-label="t('app_api', 'Daemon configuration template')">
						<label for="daemon-template">{{ t('app_api', 'Daemon configuration template') }}</label>
						<NcSelect
							id="daemon-template"
							v-model="configurationTab"
							:label-outside="true"
							:options="configurationTemplateOptions"
							:placeholder="t('app_api', 'Select daemon configuration template')" />
					</div>
				</div>
				<form class="daemon-register-form" :aria-label="t('app_api', 'Daemon registration form')">
					<div class="external-label" :aria-label="t('app_api', 'Name')">
						<label for="daemon-name">{{ t('app_api', 'Name') }}</label>
						<NcInputField
							id="daemon-name"
							:value.sync="name"
							:placeholder="t('app_api', 'Unique Deploy Daemon Name')"
							:aria-label="t('app_api', 'Unique Deploy Daemon Name')"
							:error="isDaemonNameValid === true"
							:helper-text="isDaemonNameValidHelperText" />
					</div>
					<div class="external-label" :aria-label="t('app_api', 'Display name')">
						<label for="daemon-display-name">{{ t('app_api', 'Display name') }}</label>
						<NcInputField
							id="daemon-display-name"
							:value.sync="displayName"
							:placeholder="t('app_api', 'Display name')"
							:aria-label="t('app_api', 'Display name')" />
					</div>
					<div class="external-label" :aria-label="t('app_api', 'Deployment method')">
						<label for="daemon-deploy-id">{{ t('app_api', 'Deployment method') }}</label>
						<NcSelect
							id="daemon-deploy-id"
							v-model="acceptsDeployId"
							:disabled="configurationTab.id === 'manual_install'"
							:options="deployMethods"
							:label-outside="true"
							:placeholder="t('app_api', 'Select daemon deploy method')" />
					</div>
					<div class="external-label" :aria-label="t('app_api', 'Daemon host')">
						<label for="daemon-host">{{ t('app_api', 'Daemon host') }}</label>
						<NcInputField
							id="daemon-host"
							:value.sync="host"
							:placeholder="daemonHostHelperText"
							:aria-label="daemonHostHelperText"
							:helper-text="daemonHostHelperText"
							style="max-width: 70%;" />
					</div>
					<div class="external-label" :aria-label="t('app_api', 'Nextcloud URL')">
						<label for="nextcloud-url">{{ t('app_api', 'Nextcloud URL') }}</label>
						<NcInputField
							id="nextcloud-url"
							:value.sync="nextcloud_url"
							:placeholder="t('app_api', 'Nextcloud URL')"
							:aria-label="t('app_api', 'Nextcloud URL')" />
					</div>
					<div class="row">
						<NcCheckboxRadioSwitch
							v-if="isNotManualInstall"
							id="default-deploy-config"
							:checked.sync="defaultDaemon"
							:placeholder="t('app_api', 'Set daemon as default')"
							:aria-label="t('app_api', 'Set daemon as default')">
							{{ t('app_api', 'Set as default daemon') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-if="isNotManualInstall"
							id="https-enabled"
							:checked.sync="httpsEnabled"
							:placeholder="t('app_api', 'Enable https')"
							:aria-label="t('app_api', 'Enable https')"
							@change="onProtocolChange">
							{{ t('app_api', 'Enable https') }}
						</NcCheckboxRadioSwitch>
					</div>
					<template v-if="isNotManualInstall">
						<NcButton :aria-label="t('app_api', 'Deploy config')" style="margin: 10px 0;" @click="deployConfigSettingsOpened = !deployConfigSettingsOpened">
							{{ !deployConfigSettingsOpened ? t('app_api', 'Show deploy config') : t('app_api', 'Hide deploy config') }}
							<template #icon>
								<UnfoldLessHorizontal v-if="deployConfigSettingsOpened" :size="20" />
								<UnfoldMoreHorizontal v-else :size="20" />
							</template>
						</NcButton>
						<div v-show="deployConfigSettingsOpened" class="deploy-config" :aria-label="t('app_api', 'Deploy config')">
							<div class="external-label"
								:aria-label="t('app_api', 'Network')">
								<label for="deploy-config-net">{{ t('app_api', 'Network') }}</label>
								<NcInputField
									id="deploy-config-net"
									:value.sync="deployConfig.net"
									:disabled="daemonProtocol === 'https'"
									:placeholder="t('app_api', 'Docker network name')"
									:aria-label="t('app_api', 'Docker network name')"
									:helper-text="networkHelperText" />
							</div>
							<div v-if="['http', 'https'].includes(daemonProtocol)"
								class="external-label"
								:aria-label="t('app_api', 'HaProxy password')">
								<label for="deploy-config-haproxy-password">{{ t('app_api', 'HaProxy password') }}</label>
								<NcInputField
									id="deploy-config-haproxy-password"
									:value.sync="deployConfig.haproxy_password"
									:error="isHaProxyPasswordValid === false"
									:placeholder="t('app_api', 'AppAPI Docker Socket Proxy authentication password')"
									:aria-label="t('app_api', 'AppAPI Docker Socket Proxy authentication password')"
									:helper-text="haProxyPasswordHelperText" />
							</div>
							<NcCheckboxRadioSwitch
								id="deploy-config-gpus"
								:checked.sync="deployConfig.gpu"
								:placeholder="t('app_api', 'Enable gpus support (attach gpu to ExApp containers)')"
								:aria-label="t('app_api', 'Enable gpus support (attach gpu to ExApp containers)')"
								style="margin-top: 1rem;">
								{{ t('app_api', 'Enable GPUs support') }}
							</NcCheckboxRadioSwitch>
							<p v-if="deployConfig.gpu" class="hint" :aria-label="t('app_api', 'GPUs support enabled hint')">
								{{ t('app_api', 'All available GPU devices on daemon host will be requested to be enabled in ExApp containers by Docker.') }}
								<NcNoteCard>
									{{ t('app_api', 'Only NVIDIA GPUs are supported for now.') }}
								</NcNoteCard>
							</p>
						</div>
					</template>
					<div class="row">
						<NcButton
							type="primary"
							:disabled="canRegister"
							@click="registerDaemon">
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
import { showSuccess, showError } from '@nextcloud/dialogs'
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
			deployMethods: ['docker-install', 'manual-install'],
			httpsEnabled: false,
			host: 'nextcloud-appapi-dsp:2375',
			// replace last slash with empty string
			nextcloud_url: window.location.origin + generateUrl('').slice(0, -1),
			deployConfigSettingsOpened: false,
			deployConfig: {
				net: 'host',
				haproxy_password: '',
				gpu: false,
			},
			defaultDaemon: false,
			registeringDaemon: false,
			configurationTab: { id: DAEMON_TEMPLATES[0].id, label: DAEMON_TEMPLATES[0].displayName },
			configurationTemplateOptions: [
				...DAEMON_TEMPLATES.map(template => { return { id: template.name, label: template.displayName } }),
			],
			verifyingDaemonConnection: false,
		}
	},
	computed: {
		daemonHostHelperText() {
			if (['http', 'https'].includes(this.daemonProtocol)) {
				if (this.acceptsDeployId === 'manual-install') {
					return t('app_api', 'Hostname to access ExApps')
				}
				return t('app_api', 'Hostname or path to access Docker daemon (e.g. nextcloud-appapi-dsp:2375, /var/run/docker.sock)')
			}
			return ''
		},
		daemonProtocol() {
			return this.httpsEnabled ? 'https' : 'http'
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
		isHaProxyPasswordValid() {
			if (this.daemonProtocol === 'https') {
				return this.deployConfig.haproxy_password !== null && this.deployConfig.haproxy_password.length >= 12
			}
			// HaProxy password required only for https
			return true
		},
		haProxyPasswordHelperText() {
			return this.isHaProxyPasswordValid ? t('app_api', 'AppAPI Docker Socket Proxy authentication password') : t('app_api', 'Password must be at least 12 characters long')
		},
		networkHelperText() {
			if (this.httpsEnabled) {
				return t('app_api', 'With https enabled network is set to host')
			}
			return t('app_api', 'Docker network name')
		},
		canRegister() {
			return this.isDaemonNameValid === true || this.isHaProxyPasswordValid === false
		},
	},
	watch: {
		configurationTab(newConfigurationTab) {
			this.setupFormConfiguration(newConfigurationTab)
		},
		httpsEnabled(newHttpsEnabled) {
			if (newHttpsEnabled) {
				this.prevNet = this.deployConfig.net
				this.deployConfig.net = 'host'
			} else {
				this.deployConfig.net = this.prevNet
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
				protocol: this.acceptsDeployId === 'docker-install' ? this.daemonProtocol : 'http',
				host: this.host,
				deploy_config: {
					net: this.deployConfig.net,
					nextcloud_url: this.nextcloud_url,
					gpu: this.deployConfig.gpu,
					haproxy_password: this.deployConfig.haproxy_password ?? '',
				},
			}
		},
		setupFormConfiguration(templateName) {
			const template = Object.assign({}, DAEMON_TEMPLATES.find(template => template.name === templateName.id))
			if (!template) {
				return
			}
			this.name = template.name
			this.displayName = template.displayName
			this.acceptsDeployId = template.acceptsDeployId
			this.httpsEnabled = template.httpsEnabled
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
			if (this.daemonProtocol === 'unix-socket') {
				this.host = '/var/run/docker.sock'
			} else {
				this.host = DAEMON_TEMPLATES.find(template => template.name === this.configurationTab.id).host || ''
			}
		},
		closeModal() {
			const customTemplate = DAEMON_TEMPLATES.find(template => template.name === 'custom')
			this.configurationTab = { id: customTemplate.name, label: customTemplate.displayName }
			this.$emit('update:show', false)
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

	.row {
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
