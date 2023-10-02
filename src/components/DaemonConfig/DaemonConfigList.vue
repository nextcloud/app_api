<template>
	<div class="daemon-config">
		<div class="daemon-config-list">
			<ul v-if="daemons.length > 0">
				<DaemonConfig
					v-for="daemon in daemons"
					:key="daemon.id"
					:daemon="daemon"
					:is-default="defaultDaemon === daemon.name"
					:save-options="saveOptions"
					:get-all-daemons="getAllDaemons" />
			</ul>
			<NcEmptyContent
				v-else
				:name="t('app_api', 'No DaemonConfigs')"
				:description="t('app_api', 'No DaemonConfigs registered. Create a custom one or setup default configuration automatically')">
				<template #icon>
					<FormatListBullet :size="20" />
				</template>
				<template #action>
					<NcButton @click="registerDefaultDaemonConfig">
						{{ t('app_api', 'Register default DaemonConfig') }}
						<template #icon>
							<NcLoadingIcon v-if="registeringDefaultDaemonConfig" :size="20" />
						</template>
					</NcButton>
				</template>
			</NcEmptyContent>
		</div>
		<NcButton type="primary" style="margin: 20px 0;" @click="showRegister">
			{{ t('app_api', 'Register Daemon') }}
			<template #icon>
				<Plus v-if="!registering" :size="20" />
				<NcLoadingIcon v-else />
			</template>
		</NcButton>
		<RegisterDaemonConfigModal :show.sync="showRegisterModal" :get-all-daemons="getAllDaemons" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

import DaemonConfig from './DaemonConfig.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'

import FormatListBullet from 'vue-material-design-icons/FormatListBulleted.vue'

import RegisterDaemonConfigModal from './RegisterDaemonConfigModal.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'

export default {
	name: 'DaemonConfigList',
	components: {
		FormatListBullet,
		NcButton,
		NcLoadingIcon,
		Plus,
		DaemonConfig,
		RegisterDaemonConfigModal,
		NcEmptyContent,
	},
	props: {
		daemons: {
			type: Array,
			required: true,
			default: () => [],
		},
		defaultDaemon: {
			type: String,
			required: true,
		},
		saveOptions: {
			type: Function,
			required: true,
		},
	},
	data() {
		return {
			showRegisterModal: false,
			registering: false,
			registeringDefaultDaemonConfig: false,
		}
	},
	methods: {
		showRegister() {
			this.showRegisterModal = true
		},
		updateDaemonConfig(daemon) {
			axios.put(generateUrl('/apps/app_api/daemons'), { name: daemon.name, params: daemon })
				.then(res => {
					if (res.data.success) {
						this.getAllDaemons()
					}
				})
		},
		getAllDaemons() {
			return axios.get(generateUrl('/apps/app_api/daemons'))
				.then(res => {
					this.$emit('update:daemons', res.data.daemons)
					this.$emit('update:defaultDaemon', res.data.default_daemon_config)
				})
		},
		registerDefaultDaemonConfig() {
			this.registeringDefaultDaemonConfig = true
			axios.post(generateUrl('/apps/app_api/daemons'), {
				daemonConfigParams: {
					name: 'docker_local',
					display_name: 'Local Docker',
					accepts_deploy_id: 'docker-install',
					protocol: 'unix-socket',
					host: '/var/run/docker.sock',
					deploy_config: {
						net: 'host',
						host: null,
						nextcloud_url: window.location.origin + generateUrl(''),
						ssl_key: null,
						ssl_key_password: null,
						ssl_cert: null,
						ssl_cert_password: null,
						gpus: [],
					},
				},
			})
				.then(res => {
					this.registeringDefaultDaemonConfig = false
					if (res.data.success) {
						showSuccess(t('app_api', 'DaemonConfig successfully registered'))
						this.getAllDaemons()
					} else {
						showError(t('app_api', 'Failed to register DaemonConfig. Check the logs'))
					}
				})
				.catch(err => {
					this.registeringDefaultDaemonConfig = false
					console.debug(err)
					showError(t('app_api', 'Failed to register DaemonConfig. Check the logs'))
				})
		},
	},
}
</script>

<style scoped lang="scss">
.daemon-config-list {
	max-width: 50%;
	max-height: 200px;
	overflow-y: scroll;

	.empty-content {
		margin-top: 0;
		text-align: center;
	}
}
</style>
