<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI;

/**
 * @psalm-type AppAPIExAppStatus = array{
 *     deploy: int,
 *     init: int,
 *     action: string,
 *     type: string,
 *     error: string,
 *     deploy_start_time?: int,
 *     init_start_time?: int,
 *     heartbeat_count?: int,
 * }
 *
 * @psalm-type AppAPIExApp = array{
 *     id: string,
 *     name: string,
 *     version: string,
 *     enabled: bool,
 *     status: AppAPIExAppStatus,
 * }
 *
 * @psalm-type AppAPIExAppConfig = array{
 *     id: int,
 *     appid: string,
 *     configkey: string,
 *     configvalue: string,
 *     sensitive: int,
 * }
 *
 * @psalm-type AppAPIExAppConfigValue = array{
 *     configkey: string,
 *     configvalue: string,
 * }
 *
 * @psalm-type AppAPIExAppPreference = array{
 *     id: int,
 *     user_id: string,
 *     appid: string,
 *     configkey: string,
 *     configvalue: string,
 *     sensitive: int,
 * }
 *
 * @psalm-type AppAPIExAppRequestResult = array{
 *     status_code: int,
 *     headers: array<string, mixed>,
 *     body: string,
 * }
 *
 * @psalm-type AppAPIFileAction = array{
 *     id: int,
 *     appid: string,
 *     name: string,
 *     display_name: string,
 *     mime: string,
 *     permissions: string,
 *     order: int,
 *     icon: string,
 *     action_handler: string,
 *     version: string,
 *     default_action: ?string,
 * }
 *
 * @psalm-type AppAPITopMenu = array{
 *     id: int,
 *     appid: string,
 *     name: string,
 *     display_name: string,
 *     icon: string,
 *     admin_required: int,
 * }
 *
 * @psalm-type AppAPIInitialState = array{
 *     id: int,
 *     appid: string,
 *     type: string,
 *     name: string,
 *     key: string,
 *     value: mixed,
 * }
 *
 * @psalm-type AppAPIScript = array{
 *     id: int,
 *     appid: string,
 *     type: string,
 *     name: string,
 *     path: string,
 *     after_app_id: string,
 * }
 *
 * @psalm-type AppAPIStyle = array{
 *     id: int,
 *     appid: string,
 *     type: string,
 *     name: string,
 *     path: string,
 * }
 *
 * @psalm-type AppAPITaskProcessingProvider = array{
 *     id: ?int,
 *     app_id: ?string,
 *     name: ?string,
 *     display_name: ?string,
 *     task_type: ?string,
 *     provider: ?string,
 *     custom_task_type: ?string,
 * }
 *
 * @psalm-type AppAPIOccCommand = array{
 *     id: int,
 *     appid: string,
 *     name: string,
 *     description: string,
 *     hidden: int,
 *     arguments: list<mixed>,
 *     options: list<mixed>,
 *     usages: list<mixed>,
 *     execute_handler: string,
 * }
 *
 * @psalm-type AppAPITalkBot = array{
 *     id: string,
 *     secret: string,
 * }
 *
 * @psalm-type AppAPINotification = array{
 *     app: string,
 *     user: string,
 *     datetime: string,
 *     object_type: string,
 *     object_id: string,
 *     subject: string,
 *     message: string,
 *     link: string,
 *     icon: string,
 * }
 *
 * @psalm-type AppAPIDaemonConfig = array{
 *     id: int,
 *     accepts_deploy_id: string,
 *     name: string,
 *     display_name: string,
 *     protocol: string,
 *     host: string,
 *     deploy_config: array<string, mixed>,
 * }
 *
 * @psalm-type AppAPIDaemonConfigWithAppsCount = array{
 *     id: int,
 *     accepts_deploy_id: string,
 *     name: string,
 *     display_name: string,
 *     protocol: string,
 *     host: string,
 *     deploy_config: array<string, mixed>,
 *     exAppsCount: int,
 * }
 *
 * @psalm-type AppAPICategory = array{
 *     id: string,
 *     ident: string,
 *     displayName: string,
 * }
 *
 * @psalm-type AppAPIDeployOptions = array{
 *     environment_variables: array<string, string>,
 *     mounts: list<array{
 *         hostPath: string,
 *         containerPath: string,
 *         readonly: bool,
 *     }>,
 * }
 */
class ResponseDefinitions {
}
