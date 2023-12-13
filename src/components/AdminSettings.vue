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
			:name="t('app_api', 'ExApps')"
			:description="t('app_api', 'ExApps management similar to default apps and available by the link below or .')">
			<NcButton
				type="primary"
				:href="linkToExAppsManagement()"
				:aria-label="t('app_api', 'External Apps management')"
				style="margin: 20px 0;">
				{{ exAppsManagementButtonText }}
				<template #icon>
					<OpenInNew :size="20" />
				</template>
			</NcButton>
		</NcSettingsSection>
		<NcSettingsSection
			:name="t('app_api', 'Deploy Daemons')"
			:description="t('app_api', 'Deploy Daemon (DaemonConfig) is an ExApps orchestration daemon.')"
			:doc-url="'https://cloud-py-api.github.io/app_api/CreationOfDeployDaemon.html'">
			<NcNoteCard type="warning">
				<p>{{ t('app_api', 'Currently only Docker Daemon is supported.') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="state.default_daemon_config !== '' && !state?.daemon_config_accessible" type="error">
				<p>{{ t('app_api', 'Default Deploy Daemon is not accessible. Please verify its configuration') }}</p>
			</NcNoteCard>
			<DaemonConfigList :daemons.sync="daemons" :default-daemon.sync="default_daemon_config" :save-options="saveOptions" />
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
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'

import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'

import AppAPIIcon from './icons/AppAPIIcon.vue'
import DaemonConfigList from './DaemonConfig/DaemonConfigList.vue'

export default {
	name: 'AdminSettings',
	components: {
		NcSettingsSection,
		NcButton,
		OpenInNew,
		DaemonConfigList,
		AppAPIIcon,
		NcNoteCard,
	},
	data() {
		return {
			state: loadState('app_api', 'admin-initial-data'),
			daemons: [],
			default_daemon_config: '',
			docker_socket_accessible: false,
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
			this.docker_socket_accessible = state.docker_socket_accessible
		},
		onInput() {
			delay(() => {
				this.saveOptions({
					file_actions_menu: this.state.file_actions_menu,
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
}
</style>
