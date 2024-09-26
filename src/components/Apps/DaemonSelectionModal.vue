<template>
	<div class="daemon-selection-modal">
		<NcModal :show="show" @close="closeModal">
			<div class="select-modal-body">
				<h3>{{ t('app_api', 'Choose Deploy Daemon for {appName}', {appName: app.name }) }}</h3>
				<DaemonSelectionList
					:daemons.sync="daemons"
					:default-daemon.sync="default_daemon_config"
					:app-id="app.id"
					@close="closeModal" />
			</div>
		</NcModal>
	</div>
</template>

<script>
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import DaemonSelectionList from './DaemonSelectionList.vue'

export default {
	name: 'DaemonSelectionModal',
	components: {
		NcModal,
		DaemonSelectionList,
	},
	props: {
		show: {
			type: Boolean,
			required: true,
			default: false,
		},
		app: {
			type: Object,
			required: true,
		},
	},
	data() {
		return {
			selectDaemonModal: false,
			selectedDaemon: 'Cheese',
			daemons: [],
			default_daemon_config: '',
		}
	},

	mounted() {
		this.getAllDaemons()

	},
	methods: {
		closeModal() {
			this.$emit('update:show', false)
		},
		getAllDaemons() {
			return axios.get(generateUrl('/apps/app_api/daemons'))
				.then(res => {
					this.daemons = res.data.daemons
					this.default_daemon_config = res.data.default_daemon_config
				})
		},
	},
}
</script>
<style scoped>

.select-modal-body h3 {
	text-align: center;
}

</style>
