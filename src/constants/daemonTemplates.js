/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export const DAEMON_TEMPLATES = [
	{
		name: 'harp_proxy_host',
		displayName: 'HaRP Proxy (Host)',
		acceptsDeployId: 'docker-install',
		httpsEnabled: false,
		host: 'localhost:8780',
		nextcloud_url: null,
		deployConfig: {
			net: 'host',
			haproxy_password: 'some_very_secure_password',
			gpu: false,
			computeDevice: {
				id: 'cpu',
				label: 'CPU',
			},
			harp: {
				frp_address: 'localhost:8782',
				docker_socket_port: 24000,
				exapp_direct: false,
			},
		},
		deployConfigSettingsOpened: true,
		defaultDaemon: true,
	},
	{
		name: 'harp_proxy_docker',
		displayName: 'HaRP Proxy (Docker)',
		acceptsDeployId: 'docker-install',
		httpsEnabled: false,
		host: 'appapi-harp:8780',
		nextcloud_url: null,
		deployConfig: {
			net: '',
			haproxy_password: 'some_very_secure_password',
			gpu: false,
			computeDevice: {
				id: 'cpu',
				label: 'CPU',
			},
			harp: {
				frp_address: 'appapi-harp:8782',
				docker_socket_port: 24000,
				exapp_direct: false,
			},
		},
		deployConfigSettingsOpened: true,
		defaultDaemon: true,
	},
	{
		name: 'harp_aio',
		displayName: 'HaRP All-in-One',
		acceptsDeployId: 'docker-install',
		httpsEnabled: false,
		host: 'nextcloud-aio-harp:8780',
		nextcloud_url: null,
		deployConfig: {
			net: 'nextcloud-aio',
			haproxy_password: 'some_very_secure_password',
			gpu: false,
			computeDevice: {
				id: 'cpu',
				label: 'CPU',
			},
			harp: {
				frp_address: 'nextcloud-aio-harp:8782',
				docker_socket_port: 24000,
				exapp_direct: false,
			},
		},
		deployConfigSettingsOpened: false,
		defaultDaemon: true,
	},
	{
		name: 'manual_install_harp',
		displayName: 'HaRP Manual Install',
		acceptsDeployId: 'manual-install',
		httpsEnabled: false,
		host: 'appapi-harp:8780',
		nextcloud_url: null,
		deployConfig: {
			net: 'host',
			haproxy_password: 'some_very_secure_password',
			gpu: false,
			computeDevice: {
				id: 'cpu',
				label: 'CPU',
			},
			harp: {
				frp_address: 'localhost:8782',
				docker_socket_port: 24000,
				exapp_direct: false,
			},
		},
		deployConfigSettingsOpened: true,
		defaultDaemon: false,
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
			harp: null,
		},
		deployConfigSettingsOpened: false,
		defaultDaemon: true,
	},
	{
		name: 'custom',
		displayName: 'Custom Default',
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
			harp: null,
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
			harp: null,
		},
		deployConfigSettingsOpened: false,
		defaultDaemon: true,
	},
	{
		name: 'manual_install',
		displayName: 'Manual Install',
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
			harp: null,
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
