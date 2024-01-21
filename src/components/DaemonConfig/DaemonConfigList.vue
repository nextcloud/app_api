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
				:name="t('app_api', 'No Deploy daemons configured')"
				:description="t('app_api', 'Register a custom one or setup from available templates')">
				<template #icon>
					<FormatListBullet :size="20" />
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
		<RegisterDaemonConfigModal :show.sync="showRegisterModal" :daemons="daemons" :get-all-daemons="getAllDaemons" />
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
		getAllDaemons() {
			return axios.get(generateUrl('/apps/app_api/daemons'))
				.then(res => {
					this.$emit('update:daemons', res.data.daemons)
					this.$emit('update:defaultDaemon', res.data.default_daemon_config)
				})
		},
	},
}
</script>

<style scoped lang="scss">
.daemon-config-list {
	max-width: 50%;
	max-height: 300px;
	overflow-y: scroll;

	.empty-content {
		margin-top: 0;
		text-align: center;
	}
}
</style>
