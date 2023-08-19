Glossary
========

AppEcosystemV2 brings out the following terms frequently used in the code:

* ``ExApp`` (External App) - the app on another (from PHP) programming language, which uses AppEcosystemV2 API
* ``DaemonConfig`` - configuration of orchestration daemon (e.g. Docker) where ExApps are deployed
* ``DeployConfig`` - additional DaemonConfig options for orchestrator (e.g. network) and ExApps (nextcloud_url, host, etc.)
* ``ExAppConfig`` - similar to Nextcloud `app_config`, but for ExApps configuration
* ``ExAppPreferences`` - similar to Nextcloud `app_preferences`, user-specific settings for ExApps
* ``AppEcosystemAuth`` - AppEcosystemV2 authentication
* ``ExAppScope`` - granted to ExApp scope group of access to API routes
* ``ExAppApiScope`` - pre-defined scope group of access to list of API routes
* ``FileActionsMenu`` - entry in files actions menu (context menu)
