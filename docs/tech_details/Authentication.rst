Authentication
==============

AppAPI introduces a distinct method of authentication for external apps.
This authentication relies on a shared secret between Nextcloud and the external app

Authentication flow
^^^^^^^^^^^^^^^^^^^

1. ExApp sends a request to Nextcloud
2. Nextcloud passes request to AppAPI
3. AppApi validates request (see `authentication flow in details`_)
4. Request is accepted/rejected

.. mermaid::

	sequenceDiagram
    	participant ExApp
    	box Nextcloud
			participant Nextcloud
			participant AppAPI
		end
    	ExApp->>+Nextcloud: Request to API
    	Nextcloud->>+AppAPI: Validate request
    	AppAPI-->>-Nextcloud: Request accepted/rejected
    	Nextcloud-->>-ExApp: Response (200/401)


Authentication headers
^^^^^^^^^^^^^^^^^^^^^^

Each ExApp request to secured API with AppAPIAuth must contain the following headers:

1. ``AA-VERSION`` - minimal version of the AppAPI
2. ``EX-APP-ID``- ID of the ExApp
3. ``EX-APP-VERSION`` - version of the ExApp
4. ``AUTHORIZATION-APP-API`` - base64 encoded ``userid:secret``

Authentication flow in details
******************************

.. mermaid::
	:zoom:

	sequenceDiagram
		autonumber
		participant ExApp
		box Nextcloud
			participant Nextcloud
			participant AppApi
		end
		ExApp->>+Nextcloud: Request to API
		Nextcloud->>Nextcloud: Check if AUTHORIZATION-APP-API header exists
		Nextcloud-->>ExApp: Reject if AUTHORIZATION-APP-API header not exists
		Nextcloud->>Nextcloud: Check if AppApi app is enabled
		Nextcloud-->>ExApp: Reject if AppApi is not exists or disabled
		Nextcloud->>+AppApi: Validate request
		AppApi-->>AppApi: Check if ExApp exists and enabled
		AppApi-->>Nextcloud: Reject if ExApp not exists or disabled
		AppApi-->>AppApi: Check if ExApp version changed
		AppApi-->>Nextcloud: Disable ExApp and notify admins if version changed
		AppApi-->>AppApi: Validate shared secret from AUTHORIZATION-APP-API
		AppApi-->>Nextcloud: Reject if secret does not match
		AppApi-->>AppApi: Check API scope
		AppApi-->>Nextcloud: Reject if API scope not match
		AppApi-->>AppApi: Check if user interacted with ExApp
		AppApi-->>Nextcloud: Reject if user has not interacted with ExApp (attempt to bypass user)
		AppApi-->>AppApi: Check if user is not empty and active
		AppApi-->>Nextcloud: Set active user
		AppApi->>-Nextcloud: Request accepted/rejected
		Nextcloud->>-ExApp: Response (200/401)


AppAPIAuth
^^^^^^^^^^

AppApi provides ``AppAPIAuth`` attribute with middleware to validate requests from ExApps.
In your API controllers you can use it as an PHP attribute.
