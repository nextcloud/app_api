<!--
  - SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="register-daemon-config">
		<NcModal :show="show" name="register-deploy-daemon" @close="closeModal">
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
						<label for="daemon-name">
							{{ t('app_api', 'Name') }}
							<InfoTooltip :text="t('app_api', 'Unique Deploy Daemon Name')" />
						</label>
						<NcInputField
							id="daemon-name"
							class="ex-input-field"
							:disabled="isEdit"
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
							class="ex-input-field"
							:value.sync="displayName"
							:placeholder="t('app_api', 'Display name')"
							:aria-label="t('app_api', 'Display name')" />
					</div>
					<div class="external-label" :aria-label="t('app_api', 'Deployment method')">
						<label for="daemon-deploy-id">{{ t('app_api', 'Deployment method') }}</label>
						<NcSelect
							id="daemon-deploy-id"
							v-model="acceptsDeployId"
							class="ex-input-field"
							:disabled="configurationTab.id === 'manual_install' || isEdit"
							:options="deployMethods"
							:label-outside="true"
							:placeholder="t('app_api', 'Select daemon deploy method')" />
					</div>
					<div class="external-label" :aria-label="isHarp ? t('app_api', 'HaRP host') : t('app_api', 'Daemon host')">
						<label for="daemon-host">
							{{ isHarp ? t('app_api', 'HaRP host') : t('app_api', 'Daemon host') }}
							<InfoTooltip :text="daemonHostHelperText" />
						</label>
						<NcInputField
							id="daemon-host"
							class="ex-input-field"
							:value.sync="host"
							:placeholder="daemonHostHelperText"
							:aria-label="daemonHostHelperText"
							style="max-width: 70%;" />
					</div>
					<div v-if="['http', 'https'].includes(daemonProtocol)"
						class="external-label"
						:aria-label="isHarp ? t('app_api', 'HaRP shared key') : t('app_api', 'HaProxy password')">
						<label for="deploy-config-haproxy-password">
							{{ isHarp ? t('app_api', 'HaRP shared key') : t('app_api', 'HaProxy password') }}
							<InfoTooltip :text="haProxyPasswordHelperText" />
						</label>
						<NcPasswordField
							id="deploy-config-haproxy-password"
							class="ex-input-field"
							:value.sync="deployConfig.haproxy_password"
							:error="isHaProxyPasswordValid === false"
							:placeholder="haProxyPasswordHelperText"
							:aria-label="haProxyPasswordHelperText"
							:helper-text="!isHaProxyPasswordValid ? t('app_api', 'Password must be at least 12 characters long') : ''"
							autocomplete="off" />
					</div>
					<div class="external-label" :aria-label="t('app_api', 'Nextcloud URL')">
						<label for="nextcloud-url">{{ t('app_api', 'Nextcloud URL') }}</label>
						<NcInputField
							id="nextcloud-url"
							class="ex-input-field"
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
							class="ex-input-field"
							:checked.sync="defaultDaemon"
							:placeholder="t('app_api', 'Set daemon as default')"
							:aria-label="t('app_api', 'Set daemon as default')">
							{{ t('app_api', 'Set as default daemon') }}
						</NcCheckboxRadioSwitch>
						<div v-if="isEdit" />
						<NcCheckboxRadioSwitch v-if="isNotManualInstall && !isHarp"
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
							<NcCheckboxRadioSwitch
								:checked="isHarp"
								:placeholder="t('app_api', 'Enable HaRP')"
								:aria-label="t('app_api', 'Enable HaRP')"
								@update:checked="toggleHarp">
								{{ t('app_api', 'Enable HaRP') }}
							</NcCheckboxRadioSwitch>
							<div v-if="isHarp" class="harp-options">
								<div class="external-label" :aria-label="t('app_api', 'FRP server address')">
									<label for="frp-address">
										{{ t('app_api', 'FRP server address') }}
										<InfoTooltip :text="t('app_api', 'The address (host:port) of the FRP server that should be reachable by the ex-app in the network defined in \'Docker network\'.')" />
									</label>
									<NcInputField
										id="frp-address"
										class="ex-input-field"
										:value.sync="deployConfig.harp.frp_address"
										:placeholder="t('app_api', 'FRP server address')"
										:aria-label="t('app_api', 'FRP server address')" />
								</div>
								<div class="external-label" :aria-label="t('app_api', 'Docker socket proxy port')">
									<label for="harp-port">
										{{ t('app_api', 'Docker socket proxy port') }}
										<InfoTooltip :text="t('app_api', 'The port in HaRP which the docker socket proxy connects to. This should be exposed but for the in-built one, it is not required to be exposed or changed.')" />
									</label>
									<NcInputField
										id="harp-dsp-port"
										class="ex-input-field"
										:value.sync="deployConfig.harp.docker_socket_port"
										:placeholder="t('app_api', 'Docker socket proxy port')"
										:aria-label="t('app_api', 'Docker socket proxy port')" />
								</div>
							</div>
							<div class="external-label"
								:aria-label="t('app_api', 'Docker network')">
								<label for="deploy-config-net">
									{{ t('app_api', 'Docker network') }}
									<InfoTooltip :text="getNetworkHelperText"
										:type="isEditDifferentNetwork ? 'warning' : 'info'" />
								</label>
								<NcInputField
									id="deploy-config-net"
									ref="deploy-config-net"
									class="ex-input-field"
									:value.sync="deployConfig.net"
									:placeholder="t('app_api', 'Docker network')"
									:aria-label="t('app_api', 'Docker network')"
									:show-trailing-button="isEditDifferentNetwork"
									@trailing-button-click="deployConfig.net = daemon.deploy_config.net">
									<template #trailing-button-icon>
										<Replay :size="20" />
									</template>
								</NcInputField>
							</div>
							<div class="external-label" :aria-label="t('app_api', 'Compute device')">
								<label for="compute-device">
									{{ t('app_api', 'Compute device') }}
									<InfoTooltip v-if="getComputeDeviceHelperText !== ''"
										:text="getComputeDeviceHelperText"
										:type="getComputeDeviceHelperText !== '' ? 'warning' : 'info'" />
								</label>
								<NcSelect
									id="compute-device"
									v-model="deployConfig.computeDevice"
									class="ex-input-field"
									:aria-label="t('app_api', 'Compute device')"
									:options="computeDevices" />
							</div>
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
												:disabled="isEdit"
												:value.sync="option.value"
												:placeholder="option.value"
												:aria-label="option.value"
												style="margin: 0 5px 0 0;" />
											<NcButton v-if="!isEdit" type="tertiary" @click="removeAdditionalOption(option, index)">
												<template #icon>
													<Close :size="20" />
												</template>
											</NcButton>
										</div>
									</div>
								</div>
							</template>

							<div v-if="!isEdit" class="additional-options">
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
import { showError, showSuccess } from '@nextcloud/dialogs'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { generateUrl } from '@nextcloud/router'

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcInputField from '@nextcloud/vue/dist/Components/NcInputField.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcPasswordField from '@nextcloud/vue/dist/Components/NcPasswordField.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'

import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Connection from 'vue-material-design-icons/Connection.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Replay from 'vue-material-design-icons/Replay.vue'
import UnfoldLessHorizontal from 'vue-material-design-icons/UnfoldLessHorizontal.vue'
import UnfoldMoreHorizontal from 'vue-material-design-icons/UnfoldMoreHorizontal.vue'
import { DAEMON_COMPUTE_DEVICES, DAEMON_TEMPLATES } from '../../constants/daemonTemplates.js'
import InfoTooltip from './InfoTooltip.vue'

export default {
	name: 'ManageDaemonConfigModal',
	components: {
		NcLoadingIcon,
		NcModal,
		NcInputField,
		NcPasswordField,
		UnfoldLessHorizontal,
		UnfoldMoreHorizontal,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcButton,
		InfoTooltip,
		Check,
		Connection,
		Plus,
		Close,
		Replay,
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
			...JSON.parse(JSON.stringify(DAEMON_TEMPLATES[0])),
			deployMethods: ['docker-install', 'manual-install'],
			// replace last slash with empty string
			nextcloud_url: window.location.origin + generateUrl('').slice(0, -1),
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
			data.httpsEnabled = this.daemon.protocol === 'https'
			data.host = this.daemon.host
			data.nextcloud_url = this.daemon.deploy_config.nextcloud_url
			data.deployConfig = JSON.parse(JSON.stringify(this.daemon.deploy_config))
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
				return t('app_api', 'The hostname (and port) at which the {name} is available. This need not be a public host, just a host accessible by the Nextcloud server. (e.g. {host})', {
					name: this.isHarp ? 'HaRP' : 'Docker Socket Proxy',
					host: this.isHarp ? 'appapi-harp:8780' : 'nextcloud-appapi-dsp:2375',
				})
			}
			return t('app_api', 'The hostname (and port) or path at which the {name} is available. This need not be a public host, just a host accessible by the Nextcloud server. It can also be a path to the docker socket. (e.g. nextcloud-appapi-dsp:2375, /var/run/docker.sock)')
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
			if (this.daemonProtocol === 'https' || this.isHarp) {
				return this.deployConfig.haproxy_password !== null && this.deployConfig.haproxy_password.length >= 12
			}
			// HaProxy password required only for https
			return true
		},
		haProxyPasswordHelperText() {
			return this.isHarp ? t('app_api', 'The secret key for the HaRP container communication (HP_SHARED_KEY).') : t('app_api', 'AppAPI Docker Socket Proxy authentication password')
		},
		isEditDifferentNetwork() {
			return this.isEdit && this.deployConfig.net !== this.daemon.deploy_config.net
		},
		getNetworkHelperText() {
			if (this.isEditDifferentNetwork) {
				return t('app_api', 'Changes would be applied only for newly installed ExApps. For existing ExApps, Docker containers should be recreated.')
			}
			return t('app_api', 'The docker network that the deployed ex-apps would use.')
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
		isHarp() {
			return this.deployConfig.harp !== null
		},
	},
	watch: {
		configurationTab(newConfigurationTab) {
			this.setupFormConfiguration(newConfigurationTab)
		},
		httpsEnabled(newHttpsEnabled) {
			this.prevNet = this.deployConfig.net
			this.deployConfig.net = newHttpsEnabled ? 'host' : this.prevNet
		},
		show(newShow) {
			if (newShow === true) {
				this.resetData()
			}
		},
		acceptsDeployId(newAcceptsDeployId) {
			if (newAcceptsDeployId === 'manual-install') {
				this.deployConfig.harp = null
			}
		},
	},
	methods: {
		resetData() {
			Object.assign(this.$data, this.$options.data.apply(this))
		},
		registerDaemon() {
			this.registeringDaemon = true

			confirmPassword().then(() => {
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
			}).catch(() => {
				this.registeringDaemon = false
				showError(t('app_api', 'Password confirmation failed'))
			})
		},
		updateDaemon() {
			if (this.isEdit) {
				console.debug('Logic error. Cannot update daemon if it\'s not set')
			}

			this.registeringDaemon = true

			confirmPassword().then(() => {
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
			}).catch(() => {
				this.registeringDaemon = false
				showError(t('app_api', 'Password confirmation failed'))
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
					harp: this.deployConfig.harp ?? null,
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
			if (Object.keys(template).length === 0) {
				return
			}
			this.name = template.name
			this.displayName = template.displayName
			this.acceptsDeployId = template.acceptsDeployId
			this.httpsEnabled = template.httpsEnabled
			this.host = template.host
			this.nextcloud_url = template.nextcloud_url ?? window.location.origin + generateUrl('').slice(0, -1)
			this.deployConfigSettingsOpened = template.deployConfigSettingsOpened
			this.deployConfig = { ...template.deployConfig }
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
			this.$emit('update:show', false)
		},
		toggleHarp(value) {
			if (value === true) {
				const harpDeployTempl = DAEMON_TEMPLATES.find(template => template.name === 'harp_proxy')
				this.deployConfig.harp = {
					...harpDeployTempl.deployConfig.harp,
				}
			} else {
				this.deployConfig.harp = null
			}
		},
	},
}
</script>

<style scoped lang="scss">
.register-daemon-config-body {
	padding: 20px;

	.daemon-register-form {
		display: flex;
		flex-direction: column;
		flex: fit-content;
	}

	.external-label {
		display: flex;
		align-items: center;
		justify-content: space-between;
		width: 100%;
		padding: .25rem 0;

		label {
			flex: fit-content;
			display: flex;
			flex-direction: row;
			align-items: center;
			gap: .5rem;
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
		width: 320px;
		max-width: 320px;
	}

	.templates {
		display: flex;
		border-bottom: 2px solid var(--color-border-dark);
		padding-bottom: 15px;
		margin-bottom: 10px;
	}

	.additional-options {
		padding: 10px 0;
	}

	.additional-option {
		display: flex;
		width: 320px;
		max-width: 320px;
	}

	.ex-input-field {
		width: 320px;
	}

	:deep .v-select.select {
		margin: 0 !important;
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
