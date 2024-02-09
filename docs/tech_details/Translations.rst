Translations
============

ExApps translations work in the `same way as for PHP apps <https://docs.nextcloud.com/server/latest/developer_manual/basics/front-end/l10n.html>`_ with a few adjustments.

You just have to provide a ``l10n/<lang>.js`` (for front-end) and ``l10n/<lang>.json`` (for back-end) files for your app.

Front-end
*********

For the front-end part AppAPI will inject the current user's locale ``l10n/<lang>.js`` script, so that access to translated strings kept the same as was before in PHP apps.

.. note::

	ExApp l10n files are included only on their UI pages (Top Menu), Files and Settings.

Back-end
********

For the back-end part of ExApp which can be written in different programming languages is **up to the developer to decide** how to handle and translations files.
The only requirement as mentioned above - is to provide ``l10n/<lang>.json`` file with translations.

Manual install
**************

For ``manual-install`` type administrator will have to manually extract to the server's writable apps directory ``l10n`` folder of ExApp
(e.g. ``/path/to/apps-writable/<appid>/l10n/*.(js|json)``).
This will allow server to access ExApp's strings with translations.

.. note::

	Only ``l10n`` folder must be present on the server side, ``appinfo/info.xml`` could lead to be misdetected by server as PHP app folder.


Docker install
**************

For ``docker-install`` type AppAPI will extract ``l10n`` folder to the server automatically during installation from ExApp release archive.


Translation tool
****************

TODO: Write notes about changes in ``nextcloud/docker-ci`` translationtool to extract translation strings and convert to NC format (js,json) - needs adjustments to the source code supported extensions.
TODO: Also write about the changes needed for automated translation updates in CI/CD pipeline to include source *.mo, *.po files in repository to be handled differently by different programming languages.
