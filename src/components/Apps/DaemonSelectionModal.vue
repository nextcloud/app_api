<template>
	<div class="daemon-selection-modal">
		<NcModal name="selectionModal" :show="show" @close="closeModal">
			<div class="select-modal-body">
				<h3>Please choose a deploy daemon</h3>
				<DaemonSelectionList
					:daemons.sync="daemons"
					:default-daemon.sync="default_daemon_config"
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
