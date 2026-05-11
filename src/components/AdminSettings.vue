<!--
  - SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div id="app_api_settings">
		<div class="section">
			<h2>
				<AppAPIIcon class="app-api-icon" />
				{{ t('app_api', 'AppAPI') }}
			</h2>
			<p>{{ t('app_api', 'The AppAPI Project is an exciting initiative that aims to revolutionize the way applications are developed for Nextcloud through the use of docker containers. Allowing for greater programming language choice and allowing computationally expensive tasks to be offloaded to a different server.') }}</p>
		</div>
		<NcSettingsSection
			:name="t('app_api', 'Deploy daemons')"
			:description="t('app_api', 'A deploy daemon (DaemonConfig) is an ExApps orchestration daemon.')"
			:aria-label="t('app_api', 'Deploy daemons. A deploy daemon (DaemonConfig) is an ExApps orchestration daemon.')"
			doc-url="https://docs.nextcloud.com/server/latest/admin_manual/exapps_management/AppAPIAndExternalApps.html#setup-deploy-daemon">
			<NcNoteCard v-if="state.default_daemon_config !== '' && !state?.daemon_config_accessible" type="error">
				<p>{{ t('app_api', 'Default deploy daemon is not accessible. Please check its configuration') }}</p>
			</NcNoteCard>
			<DaemonConfigList
				v-model:daemons="daemons"
				v-model:default-daemon="default_daemon_config"
				:save-options="saveOptions" />
		</NcSettingsSection>
		<NcSettingsSection
			:name="t('app_api', 'ExApp init timeout (minutes)')"
			:description="t('app_api', 'ExApp initialization process timeout after which AppAPI will mark it as failed')"
			:aria-label="t('app_api', 'ExApp initialization process timeout after which AppAPI will mark it as failed')">
			<NcInputField v-model="state.init_timeout"
				class="setting"
				type="number"
				:placeholder="t('app_api', 'ExApp init timeout')"
				@update:model-value="onInput" />
		</NcSettingsSection>
		<NcSettingsSection
			:name="t('app_api', 'ExApp container restart policy')"
			:description="t('app_api', 'Choose the container restart policy, e.g. \'always\' to ensure ExApps will be running after a daemon server reboot')"
			:aria-label="t('app_api', 'ExApp container restart policy')">
			<NcNoteCard type="info">
				{{ t('app_api', 'This settings changes are effective only for newly created containers') }}
			</NcNoteCard>
			<NcSelect
				v-model="state.container_restart_policy"
				:options="['no', 'always', 'unless-stopped']"
				:placeholder="t('app_api', 'ExApp container restart policy')"
				:aria-label="t('app_api', 'ExApp container restart policy')"
				:aria-label-combobox="t('app_api', 'ExApp container restart policy')"
				@update:model-value="onInput" />
		</NcSettingsSection>
		<NcSettingsSection
			:name="t('app_api', 'ExApp Docker image cleanup')"
			:description="t('app_api', 'Remove old ExApp images from Docker daemons after uninstall or update. Kubernetes deployments use the kubelet built-in image garbage collection and are not affected by these settings.')"
			:aria-label="t('app_api', 'ExApp Docker image cleanup')">
			<NcCheckboxRadioSwitch v-model="state.image_cleanup_enabled"
				type="switch"
				@update:model-value="onCleanupEnabledToggled">
				{{ t('app_api', 'Automatically remove unused ExApp images') }}
			</NcCheckboxRadioSwitch>
			<p class="cleanup-grace-desc">
				{{ t('app_api', 'Grace period in hours before deleting an orphaned ExApp image. 0 = delete immediately, maximum 720 (30 days).') }}
			</p>
			<NcInputField v-model="state.image_cleanup_grace_hours"
				class="setting"
				type="number"
				:min="0"
				:max="720"
				:placeholder="t('app_api', 'Grace period in hours')"
				@update:model-value="onGraceHoursInput" />
		</NcSettingsSection>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { delay } from '../utils.js'
import { showSuccess, showError } from '@nextcloud/dialogs'

import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcInputField from '@nextcloud/vue/components/NcInputField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'

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
		NcCheckboxRadioSwitch,
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
					init_timeout: this.state.init_timeout,
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
		onCleanupEnabledToggled() {
			// v-model has already updated state.image_cleanup_enabled to the new bool;
			// persist it as a real boolean so the backend stores via setValueBool.
			this.saveOptions({ image_cleanup_enabled: !!this.state.image_cleanup_enabled })
		},
		onGraceHoursInput(value) {
			delay(() => {
				const clamped = Math.min(720, Math.max(0, parseInt(value, 10) || 0))
				this.state.image_cleanup_grace_hours = clamped
				this.saveOptions({ image_cleanup_grace_hours: clamped })
			}, 2000)()
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
		.app-api-icon {
			margin-right: 12px;
		}
	}

	.setting {
		width: fit-content;
		max-width: 400px;
	}

	// Mirrors NcSettingsSection's __desc style so the grace-period hint reads
	// like a section description even though it lives inside the section above.
	.cleanup-grace-desc {
		color: var(--color-text-maxcontrast);
		max-width: 900px;
		margin-top: 12px;
		margin-bottom: 8px;
	}
}
</style>
