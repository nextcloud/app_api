import sys
import pytest
import nc_py_api


if __name__ == "__main__":
    nc = nc_py_api.NextcloudApp(user="admin")
    assert nc.capabilities
    if int(sys.argv[1]) == 0:
        assert nc.ocs("GET", "/ocs/v2.php/core/whatsnew")
    else:
        with pytest.raises(nc_py_api.NextcloudException) as e:
            assert e.value.status_code == 401
    assert nc.users_list()
    exit(0)
