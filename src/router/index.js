import VueRouter from 'vue-router' // eslint-disable-line
import { generateUrl } from '@nextcloud/router'
import Vue from 'vue'
import { APPS_SECTION_ENUM } from '../constants/AppsConstants.js'
import store from '../store/index.js'

const Apps = () => import('../views/Apps.vue')

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
			path: '/embedded/:appid/:name',
			component: ExAppView,
			name: 'embedded',
			meta: {
				title: async () => {
					return t('app_api', 'Embedded ExApp')
				},
			},
		},
		{
			path: '/apps',
			component: Apps,
			name: 'apps',
			meta: {
				title: () => {
					return t('app_api', 'Your ExApps')
				},
			},
			children: [
				{
					path: ':category',
					name: 'apps-category',
					meta: {
						title: async (to) => {
							if (to.name === 'apps') {
								return t('app_api', 'Your ExApps')
							}
							if (APPS_SECTION_ENUM[to.params.category]) {
								return APPS_SECTION_ENUM[to.params.category]
							}
							await store.dispatch('getCategories')
							const category = store.getters.getCategoryById(to.params.category)
							if (category.displayName) {
								return category.displayName
							}
						},
					},
					component: Apps,
					children: [
						{
							path: ':id',
							name: 'apps-details',
							component: Apps,
						},
					],
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
