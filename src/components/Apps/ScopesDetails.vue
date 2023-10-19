<template>
	<div class="scopes">
		<template v-if="app.fromAppStore">
			<div class="required">
				<h3>{{ t('app_api', 'Required') }}</h3>
				{{ requiredScopes }}
			</div>
			<div class="optional">
				<h3>{{ t('app_api', 'Optional') }}</h3>
				{{ optionalScopes }}
			</div>
		</template>
		<template v-else>
			<div class="granted">
				<h3>{{ t('app_api', 'All granted scopes') }}</h3>
				{{ scopes }}
			</div>
		</template>
	</div>
</template>

<script>
export default {
	name: 'ScopesDetails',
	props: {
		app: {
			type: Object,
			required: true,
		},
	},
	computed: {
		requiredScopes() {
			return this.app.releases[0].apiScopes.filter(apiScope => apiScope.optional === false).map(apiScope => apiScope.scopeName).join(', ') || t('app_api', 'No required scopes')
		},
		optionalScopes() {
			return this.app.releases[0].apiScopes.filter(apiScope => apiScope.optional === true).map(apiScope => apiScope.scopeName).join(', ') || t('app_api', 'No optional scopes')
		},
		scopes() {
			return this.app.scopes.length > 0 ? this.app.scopes.join(', ') : t('app_api', 'No scopes granted to this app')
		},
	},
}
</script>

<style scoped lang="scss">
.scopes {
	padding: 20px;
}

.required {
	margin-bottom: 20px;

	h3 {
		font-weight: bold;
	}
}
</style>
