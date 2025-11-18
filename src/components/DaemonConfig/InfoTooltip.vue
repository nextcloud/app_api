<!--
  - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<NcButton
			:title="text"
			variant="tertiary"
			@click="showDialog = true">
			<template #icon>
				<component :is="iconComponent" :size="20" />
			</template>
		</NcButton>
		<NcDialog v-model:open="showDialog"
			:name="t('app_api', 'More information')"
			:message="text"
			:close-on-click-outside="true"
			:out-transition="false"
			:container="null" />
	</div>
</template>

<script>
import Warning from 'vue-material-design-icons/AlertOutline.vue'
import Information from 'vue-material-design-icons/InformationOutline.vue'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'

export default {
	name: 'InfoTooltip',
	components: {
		NcButton,
		NcDialog,
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
			showDialog: false,
		}
	},
	computed: {
		iconComponent() {
			return this.type === 'warning' ? Warning : Information
		},
	},
}
</script>
