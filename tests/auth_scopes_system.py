import sys
import pytest
import nc_py_api

# sys.argv[1] = 0 -> System App, ALL Scope
# sys.argv[1] = 1 -> System App, No ALL Scope
# sys.argv[1] = 2 -> No System App, ALL Scope

if __name__ == "__main__":
    nc = nc_py_api.NextcloudApp(user="admin")
    assert nc.capabilities
    if int(sys.argv[1]) == 0:
        nc.ocs("GET", "/ocs/v2.php/core/whatsnew")
    else:
        with pytest.raises(nc_py_api.NextcloudException) as e:
            nc.ocs("GET", "/ocs/v2.php/core/whatsnew")
        assert e.value.status_code == 401

    if int(sys.argv[1]) == 2:
        # as NextcloudApp was initialized with `user="admin"` this will fail for non-system app.
        with pytest.raises(nc_py_api.NextcloudException) as e:
            nc.users_list()
        assert e.value.status_code == 401
    else:
        assert nc.users_list()
