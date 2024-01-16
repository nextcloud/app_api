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
						<label for="nextcloud-url">{{ t('app_api', 'Nextcloud URL') }}</label>
						<NcInputField
							id="nextcloud-url"
							:value.sync="nextcloud_url"
							:placeholder="t('app_api', 'Nextcloud URL')"
							:aria-label="t('app_api', 'Nextcloud URL')" />
					</div>
					<NcCheckboxRadioSwitch
						v-if="acceptsDeployId !== 'manual-install'"
						id="default-deploy-config"
						:checked.sync="defaultDaemon"
						:placeholder="t('app_api', 'Set daemon as default')"
						:aria-label="t('app_api', 'Set daemon as default')"
						style="margin-top: 1rem;">
						{{ t('app_api', 'Set as default daemon') }}
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
									:placeholder="t('app_api', 'Docker network name')"
									:aria-label="t('app_api', 'Docker network name')"
									:helper-text="t('app_api', 'Docker network name')" />
							</div>
							<div class="external-label">
								<label for="deploy-config-host">{{ t('app_api', 'Host') }}</label>
								<NcInputField
									v-if="deployConfig.net === 'host'"
									id="deploy-config-host"
									:value.sync="deployConfig.host"
									:placeholder="t('app_api', 'Hostname to reach ExApp (optional)')"
									:aria-label="t('app_api', 'Hostname to reach ExApp (optional)')"
									:helper-text="t('app_api', 'Hostname to reach ExApp (optional)')" />
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
								{{ t('app_api', 'All GPU devices will be requested to be enabled in ExApp containers by Docker') }}
							</p>
						</div>
					</template>
					<div class="actions">
						<NcButton type="primary" @click="registerDaemon">
							{{ t('app_api', 'Register') }}
							<template #icon>
								<NcLoadingIcon v-if="registeringDaemon" :size="20" />
								<Check v-else :size="20" />
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

import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import Check from 'vue-material-design-icons/Check.vue'
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
		NcSelect,
		NcButton,
		Check,
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
			// replace last slash with empty string
			nextcloud_url: window.location.origin + generateUrl('').slice(0, -1),
			deployConfigSettingsOpened: false,
			deployConfig: {
				net: 'host',
				host: 'localhost',
				haproxy_password: null,
				gpu: false,
			},
			defaultDaemon: false,
			registeringDaemon: false,
			registerInOneClickLoading: false,
			configurationTab: 'custom',
			configurationTemplateOptions: ['custom', ...DAEMON_TEMPLATES.map(template => template.name)],
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
		'deployConfig.net'(newNet) {
			if (newNet === 'host') {
				this.deployConfig.host = 'localhost'
			} else {
				this.deployConfig.host = ''
			}
		},
	},
	methods: {
		DAEMON_TEMPLATES() {
			return DAEMON_TEMPLATES
		},
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
		setupFormConfiguration(templateName) {
			if (templateName === 'custom') {
				this.setFormDefaults()
				return
			}
			const template = DAEMON_TEMPLATES.find(template => template.name === templateName)
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
			this.deployConfig.host = template.deployConfig.host
			this.deployConfig.haproxy_password = template.deployConfig.haproxy_password
			this.deployConfig.gpu = template.deployConfig.gpu
			this.defaultDaemon = template.defaultDaemon
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
				host: 'localhost',
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
		color: var(--color-warning-text)
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
