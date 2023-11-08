# Change Log

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

## [1.2.0 - 2023-11-08]

### Changed

- Prototypes of functions for calling external applications and PHP have been redesigned. @bigcat88, @kyteinsky
- Init (`/init`) handler now called with AppAPI auth too.

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
