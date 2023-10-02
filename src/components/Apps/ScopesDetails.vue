<template>
	<div class="scopes">
		<div class="required">
			<h3>{{ t('app_api', 'Required') }}</h3>
			{{ requiredScopes }}
		</div>
		<div class="optional">
			<h3>{{ t('app_api', 'Optional') }}</h3>
			{{ optionalScopes }}
		</div>
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
