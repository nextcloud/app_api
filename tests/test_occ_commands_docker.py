#
# SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
from subprocess import run, DEVNULL, PIPE


SKELETON_XML_URL = (
    "https://raw.githubusercontent.com/nextcloud/app-skeleton-python/main/appinfo/info.xml"
)
# HaRP and nginx in the host network, as the "Create HaRP container" step of tests-deploy.yml starts them
DAEMON_NAME = "harp_occ_test"
HARP_HOST = "127.0.0.1:8780"
HARP_FRP_ADDRESS = "127.0.0.1:8782"
HARP_SHARED_KEY = "some_very_secure_password"
NEXTCLOUD_URL = "http://127.0.0.1"


def register_daemon(nextcloud_url: str):
    run(
        [
            "php", "occ", "app_api:daemon:register", DAEMON_NAME, "HaRP", "docker-install", "http", HARP_HOST,
            nextcloud_url, "--net", "host", "--harp", "--harp_frp_address", HARP_FRP_ADDRESS,
            "--harp_shared_key", HARP_SHARED_KEY,
        ],
        stderr=DEVNULL,
        stdout=DEVNULL,
        check=True,
    )


def unregister_daemon():
    run(
        f"php occ app_api:daemon:unregister {DAEMON_NAME}".split(),
        stderr=DEVNULL,
        stdout=DEVNULL,
        check=True,
    )


def deploy_register():
    run(
        f"php occ app_api:app:register app-skeleton-python {DAEMON_NAME} --info-xml {SKELETON_XML_URL}".split(),
        stderr=DEVNULL,
        stdout=DEVNULL,
        check=True,
    )


def daemon_list() -> str:
    r = run("php occ --no-warnings app_api:daemon:list".split(), stdout=PIPE, stderr=PIPE, check=True)
    assert not r.stderr.decode("UTF-8")
    return r.stdout.decode("UTF-8")


if __name__ == "__main__":
    # docker-install without HaRP (Docker Socket Proxy or the Docker socket) is refused
    for direct_host in ("nextcloud-appapi-dsp:2375", "/var/run/docker.sock"):
        r = run(
            [
                "php", "occ", "--no-warnings", "app_api:daemon:register", "direct_docker", "Docker",
                "docker-install", "http", direct_host, NEXTCLOUD_URL,
            ],
            stdout=PIPE,
            stderr=PIPE,
        )
        assert r.returncode == 1, f"docker-install without --harp was accepted for {direct_host}: {r.stdout.decode()}"
        assert "requires --harp" in r.stdout.decode("UTF-8")
        assert daemon_list().find("direct_docker") == -1
    # nextcloud_url should be without slash at the end
    register_daemon(NEXTCLOUD_URL + "/")
    r_output = daemon_list()
    assert r_output.find(NEXTCLOUD_URL + "/") == -1
    assert r_output.find(NEXTCLOUD_URL) != -1
    unregister_daemon()
    # nextcloud_url should be without slash at the end but with "index.php"
    register_daemon(NEXTCLOUD_URL + "/index.php/")
    r_output = daemon_list()
    assert r_output.find(NEXTCLOUD_URL + "/index.php/") == -1
    assert r_output.find(NEXTCLOUD_URL + "/index.php") != -1
    # silent should not fail, as there are not such ExApp
    r = run("php occ --no-warnings app_api:app:unregister app-skeleton-python --silent".split(), stdout=PIPE, stderr=PIPE, check=True)
    assert not r.stderr.decode("UTF-8")
    r_output = r.stdout.decode("UTF-8")
    assert not r_output, f"Output should be empty: {r_output}"
    # without "--silent" it should fail, as there are not such ExApp
    r = run("php occ --no-warnings app_api:app:unregister app-skeleton-python".split(), stdout=PIPE)
    assert r.returncode
    assert r.stdout.decode("UTF-8"), "Output should be non empty"
    # testing if volume is kept by default
    deploy_register()
    r = run("php occ --no-warnings app_api:app:unregister app-skeleton-python".split(), stdout=PIPE, check=True)
    assert r.stdout.decode("UTF-8"), "Output should be non empty"
    run("docker volume inspect nc_app_app-skeleton-python_data".split(), check=True)
    # test if volume will be removed with "--rm-data"
    deploy_register()
    run("php occ --no-warnings app_api:app:unregister app-skeleton-python --rm-data".split(), check=True)
    r = run("docker volume inspect nc_app_app-skeleton-python_data".split())
    assert r.returncode
    # test "--force" option
    deploy_register()
    run("docker container rm --force nc_app_app-skeleton-python".split(), check=True)
    r = run("php occ --no-warnings app_api:app:unregister app-skeleton-python".split())
    assert r.returncode
    r = run("php occ --no-warnings app_api:app:unregister app-skeleton-python --silent".split())
    assert r.returncode
    run("php occ --no-warnings app_api:app:unregister app-skeleton-python --force".split(), check=True)
    unregister_daemon()
