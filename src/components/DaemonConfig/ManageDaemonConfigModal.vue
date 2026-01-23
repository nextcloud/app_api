<!--
  - SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="register-daemon-config">
		<NcModal :show="show"
			:name="isEdit ? t('app_api', 'Edit the deploy daemon') : t('app_api', 'Register a new deploy daemon')"
			@close="closeModal">
			<div class="register-daemon-config-body">
				<h2>{{ isEdit ? t('app_api', 'Edit the deploy daemon') : t('app_api', 'Register a new deploy daemon') }}</h2>
				<div v-if="!isEdit" class="templates">
					<NcSelect
						id="daemon-template"
						v-model="configurationTab"
						class="ncselect"
						:input-label="t('app_api', 'Daemon configuration template')"
						:options="configurationTemplateOptions"
						:placeholder="t('app_api', 'Select a daemon configuration template')" />
				</div>
				<form class="daemon-register-form" :aria-label="t('app_api', 'Daemon registration form')">
					<div class="row" :aria-label="t('app_api', 'Name')">
						<NcInputField
							id="daemon-name"
							v-model="name"
							:label="t('app_api', 'Name')"
							:readonly="isEdit"
							:placeholder="t('app_api', 'Unique deploy daemon name')"
							:aria-label="t('app_api', 'Unique deploy Daemon name')"
							:error="isDaemonNameInvalid === true"
							:helper-text="isDaemonNameValidHelperText" />
						<InfoTooltip :text="t('app_api', 'Unique deploy daemon name')" />
					</div>
					<div class="row" :aria-label="t('app_api', 'Display name')">
						<NcInputField
							id="daemon-display-name"
							v-model="displayName"
							:label="t('app_api', 'Display name')"
							:placeholder="t('app_api', 'Display name')"
							:aria-label="t('app_api', 'Display name')" />
					</div>
					<div class="row" :aria-label="t('app_api', 'Deployment method')">
						<NcSelect
							id="daemon-deploy-id"
							v-model="acceptsDeployId"
							class="ncselect"
							:input-label="t('app_api', 'Deployment method')"
							:disabled="isEdit"
							:options="deployMethods"
							:placeholder="t('app_api', 'Select the daemon deploy method')" />
					</div>
					<div class="row" :aria-label="isHarp ? t('app_api', 'HaRP host') : t('app_api', 'Daemon host')">
						<NcInputField
							id="daemon-host"
							v-model="host"
							:label="isHarp ? t('app_api', 'HaRP host') : t('app_api', 'Daemon host')"
							:placeholder="daemonHostHelperText"
							:aria-label="daemonHostHelperText" />
						<InfoTooltip :text="daemonHostHelperText" />
					</div>
					<div v-if="['http', 'https'].includes(daemonProtocol) && !isPureManual"
						class="row"
						:aria-label="isHarp ? t('app_api', 'HaRP shared key') : t('app_api', 'HaProxy password')">
						<NcPasswordField
							id="deploy-config-haproxy-password"
							v-model="deployConfig.haproxy_password"
							:label="isHarp ? t('app_api', 'HaRP shared key') : t('app_api', 'HaProxy password')"
							:error="isHaProxyPasswordValid === false"
							:placeholder="haProxyPasswordHelperText"
							:aria-label="haProxyPasswordHelperText"
							:helper-text="!isHaProxyPasswordValid ? t('app_api', 'The password must be at least 12 characters long') : ''"
							autocomplete="off" />
						<InfoTooltip :text="haProxyPasswordHelperText" />
					</div>
					<div class="row" :aria-label="t('app_api', 'Nextcloud URL')">
						<NcInputField
							id="nextcloud-url"
							v-model="nextcloud_url"
							:label="t('app_api', 'Nextcloud URL')"
							:helper-text="getNextcloudUrlHelperText"
							:input-class="getNextcloudUrlHelperText !== '' ? 'text-warning' : ''"
							:placeholder="t('app_api', 'Nextcloud URL')"
							:aria-label="t('app_api', 'Nextcloud URL')" />
					</div>
					<NcFormBox class="formbox">
						<NcFormBoxSwitch v-if="!isEdit && acceptsDeployId === 'docker-install'"
							v-model="defaultDaemon"
							:title="t('app_api', 'Set this daemon as the default one')">
							{{ t('app_api', 'Set as the default daemon') }}
						</NcFormBoxSwitch>
						<NcFormBoxSwitch v-if="!isHarp"
							v-model="httpsEnabled"
							@update:model-value="onProtocolChange">
							{{ t('app_api', 'Enable HTTPS') }}
						</NcFormBoxSwitch>
					</NcFormBox>
					<NcButton
						:aria-label="t('app_api', 'Deploy options')"
						style="margin: 10px 0; width: 100%;"
						@click="deployConfigSettingsOpened = !deployConfigSettingsOpened">
						{{ !deployConfigSettingsOpened ? t('app_api', 'Show deploy options') : t('app_api', 'Hide deploy options') }}
						<template #icon>
							<UnfoldLessHorizontal v-if="deployConfigSettingsOpened" :size="20" />
							<UnfoldMoreHorizontal v-else :size="20" />
						</template>
					</NcButton>
					<div v-show="deployConfigSettingsOpened" class="deploy-config" :aria-label="t('app_api', 'Deploy options')">
						<NcFormBoxSwitch
							v-model="isHarp"
							@update:model-value="toggleHarp">
							{{ t('app_api', 'Enable HaRP') }}
						</NcFormBoxSwitch>
						<div v-if="isHarp" class="harp-options">
							<div class="row" :aria-label="t('app_api', 'FRP server address')">
								<NcInputField
									id="frp-address"
									v-model="deployConfig.harp.frp_address"
									:label="t('app_api', 'FRP server address')"
									:placeholder="t('app_api', 'FRP server address')"
									:aria-label="t('app_api', 'FRP server address')" />
								<InfoTooltip :text="t('app_api', 'The address (host:port) of the FRP server that should be reachable by the ExApp in the network defined in the \'Docker network\' section.')" />
							</div>
							<div class="row" :aria-label="t('app_api', 'Docker socket proxy port')">
								<NcInputField
									id="harp-dsp-port"
									v-model="deployConfig.harp.docker_socket_port"
									:label="t('app_api', 'Docker socket proxy port')"
									:placeholder="t('app_api', 'Docker socket proxy port')"
									:aria-label="t('app_api', 'Docker socket proxy port')" />
								<InfoTooltip :text="t('app_api', 'The port in HaRP which the Docker socket proxy connects to. This should be exposed but for the in-built one, it is not required to be exposed or changed.')" />
							</div>
							<div class="row-switch" :aria-label="t('app_api', 'Disable FRP')">
								<NcFormBoxSwitch
									v-model="deployConfig.harp.exapp_direct"
									class="switch"
									:disabled="isEdit || isHarpAio">
									{{ t('app_api', 'Disable FRP') }}
								</NcFormBoxSwitch>
								<InfoTooltip :text="isHarpAio ? t('app_api', 'FRP is always disabled for the All-in-One setup.') : t('app_api', 'Flag for advanced setups only. Disables the FRP tunnel between ExApps and HaRP.')" />
							</div>
						</div>
						<template v-if="!isPureManual">
							<div class="row"
								:aria-label="t('app_api', 'Docker network')">
								<NcInputField
									id="deploy-config-net"
									ref="deploy-config-net"
									v-model="deployConfig.net"
									:label="t('app_api', 'Docker network')"
									:placeholder="t('app_api', 'Docker network')"
									:aria-label="t('app_api', 'Docker network')"
									:show-trailing-button="isEditDifferentNetwork"
									:error="isHarp && !deployConfig.net"
									:helper-text="(isHarp && !deployConfig.net) ? t('app_api', 'Docker network for ex-app deployment must be defined') : ''"
									@trailing-button-click="deployConfig.net = daemon.deploy_config.net">
									<template #trailing-button-icon>
										<Replay :size="20" />
									</template>
								</NcInputField>
								<InfoTooltip :text="getNetworkHelperText"
									:type="isEditDifferentNetwork ? 'warning' : 'info'" />
							</div>
							<div class="row" :aria-label="t('app_api', 'Computation device')">
								<NcSelect
									id="compute-device"
									v-model="deployConfig.computeDevice"
									class="ncselect"
									:input-label="t('app_api', 'Compute device')"
									:aria-label-combobox="t('app_api', 'Computation device')"
									:options="computeDevices" />
								<InfoTooltip v-if="getComputeDeviceHelperText !== ''"
									:text="getComputeDeviceHelperText"
									:type="getComputeDeviceHelperText !== '' ? 'warning' : 'info'" />
							</div>
							<div class="row" :aria-label="t('app_api', 'Memory limit')">
								<NcInputField
									id="memory-limit"
									ref="memory-limit"
									v-model="memoryLimit"
									:label="t('app_api', 'Memory limit (in MiB)')"
									:placeholder="t('app_api', 'Memory limit (in MiB)')"
									:aria-label="t('app_api', 'Memory limit (in MiB)')"
									:error="isMemoryLimitValid === false"
									:helper-text="isMemoryLimitValid === false ? t('app_api', 'Must be a positive integer') : ''" />
								<InfoTooltip :text="t('app_api', 'Maximum amount of memory that the ExApp container can use in mebibytes')" />
							</div>
							<div class="row" :aria-label="t('app_api', 'CPU limit')">
								<NcInputField
									id="cpu-limit"
									ref="cpu-limit"
									v-model="cpuLimit"
									:label="t('app_api', 'CPU limit')"
									:placeholder="t('app_api', 'CPU limit as decimal value')"
									:aria-label="t('app_api', 'CPU limit')"
									:error="isCpuLimitValid === false"
									:helper-text="isCpuLimitValid === false ? t('app_api', 'Must be a positive number') : ''" />
								<InfoTooltip :text="t('app_api', 'Maximum number of CPU cores that the ExApp container can use (e.g. 0.5 for half a core, 2 for two cores)')" />
							</div>
							<template v-if="additionalOptions.length > 0">
								<div class="row" style="flex-direction: column;">
									<div
										v-for="(option, index) in additionalOptions"
										:id="option.key"
										:key="index"
										class="row"
										:aria-label="t('app_api', 'Additional options')">
										<label :for="option.key">{{ option.key }}</label>
										<div class="additional-option">
											<NcInputField
												:id="option.key"
												v-model="option.value"
												:disabled="isEdit"
												:placeholder="option.value"
												:aria-label="option.value"
												style="margin: 0 5px 0 0;" />
											<NcButton v-if="!isEdit" variant="tertiary" @click="removeAdditionalOption(option, index)">
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
									<NcButton variant="tertiary" @click="addAdditionalOption">
										<template #icon>
											<Plus :size="20" />
										</template>
										{{ t('app_api', 'Add additional option') }}
									</NcButton>
								</div>
								<template v-if="addingAdditionalOption">
									<div class="row" style="align-items: end;">
										<NcInputField
											id="additional-option-key"
											ref="additionalOptionKey"
											v-model="additionalOption.key"
											:label="t('app_api', 'Option key (unique)')"
											:placeholder="t('app_api', 'Option key (unique, e.g. my_key)')"
											:error="additionalOption.key.trim() === ''"
											:helper-text="additionalOption.key.trim() === '' ? t('app_api', 'Option key is required') : ''"
											style="margin: 0 5px 0 0;" />
										<NcInputField
											id="additional-option-value"
											v-model="additionalOption.value"
											:label="t('app_api', 'Option value')"
											:placeholder="t('app_api', 'Option value')"
											:error="additionalOption.value.trim() === ''"
											:helper-text="additionalOption.value.trim() === '' ? t('app_api', 'Option value is required') : ''"
											style="margin: 0 5px 0 0;" />
										<NcButton
											variant="tertiary"
											:aria-label="t('app_api', 'Confirm')"
											:disabled="isAdditionalOptionValid === false"
											@click="confirmAddingAdditionalOption">
											<template #icon>
												<Check :size="20" />
											</template>
										</NcButton>
										<NcButton
											variant="tertiary"
											:aria-label="t('app_api', 'Cancel')"
											@click="cancelAddingAdditionalOption">
											<template #icon>
												<Close :size="20" />
											</template>
										</NcButton>
									</div>
								</template>
							</div>
						</template>
					</div>
				</form>
			</div>
			<div class="row footer">
				<NcButton
					variant="primary"
					:disabled="cannotRegister"
					@click="isEdit ? updateDaemon() : registerDaemon()">
					{{ isEdit ? t('app_api', 'Save') : t('app_api', 'Register') }}
					<template #icon>
						<NcLoadingIcon v-if="registeringDaemon" :size="20" />
						<Check v-else :size="20" />
					</template>
				</NcButton>
				<NcButton variant="secondary" @click="verifyDaemonConnection">
					{{ t('app_api', 'Check connection') }}
					<template #icon>
						<NcLoadingIcon v-if="verifyingDaemonConnection" :size="20" />
						<Connection v-else :size="20" />
					</template>
				</NcButton>
			</div>
		</NcModal>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { generateUrl } from '@nextcloud/router'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcInputField from '@nextcloud/vue/components/NcInputField'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcFormBoxSwitch from '@nextcloud/vue/components/NcFormBoxSwitch'
import NcFormBox from '@nextcloud/vue/components/NcFormBox'

import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
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
		NcSelect,
		NcButton,
		NcFormBoxSwitch,
		NcFormBox,
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
			configurationTab: { id: DAEMON_TEMPLATES[0].name, label: DAEMON_TEMPLATES[0].displayName },
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
			data.deployConfigSettingsOpened = true
			if (data.deployConfig.resourceLimits) {
				if (data.deployConfig.resourceLimits.memory) {
					// memory in bytes
					data.deployConfig.resourceLimits.memoryMiB = data.deployConfig.resourceLimits.memory / (1024 * 1024)
					delete data.deployConfig.resourceLimits.memory
				} else {
					data.deployConfig.resourceLimits.memoryMiB = null
				}
				if (data.deployConfig.resourceLimits.nanoCPUs) {
					data.deployConfig.resourceLimits.cpus = data.deployConfig.resourceLimits.nanoCPUs / 1000000000
					delete data.deployConfig.resourceLimits.nanoCPUs
				} else {
					data.deployConfig.resourceLimits.cpus = null
				}
			}
		}
		if (!data.deployConfig.harp) {
			data.deployConfig.harp = null
			data.deployConfigSettingsOpened = false
		}

		if (!data.deployConfig.resourceLimits) {
			data.deployConfig.resourceLimits = { memoryMiB: null, cpus: null }
		}

		return data
	},
	computed: {
		daemonHostHelperText() {
			if (['http', 'https'].includes(this.daemonProtocol)) {
				if (this.acceptsDeployId === 'manual-install' && !this.isHarp) {
					return t('app_api', 'Hostname used by Nextcloud to access the ExApps')
				}
				return t('app_api', 'The hostname (and port) at which the {name} is available. This does not need to be a public host, just a host accessible by the Nextcloud server, e.g. {host}.', {
					name: this.isHarp ? 'HaRP proxy' : 'Docker Socket Proxy',
					host: this.isHarp ? 'appapi-harp:8780' : 'nextcloud-appapi-dsp:2375',
				})
			}
			return t('app_api', 'The hostname (and port) or path at which the {name} is available. This does not need to be a public host, just a host accessible by the Nextcloud server. It can also be the path to the Docker socket. (e.g. nextcloud-appapi-dsp:2375, /var/run/docker.sock)')
		},
		daemonProtocol() {
			return this.httpsEnabled ? 'https' : 'http'
		},
		memoryLimit: {
			get() {
				return this.deployConfig.resourceLimits.memoryMiB || ''
			},
			set(value) {
				this.deployConfig.resourceLimits.memoryMiB = value === '' ? null : value
			},
		},
		cpuLimit: {
			get() {
				return this.deployConfig.resourceLimits.cpus || ''
			},
			set(value) {
				this.deployConfig.resourceLimits.cpus = value === '' ? null : value
			},
		},
		isMemoryLimitValid() {
			if (this.memoryLimit === '' || this.memoryLimit === null) return true
			const str = String(this.memoryLimit).trim()
			return /^[1-9]\d*$/.test(str)
		},
		isCpuLimitValid() {
			if (this.cpuLimit === '' || this.cpuLimit === null) return true
			const str = String(this.cpuLimit).trim()
			return /^\d*\.?\d+$/.test(str)
		},
		isDaemonNameInvalid() {
			return this.daemons.some(daemon => daemon.name === this.name && daemon.name !== this.daemon?.name)
		},
		isDaemonNameValidHelperText() {
			return this.isDaemonNameInvalid === true ? t('app_api', 'A daemon with this name already exists') : ''
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
				return t('app_api', 'Changes are only effective for newly installed ExApps. For existing ExApps, the Docker containers should be recreated to apply the new settings values.')
			}
			return t('app_api', 'The Docker network that the deployed ExApps will use.')
		},
		cannotRegister() {
			return this.isDaemonNameInvalid === true || this.isHaProxyPasswordValid === false || (this.isHarp && !this.deployConfig.net) || this.isMemoryLimitValid === false || this.isCpuLimitValid === false
		},
		isAdditionalOptionValid() {
			return this.additionalOption.key.trim() !== '' && this.additionalOption.value.trim() !== ''
		},
		getNextcloudUrlHelperText() {
			if (!/^https?:\/\//.test(this.nextcloud_url)) {
				return t('app_api', 'The URL should start with http:// or https://')
			}

			if (this.httpsEnabled && !this.nextcloud_url.startsWith('https://')) {
				return t('app_api', 'For a HTTPS daemon, the Nextcloud URL should be HTTPS')
			}

			if (this.isEdit && this.nextcloud_url !== this.daemon.deploy_config.nextcloud_url) {
				return t('app_api', 'Changes are only effective for newly installed ExApps. For existing ExApps, the Docker containers should be recreated to apply the new settings values.')
			}

			return ''
		},
		getComputeDeviceHelperText() {
			if (this.isEdit && this.deployConfig.computeDevice.id !== this.daemon.deploy_config.computeDevice.id) {
				return t('app_api', 'Changes are only effective for newly installed ExApps. For existing ExApps, the Docker containers should be recreated to apply the new settings values.')
			}

			if (this.deployConfig.computeDevice.id !== 'cpu') {
				return t('app_api', 'All available GPU devices on the daemon host need to be enabled in ExApp containers by Docker.')
			}

			return ''
		},
		isEdit() {
			return this.daemon !== null
		},
		isHarp() {
			return this.deployConfig.harp !== null
		},
		isHarpAio() {
			return this.configurationTab?.id === 'harp_aio'
		},
		isPureManual() {
			return this.acceptsDeployId === 'manual-install' && !this.isHarp
		},
	},
	watch: {
		configurationTab(newConfigurationTab) {
			if (this.isEdit) {
				return
			}
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
					registries: this.deployConfig.registries || null,
				},
			}

			const resourceLimits = {}
			if (this.deployConfig.resourceLimits.memoryMiB && this.isMemoryLimitValid) {
				// memory in bytes
				resourceLimits.memory = Number(this.deployConfig.resourceLimits.memoryMiB) * 1024 * 1024
			}
			if (this.deployConfig.resourceLimits.cpus && this.isCpuLimitValid) {
				resourceLimits.nanoCPUs = Number(this.deployConfig.resourceLimits.cpus) * 1000000000
			}
			params.deploy_config.resourceLimits = resourceLimits

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
			this.deployConfig = JSON.parse(JSON.stringify(template.deployConfig))
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
			if (value) {
				const toFind = this.configurationTab.id.includes('harp') ? this.configurationTab.id : 'harp_proxy_host'
				const harpDeployTempl = DAEMON_TEMPLATES.find(template => template.name === toFind)
				this.deployConfig.harp = { ...harpDeployTempl.deployConfig.harp }
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

	h2 {
		margin-top: 0;
	}

	.daemon-register-form {
		display: flex;
		flex-direction: column;
		flex: fit-content;
	}

	.note a {
		color: #fff;
		text-decoration: underline;
	}

	.row-switch {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-top: 10px;
		gap: 4px;
		.switch {
			flex-grow: 1;
		}
	}

	.formbox {
		margin-top: 12px;
	}

	.ncselect {
		width: 100%;
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

	:deep(.v-select.select) {
		margin: 0 !important;
	}
}

.row {
	display: flex;
	justify-content: space-between;
	align-items: end;
	margin-top: 10px;
	gap: 4px;
}

.footer {
	position: sticky;
	bottom: 0;
	background-color: var(--color-main-background);
	padding: 20px;
	margin: 0;
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
