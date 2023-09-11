<template>
	<div id="app_api_settings" class="section">
		<h2>
			<AppAPIIcon class="app-api-icon" />
			{{ t('app_api', 'AppAPI') }}
		</h2>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { delay } from '../utils.js'
import { showSuccess, showError } from '@nextcloud/dialogs'

import AppAPIIcon from './icons/AppAPIIcon.vue'

export default {
	name: 'AdminSettings',
	components: {
		AppAPIIcon,
	},
	props: [],
	data() {
		return {
			state: loadState('app_api', 'admin-config'),
			// to prevent some browsers to fill fields with remembered passwords
			readonly: true,
		}
	},
	watch: {
	},
	mounted() {
	},
	methods: {
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
			axios.put(url, req).then((response) => {
				showSuccess(t('app_ecosystem_v2', 'Admin options saved'))
			}).catch((error) => {
				showError(
					t('app_ecosystem_v2', 'Failed to save admin options')
					+ ': ' + (error.response?.request?.responseText ?? '')
				)
				console.error(error)
			})
		},
		onCheckboxChanged(newValue, key) {
			this.state[key] = newValue
			this.saveOptions({ [key]: this.state[key] ? '1' : '0' })
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
