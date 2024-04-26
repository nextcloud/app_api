.. _test_deploy:

Test Deploy Daemon
------------------

You can test each Daemon configuration deployment from the AppAPI Admin settings.

.. image:: ./img/test_deploy.png


Status checks
^^^^^^^^^^^^^

Deploy test installs a `test-deploy <https://github.com/cloud-py-api/test-deploy>`_ ExApp
to verify each step of the deployment process, including hardware support check -
for each compute device there is a separate Docker image.

.. note::
    Test Deploy ExApp container is not removed after the test as it's needed for the logs and status checks.
    You can remove it after test from the External Apps page.
    The Docker images are also not removed from the Daemon, you can cleanup unused images with the ``docker image prune`` command.

.. image:: ./img/test_deploy_modal_4.png


Register
********

Register step is the first step, checks if the ExApp is registered in the Nextcloud.

Image pull
**********

Image pull step downloads the ExApp Docker image.

Possible errors:

- Image not found
- Image pull failed (e.g. due to network issues)
- Image pull timeout

Container started
*****************

Container started step verifies if the ExApp container is created and started successfully.

Possible errors:

- Container failed to start with GPUs support

    - For NVIDIA refer to the `NVIDIA Docker configuration docs <https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/latest/install-guide.html>`_.
    - For AMD refer to the `ROCm Docker configuration docs <https://rocm.docs.amd.com/projects/install-on-linux/en/latest/how-to/docker.html>`_.


Heartbeat
*********

Heartbeat step checks if the container healthcheck finished and healthy.
ExApp might have additional pre-configuration logic during this step.

Possible errors:

- ExApp failed to start a webserver e.g. if it's already in use (should be visible in the container logs)


Init
****

Init step checks if the ExApp is initialized and ready to use.
During init step ExApp can perform download of extra stuff required for it.

Possible errors:

- Initialization failed (e.g. due to network issues or timeout)


Enabled
*******

Enabled step checks if the ExApp is enabled and ready to use.
During this step ExApp registers all the required and available APIs of the Nextcloud AppFramework.

Possible errors:

- ExApp didn't respond to the enable request
- ExApp failed to enable due to failure of registering AppAPI Nextcloud AppFramework APIs (should be visible both in the container logs and in the Nextcloud logs if there are any errors)


Download logs
^^^^^^^^^^^^^

You can download the logs of the last test deploy attempt container.

.. note::
    Downloading Docker container works only for containers with the json-file or journald logging driver.
