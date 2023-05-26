import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { registerFileAction } from '@nextcloud/files'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'

const state = loadState('app_ecosystem_v2', 'ex_files_actions_menu')

// TODO: Go through ex apps files actions and register them
state.fileActions.forEach(fileAction => {
	registerFileAction({
		id: fileAction.id,
		displayName: t(fileAction.appid, fileAction.displayName),
		mime: fileAction.mime,
		icon: fileAction.icon,
		order: fileAction.order,
		permissions: fileAction.permissions,
		actionHandler: (file) => {
			axios.post(generateUrl('/apps/app_ecosystem_v2/api/v1/files/action'), {
				appid: fileAction.appid,
				file: file
			})
		}
	})
});
