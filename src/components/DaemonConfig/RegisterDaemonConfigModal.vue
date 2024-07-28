<template>
	<div class="register-daemon-config">
		<NcModal :show="show" @close="closeModal">
			<div class="register-daemon-config-body">
				<h2>{{ isEdit ? t('app_api', 'Edit Deploy Daemon') : t('app_api', 'Register Deploy Daemon') }}</h2>
				<div v-if="!isEdit" class="templates">
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
							:helper-text="getNextcloudUrlHelperText"
							:input-class="getNextcloudUrlHelperText !== '' ? 'text-warning' : ''"
							:value.sync="nextcloud_url"
							style="max-width: 70%;"
							:placeholder="t('app_api', 'Nextcloud URL')"
							:aria-label="t('app_api', 'Nextcloud URL')" />
					</div>
					<div class="row">
						<NcCheckboxRadioSwitch
							v-if="isNotManualInstall && !isEdit"
							id="default-deploy-config"
							:checked.sync="defaultDaemon"
							:placeholder="t('app_api', 'Set daemon as default')"
							:aria-label="t('app_api', 'Set daemon as default')">
							{{ t('app_api', 'Set as default daemon') }}
						</NcCheckboxRadioSwitch>
						<div v-if="isEdit" />
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
									:helper-text="getNetworkHelperText || t('app_api', 'Docker network name')"
									:input-class="getNetworkHelperText !== '' ? 'text-warning' : ''" />
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
							<NcSelect
								id="compute-device"
								v-model="deployConfig.computeDevice"
								:options="computeDevices"
								:input-label="t('app_api', 'Compute device')" />
							<p v-if="getComputeDeviceHelperText !== ''"
								class="hint">
								{{ getComputeDeviceHelperText }}
							</p>

							<template v-if="additionalOptions.length > 0">
								<div class="row" style="flex-direction: column;">
									<div
										v-for="(option, index) in additionalOptions"
										:id="option.key"
										:key="index"
										class="external-label"
										:aria-label="t('app_api', 'Additional option')">
										<label :for="option.key">{{ option.key }}</label>
										<div class="additional-option">
											<NcInputField
												:id="option.key"
												:value.sync="option.value"
												:placeholder="option.value"
												:aria-label="option.value"
												style="margin: 0 5px 0 0; width: fit-content;" />
											<NcButton type="tertiary" @click="removeAdditionalOption(option, index)">
												<template #icon>
													<Close :size="20" />
												</template>
											</NcButton>
										</div>
									</div>
								</div>
							</template>

							<div class="additional-options">
								<div style="display: flex; justify-content: flex-end;">
									<NcButton type="tertiary" @click="addAdditionalOption">
										<template #icon>
											<Plus :size="20" />
										</template>
										{{ t('app_api', 'Add additional option') }}
									</NcButton>
								</div>
								<template v-if="addingAdditionalOption">
									<div class="row" style="align-items: start;">
										<NcInputField
											id="additional-option-key"
											ref="additionalOptionKey"
											:value.sync="additionalOption.key"
											:label="t('app_api', 'Option key (unique)')"
											:placeholder="t('app_api', 'Option key (unique, e.g. my_key)')"
											:error="additionalOption.key.trim() === ''"
											:helper-text="additionalOption.key.trim() === '' ? t('app_api', 'Option key is required') : ''"
											style="margin: 0 5px 0 0;" />
										<NcInputField
											id="additional-option-value"
											:value.sync="additionalOption.value"
											:label="t('app_api', 'Option value')"
											:placeholder="t('app_api', 'Option value')"
											:error="additionalOption.value.trim() === ''"
											:helper-text="additionalOption.value.trim() === '' ? t('app_api', 'Option value is required') : ''"
											style="margin: 0 5px 0 0;" />
										<NcButton
											type="tertiary"
											:aria-label="t('app_api', 'Confirm')"
											:disabled="isAdditionalOptionValid === false"
											@click="confirmAddingAdditionalOption">
											<template #icon>
												<Check :size="20" />
											</template>
										</NcButton>
										<NcButton
											type="tertiary"
											:aria-label="t('app_api', 'Cancel')"
											@click="cancelAddingAdditionalOption">
											<template #icon>
												<Close :size="20" />
											</template>
										</NcButton>
									</div>
								</template>
							</div>
						</div>
					</template>

					<div class="row">
						<NcButton
							type="primary"
							:disabled="canRegister"
							@click="isEdit ? updateDaemon() : registerDaemon()">
							{{ isEdit ? t('app_api', 'Save') : t('app_api', 'Register') }}
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

import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import Check from 'vue-material-design-icons/Check.vue'
import Connection from 'vue-material-design-icons/Connection.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Close from 'vue-material-design-icons/Close.vue'
import UnfoldLessHorizontal from 'vue-material-design-icons/UnfoldLessHorizontal.vue'
import UnfoldMoreHorizontal from 'vue-material-design-icons/UnfoldMoreHorizontal.vue'
import { DAEMON_TEMPLATES, DAEMON_COMPUTE_DEVICES } from '../../constants/daemonTemplates.js'

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
		Connection,
		Plus,
		Close,
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
		daemon: {
			type: Object,
			required: false,
			default: () => null,
		},
		isDefaultDaemon: {
			type: Boolean,
			required: false,
			default: () => false,
		},
	},
	data() {
		const data = {
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
				computeDevice: {
					id: 'cpu',
					label: 'CPU',
				},
			},
			defaultDaemon: false,
			registeringDaemon: false,
			configurationTab: { id: DAEMON_TEMPLATES[0].id, label: DAEMON_TEMPLATES[0].displayName },
			configurationTemplateOptions: [
				...DAEMON_TEMPLATES.map(template => { return { id: template.name, label: template.displayName } }),
			],
			verifyingDaemonConnection: false,
			computeDevices: DAEMON_COMPUTE_DEVICES,
			addingAdditionalOption: false,
			additionalOption: {
				key: '',
				value: '',
			},
			additionalOptions: [],
		}

		if (this.daemon !== null) {
			data.name = this.daemon.name
			data.displayName = this.daemon.display_name
			data.acceptsDeployId = this.daemon.accepts_deploy_id
			// TODO: Investigate why this is not working properly.
			// Throws error on change if value is "true". Seems to be unrelated to this change. Also reproducible with httpsEnabled = true as default.
			data.httpsEnabled = this.daemon.protocol === 'https'
			data.host = this.daemon.host
			data.nextcloud_url = this.daemon.deploy_config.nextcloud_url
			data.deployConfig.net = this.daemon.deploy_config.net
			data.deployConfig.haproxy_password = this.daemon.deploy_config.haproxy_password
			data.deployConfig.computeDevice = this.daemon.deploy_config.computeDevice
			data.defaultDaemon = this.isDefaultDaemon
			data.additionalOptions = Object.entries(this.daemon.deploy_config.additional_options ?? {}).map(([key, value]) => ({ key, value }))
		}

		return data
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
			return this.daemons.some(daemon => daemon.name === this.name && daemon.name !== this.daemon?.name)
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
		getNetworkHelperText() {
			if (this.httpsEnabled) {
				return t('app_api', 'With https enabled network is set to host')
			}

			if (this.isEdit && this.deployConfig.net !== this.daemon.deploy_config.net) {
				return t('app_api', 'Changes would be applied only for newly installed ExApps. For existing ExApps, Docker containers should be recreated.')
			}

			return ''
		},
		canRegister() {
			return this.isDaemonNameValid === true || this.isHaProxyPasswordValid === false
		},
		isAdditionalOptionValid() {
			return this.additionalOption.key.trim() !== '' && this.additionalOption.value.trim() !== ''
		},
		getNextcloudUrlHelperText() {
			if (!/^https?:\/\//.test(this.nextcloud_url)) {
				return t('app_api', 'URL should start with http:// or https://')
			}

			if (this.httpsEnabled && !this.nextcloud_url.startsWith('https://')) {
				return t('app_api', 'For HTTPS daemon, Nextcloud URL should be HTTPS')
			}

			if (this.isEdit && this.nextcloud_url !== this.daemon.deploy_config.nextcloud_url) {
				return t('app_api', 'Changes would be applied only for newly installed ExApps. For existing ExApps, Docker containers should be recreated.')
			}

			return ''
		},
		getComputeDeviceHelperText() {
			if (this.isEdit && this.deployConfig.computeDevice.id !== this.daemon.deploy_config.computeDevice.id) {
				return t('app_api', 'Changes would be applied only for newly installed ExApps. For existing ExApps, Docker containers should be recreated.')
			}

			if (this.deployConfig.computeDevice.id !== 'cpu') {
				return t('app_api', 'All available GPU devices on daemon host will be requested to be enabled in ExApp containers by Docker.')
			}

			return ''
		},
		isEdit() {
			return this.daemon !== null
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
		updateDaemon() {
			if (this.isEdit) {
				console.debug('Logic error. Cannot update daemon if it\'s not set')
			}

			this.registeringDaemon = true

			axios.put(generateUrl(`/apps/app_api/daemons/${this.daemon.name}`), {
				daemonConfigParams: this._buildDaemonParams(),
			})
				.then(res => {
					this.registeringDaemon = false
					if (res.data.success) {
						showSuccess(t('app_api', 'DaemonConfig successfully updated'))
						this.closeModal()
						this.getAllDaemons()
					} else {
						showError(t('app_api', 'Failed to update DaemonConfig. Check the logs'))
					}
				})
				.catch(err => {
					this.registeringDaemon = false
					console.debug(err)
					showError(t('app_api', 'Failed to update DaemonConfig. Check the logs'))
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
			const params = {
				name: this.name,
				display_name: this.displayName,
				accepts_deploy_id: this.acceptsDeployId,
				protocol: this.acceptsDeployId === 'docker-install' ? this.daemonProtocol : 'http',
				host: this.host,
				deploy_config: {
					net: this.deployConfig.net,
					nextcloud_url: this.nextcloud_url,
					haproxy_password: this.deployConfig.haproxy_password ?? '',
					computeDevice: this.deployConfig.computeDevice,
				},
			}
			if (this.additionalOptions.length > 0) {
				params.deploy_config.additional_options = this.additionalOptions.reduce((acc, option) => {
					acc[option.key] = option.value
					return acc
				}, {})
			}
			return params
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
			this.deployConfig.computeDevice = template.deployConfig.computeDevice
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
		addAdditionalOption() {
			this.addingAdditionalOption = true
			this.$nextTick(() => {
				this.$refs.additionalOptionKey.focus()
			})
		},
		removeAdditionalOption(option, index) {
			this.additionalOptions.splice(index, 1)
		},
		confirmAddingAdditionalOption() {
			this.additionalOptions.push({ key: this.additionalOption.key, value: this.additionalOption.value })
			this.addingAdditionalOption = false
			this.additionalOption = { key: '', value: '' }
		},
		cancelAddingAdditionalOption() {
			this.addingAdditionalOption = false
			this.additionalOption = { key: '', value: '' }
		},
		closeModal() {
			// TODO: Not sure what is this for. But it seems to mess the modal data when closing. Therefore commented out.
			// const customTemplate = DAEMON_TEMPLATES.find(template => template.name === 'custom')
			// this.configurationTab = { id: customTemplate.name, label: customTemplate.displayName }
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

	.additional-options {
		margin: 20px 0;
		padding: 10px 0;
	}

	.additional-option {
		display: flex;
	}
}
</style>

<style lang="scss">
.register-daemon-config-body {
	.input-field__input.text-warning {
		border-color: var(--color-warning-text) !important;
		color: var(--color-warning-text) !important;
	}
}
</style>
