<!--
  - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcButton
		v-tooltip="{ content: text, triggers: ['hover'], delay: 50, placement: placement, autoHide: true }"
		type="tertiary"
		:aria-label="text">
		<template #icon>
			<component :is="iconComponent" :fill-color="iconColor" :size="20" />
		</template>
	</NcButton>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import Tooltip from '@nextcloud/vue/dist/Directives/Tooltip.js'
import Warning from 'vue-material-design-icons/AlertOutline.vue'
import Information from 'vue-material-design-icons/InformationOutline.vue'

export default {
	name: 'InfoTooltip',
	components: {
		NcButton,
		Information,
		Warning,
	},
	directives: {
		tooltip: Tooltip,
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
