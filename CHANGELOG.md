# Change Log

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [2.4.0 - 2024-04-04]

### Added

- API for listening to file system events. #259

### Changed

- Optimizations(1) related to speed up handling the incoming ExApps requests. #262

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
