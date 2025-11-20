<!--
  - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcModal :show="show"
		:name="t('app_api', 'Override Docker registries')"
		@close="closeModal">
		<div class="daemon-config-modal-details" :aria-label="t('app_api', 'Override Docker registries')">
			<h2>{{ t('app_api', 'Override Docker registries') }}</h2>

			<NcNoteCard type="info" :text="daemonName" />

			<p style="color: var(--color-text-lighter);">
				{{ t('app_api', 'Configure Docker registry override mappings for the selected daemon.') }}
				{{ t('app_api', 'The matching source registry in ExApp info.xml will be overwritten during deployment (image pull step).') }}
				{{ t('app_api', 'This is useful if you want to use a custom Docker registry, for example, to use a private Docker registry, or to use a different Docker registry for testing.') }}
			</p>

			<div class="registry-mapping-list">
				<NcListItem
					v-for="(registry, index) in registries"
					:key="index"
					:name="`${registry.from} -> ${registry.to}`"
					:force-display-actions="true">
					<template v-if="registry.to === 'local'" #details>
						<span style="color: var(--color-warning);">{{ t('app_api', 'Image pull will be skipped') }}</span>
					</template>
					<template #actions>
						<NcButton
							variant="icon"
							:disabled="removingRegistryLoading"
							@click="removeDockerRegistry(registry)">
							<template #icon>
								<NcLoadingIcon v-if="removingRegistryLoading" :size="20" />
								<Close v-else :size="20" />
							</template>
							{{ t('app_api', 'Remove') }}
						</NcButton>
					</template>
				</NcListItem>
			</div>

			<NcEmptyContent
				v-if="!addingRegistry && registries.length === 0"
				:name="t('app_api', 'No custom Docker registries are registered')">
				<template #icon>
					<Docker :size="50" />
				</template>
			</NcEmptyContent>

			<NcButton
				variant="primary"
				style="margin: 20px 0;"
				:disabled="addingLoading"
				@click="startAdding">
				{{ t('app_api', 'Add registry override mapping') }}
				<template #icon>
					<NcLoadingIcon v-if="addingLoading" :size="20" />
					<Plus :size="20" />
				</template>
			</NcButton>

			<div v-if="addingRegistry">
				<div style="display: flex; gap: 10px;">
					<NcTextField
						ref="dockerRegistryInput"
						v-model="dockerRegistry.from"
						:label="t('app_api', 'From')"
						:placeholder="t('app_api', 'registry URL (e.g. ghcr.io)')"
						:disabled="addingLoading"
						:loading="addingLoading"
						:error="!registryMappingFromValid"
						:helper-text="registryMappingFromValidationError"
						@keyup.enter="addDockerRegistry" />
					<NcTextField
						v-model="dockerRegistry.to"
						:label="t('app_api', 'To')"
						:placeholder="t('app_api', 'registry URL (e.g. docker.io)')"
						:disabled="addingLoading"
						:loading="addingLoading"
						:error="!registryMappingToValid"
						:helper-text="registryMappingToValidationError"
						@keyup.enter="addDockerRegistry" />
				</div>
				<p v-if="!newRegistryMappingValid" style="margin: 5px 0;">
					<span style="color: var(--color-error);">
						{{ registryMappingValidationError }}
					</span>
				</p>
				<div class="actions">
					<NcButton
						:disabled="addingLoading || !newRegistryMappingValid"
						variant="primary"
						@click="addDockerRegistry">
						{{ t('app_api', 'Add') }}
						<template #icon>
							<NcLoadingIcon v-if="addingLoading" :size="20" />
							<Check :size="20" />
						</template>
					</NcButton>
					<NcButton
						:disabled="addingLoading"
						variant="secondary"
						@click="addingRegistry = false">
						{{ t('app_api', 'Cancel') }}
						<template #icon>
							<NcLoadingIcon v-if="addingLoading" :size="20" />
							<Close :size="20" />
						</template>
					</NcButton>
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { showSuccess, showError } from '@nextcloud/dialogs'

import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcListItem from '@nextcloud/vue/components/NcListItem'

import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import Docker from 'vue-material-design-icons/Docker.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'

export default {
	name: 'DockerRegistriesModal',
	components: {
		NcModal,
		NcButton,
		NcNoteCard,
		NcLoadingIcon,
		NcTextField,
		NcEmptyContent,
		NcListItem,
		Docker,
		Plus,
		Check,
		Close,
	},
	props: {
		daemon: {
			type: Object,
			required: true,
			default: () => {},
		},
		show: {
			type: Boolean,
			required: true,
			default: false,
		},
		isDefault: {
			type: Boolean,
			required: true,
			default: () => false,
		},
		getAllDaemons: {
			type: Function,
			required: true,
		},
	},
	data() {
		return {
			addingRegistry: false,
			addingLoading: false,
			removingRegistryLoading: false,
			dockerRegistry: {
				from: '',
				to: '',
			},
		}
	},
	computed: {
		daemonName() {
			return `Daemon: ${this.daemon.display_name} (${this.daemon.name})`
		},
		registries() {
			return this.daemon.deploy_config?.registries || []
		},
		registryMappingFromValid() {
			return this.dockerRegistry.from
				&& this.dockerRegistry.from !== 'local'
				&& this.dockerRegistry.from !== this.dockerRegistry.to
				&& this.registries?.findIndex((registry) => registry.from === this.dockerRegistry.from) === -1
		},
		registryMappingToValid() {
			return this.dockerRegistry.to
				&& this.dockerRegistry.from !== 'local'
				&& this.dockerRegistry.from !== this.dockerRegistry.to
		},
		newRegistryMappingValid() {
			return this.registryMappingFromValid
				&& this.registryMappingToValid
				&& this.dockerRegistry.from !== this.dockerRegistry.to
		},
		registryMappingFromValidationError() {
			if (!this.dockerRegistry.from) {
				return t('app_api', 'Please enter a registry domain')
			}
			if (this.dockerRegistry.from === 'local') {
				return t('app_api', '"From" cannot be "local"')
			}
			if (this.registries?.findIndex((registry) => registry.from === this.dockerRegistry.from) !== -1) {
				return t('app_api', 'This registry mapping already exists')
			}
			return ''
		},
		registryMappingToValidationError() {
			if (!this.dockerRegistry.to) {
				return t('app_api', 'Please enter a registry domain')
			}
			return ''
		},
		registryMappingValidationError() {
			if (this.dockerRegistry.from && this.dockerRegistry.to && this.dockerRegistry.from === this.dockerRegistry.to) {
				return t('app_api', '"From" and "To" cannot be the same')
			}
			return ''
		},
	},
	methods: {
		closeModal() {
			this.$emit('update:show', false)
		},
		startAdding() {
			this.addingRegistry = true
			this.dockerRegistry = {
				from: '',
				to: '',
			}
			this.$nextTick(() => {
				this.$refs.dockerRegistryInput.focus()
			})
		},
		addDockerRegistry() {
			this.addingLoading = true
			confirmPassword().then(() => {
				axios.post(generateUrl(`/apps/app_api/daemons/${this.daemon.name}/add-registry`), { registryMap: this.dockerRegistry }).then((response) => {
					if (!response.data?.error) {
						showSuccess(t('app_api', 'Docker registry mapping added'))
						this.getAllDaemons().then(() => {
							this.addingRegistry = false
							this.dockerRegistry = {
								from: '',
								to: '',
							}
						})
					} else {
						showError('Error adding Docker registry mapping:' + response.data.error)
					}
				}).catch((err) => {
					console.error('Error adding Docker registry mapping', err)
					showError(t('app_api', 'Error adding Docker registry mapping'))
				}).finally(() => {
					this.addingLoading = false
				})
			}).catch(() => {
				this.addingLoading = false
				showError(t('app_api', 'Password confirmation failed'))
			})
		},
		removeDockerRegistry(registry) {
			this.removingRegistryLoading = true
			confirmPassword().then(() => {
				axios.post(generateUrl(`/apps/app_api/daemons/${this.daemon.name}/remove-registry`), { registryMap: registry }).then((response) => {
					if (!response.data?.error) {
						showSuccess(t('app_api', 'Docker registry mapping removed'))
						this.getAllDaemons()
					} else {
						showError('Error removing Docker registry mapping:' + response.data.error)
					}
				}).catch((err) => {
					console.error('Error removing Docker registry mapping', err)
					showError(t('app_api', 'Error removing Docker registry mapping'))
				}).finally(() => {
					this.removingRegistryLoading = false
				})
			}).catch(() => {
				this.removingRegistryLoading = false
				showError(t('app_api', 'Password confirmation failed'))
			})
		},
	},
}
</script>

<style scoped lang="scss">
.daemon-config-modal-details {
	padding: 20px;
}

.actions {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	margin: 20px 0;
}

.registry-mapping-list {
	max-height: 300px;
	margin: 10px 0;
}
</style>
