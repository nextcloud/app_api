<!--
  - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcButton
		v-tooltip="{ content: text, trigger: 'click focus', delay: 0, placement: placement, autoHide: false }"
		type="tertiary"
		:aria-label="text">
		<template #icon>
			<Information :size="20" />
		</template>
	</NcButton>
</template>

<script>
import Information from 'vue-material-design-icons/Information.vue'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import Tooltip from '@nextcloud/vue/dist/Directives/Tooltip.js'

export default {
	name: 'InfoTooltip',
	components: {
		NcButton,
		Information,
	},
	directives: {
		Tooltip,
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
	},
	data() {
		return {
		}
	},
}
</script>
