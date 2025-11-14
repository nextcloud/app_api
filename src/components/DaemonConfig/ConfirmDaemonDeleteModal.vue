<!--
  - SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="confirm-daemon-delete">
		<NcModal :show="show"
			:name="t('app_api', 'Deploy daemon deletion confirmation')"
			@close="closeModal">
			<div class="confirm-delete-dialog">
				<h2>{{ t('app_api', 'Are you sure you want delete the "{name}" deploy daemon?', { name: daemon.name }) }}</h2>

				<NcCheckboxRadioSwitch
					:checked.sync="removeExAppsOnDaemonDelete">
					<template #default>
						{{ t('app_api', 'Remove all ExApps installed on this daemon') }}
					</template>
				</NcCheckboxRadioSwitch>

				<div class="actions">
					<NcButton type="tertiary" @click="closeModal">
						{{ t('app_api', 'Cancel') }}
					</NcButton>
					<NcButton type="error"
						:disabled="!removeExAppsOnDaemonDelete || deleting"
						@click="deleteDaemonConfig(daemon)">
						<template #icon>
							<NcLoadingIcon v-if="deleting" :size="20" />
							<Delete v-else :size="20" />
						</template>
						{{ t('app_api', 'Delete') }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import Delete from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'ConfirmDaemonDeleteModal',
	components: {
		NcModal,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		Delete,
		NcButton,
	},
	props: {
		show: {
			type: Boolean,
			required: true,
			default: false,
		},
		deleteDaemonConfig: {
			type: Function,
			required: true,
		},
		deleting: {
			type: Boolean,
			required: true,
			default: false,
		},
		daemon: {
			type: Object,
			required: true,
			default: () => {},
		},
	},
	data() {
		return {
			removeExAppsOnDaemonDelete: false,
		}
	},
	methods: {
		closeModal() {
			this.removeExAppsOnDaemonDelete = false
			this.$emit('update:show', false)
		},
	},
}
</script>

<style scoped lang="scss">
.confirm-delete-dialog {
	padding: 20px;

	p {
		margin-bottom: 10px;
	}

	.actions {
		display: flex;
		justify-content: flex-end;
		margin-top: 20px;

		button:first-child {
			margin-right: 10px;
		}
	}
}
</style>
