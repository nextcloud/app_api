# Change Log

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

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
