import Vue from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import { generateFilePath } from '@nextcloud/router'

Vue.prototype.t = translate
Vue.prototype.n = translatePlural
Vue.prototype.OC = window.OC
Vue.prototype.OCA = window.OCA

// eslint-disable-next-line
__webpack_public_path__ = generateFilePath('app_ecosystem_v2', '', 'js/')
