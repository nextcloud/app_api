<template>
	<div class="ex-app-modal">
		<NcModal :show="show" size="normal" @close="closeModal">
			<div class="ex-app-modal-details">
				<h2>{{ app.name }}</h2>
				<div class="top-sections">
					<div class="image">
						<AppAPIIcon :size="64" />
					</div>
					<div class="summary">
						<h3>{{ app.display_name }}</h3>
						<p>{{ t('app_api', 'Version:') }} {{ app.version }}</p>
						<p>{{ t('app_api', 'System:') }} {{ app.system }}</p>
					</div>
				</div>
				<div class="actions">
					<NcButton type="primary" @click="disableExApp">
						{{ t('app_api', 'Disable') }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

import AppAPIIcon from '../icons/AppAPIIcon.vue'

export default {
	name: 'ExAppDetailsModal',
	components: {
		NcModal,
		NcButton,
		AppAPIIcon,
	},
	props: {
		app: {
			type: Object,
			required: true,
			default: () => {},
		},
		show: {
			type: Boolean,
			required: true,
			default: false,
		},
	},
	data() {
		return {
		}
	},
	computed: {
	},
	methods: {
		closeModal() {
			this.$emit('update:show', false)
		},
		disableExApp() {
			this.closeModal()
		},
	},
}
</script>

<style scoped lang="scss">
.ex-app-modal-details {
	padding: 20px;

	.top-sections {
		display: flex;
		margin: 20px 0;

		.image, .summary {
			width: 100%;
		}
	}
}
</style>
