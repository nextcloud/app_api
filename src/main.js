import { linkTo } from '@nextcloud/router'
import { getRequestToken } from '@nextcloud/auth'
import Vue from 'vue'
import './bootstrap.js'
import { Tooltip } from '@nextcloud/vue'
import App from './App.vue'
import store from './store/index.js'
import router from './router/index.js'

import { sync } from 'vuex-router-sync'

Vue.directive('tooltip', Tooltip)

__webpack_nonce__ = btoa(getRequestToken()) // eslint-disable-line
__webpack_public_path__ = linkTo('app_api', 'js/') // eslint-disable-line

sync(store, router)

Vue.mixin({ methods: { t, n } })

export default new Vue({
	el: '#content',
	router,
	store,
	render: h => h(App),
})
