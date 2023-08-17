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
			const file = context.$file[0]
			axios.post(generateOcsUrl('/apps/app_ecosystem_v2/api/v1/files/action'), {
				appId: fileAction.appid,
				actionName: fileAction.name,
				actionHandler: fileAction.action_handler,
				actionFile: {
					fileId: Number(file.dataset.id),
					name: fileName,
					directory: file.dataset.path,
					etag: file.dataset.etag,
					mime: file.dataset.mime,
					favorite: file.dataset?.favorite,
					permissions: file.dataset.permissions,
					fileType: file.dataset.type,
					size: file.dataset.size,
					mtime: file.dataset.mtime,
					shareTypes: file.dataset?.shareTypes,
					shareAttributes: file.dataset.shareAttributes,
					sharePermissions: file.dataset.sharePermissions,
				},
			}).then((response) => {
				console.debug('response', response)
				if (response.data.ocs.meta.statuscode === 200) {
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
