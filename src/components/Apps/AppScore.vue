<template>
	<img :src="scoreImage" class="app-score-image">
</template>

<script>
import { imagePath } from '@nextcloud/router'

export default {
	name: 'AppScore',
	props: {
		score: {
			type: Number,
			required: true,
		},
	},
	computed: {
		scoreImage() {
			const score = Math.round(this.score * 10)
			const imageName = 'rating/s' + score + '.svg'
			return imagePath('core', imageName)
		},
	},
}
</script>

<style lang="scss">
.app-score-image {
	height: 14px;
}
</style>
