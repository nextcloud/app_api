Authentication
==============

AppEcosystemV2 introduces a distinct method of authentication for external apps.

This authentication relies on a shared secret between Nextcloud and the external app, which generates a unique signature for each request.

Authentication flow
^^^^^^^^^^^^^^^^^^^

1. ExApp sends a request to Nextcloud
2. Nextcloud passes request to AppEcosystemV2
3. AppEcosystemV2 validates request (see `authentication flow in details`_)
4. Request is accepted/rejected

.. mermaid::

	sequenceDiagram
    	participant ExApp
    	box Nextcloud
			participant Nextcloud
			participant AppEcosystemV2
		end
    	ExApp->>+Nextcloud: Request to API
    	Nextcloud->>+AppEcosystemV2: Validate request
    	AppEcosystemV2-->>-Nextcloud: Request accepted/rejected
    	Nextcloud-->>-ExApp: Response (200/401)


Authentication headers
^^^^^^^^^^^^^^^^^^^^^^

Each ExApp request to secured API with AppEcosystemAuth must contain the following headers (order is important):

1. ``AE-VERSION`` - minimal version of the AppEcosystemV2
2. ``EX-APP-ID``- ID of the ExApp
3. ``EX-APP-VERSION`` - version of the ExApp
4. ``NC-USER-ID`` - the user under which the request is made, can be empty in case of system apps (more details in [scopes](#AppEcosystemV2-scopes) section)
5. ``AE-DATA-HASH`` - hash of the request body (see details in `ae_signature`_ section)
6. ``AE-SIGN-TIME`` - Unix timestamp of the request
7. ``AE-SIGNATURE`` - signature of the request (see details `ae_signature`_ section)


AE_SIGNATURE
************

AppEcosystemV2 signature (AE-SIGNATURE) is a HMAC-SHA256 hash of the request signed with the shared secret.

The signature is calculated from the following data:

* method
* uri (with urlencoded query parameters)
* headers (``AE-VERSION``, ``EX-APP-ID``, ``EX-APP-VERSION``, ``NC-USER-ID``, ``AE-DATA-HASH``, ``AE-SIGN-TIME``)
* xxh64 hash from request body (post data, json, files, etc.)

AE_DATA_HASH
************

``AE-DATA-HASH`` header must contain a xxh64 hash of the request body.
It's calculated even if the request body is empty (e.g. empty hash: ``ef46db3751d8e999``).


Authentication flow in details
******************************

.. mermaid::
	:zoom:

	sequenceDiagram
		autonumber
		participant ExApp
		box Nextcloud
			participant Nextcloud
			participant AppEcosystemV2
		end
		ExApp->>+Nextcloud: Request to API
		Nextcloud->>Nextcloud: Check if AE-SIGNATURE header exists
		Nextcloud-->>ExApp: Reject if AE-SIGNATURE header not exists
		Nextcloud->>Nextcloud: Check if AppEcosystemV2 enabled
		Nextcloud-->>ExApp: Reject if AppEcosystemV2 not enabled
		Nextcloud->>+AppEcosystemV2: Validate request
		AppEcosystemV2-->>AppEcosystemV2: Check if ExApp exists and enabled
		AppEcosystemV2-->>Nextcloud: Reject if ExApp not exists or disabled
		AppEcosystemV2-->>AppEcosystemV2: Check if ExApp version changed
		AppEcosystemV2-->>AppEcosystemV2: Validate AE-SIGN-TIME
		AppEcosystemV2-->>Nextcloud: Reject if sign time diff > 5 min
		AppEcosystemV2-->>AppEcosystemV2: Generate and validate AE-SIGNATURE
		AppEcosystemV2-->>Nextcloud: Reject if signature not match
		AppEcosystemV2-->>AppEcosystemV2: Validate AE-DATA-HASH
		AppEcosystemV2-->>Nextcloud: Reject if data hash not match
		AppEcosystemV2-->>AppEcosystemV2: Check API scope
		AppEcosystemV2-->>Nextcloud: Reject if API scope not match
		AppEcosystemV2-->>AppEcosystemV2: Check if user interacted with ExApp
		AppEcosystemV2-->>Nextcloud: Reject if user has not interacted with ExApp (attempt to bypass user)
		AppEcosystemV2-->>AppEcosystemV2: Check if user is not empty and active
		AppEcosystemV2-->>Nextcloud: Set active user
		AppEcosystemV2->>-Nextcloud: Request accepted/rejected
		Nextcloud->>-ExApp: Response (200/401)


AppEcosystemAuth
^^^^^^^^^^^^^^^^

AppEcosystemV2 provides ``AppEcosystemAuth`` attribute with middleware to validate requests from ExApps.
In PHP API controllers you can use it as an attribute or annotation (for NC26).
