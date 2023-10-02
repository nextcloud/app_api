<script>
export default {
	name: 'SvgFilterMixin',
	data() {
		return {
			filterId: '',
		}
	},
	computed: {
		filterUrl() {
			return `url(#${this.filterId})`
		},
	},
	mounted() {
		this.filterId = 'invertIconApps-' + Math.random().toString(36).substring(2)
	},
}
</script>
