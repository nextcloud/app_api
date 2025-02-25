/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export const DAEMON_TEMPLATES = [
	{
		name: 'harp_proxy',
		displayName: 'HaRP Proxy with DSP',
		acceptsDeployId: 'docker-install',
		httpsEnabled: false,
		host: 'nextcloud-appapi-harp:8780',
		nextcloud_url: null,
		deployConfig: {
			net: 'host',
			haproxy_password: 'harp_shared_key',
			gpu: false,
			computeDevice: {
				id: 'cpu',
				label: 'CPU',
			},
		},
		deployConfigSettingsOpened: true,
		defaultDaemon: true,
		harp: {
			frp_address: 'nextcloud-appapi-harp:8782',
			docker_socket_proxy_port: 24000,
		},
	},
	{
		name: 'docker_socket_proxy',
		displayName: 'Docker Socket Proxy',
		acceptsDeployId: 'docker-install',
		httpsEnabled: false,
		host: 'nextcloud-appapi-dsp:2375',
		nextcloud_url: null,
		deployConfig: {
			net: 'host',
			haproxy_password: 'enter_haproxy_password',
			gpu: false,
			computeDevice: {
				id: 'cpu',
				label: 'CPU',
			},
		},
		deployConfigSettingsOpened: false,
		defaultDaemon: true,
	},
	{
		name: 'custom',
		displayName: 'Custom default',
		acceptsDeployId: 'docker-install',
		httpsEnabled: false,
		host: 'nextcloud-appapi-dsp:2375',
		nextcloud_url: null,
		deployConfig: {
			net: 'host',
			haproxy_password: 'some_secure_password',
			gpu: false,
			computeDevice: {
				id: 'cpu',
				label: 'CPU',
			},
		},
		deployConfigSettingsOpened: false,
		defaultDaemon: true,
	},
	{
		name: 'docker_aio',
		displayName: 'All-in-One',
		acceptsDeployId: 'docker-install',
		httpsEnabled: false,
		host: 'nextcloud-aio-docker-socket-proxy:2375',
		nextcloud_url: null,
		deployConfig: {
			net: 'nextcloud-aio',
			haproxy_password: '',
			gpu: false,
			computeDevice: {
				id: 'cpu',
				label: 'CPU',
			},
		},
		deployConfigSettingsOpened: false,
		defaultDaemon: true,
	},
	{
		name: 'manual_install',
		displayName: 'Manual install',
		acceptsDeployId: 'manual-install',
		httpsEnabled: false,
		host: 'host.docker.internal',
		nextcloud_url: null,
		deployConfig: {
			net: 'host',
			haproxy_password: '',
			gpu: false,
			computeDevice: {
				id: 'cpu',
				label: 'CPU',
			},
		},
		deployConfigSettingsOpened: false,
		defaultDaemon: false,
	},
]

export const DAEMON_COMPUTE_DEVICES = [
	{
		id: 'cpu',
		label: 'CPU',
	},
	{
		id: 'cuda',
		label: 'CUDA (NVIDIA)',
	},
	{
		id: 'rocm',
		label: 'ROCm (AMD)',
	},
]
