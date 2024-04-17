.. _occ_command:

===========
OCC Command
===========

This API allows you to register the occ (CLI) commands.
The principal is similar to the regular Nextcloud OCC command for PHP apps, that are working in context of the Nextcloud instance,
but for ExApps it is a trigger via Nextcloud OCC interface to perform some action on the External App side.


.. note::

    Passing files directly as an input argument to the occ command is not supported.

Register
^^^^^^^^

OCS endpoint: ``POST /apps/app_api/api/v1/occ_command``

Params
******

.. code-block:: json

    {
        "name": "appid:unique:command:name",
        "description": "Description of the command",
        "hidden": "true/false",
        "arguments": [
            {
                "name": "argument_name",
                "mode": "required (InputArgument::REQUIRED)/optional(InputArgument::OPTIONAL)/array(InputArgument::IS_ARRAY)",
                "description": "Description of the argument",
                "default": "default_value"
            }
        ],
        "options": [
            {
                "name": "option_name",
                "shortcut": "shortcut",
                "mode": "value_required(InputOption::VALUE_REQUIRED)/value_optional(InputOption::VALUE_OPTIONAL)/value_none(InputOption::VALUE_NONE)/array(InputOption::VALUE_IS_ARRAY)/negatable(InputOption::VALUE_NEGATABLE)",
                "description": "Description of the option",
                "default": "default_value"
            }
        ],
        "execute_handler": "handler_route"
    }

Unregister
^^^^^^^^^^

OCS endpoint: ``DELETE /apps/app_api/api/v1/occ_command``

Params
******

To unregister OCC Command, you just need to provide a command `name`:

.. code-block:: json

	{
		"name": "occ_command_name"
	}
