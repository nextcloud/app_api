<template>
	<div class="daemon-selection-modal">
		<NcModal :show="show" @close="closeModal">
			<div class="select-modal-body">
				<h3>Please choose a deploy daemon</h3>
				<div class="form-group">
					<NcSelect v-model="selectedDaemon" input-id="pizza" :options="['Cheese', 'Tomatoes', 'Pineapples']" />
				</div>
				<NcButton
					type="primary"
					@click="closeSelectModal">
					Deploy and Enable on chosen daemon
				</NcButton>
			</div>
		</NcModal>
	</div>
</template>

<script>
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'

export default {
	name: 'DaemonSelectionModal',
	components: {
		NcModal,
		NcButton,
		NcSelect,
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
		}
	},
	methods: {
		closeModal() {
			this.$emit('update:show', false)
		},
	},
}
</script>
<style scoped>
.select-modal-body {
	margin: 50px;
}

.select-modal-body h2 {
	text-align: center;
}

.form-group {
	margin: calc(var(--default-grid-baseline) * 4) 0;
	display: flex;
	flex-direction: column;
	align-items: flex-start;
}
</style>
