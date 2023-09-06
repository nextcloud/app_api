.. _api_scopes:

Api Scopes
==========

One of the primary advantages inherent to the AppAPI is its clear-cut categorization of the required API groups
essential for the application's operation.
Equally significant is the provision of optional API groups, enabling the application to execute extra functions that
enhance its capabilities, though not obligatory for its core functionality. An example of such a function is sending notifications.

During installation, the Nextcloud administrator retains the authority to regulate the application's access
to any of these optional API function groups.

Both ``optional`` and ``required`` API groups employed by the application are explicitly outlined within
the **info.xml** file. Upon the release of a new application version, adjustments to the API groups can be made.
This grants the flexibility to introduce new API groups or remove outdated ones.

For instance, if the initial version of your application did not necessitate notifications,
but a subsequent version does, you can effortlessly specify the new API groups in the **info.xml** file.

The following API groups are currently supported:

* ``2``   SYSTEM
* ``10``  FILES
* ``11``  FILES_SHARING
* ``30``  USER_INFO
* ``31``  USER_STATUS
* ``32``  NOTIFICATIONS
* ``33``  WEATHER_STATUS
* ``50``  TALK
* ``60``  TALK_BOT
* ``110`` ACTIVITIES

These groups are identified using names. As time progresses,
the list will steadily expand, comprehensively encompassing all potential APIs provided by Nextcloud.

There is a CLI command to list registered scopes: ``occ app_api:scopes:list``.
