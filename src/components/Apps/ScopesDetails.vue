<template>
	<div class="scopes">
		<h3>{{ t('app_api', 'Required scopes') }}</h3>
		<template v-if="app.scopes">
			<div class="scopes-list">
				<span v-for="scope in app.scopes" :key="scope" class="badge">{{ scope }}</span>
			</div>
		</template>
		<template v-else>
			<p>{{ t('app_api', 'No scopes granted to this app') }}</p>
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
}
</script>

<style scoped lang="scss">
.scopes {
	padding: 20px;

	&-list {
		display: flex;
		flex-wrap: wrap;
	}

	.badge {
		margin-right: 5px;
		margin-bottom: 5px;
		padding: 3px 5px;
		border: 1px solid var(--color-border-dark);
		border-radius: var(--border-radius);
		color: var(--color-text-maxcontrast);
	}
}
</style>
