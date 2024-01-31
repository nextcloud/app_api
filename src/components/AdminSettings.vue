<template>
	<div id="app_api_settings">
		<div class="section">
			<h2>
				<AppAPIIcon class="app-api-icon" />
				{{ t('app_api', 'AppAPI') }}
			</h2>
			<p>{{ t('app_api', 'The AppAPI Project is an exciting initiative that aims to revolutionize the way applications are developed for Nextcloud.') }}</p>
		</div>
		<NcSettingsSection
			:name="t('app_api', 'Deploy Daemons')"
			:description="t('app_api', 'Deploy Daemon (DaemonConfig) is an ExApps orchestration daemon.')"
			:aria-label="t('app_api', 'Deploy Daemons. Deploy Daemon (DaemonConfig) is an ExApps orchestration daemon.')"
			:doc-url="'https://cloud-py-api.github.io/app_api/CreationOfDeployDaemon.html'">
			<NcNoteCard v-if="state.default_daemon_config !== '' && !state?.daemon_config_accessible" type="error">
				<p>{{ t('app_api', 'Default Deploy Daemon is not accessible. Please verify its configuration') }}</p>
			</NcNoteCard>
			<DaemonConfigList :daemons.sync="daemons" :default-daemon.sync="default_daemon_config" :save-options="saveOptions" />
		</NcSettingsSection>
		<NcSettingsSection
			:name="t('app_api', 'ExApp init timeout (minutes)')"
			:description="t('app_api', 'ExApp initialization process timeout after which AppAPI will mark it as failed')"
			:aria-label="t('app_api', 'ExApp initialization process timeout after which AppAPI will mark it as failed')">
			<NcInputField :value.sync="state.init_timeout"
				class="setting"
				type="number"
				:placeholder="t('app_api', 'ExApp init timeout')"
				@update:value="onInput" />
		</NcSettingsSection>
		<NcSettingsSection
			:name="t('app_api', 'ExApp container restart policy')"
			:description="t('app_api', 'Specify container restart policy, e.g. \'always\' to ensure ExApp running after daemon server reboot')"
			:aria-label="t('app_api', 'ExApp container restart policy')">
			<NcSelect
				v-model="state.container_restart_policy"
				:options="['no', 'always', 'unless-stopped']"
				:placeholder="t('app_api', 'ExApp container restart policy')"
				:aria-label="t('app_api', 'ExApp container restart policy')"
				@input="onInput" />
		</NcSettingsSection>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { delay } from '../utils.js'
import { showSuccess, showError } from '@nextcloud/dialogs'

import NcSettingsSection from '@nextcloud/vue/dist/Components/NcSettingsSection.js'
import NcInputField from '@nextcloud/vue/dist/Components/NcInputField.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'

import AppAPIIcon from './icons/AppAPIIcon.vue'
import DaemonConfigList from './DaemonConfig/DaemonConfigList.vue'

export default {
	name: 'AdminSettings',
	components: {
		NcSettingsSection,
		DaemonConfigList,
		AppAPIIcon,
		NcNoteCard,
		NcInputField,
		NcSelect,
	},
	data() {
		return {
			state: loadState('app_api', 'admin-initial-data'),
			daemons: [],
			default_daemon_config: '',
		}
	},
	computed: {
		exAppsManagementButtonText() {
			return this.state.updates_count > 0 ? t('app_api', 'External Apps management') + ` (${this.state.updates_count})` : t('app_api', 'External Apps management')
		},
	},
	mounted() {
		this.loadInitialState()
	},
	methods: {
		loadInitialState() {
			const state = loadState('app_api', 'admin-initial-data')
			this.daemons = state.daemons
			this.default_daemon_config = state.default_daemon_config
		},
		onInput() {
			delay(() => {
				this.saveOptions({
					ex_app_init_timeout: this.state.init_timeout,
					container_restart_policy: this.state.container_restart_policy,
				})
			}, 2000)()
		},
		saveOptions(values) {
			const req = {
				values,
			}
			const url = generateUrl('/apps/app_api/admin-config')
			return axios.put(url, req).then((response) => {
				showSuccess(t('app_api', 'Admin options saved'))
			}).catch((error) => {
				showError(
					t('app_api', 'Failed to save admin options')
					+ ': ' + (error.response?.request?.responseText ?? ''),
				)
				console.error(error)
			})
		},
		onCheckboxChanged(newValue, key) {
			this.state[key] = newValue
			this.saveOptions({ [key]: this.state[key] ? '1' : '0' })
		},
		linkToExAppsManagement() {
			return generateUrl('/apps/app_api/apps')
		},
	},
}
</script>

<style scoped lang="scss">
#app_api_settings {
	h2 {
		display: flex;
		.app-api-icon {
			margin-right: 12px;
		}
	}

	.setting {
		width: fit-content;
		max-width: 400px;
	}
}
</style>
