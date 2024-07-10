<template>
	<div class="daemon">
		<h3>{{ t('app_api', 'Deploy Daemon') }}</h3>
		<p><b>{{ t('app_api', 'Type') }}</b>: {{ daemon.accepts_deploy_id }}</p>
		<p><b>{{ t('app_api', 'Name') }}</b>: {{ daemon.name }}</p>
		<p><b>{{ t('app_api', 'Display Name') }}</b>: {{ daemon.display_name }}</p>
		<p><b>{{ t('app_api', 'GPUs support') }}</b>: {{ daemon.deploy_config?.gpu || 'false' }}</p>
	</div>
</template>

<script>
export default {
	name: 'DaemonDetails',
	props: {
		app: {
			type: Object,
			required: true,
		},
	},
	computed: {
		daemon() {
			return this.app.daemon
		},
	},
}
</script>

<style scoped lang="scss">
.daemon {
	padding: 20px;

	h3 {
		font-weight: bold;
	}
}
</style>
