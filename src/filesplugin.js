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
		icon: fileAction.icon,
		iconClass: fileAction.icon_class,
		actionHandler: (fileName, context) => {
			console.debug('file', fileName)
			console.debug('context', context)
			axios.post(generateOcsUrl('/apps/app_ecosystem_v2/api/v1/files/action'), {
				appId: fileAction.appid,
				actionFile: {
					fileId: Number(context.$file[0].dataset.id),
					name: fileName,
					dir: context.$file[0].dataset.path,
				},
			}).then((response) => {
				console.debug('response', response)
				// TODO: Handle defined format of response for next actions (e.g. show notification, open dialog, etc.)
			}).catch((error) => {
				console.error('error', error)
			})
		},
	}
	OCA.Files.fileActions.registerAction(action)
})
