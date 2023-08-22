import pytest

from nc_py_api import Nextcloud, NextcloudApp, NextcloudException


if __name__ == "__main__":
    nc_client = Nextcloud(nc_auth_user="admin", nc_auth_pass="admin")
    assert nc_client.apps.ex_app_is_disabled("nc_py_api") is False
    nc_client.users.create("second_admin", password="2Very3Strong4", groups=["admin"])

    nc_application = NextcloudApp(user="admin")
    assert nc_application.users.get_details()  # OCS call works
    assert not nc_application.notifications.get_all()  # there are no notifications
    nc_application._session.adapter.headers.update({"EX-APP-VERSION": "99.0.0"})  # change ExApp version
    with pytest.raises(NextcloudException) as exc_info:
        nc_application.users.get_details()  # this call should be rejected by AppEcosystem
    assert exc_info.value.status_code == 401

    assert nc_client.apps.ex_app_is_disabled("nc_py_api") is True
    notifications = nc_client.notifications.get_all()
    notification = [i for i in notifications if i.object_type == "ex_app_update"]
    assert len(notification) == 1  # only one notification for each admin
    nc_client = Nextcloud(nc_auth_user="second_admin", nc_auth_pass="2Very3Strong4")
    notifications = nc_client.notifications.get_all()
    notification = [i for i in notifications if i.object_type == "ex_app_update"]
    assert len(notification) == 1  # only one notification for each admin
