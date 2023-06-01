import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'

const state = loadState('app_ecosystem_v2', 'ex_files_actions_menu')
console.debug('state', state)

state.fileActions.forEach(fileAction => {
	console.debug('[AppEcosystemV2] registering file action', fileAction)
	const action = {
		name: fileAction.name,
		displayName: t(fileAction.appid, fileAction.display_name),
		mime: fileAction.mime,
		permissions: Number(fileAction.permissions),
		order: Number(fileAction.order),
		icon: fileAction.icon !== '' ? generateOcsUrl('/apps/app_ecosystem_v2/api/v1/files/action/icon?appId=' + fileAction.appid + '&exFileActionName=' + fileAction.name) : null,
		iconClass: fileAction.icon_class,
		actionHandler: (fileName, context) => {
			console.debug('[AppEcosystemV2] file', fileName)
			console.debug('[AppEcosystemV2] context', context)
			console.debug('[AppEcosystemV2] fileAction', fileAction)
			axios.post(generateOcsUrl('/apps/app_ecosystem_v2/api/v1/files/action'), {
				appId: fileAction.appid,
				actionName: fileAction.name,
				actionFile: {
					fileId: Number(context.$file[0].dataset.id),
					name: fileName,
					dir: context.$file[0].dataset.path,
				},
				actionHandler: fileAction.action_handler,
			}).then((response) => {
				console.debug('response', response)
				if (response.data.ocs.data.success) {
					OC.dialogs.info(t(fileAction.appid, 'Action request sent to ExApp'), t(fileAction.appid, fileAction.display_name))
				} else {
					OC.dialogs.info(t(fileAction.appid, 'Error while sending action request to ExApp'), t(fileAction.appid, fileAction.display_name))
				}
				// TODO: Handle defined format of response for next actions (e.g. show notification, open dialog, etc.)
			}).catch((error) => {
				console.error('error', error)
				OC.dialogs.info(t(fileAction.appid, 'Error while sending action request to ExApp'), t(fileAction.appid, fileAction.display_name))
			})
		},
	}
	OCA.Files.fileActions.registerAction(action)
})
