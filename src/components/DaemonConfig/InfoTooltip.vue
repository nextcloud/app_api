<!--
  - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcButton
		:title="text"
		variant="tertiary"
		:aria-label="text">
		<template #icon>
			<component :is="iconComponent" :fill-color="iconColor" :size="20" />
		</template>
	</NcButton>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import Warning from 'vue-material-design-icons/AlertOutline.vue'
import Information from 'vue-material-design-icons/InformationOutline.vue'

export default {
	name: 'InfoTooltip',
	components: {
		NcButton,
		Information,
		Warning,
	},
	props: {
		text: {
			type: String,
			required: true,
		},
		placement: {
			type: String,
			default: 'top',
		},
		type: {
			type: String,
			default: 'info',
		},
	},
	data() {
		return {
		}
	},
	computed: {
		iconComponent() {
			return this.type === 'warning' ? Warning : Information
		},
		iconColor() {
			return this.type === 'warning' ? 'var(--color-warning)' : 'var(--color-primary)'
		},
	},
}
</script>
