import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { getCurrentUser } from '@nextcloud/auth'

const state = loadState('app_api', 'ex_files_actions_menu')

function generateAppAPIProxyUrl(appId, route) {
	return generateUrl(`/apps/app_api/proxy/${appId}/${route}`)
}

if (OCA.Files && OCA.Files.fileActions) { // NC 27
	state.fileActions.forEach(fileAction => {
		const mimes = fileAction.mime.split(',').map(mime => mime.trim()) // multiple mimes are separated by comma

		const actionHandler = (fileName, context) => {
			const file = context.$file[0]
			const exAppFileActionHandler = generateAppAPIProxyUrl(fileAction.appid, fileAction.action_handler)
			axios.post(exAppFileActionHandler, {
				fileId: Number(file.dataset.id),
				name: fileName,
				directory: file.dataset.path,
				etag: file.dataset.etag,
				mime: file.dataset.mime,
				favorite: file.dataset.favorite || 'false',
				permissions: Number(file.dataset.permissions),
				fileType: file.dataset.type,
				size: Number(file.dataset.size),
				mtime: Number(file.dataset.mtime) / 1000, // convert ms to s
				shareTypes: file.dataset?.shareTypes || null,
				shareAttributes: file.dataset?.shareAttributes || null,
				sharePermissions: file.dataset?.sharePermissions || null,
				shareOwner: file.dataset?.shareOwner || null,
				shareOwnerId: file.dataset?.shareOwnerId || null,
				userId: getCurrentUser().uid,
				instanceId: state.instanceId,
			}).then((response) => {
				if (response.status === 200) {
					OC.dialogs.info(t('app_api', 'Action request sent to ExApp'), t(fileAction.appid, fileAction.display_name))
				} else {
					console.debug(response)
					OC.dialogs.info(t('app_api', 'Error while sending File action request to ExApp'), t(fileAction.appid, fileAction.display_name))
				}
			}).catch((error) => {
				console.error('error', error)
				OC.dialogs.info(t('app_api', 'Error while sending File action request to ExApp'), t(fileAction.appid, fileAction.display_name))
			})
		}

		mimes.forEach((mimeType) => {
			const action = {
				name: fileAction.name,
				displayName: t(fileAction.appid, fileAction.display_name),
				mime: mimeType,
				permissions: Number(fileAction.permissions),
				order: Number(fileAction.order),
				icon: fileAction.icon !== '' ? generateAppAPIProxyUrl(fileAction.appid, fileAction.icon) : null,
				iconClass: fileAction.icon === '' ? 'icon-app-api' : '',
				actionHandler,
			}
			OCA.Files.fileActions.registerAction(action)
		})
	})
}
