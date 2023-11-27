.. _app_installation_flow:

App Installation Flow
=====================

Heartbeat
---------

The first thing AppAPI does is deploy of the application.

In the case of ``Docker``, this is:

#. 1. performing an image pull
#. 2. creating container from the docker image
#. 3. waiting until the “/heartbeat” endpoint becomes available with a ``GET`` request.

The application, in response to the request "/heartbeat", should return json: ``{"status": "ok"}``.

.. note:: The request to ``/heartbeat`` endpoint is made without AppAPI authentication.

Init
----

.. note:: Starting from this point, all requests made by AppAPI contains :ref:`auth-headers`.

After application is ready, which is determined by previous step,
AppAPI sends ``POST`` request to the ``/init`` application endpoint.

*If the application does not need to carry out long initialization, it has an option to not implement "/init" endpoint, so
AppAPI will get 404 or 501 error on it's request, and consider that initialization is done and this section can be skipped.*

In case you want to implement "/init" endpoint, your application should:

1. In "/init" handler: Response with empty JSON on AppAPI call.
2. In background job: Send an ``OCS request`` to ``/ocs/v1.php/apps/app_api/apps/status/$APP_ID`` with the progress value.

Possible values for **progress** are integers from 1 to 100;
after receiving the value 100, the **application is considered initialized and ready to work**.

If at the initialization stage the application has a critical error due to which its further operation is impossible,

``"error": "some error"``

should be added to the ``OCS request`` for setting progress,
with a short explanation at what stage this error occurred.

Example of request payload with error will look like this::

	{"progress": 67, "error": "connection error to huggingface."}

Enabled
-------

After receiving **progress: 100**, AppAPI enables the application.

It is done, by calling ``/enabled`` application endpoint with the ``PUT`` request.

Payload of the request made by AppAPI to the application contains ``enabled`` value, which is ``True`` for enabling.

.. note:: ``/enabled`` endpoint shares both **enabling** and **disabling**,
	so app should determine what is going on using the ``enabled`` input parameter of the request.

Inside ``/enabled`` handler application should register all actions related to the Nextcloud, like UI and all other stuff.

Response for this request should contain::

	{"error": ""}

for success and if some error occur during **enabling**, it should be present and not be empty::

	{"error": "i cant handle enabling"}

This is all three steps involved in the applications installation flow.
