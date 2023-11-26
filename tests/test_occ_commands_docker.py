from subprocess import run, DEVNULL, PIPE


SKELETON_XML_URL = (
    "https://raw.githubusercontent.com/cloud-py-api/nc_py_api/main/examples/as_app/skeleton/appinfo/info.xml"
)


def register_daemon():
    run(
        "php occ app_api:daemon:register docker_local_sock "
        "Docker docker-install unix-socket /var/run/docker.sock http://127.0.0.1:8080/index.php".split(),
        stderr=DEVNULL,
        stdout=DEVNULL,
        check=True,
    )


def deploy_register():
    run(
        f"php occ app_api:app:deploy skeleton docker_local_sock --info-xml {SKELETON_XML_URL}".split(),
        stderr=DEVNULL,
        stdout=DEVNULL,
        check=True,
    )
    run(
        f"php occ app_api:app:register skeleton docker_local_sock --info-xml {SKELETON_XML_URL}".split(),
        stderr=DEVNULL,
        stdout=DEVNULL,
        check=True,
    )


if __name__ == "__main__":
    register_daemon()
    # silent should not fail, as there are not such ExApp
    r = run("php occ app_api:app:unregister skeleton --silent".split(), stdout=PIPE, stderr=PIPE, check=True)
    assert not r.stderr.decode("UTF-8")
    assert not r.stdout.decode("UTF-8")
    # without "--silent" it should fail, as there are not such ExApp
    r = run("php occ app_api:app:unregister skeleton".split(), stdout=PIPE, stderr=PIPE)
    assert r.returncode
    assert r.stderr.decode("UTF-8")
    # testing if "--keep-data" works.
    deploy_register()
    r = run("php occ app_api:app:unregister skeleton --keep-data".split(), stdout=PIPE, stderr=PIPE, check=True)
    assert r.stdout.decode("UTF-8")
    run("docker volume inspect nc_app_skeleton_data".split(), check=True)
    # test if volume will be removed without "--keep-data"
    deploy_register()
    run("php occ app_api:app:unregister skeleton".split(), check=True)
    r = run("docker volume inspect nc_app_skeleton_data".split())
    assert r.returncode
    # test "--force" option
    deploy_register()
    run("docker container stop nc_app_skeleton".split(), check=True)
    r = run("php occ app_api:app:unregister skeleton".split())
    assert r.returncode
    run("php occ app_api:app:unregister skeleton --force".split(), check=True)
