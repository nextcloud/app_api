import VueRouter from 'vue-router' // eslint-disable-line
import { generateUrl } from '@nextcloud/router'
import Vue from 'vue'

const ExApps = () => import('../views/ExApps.vue')

Vue.use(VueRouter)

function setPageHeading(heading) {
	const headingEl = document.getElementById('page-heading-level-1')
	if (headingEl) {
		headingEl.textContent = heading
	}
}

const baseTitle = document.title
const router = new VueRouter({
	mode: 'history',
	base: generateUrl('/apps/app_api', ''),
	linkActiveClass: 'active',
	routes: [
		{
			path: '/apps',
			component: ExApps,
			name: 'apps',
			meta: {
				title: () => {
					return t('app_api', 'Your ExApps')
				},
			},
			props: (route) => ({
				rootTitle: t('app_api', 'ExApps'),
			}),
			children: [
				{
					path: ':category',
					name: 'apps-category',
					meta: {
						title: async (to) => {
							if (to.name === 'apps') {
								return t('app_api', 'Your ExApps')
							}
						},
					},
				},
			],
		},
	],
})

router.afterEach(async (to) => {
	const metaTitle = await to.meta.title?.(to)
	if (metaTitle) {
		document.title = `${metaTitle} - ${baseTitle}`
		setPageHeading(metaTitle)
	} else {
		document.title = baseTitle
	}
})

export default router
