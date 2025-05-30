/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { registerFileAction, FileAction } from '@nextcloud/files'
import { getCurrentUser } from '@nextcloud/auth'
import { translate as t } from '@nextcloud/l10n'

const state = loadState('app_api', 'ex_files_actions_menu')

function loadStaticAppAPIInlineSvgIcon() {
	return '<svg xmlns="http://www.w3.org/2000/svg" version="1.1" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:svgjs="http://svgjs.com/svgjs" width="512" height="512" x="0" y="0" viewBox="0 0 100 100" style="enable-background:new 0 0 512 512; filter: var(--background-invert-if-dark);" xml:space="preserve" class=""><g><g stroke-linecap="round" stroke-linejoin="round"><path d="M53.105 17.553a1 1 0 0 0-.623.447 2.93 2.93 0 0 1-4.975-.006 1 1 0 0 0-1.378-.314L26.16 30.22a1 1 0 0 0-.318 1.376 2.955 2.955 0 0 1 0 3.133 1 1 0 0 0 .318 1.375l19.83 12.45a1 1 0 0 0 1.416-.38 2.91 2.91 0 0 1 2.596-1.557c1.127 0 2.093.626 2.584 1.557a1 1 0 0 0 1.416.38l19.51-12.24a1 1 0 0 0 .285-1.425 2.95 2.95 0 0 1-.557-1.721c0-.65.2-1.23.551-1.715a1 1 0 0 0-.277-1.433l-19.65-12.34a1 1 0 0 0-.759-.127zm-6.544 2.218c.898.924 2.054 1.606 3.441 1.606 1.38 0 2.533-.68 3.43-1.606l18.402 11.555c-.253.596-.594 1.16-.594 1.842 0 .689.337 1.246.59 1.84L53.605 46.44c-.906-1.05-2.123-1.824-3.603-1.824-1.486 0-2.707.774-3.615 1.824L27.827 34.79c.193-.53.464-1.03.464-1.621 0-.596-.273-1.098-.467-1.63z" fill="#000000" data-original="#000000" class=""></path><path d="M27.223 34.41a1 1 0 0 0-1.377.313 2.931 2.931 0 0 1-2.494 1.384 1 1 0 0 0-1 1v23.12a1 1 0 0 0 1 1c1.641 0 2.939 1.3 2.939 2.941 0 .5-.125.969-.344 1.383a1 1 0 0 0 .352 1.312l19.83 12.451A1 1 0 0 0 47.508 79a2.93 2.93 0 0 1 4.974-.006 1 1 0 0 0 1.381.32l19.96-12.52a1 1 0 0 0 .314-1.382 2.793 2.793 0 0 1-.436-1.525 2.936 2.936 0 0 1 2.94-2.94 1 1 0 0 0 1-1v-22.87a1 1 0 0 0-1.131-.991 2.41 2.41 0 0 1-.328.021c-.992 0-1.851-.483-2.393-1.228a1 1 0 0 0-1.34-.26L52.94 46.86a1 1 0 0 0-.345 1.327c.222.407.347.87.347 1.37 0 1.64-1.31 2.94-2.939 2.94a2.918 2.918 0 0 1-2.941-2.94c0-.5.127-.968.345-1.382a1 1 0 0 0-.353-1.315zm-.43 2.09 18.61 11.686c-.137.45-.342.874-.342 1.37 0 2.719 2.223 4.94 4.941 4.94 2.71 0 4.94-2.218 4.94-4.94 0-.494-.212-.92-.35-1.372l18.316-11.49c.75.692 1.665 1.155 2.733 1.28V59.15c-2.224.48-3.94 2.377-3.94 4.737 0 .578.268 1.069.455 1.59L53.432 77.223c-.897-.926-2.05-1.606-3.43-1.606-1.387 0-2.543.682-3.441 1.606L27.949 65.539c.136-.451.342-.874.342-1.371 0-2.364-1.714-4.26-3.94-4.738V37.844a4.748 4.748 0 0 0 2.442-1.344z" fill="#000000" data-original="#000000" class=""></path><path d="M27.223 34.41a1 1 0 0 0-1.377.313 2.931 2.931 0 0 1-2.494 1.384 1 1 0 0 0-1 1v23.12a1 1 0 0 0 1 1c1.641 0 2.939 1.3 2.939 2.941 0 .5-.125.969-.344 1.383a1 1 0 0 0 .352 1.312l19.83 12.451A1 1 0 0 0 47.508 79a2.93 2.93 0 0 1 4.974-.006 1 1 0 0 0 1.381.32l19.96-12.52a1 1 0 0 0 .314-1.382 2.793 2.793 0 0 1-.436-1.525 2.936 2.936 0 0 1 2.94-2.94 1 1 0 0 0 1-1v-22.87a1 1 0 0 0-1.131-.991 2.41 2.41 0 0 1-.328.021c-.992 0-1.851-.483-2.393-1.228a1 1 0 0 0-1.34-.26L52.94 46.86a1 1 0 0 0-.345 1.327c.222.407.347.87.347 1.37 0 1.64-1.31 2.94-2.939 2.94a2.918 2.918 0 0 1-2.941-2.94c0-.5.127-.968.345-1.382a1 1 0 0 0-.353-1.315zm-.43 2.09 18.61 11.686c-.137.45-.342.874-.342 1.37 0 2.719 2.223 4.94 4.941 4.94 2.71 0 4.94-2.218 4.94-4.94 0-.494-.212-.92-.35-1.372l18.316-11.49c.75.692 1.665 1.155 2.733 1.28V59.15c-2.224.48-3.94 2.377-3.94 4.737 0 .578.268 1.069.455 1.59L53.432 77.223c-.897-.926-2.05-1.606-3.43-1.606-1.387 0-2.543.682-3.441 1.606L27.949 65.539c.136-.451.342-.874.342-1.371 0-2.364-1.714-4.26-3.94-4.738V37.844a4.748 4.748 0 0 0 2.442-1.344z" fill="#000000" data-original="#000000" class=""></path><path d="M53.105 17.553a1 1 0 0 0-.623.447 2.93 2.93 0 0 1-4.975-.006 1 1 0 0 0-1.378-.314L26.16 30.22a1 1 0 0 0-.318 1.376 2.955 2.955 0 0 1 0 3.133 1 1 0 0 0 .318 1.375l19.83 12.45a1 1 0 0 0 1.416-.38 2.91 2.91 0 0 1 2.596-1.557c1.127 0 2.093.626 2.584 1.557a1 1 0 0 0 1.416.38l19.51-12.24a1 1 0 0 0 .285-1.425 2.95 2.95 0 0 1-.557-1.721c0-.65.2-1.23.551-1.715a1 1 0 0 0-.277-1.433l-19.65-12.34a1 1 0 0 0-.759-.127zm-6.544 2.218c.898.924 2.054 1.606 3.441 1.606 1.38 0 2.533-.68 3.43-1.606l18.402 11.555c-.253.596-.594 1.16-.594 1.842 0 .689.337 1.246.59 1.84L53.605 46.44c-.906-1.05-2.123-1.824-3.603-1.824-1.486 0-2.707.774-3.615 1.824L27.827 34.79c.193-.53.464-1.03.464-1.621 0-.596-.273-1.098-.467-1.63z" fill="#000000" data-original="#000000" class=""></path><path d="M65.91 38.799a1 1 0 0 0-1.379.314 1 1 0 0 0 .313 1.38l-.012-.009a1 1 0 0 0 .969.926 1 1 0 0 0 1-1v-.5a1 1 0 0 0-.469-.846zM65.8 44.17a1 1 0 0 0-1 1v.951a1 1 0 0 0 1 1 1 1 0 0 0 1-1v-.951a1 1 0 0 0-1-1zm0 5.709a1 1 0 0 0-1 1v.951a1 1 0 0 0 1 1 1 1 0 0 0 1-1v-.951a1 1 0 0 0-1-1zM65.8 55.59a1 1 0 0 0-.968.926l.012-.008a1 1 0 0 0-.313 1.379 1 1 0 0 0 1.38.314l.421-.265a1 1 0 0 0 .469-.846v-.5a1 1 0 0 0-1-1zM61.201 59.14a1 1 0 0 0-.754.13l-.879.55a1 1 0 0 0-.316 1.38 1 1 0 0 0 1.379.316l.879-.553a1 1 0 0 0 .316-1.379 1 1 0 0 0-.625-.443zm-6.031 3.442-.879.55a1 1 0 0 0-.316 1.38 1 1 0 0 0 1.379.316l.878-.553a1 1 0 0 0 .317-1.379 1 1 0 0 0-1.38-.314zM49.355 65.766a1 1 0 0 0-.625.443 1 1 0 0 0 .315 1.379l.422.266a1 1 0 0 0 1.066 0l.422-.266a1 1 0 0 0 .315-1.379 1 1 0 0 0-1.208-.344l.047.03a1 1 0 0 0-.109-.02 1 1 0 0 0-.11.02l.047-.03a1 1 0 0 0-.582-.1zM38.799 59.14a1 1 0 0 0-.623.444 1 1 0 0 0 .314 1.379l.88.553a1 1 0 0 0 1.378-.317 1 1 0 0 0-.314-1.379l-.881-.55a1 1 0 0 0-.754-.13zm6.033 3.442a1 1 0 0 0-1.379.314 1 1 0 0 0 .315 1.38l.878.552a1 1 0 0 0 1.38-.316 1 1 0 0 0-.315-1.377zM34.2 55.59a1 1 0 0 0-1 1v.5a1 1 0 0 0 .468.846l.422.265a1 1 0 0 0 1.379-.314 1 1 0 0 0-.27-1.332v.035a1 1 0 0 0-.01-.045 1 1 0 0 0-.033-.037l.031.021a1 1 0 0 0-.988-.94zM34.2 44.17a1 1 0 0 0-1 1v.951a1 1 0 0 0 1 1 1 1 0 0 0 1-1v-.951a1 1 0 0 0-1-1zm0 5.709a1 1 0 0 0-1 1v.951a1 1 0 0 0 1 1 1 1 0 0 0 1-1v-.951a1 1 0 0 0-1-1zM34.846 38.67a1 1 0 0 0-.756.129l-.422.265a1 1 0 0 0-.469.846v.5a1 1 0 0 0 1 1 1 1 0 0 0 .969-.926l-.012.008a1 1 0 0 0 .016-.017 1 1 0 0 0 .027-.065v.035a1 1 0 0 0 .27-1.332 1 1 0 0 0-.623-.443zM45.402 32.045a1 1 0 0 0-.756.127l-.878.553a1 1 0 0 0-.317 1.379 1 1 0 0 0 1.38.314l.878-.55a1 1 0 0 0 .316-1.38 1 1 0 0 0-.623-.443zm-5.279 3.312a1 1 0 0 0-.754.127l-.879.553a1 1 0 0 0-.316 1.379 1 1 0 0 0 1.379.314l.879-.55a1 1 0 0 0 .316-1.38 1 1 0 0 0-.625-.443zM49.467 29.146l-.422.266a1 1 0 0 0-.315 1.379 1 1 0 0 0 1.27.29 1 1 0 0 0 1.27-.29 1 1 0 0 0-.315-1.379l-.422-.266a1 1 0 0 0-1.066 0zM54.598 32.045a1 1 0 0 0-.623.443 1 1 0 0 0 .314 1.377l.879.553a1 1 0 0 0 1.379-.314 1 1 0 0 0-.315-1.38l-.878-.552a1 1 0 0 0-.756-.127zm5.277 3.312a1 1 0 0 0-.623.444 1 1 0 0 0 .314 1.379l.881.55a1 1 0 0 0 1.377-.314 1 1 0 0 0-.314-1.379l-.88-.553a1 1 0 0 0-.755-.127z" fill="#000000" data-original="#000000" class=""></path><g stroke-miterlimit="10"><path d="M50.002 52.496a1 1 0 0 0-1 1v23.121a1 1 0 0 0 1 1 1 1 0 0 0 1-1v-23.12a1 1 0 0 0-1-1zM50 11.5c-2.716 0-4.94 2.223-4.94 4.94s2.224 4.94 4.94 4.94 4.94-2.224 4.94-4.94S52.715 11.5 50 11.5zm0 2c1.636 0 2.94 1.304 2.94 2.94s-1.304 2.94-2.94 2.94-2.94-1.305-2.94-2.94S48.365 13.5 50 13.5zM23.354 28.227a4.954 4.954 0 0 0-4.94 4.939 4.954 4.954 0 0 0 4.94 4.94c2.716 0 4.94-2.224 4.94-4.94s-2.224-4.94-4.94-4.94zm0 2a2.926 2.926 0 0 1 2.94 2.939 2.926 2.926 0 0 1-2.94 2.94 2.924 2.924 0 0 1-2.94-2.94 2.924 2.924 0 0 1 2.94-2.94z" fill="#000000" data-original="#000000" class=""></path><path d="M50 44.617a4.954 4.954 0 0 0-4.94 4.94c0 2.716 2.224 4.94 4.94 4.94s4.94-2.224 4.94-4.94a4.954 4.954 0 0 0-4.94-4.94zm0 2a2.924 2.924 0 0 1 2.94 2.94c0 1.635-1.304 2.94-2.94 2.94s-2.94-1.305-2.94-2.94a2.924 2.924 0 0 1 2.94-2.94zM76.182 28.227a4.954 4.954 0 0 0-4.94 4.939 4.954 4.954 0 0 0 4.94 4.94 4.954 4.954 0 0 0 4.94-4.94 4.954 4.954 0 0 0-4.94-4.94zm0 2a2.924 2.924 0 0 1 2.94 2.939 2.924 2.924 0 0 1-2.94 2.94 2.924 2.924 0 0 1-2.94-2.94 2.924 2.924 0 0 1 2.94-2.94zM23.354 59.229a4.954 4.954 0 0 0-4.94 4.939 4.954 4.954 0 0 0 4.94 4.94c2.716 0 4.94-2.224 4.94-4.94s-2.224-4.94-4.94-4.94zm0 2c1.635 0 2.94 1.303 2.94 2.939s-1.305 2.94-2.94 2.94a2.924 2.924 0 0 1-2.94-2.94 2.924 2.924 0 0 1 2.94-2.94zM50 75.62c-2.716 0-4.94 2.224-4.94 4.94S47.285 85.5 50 85.5s4.94-2.223 4.94-4.94-2.224-4.94-4.94-4.94zm0 2c1.636 0 2.94 1.305 2.94 2.94S51.635 83.5 50 83.5s-2.94-1.304-2.94-2.94 1.304-2.94 2.94-2.94zM76.646 58.951c-2.716 0-4.94 2.225-4.94 4.942s2.224 4.939 4.94 4.939c2.717 0 4.94-2.223 4.94-4.94s-2.223-4.94-4.94-4.94zm0 2c1.636 0 2.94 1.306 2.94 2.942s-1.304 2.939-2.94 2.939c-1.635 0-2.94-1.304-2.94-2.94s1.305-2.94 2.94-2.94zM82.527 16.059l-2.129 2.128a1 1 0 0 0 0 1.415 1 1 0 0 0 1.415 0l2.128-2.13a1 1 0 0 0 0-1.413 1 1 0 0 0-1.414 0zM18.895 80.105a1 1 0 0 0-.707.293l-2.13 2.13a1 1 0 0 0 0 1.413 1 1 0 0 0 1.415 0l2.129-2.129a1 1 0 0 0 0-1.414 1 1 0 0 0-.707-.293zM93.99 49a1 1 0 0 0-1 1 1 1 0 0 0 1 1H97a1 1 0 0 0 1-1 1 1 0 0 0-1-1zM3 49a1 1 0 0 0-1 1 1 1 0 0 0 1 1h3.01a1 1 0 0 0 1-1 1 1 0 0 0-1-1zM50 2a1 1 0 0 0-1 1v3.01a1 1 0 0 0 1 1 1 1 0 0 0 1-1V3a1 1 0 0 0-1-1zM50 92.99a1 1 0 0 0-1 1V97a1 1 0 0 0 1 1 1 1 0 0 0 1-1v-3.01a1 1 0 0 0-1-1zM80.398 80.398a1 1 0 0 0 0 1.415l2.13 2.128a1 1 0 0 0 1.413 0 1 1 0 0 0 0-1.414l-2.129-2.129a1 1 0 0 0-1.414 0zM16.766 15.766a1 1 0 0 0-.707.293 1 1 0 0 0 0 1.414l2.128 2.129a1 1 0 0 0 1.415 0 1 1 0 0 0 0-1.414l-2.13-2.13a1 1 0 0 0-.706-.292z" fill="#000000" data-original="#000000" class=""></path></g></g></g></svg>'
}

function loadExAppInlineSvgIcon(appId, route) {
	const url = generateAppAPIProxyUrl(appId, route)
	return axios.get(url).then((response) => {
		// Check content type to be svg image
		if (response.headers['content-type'] !== 'image/svg+xml') {
			return null
		}
		return response.data
	}).catch((error) => {
		console.error('Failed to load ExApp FileAction icon inline svg', error)
		return null
	})
}

function generateAppAPIProxyUrl(appId, route) {
	return generateUrl(`/apps/app_api/proxy/${appId}/${route}`)
}

function generateExAppUIPageUrl(appId, route) {
	return generateUrl(`/apps/app_api/embedded/${appId}/${route}`)
}

function registerFileAction28(fileAction, inlineSvgIcon) {
	const action = new FileAction({
		id: fileAction.name,
		displayName: () => t(fileAction.appid, fileAction.display_name),
		iconSvgInline: () => inlineSvgIcon,
		order: Number(fileAction.order),
		enabled(files, view) {
			if (files.length === 1) {
				// Check for multiple mimes separated by comma
				let isMimeMatch = false
				fileAction.mime.split(',').forEach((mime) => {
					if (files[0].mime.indexOf(mime.trim()) !== -1) {
						isMimeMatch = true
					}
				})
				return isMimeMatch
			} else if (files.length > 1) {
				// Check all files match fileAction mime
				return files.every((file) => {
					// Check for multiple mimes separated by comma
					let isMimeMatch = false
					fileAction.mime.split(',').forEach((mime) => {
						if (file.mime.indexOf(mime.trim()) !== -1) {
							isMimeMatch = true
						}
					})
					return isMimeMatch
				})
			}
		},
		async exec(node, view, dir) {
			const exAppFileActionHandler = generateAppAPIProxyUrl(fileAction.appid, fileAction.action_handler)
			if ('version' in fileAction && fileAction.version === '2.0') {
				return axios.post(exAppFileActionHandler, { files: [buildNodeInfo(node)] })
					.then((response) => {
						if (typeof response.data === 'object' && 'redirect_handler' in response.data) {
							const redirectPage = generateExAppUIPageUrl(fileAction.appid, response.data.redirect_handler)
							window.location.assign(`${redirectPage}?fileIds=${node.fileid}`)
							return true
						}
						return true
					}).catch((error) => {
						console.error('Failed to send FileAction request to ExApp', error)
						return false
					})
			}
			return axios.post(exAppFileActionHandler, buildNodeInfo(node))
				.then(() => {
					return true
				})
				.catch((error) => {
					console.error('Failed to send FileAction request to ExApp', error)
					return false
				})
		},
		async execBatch(nodes, view, dir) {
			if ('version' in fileAction && fileAction.version === '2.0') {
				const exAppFileActionHandler = generateAppAPIProxyUrl(fileAction.appid, fileAction.action_handler)
				const nodesDataList = nodes.map(buildNodeInfo)
				return axios.post(exAppFileActionHandler, { files: nodesDataList })
					.then((response) => {
						if (typeof response.data === 'object' && 'redirect_handler' in response.data) {
							const redirectPage = generateExAppUIPageUrl(fileAction.appid, response.data.redirect_handler)
							const fileIds = nodes.map((node) => node.fileid).join(',')
							window.location.assign(`${redirectPage}?fileIds=${fileIds}`)
						}
						return nodes.map(_ => true)
					})
					.catch((error) => {
						console.error('Failed to send FileAction request to ExApp', error)
						return nodes.map(_ => false)
					})
			}
			// for version 1.0 behavior is not changed
			return Promise.all(nodes.map((node) => {
				return this.exec(node, view, dir)
			}))
		},
	})
	registerFileAction(action)
}

function buildNodeInfo(node) {
	return {
		fileId: node.fileid,
		name: node.basename,
		directory: node.dirname,
		etag: node.attributes.etag,
		mime: node.mime,
		favorite: Boolean(node.attributes.favorite).toString(),
		permissions: node.permissions,
		fileType: node.type,
		size: Number(node.size),
		mtime: new Date(node.mtime).getTime() / 1000, // convert ms to s
		shareTypes: node.attributes.shareTypes || null,
		shareAttributes: node.attributes.shareAttributes || null,
		sharePermissions: node.attributes.sharePermissions || null,
		shareOwner: node.attributes.ownerDisplayName || null,
		shareOwnerId: node.attributes.ownerId || null,
		userId: getCurrentUser().uid,
		instanceId: state.instanceId,
	}
}

document.addEventListener('DOMContentLoaded', () => {
	state.fileActions.forEach(fileAction => {
		if (fileAction.icon === '') {
			const inlineSvgIcon = loadStaticAppAPIInlineSvgIcon()
			registerFileAction28(fileAction, inlineSvgIcon)
		} else {
			loadExAppInlineSvgIcon(fileAction.appid, fileAction.icon).then((svg) => {
				if (svg !== null) {
					// Set css filter for theming
					const parser = new DOMParser()
					const icon = parser.parseFromString(svg, 'image/svg+xml')
					icon.documentElement.setAttribute('style', 'filter: var(--background-invert-if-dark);')
					// Convert back to inline string
					const inlineSvgIcon = icon.documentElement.outerHTML
					registerFileAction28(fileAction, inlineSvgIcon)
				}
			})
		}
	})
})
