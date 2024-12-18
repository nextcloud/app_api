<!--
  - SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Change Log

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [3.2.0 - 2024-09-10]

### Added

- ExAppProxy: added bruteforce protection option for ExApp routes. #368
- ExAppOCS: added miscellaneous method to get Nextcloud instance base URL. #383

### Changed

- AppAPIAuth optimization: use throttler only when needed to lower the number of requests. #369
- ExAppProxy: the order of checks of the ExApp routes was changed. #366
- ExAppProxy: improve logic and logging with more explicit messages. #365
- Drop support for Nextcloud 27. #374

### Fixed

- TaskProcessing: fixed bug when provider wasn't removed on unregister. #370
- OCC: ExApp unregister command now doesn't remove volume by default. #381
- WebhooksListener: added removal of the webhook listeners on ExApp unregister. #382

### Removed

- ApiScopes are deprecated and removed. #373


## [3.1.0 - 2024-08-15]

**Breaking change**: Task processing API for NC30 AI API. (llm2 and translate2 apps) 

### Added

- Added logging in a separate file of ExApp requests made on behalf of user. #360

### Changed

- Task processing API was finalized. #359

### Fixed

- ExAppProxy: Proxy: do not set timeout for requests to ExApps. #357
- Bumped axios dependency from 1.6.2 to 1.7.4 #361

## [3.0.1 - 2024-08-07]

### Fixed

- ExAppProxy: do not forward untrusted headers to ExApp. #354

## [3.0.0 - 2024-08-05]

**Breaking change**: new mandatory (if ExAppProxy is used) ExApp routes declaration to register ExApp routes allowed to be called from Nextcloud or other origins.

### Added

- ExAppProxy: **Breaking change** added new mandatory (if ExAppProxy is used) routes declaration in `info.xml` to register ExApp routes allowed to be called from Nextcloud or other origins. #327
- New OCS API endpoint to setAppInitProgress. The old one is marked as deprecated. #319
- Added default timeout for requestToExApp function set to 3s. #277
- PublicFunction: added new method `getExApp`. #326
- TaskProcessing: added possibility to define custom task types. #324 @provokateurin
- AdminSettings: added possibility to edit Deploy daemon. #338 @vstelmakh
- ExAppProxy: added `X-Origin-IP` header for rate-limiting purposes. #351

### Changed

- ExApp system flag is now deprecated and removed to optimize performance and simplicity. #323
- PublicFunctions changes: `exAppRequestWithUserInit` and `asyncExAppRequestWithUserInit` are now deprecated. #323
- Admin settings actions on Deploy daemons now require a password confirmation. #342
- Changed the ExApp Docker image naming (`<image-name>:version_tag-<compute_device_type>`), the previous one is marked as deprecated (`<image-name>-<compute_device>:version_tag`). #340
- AppAPI now does not disable ExApp if the ExApp version has changed (`EX-APP-VERSION` header). #341
- `COMPUTE_DEVICE` environment variable is now always in upper case. #339

### Fixed

- Allow ExApps management disable and remove actions if default Deploy daemon is not accessible. #314
- Fixed Deploy daemon availability check using ping timeout set to 3s. #314
- Fix Test Deploy `image_pull` and `init` steps status update. #315
- Minor fixes to TaskProcessing provider. #336 @marcelklehr
- Fixed critical bug with work with APCu cache. #348
- ExAppProxy: preserve original `Authentication` passed to ExApp via Docker Socket Proxy. #334
- ExAppProxy: send all headers and raw data to ExApp. #330


## [2.7.0 - 2024-07-01]

### Fixed

- Nextcloud URL state warning in the Register daemon form for HTTPS configuration. #312
- Fix ExApp proxy to preserve the url params. #296
- Added missing pass through of cookies in ExApp proxy. #296
- Added missing multipart/form-data support for ExApp proxy. #296
- Fixed HTTP caching issue for application/json requests in ExApp proxy. #296 
- Fixed TopMenu API to allow using iframes. #311

## [2.6.0 - 2024-05-10]

### Added

- Added File Actions v2 version with redirect to the ExApp UI. #284
- Added async requestToExApp public functions. #290
- Added OCS API for synchronous requestToExApp functions. #290

### Changed

- Reworked scopes for database/cache requests optimization, drop old ex_app_scopes table. #285
- Corrected "Download ExApp logs" button availability in "Test deploy". #289

### Fixed

- Fixed incorrect init_timeout setting key in the UI. #288

## [2.5.1 - 2024-05-02]

### Added

- Test deploy button in Admin settings for each Daemon configuration. #279

## [2.5.0 - 2024-04-23]

### Added

- Different compute device configuration for Daemon (NVIDIA, AMD, CPU). #267
- Ability to add optional parameters when registering a daemon, for example *OVERRIDE_APP_HOST*. #269
- API for registering OCC commands. #272
- Correct support of the Docker `HEALTHCHECK` instruction. #273
- Support of pulling "custom" images for the selected compute device. #274

### Fixed

- Fixed notification icon absolute url. #268

## [2.4.0 - 2024-04-04]

### Added

- API for listening to file system events. #259

### Changed

- Optimizations(1) related to speed up handling the incoming ExApps requests. #262
- `occ app_api:scopes:list` command removed as not needed. #262

### Fixed

- Corrected error handling for `occ` commands: `register` and `update`. #258
- `SensitiveParameter` is applied to variables containing secrets, preventing them from being leaked to the logs. #261

## [2.3.2 - 2024-03-28]

### Added

- `--all` and `--showonly` flags to `occ app_api:app:update` command. #256

### Fixed

- Fixed incorrect notifications handling producing a lot of errors in the log. #252
- Replaced single ExApp caching with list caching, should improve performance. #253

## [2.3.1 - 2024-03-18]

## Added 

- `TEXT_PROCESSING` and `MACHINE_TRANSLATION` API scopes. #249

## Fixed

- Added missing check for the presence of a header for AppAPI authentication, which could lead to increased load on the server. #251
- Bump follow-redirects package from `1.15.5` to `1.15.6` #250

## [2.3.0 - 2024-03-13]

### Added

- `app_api_system` session flag for Nextcloud server to bypass rate limits for system ExApps. #248

### Changed

- ExAppProxy: adjusted how `headers` are passing from ExApp to client. #246

### Fixed

- Declarative Settings API was merged into Nextcloud Server, adjusted AppAPI code. #247

## [2.2.0 - 2024-02-21]

### Added

- Support of `L10N` translations for ExApps. #227

### Fixed

- Allowed removing of ExApp from UI during "init" stage. #235
- Reset of "Error" state for ExApp in Update/Enable actions. #236
- PublicFunctions.php: `exAppRequestWithUserInit` can accept empty `userId`. #238
- ISpeechToTextProviderWithUserId is now available in STT implementation. #240

## [2.1.0 - 2024-02-19]

### Changed

- `deploy` command was deprecated, now `register` and `deploy` is one step. #233
- Installation of ExApps algorithm has been rewritten to provide a more comfortable experience. #233

### Fixed

- Translation provider API correctly supports "language detection" feature. #232

## [2.0.4 - 2024-02-08]

### Changed

- Removed not needed `-e` parameter for `occ:app_api:app:deploy`. #222

### Fixed

- OCS API `log` always fail during ExApp `init` state. #224
- AI providers: undefined method call to ExApp. #226

## [2.0.3 - 2024-02-01]

### Added

- Added RestartPolicy option (Admin settings) #220
- Added ExApp init timeout option (Admin settings) #220

### Changed

- Removed support of `Optional` API scopes. #220

## [2.0.2 - 2024-01-28]

### Fixed

- More correct handling of the ExApps installation process when Nextcloud has a non-default directory location(e.g. `Unraid`). #217
- Correct handling of the action of stopping a Docker container when the action is already in progress. #217
- Correct handling of the ExApp deletion action, when during deletion you refresh the page and click delete again. #217

## [2.0.1 - 2024-01-25]

### Fixed

- MalformedUriException: Unable to parse URI exception - when the daemon has invalid URL. #216

## [2.0.0 - 2024-01-25]

AppAPI 2.0.
Breaking changes to Deploy daemons configuration and ExApps networking.
AppAPI Docker Socket Proxy.

### Added

- Added filesplugin batch actions implementation. #203
- Added MachineTranslation providers API. #210
- Deploy daemons management improvements and configuration templates. #212
- Added removal of ExApps on Deploy daemon deletion. #212

### Changed

- Changed TextProcessing providers API flow to asynchronous. #208
- Changed SpeechToText providers API flow to asynchronous. #209

## [1.4.6 - 2024-01-05]

### Fixed

- TopMenuAPI: support of params in styles/js ExApp URLs. #193 (Thanks to @splitt3r)
- NC28: FileActionsAPI wasn't working without specifying an icon. #198
- Bug introduced in the previous version, when the `userId` for some part of AppAPI became `null`. #199

## [1.4.5 - 2024-01-02]

### Added

- Support for `ALL` APIs scope, that allows to call any Nextcloud endpoints bypassing the API Scope check. #190

### Fixed

- Fixed incorrect DeployConfig SSL params parsing. #188 (Thanks to @raudraido)
- Incorrect HTTP status during invalid auth. #190

## [1.4.4 - 2023-12-21]

### Added

- Added ability for `requestToExApp` and `aeRequestToExApp` methods, to send `multipart` requests. #168

### Fixed

- Processing of invalid default Nextcloud URL/incorrect url with slash at the end. #169
- `occ app_api:app:register` error message in case of missing deploy of ExApp. #172
- Default Docker Daemon(`not for AIO`) configuration should be better now. #173
- UI fixes: `Update` button not working in some cases, missed `Uninstall` button. #177

## [1.4.3 - 2023-12-18]

### Added

- Links to new apps that uses AppAPI. #158

### Fixed

- Invalid timeout condition check for `/init` endpoint. #155

## [1.4.2 - 2023-12-13]

Maintenance update of npm packages to support NC28

### Changed

- Changed AIO auto-created daemon with gpu enabled to separate one (#134)
- Changed AIO detection to use new env (#150) 

## [1.4.1 - 2023-12-07]

Attempt to fix release on appstore side.

This release contains breaking changes, all ExApps should be updated accordingly to it.

### Added

- A request proxy from Frontend to ExApps, ExApps can now have a user interface like regular applications.
- New OCS endpoints to register entry in Nextcloud Top Menu. #135
- Ability to specify multiple mime types for FileAction Menu. #95

### Changed

- UI: FileActions OCS API was reworked, make it simpler to use and be in line with new UI API. #141

### Fixed

- Correct cleaning of ExApp stuff upon deletion.
- Oracle DB fixes and adjustments with additional tests.
- Tons of other bugfixes, adjustments and CI tests.


## [1.4.0 - 2023-12-06]

This release contains breaking changes, all ExApps should be updated accordingly to it.

### Added

- A request proxy from Frontend to ExApps, ExApps can now have a user interface like regular applications.
- New OCS endpoints to register entry in Nextcloud Top Menu. #135
- Ability to specify multiple mime types for FileAction Menu. #95

### Changed

- UI: FileActions OCS API was reworked, make it simpler to use and be in line with new UI API. #141

### Fixed

- Correct cleaning of ExApp stuff upon deletion.
- Oracle DB fixes and adjustments with additional tests.
- Tons of other bugfixes, adjustments and CI tests.

## [1.3.0 - 2023-11-28]

### Changed

- Reworked: algorithm  of `app:register` and occ cli command, "/init" endpoint now is optional. #128
- Reworked: `app_api:app:unregister` occ cli command, make it much robust. #127

### Fixed

- Proper pass-through of NVIDIA GPU into External apps containers. #130

## [1.2.2 - 2023-11-13]

### Fixed

- Fix "of the fix" of the bug in requestToExApp function introduced in previous release.

## [1.2.1 - 2023-11-08]

### Fixed

- Fix bug in requestToExApp function introduced in previous release.

## [1.2.0 - 2023-11-08]

### Changed

- Prototypes of functions for calling external applications and PHP have been redesigned. #112 @bigcat88, @kyteinsky
- ExApp init (`/init`) endpoint now called with AppAPI auth too. #111

### Fixed

- UI error when default daemon missing. #109
- FilesActions API: correct cast of file's permission to the number.
- Docs: ExApp install flow described. #108

## [1.1.0 - 2023-10-23]

### Added

- Added ExApp initialization progress
- Added disabled state of app management actions if default Deploy daemon is not accessible
- Added support for new fileActions registration (Nextcloud 28)

### Fixed

- Fixed incorrect error message in admin settings (https://github.com/cloud-py-api/app_api/issues/100)
- Fixed database schema for MySQL (https://github.com/cloud-py-api/app_api/issues/94)

## [1.0.1 - 2023-10-06]

### Fixed

- Invalid download of ex. applications from the AppStore. #88

## [1.0.0 - 2023-10-05]

### Added

- First release
