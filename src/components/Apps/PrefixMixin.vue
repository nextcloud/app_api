<script>
export default {
	name: 'PrefixMixin',
	methods: {
		prefix(prefix, content) {
			return prefix + '_' + content
		},
	},
}
</script>
