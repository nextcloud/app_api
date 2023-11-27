from typing import Annotated

from fastapi import Depends, FastAPI
from fastapi.responses import JSONResponse

from nc_py_api import NextcloudApp, ex_app


APP = FastAPI()


@APP.put("/enabled")
async def enabled_callback(
    enabled: bool,
    nc: Annotated[NextcloudApp, Depends(ex_app.nc_app)],
):
    if enabled:
        nc.log(ex_app.LogLvl.WARNING, f"Hello from {nc.app_cfg.app_name} :)")
    else:
        nc.log(ex_app.LogLvl.WARNING, f"Bye bye from {nc.app_cfg.app_name} :(")
    return JSONResponse(content={"error": ""}, status_code=200)


@APP.get("/heartbeat")
async def heartbeat_callback():
    return JSONResponse(content={"status": "ok"}, status_code=200)


if __name__ == "__main__":
    ex_app.run_app("install_no_init:APP", log_level="trace")
