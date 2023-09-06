Managing External Applications
==============================

There are two ways to manage ExApps:

1. Using OCC CLI tool
2. Using the ExApp Management UI (in progress)


OCC CLI
^^^^^^^

There are several commands to work with ExApps:

1. Deploy
2. Register
3. Unregister
4. Update
5. Enable
6. Disable
7. List ExApps
8. List ExApp users
9. List ExApp scopes

Deploy
------

Command: ``app_api:app:deploy [--info-xml INFO-XML] [-e|--env ENV] [--] <appid> <daemon-config-name>``

The deploy command is the first ExApp installation step.

Arguments
*********

    * ``appid`` - unique name of the ExApp (e.g. ``app_python_skeleton``, must be the same as in ``info.xml``)
    * ``daemon-config-name`` - unique name of the daemon (e.g. ``docker_local_sock``)

Options
*******

    * ``--info-xml INFO-XML`` **[required]** - path to info.xml file (url or local absolute path)
    * ``-e|--env ENV`` *[optional]* - additional environment variables (e.g. ``-e "MY_VAR=123" -e "MY_VAR2=456"``)

Register
--------

Command: ``app_api:app:register [-e|--enabled] [--force-scopes] [--info-xml INFO-XML] [--json-info JSON-INFO] [--] <appid> <daemon-config-name>``

The register command is the second ExApp installation step.

Arguments
*********

    * ``appid`` - unique name of the ExApp (e.g. ``app_python_skeleton``, must be the same as in deployed container)
    * ``daemon-config-name`` - unique name of the daemon (e.g. ``docker_local_sock``)

Options
*******

    * ``-e|--enabled`` *[optional]* - enable ExApp after registration
    * ``--force-scopes`` *[optional]* - force scopes approval
    * ``--json-info JSON-INFO`` **[optional]** - ExApp deploy JSON info (json string)
    * ``--info-xml INFO-XML`` **[required]** - path to info.xml file (url or local absolute path)


Unregister
----------

Command: ``app_api:app:unregister [--silent] [--rm-container] [--rm-data] [--] <appid>``

To remove an ExApp you can use the unregister command.
There are additional options to remove the ExApp container and persistent storage (data volume).

Arguments
*********

    * ``appid`` - unique name of the ExApp (e.g. ``app_python_skeleton``, must be the same as in deployed container)

Options
*******

    * ``--silent`` *[optional]* - do not disable ExApp before unregister
    * ``--rm-container`` *[optional]* - remove ExApp container
    * ``--rm-data`` *[optional]* - remove ExApp persistent storage (data volume)

Update
------

Command: ``app_api:app:update [--info-xml INFO-XML] [--force-update] [--force-scopes] [-e|--enabled] [--] <appid>``

ExApp will be updated if there is a new version available.

Arguments
*********

    * ``appid`` - unique name of the ExApp (e.g. ``app_python_skeleton``, must be the same as in deployed container)

Options
*******

    * ``--info-xml INFO-XML`` **[optional]** - path to info.xml file (url or local absolute path)
    * ``--force-update`` *[optional]* - force ExApp update (do not prompt for confirmation)
    * ``--force-scopes`` *[optional]* - force scopes approval (accept all scopes)
    * ``-e|--enabled`` *[optional]* - enable ExApp after update

Enable
------

Command: ``app_api:app:enable <appid>``

Disable
-------

Command: ``app_api:app:disable <appid>``

List ExApps
-----------

Command: ``app_api:app:list``

ListExApps command will show all ExApps:

.. code-block::

    ExApps:
    appid (Display Name): version [enabled/disabled]
    to_gif (ToGif): 1.0.0 [enabled]
    upscaler_demo (Upscaler Demo): 1.0.0 [enabled]

List ExApp users
----------------

Command: ``app_api:app:users:list <appid>``

System user
***********

System user (``[system user]``) in the list means that this ExApp was setup as a system ExApp.

List ExApp Scopes
-----------------

List accepted scopes (see :ref:`api_scopes`) for ExApp.

Command: ``app_api:app:scopes:list <appid>``

Using the ExApp Management UI
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

Currently the ExApp Management UI is in progress.
There will be the same functionality as in the CLI but in a more user friendly and easy way.
