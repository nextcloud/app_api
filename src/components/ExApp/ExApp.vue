<template>
	<div class="ex-app">
		<div class="ex-app-image">
			<AppAPIIcon :size="128" />
		</div>
		<div class="ex-app-name">
			{{ getExAppRow(app) }}
		</div>
		<div class="ex-app-summary">
			{{ exAppSummary }}
		</div>
		<div class="ex-app-actions">
			<NcButton type="secondary" @click="showExAppDetails">
				{{ t('app_api', 'Details') }}
			</NcButton>
			<NcButton type="primary" @click="installExApp">
				{{ t('app_api', 'Unregister') }}
			</NcButton>
		</div>
		<ExAppDetailsModal v-show="showDetailsModal" :show.sync="showDetailsModal" :app="app" />
	</div>
</template>

<script>
import { showMessage } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

import AppAPIIcon from '../icons/AppAPIIcon.vue'
import ExAppDetailsModal from './ExAppDetailsModal.vue'

export default {
	name: 'ExApp',
	components: {
		NcButton,
		AppAPIIcon,
		ExAppDetailsModal,
	},
	props: {
		app: {
			type: Object,
			required: true,
			default: () => {},
		},
	},
	data() {
		return {
			showDetailsModal: false,
		}
	},
	computed: {
		exAppSummary() {
			return this.app.summary ?? 'ExApp summary'
		},
	},
	methods: {
		getExAppRow(exApp) {
			return `${exApp.name} (v${exApp.version})`
		},

		showExAppDetails() {
			this.showDetailsModal = true
		},

		installExApp() {
			showMessage('Install ExApp')
		},
	},
}
</script>

<style scoped lang="scss">
.ex-app {
	padding: 30px;
	border-radius: var(--border-radius-large);

	&:hover {
		background-color: var(--color-background-dark);
	}

	&-image {
		margin-bottom: 20px;
	}

	&-name {
		font-weight: bold;
		margin-bottom: 10px;
	}

	&-summary {
		margin-bottom: 5px;
	}

	&-actions {
		margin-top: 10px;
		display: flex;
		justify-content: flex-end;

		button {
			margin-left: 10px;
		}
	}
}
</style>
