"""Simplest example of files_dropdown_menu, notification without using the framework."""

import json
import os
import tempfile
import typing
from base64 import b64encode, b64decode
from random import choice
from string import ascii_lowercase, ascii_uppercase, digits
from urllib.parse import quote

import cv2
import httpx
import imageio
import numpy
import uvicorn
from fastapi import BackgroundTasks, FastAPI, HTTPException, Request, responses, status
from pydantic import BaseModel
from pygifsicle import optimize
from requests import Response

APP = FastAPI()


class UiActionFileInfo(BaseModel):
    fileId: int
    name: str
    directory: str
    etag: str
    mime: str
    fileType: str
    size: int
    favorite: str
    permissions: int
    mtime: int
    userId: str
    shareOwner: typing.Optional[str]
    shareOwnerId: typing.Optional[str]
    instanceId: typing.Optional[str]


class UiFileActionHandlerInfo(BaseModel):
    actionName: str
    actionHandler: str
    actionFile: UiActionFileInfo


def random_string(size: int) -> str:
    return "".join(choice(ascii_lowercase + ascii_uppercase + digits) for _ in range(size))


def sign_request(headers: dict, user="") -> None:
    headers["AUTHORIZATION-APP-API"] = b64encode(f"{user}:{os.environ['APP_SECRET']}".encode("UTF=8"))
    headers["EX-APP-ID"] = os.environ["APP_ID"]
    headers["EX-APP-VERSION"] = os.environ["APP_VERSION"]
    headers["OCS-APIRequest"] = "true"


def sign_check(request: Request) -> str:
    headers = {
        "AA-VERSION": request.headers["AA-VERSION"],
        "EX-APP-ID": request.headers["EX-APP-ID"],
        "EX-APP-VERSION": request.headers["EX-APP-VERSION"],
        "AUTHORIZATION-APP-API": request.headers.get("AUTHORIZATION-APP-API", ""),
    }
    # AA-VERSION contains AppAPI version, for now it can be only one version, so no handling of it.
    if headers["EX-APP-ID"] != os.environ["APP_ID"]:
        raise ValueError(f"Invalid EX-APP-ID:{headers['EX-APP-ID']} != {os.environ['APP_ID']}")

    if headers["EX-APP-VERSION"] != os.environ["APP_VERSION"]:
        raise ValueError(f"Invalid EX-APP-VERSION:{headers['EX-APP-VERSION']} <=> {os.environ['APP_VERSION']}")

    auth_aa = b64decode(headers.get("AUTHORIZATION-APP-API", "")).decode("UTF-8")
    username, app_secret = auth_aa.split(":", maxsplit=1)
    if app_secret != os.environ["APP_SECRET"]:
        raise ValueError(f"Invalid APP_SECRET:{app_secret} != {os.environ['APP_SECRET']}")
    return username


def ocs_call(
    method: str,
    path: str,
    params: typing.Optional[dict] = None,
    json_data: typing.Optional[typing.Union[dict, list]] = None,
    **kwargs,
):
    method = method.upper()
    if params is None:
        params = {}
    params.update({"format": "json"})
    headers = kwargs.pop("headers", {})
    data_bytes = None
    if json_data is not None:
        headers.update({"Content-Type": "application/json"})
        data_bytes = json.dumps(json_data).encode("utf-8")
    sign_request(headers, kwargs.get("user", ""))
    return httpx.request(
        method,
        url=os.environ["NEXTCLOUD_URL"] + path,
        params=params,
        content=data_bytes,
        headers=headers,
    )


def dav_call(method: str, path: str, data: typing.Optional[typing.Union[str, bytes]] = None, **kwargs):
    headers = kwargs.pop("headers", {})
    data_bytes = None
    if data is not None:
        data_bytes = data.encode("UTF-8") if isinstance(data, str) else data
    path = quote("/remote.php/dav" + path)
    sign_request(headers, kwargs.get("user", ""))
    return httpx.request(
        method,
        url=os.environ["NEXTCLOUD_URL"] + path,
        content=data_bytes,
        headers=headers,
    )


def nc_log(log_lvl: int, content: str):
    ocs_call("POST", "/ocs/v1.php/apps/app_api/api/v1/log", json_data={"level": log_lvl, "message": content})


def create_notification(user_id: str, subject: str, message: str):
    params: dict = {
        "params": {
            "object": "app_api",
            "object_id": random_string(56),
            "subject_type": "app_api_ex_app",
            "subject_params": {
                "rich_subject": subject,
                "rich_subject_params": {},
                "rich_message": message,
                "rich_message_params": {},
            },
        }
    }
    ocs_call(
        method="POST", path=f"/ocs/v1.php/apps/app_api/api/v1/notification", json_data=params, user=user_id
    )


def convert_video_to_gif(input_file: UiActionFileInfo, user_id: str):
    # save_path = path.splitext(input_file.user_path)[0] + ".gif"
    if input_file.directory == "/":
        dav_get_file_path = f"/files/{user_id}/{input_file.name}"
    else:
        dav_get_file_path = f"/files/{user_id}{input_file.directory}/{input_file.name}"
    dav_save_file_path = os.path.splitext(dav_get_file_path)[0] + ".gif"
    # ===========================================================
    nc_log(2, f"Processing:{input_file.name}")
    try:
        with tempfile.NamedTemporaryFile(mode="w+b") as tmp_in:
            # nc.files.download2stream(input_file, tmp_in)
            # to simplify an example, we download to memory as one piece, instead of implementing stream
            r = dav_call("GET", dav_get_file_path, user=user_id)
            tmp_in.write(r.content)
            # ============================================
            nc_log(2, "File downloaded")
            tmp_in.flush()
            cap = cv2.VideoCapture(tmp_in.name)
            with tempfile.NamedTemporaryFile(mode="w+b", suffix=".gif") as tmp_out:
                image_lst = []
                previous_frame = None
                skip = 0
                while True:
                    skip += 1
                    ret, frame = cap.read()
                    if frame is None:
                        break
                    if skip == 2:
                        skip = 0
                        continue
                    if previous_frame is not None:
                        diff = numpy.mean(previous_frame != frame)
                        if diff < 0.91:
                            continue
                    frame_rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
                    image_lst.append(frame_rgb)
                    previous_frame = frame
                    if len(image_lst) > 60:
                        break
                cap.release()
                imageio.mimsave(tmp_out.name, image_lst)
                optimize(tmp_out.name)
                nc_log(2, "GIF is ready")
                # nc.files.upload_stream(save_path, tmp_out)
                # to simplify an example, we upload as one piece, instead of implementing chunked stream upload
                tmp_out.seek(0)
                dav_call("PUT", dav_save_file_path, data=tmp_out.read(), user=user_id)
                # ==========================================
                nc_log(2, "Result uploaded")
                create_notification(
                    user_id,
                    f"{input_file.name} finished!",
                    f"{os.path.splitext(input_file.name)[0] + '.gif'} is waiting for you!",
                )
    except Exception as e:
        nc_log(3, "ExApp exception:" + str(e))
        create_notification(user_id, "Error occurred", "Error information was written to log file")


@APP.post("/video_to_gif")
def video_to_gif(
    file: UiFileActionHandlerInfo,
    request: Request,
    background_tasks: BackgroundTasks,
):
    try:
        user_id = sign_check(request)
    except ValueError:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED)
    background_tasks.add_task(convert_video_to_gif, file.actionFile, user_id)
    return Response()


@APP.put("/enabled")
def enabled_callback(
    enabled: bool,
    request: Request,
):
    try:
        sign_check(request)
    except ValueError:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED)
    r = ""
    try:
        print(f"enabled={enabled}")
        if enabled:
            # nc.ui.files_dropdown_menu.register("to_gif", "TO GIF", "/video_to_gif", mime="video")
            ocs_call(
                "POST",
                "/ocs/v1.php/apps/app_api/api/v1/files/actions/menu",
                json_data={
                    "fileActionMenuParams": {
                        "name": "to_gif",
                        "display_name": "TO GIF",
                        "mime": "video",
                        "permissions": 31,
                        "order": 0,
                        "icon": "",
                        "icon_class": "icon-app-ecosystem-v2",
                        "action_handler": "/video_to_gif",
                    }
                },
            )
        else:
            # nc.ui.files_dropdown_menu.unregister("to_gif")
            ocs_call(
                "DELETE",
                "/ocs/v1.php/apps/app_api/api/v1/files/actions/menu",
                json_data={"fileActionMenuName": "to_gif"},
            )
    except Exception as e:
        r = str(e)
    return responses.JSONResponse(content={"error": r}, status_code=200)


@APP.get("/heartbeat")
def heartbeat_callback():
    return responses.JSONResponse(content={"status": "ok"}, status_code=200)


if __name__ == "__main__":
    uvicorn.run(
        "main:APP", host=os.environ.get("APP_HOST", "127.0.0.1"), port=int(os.environ["APP_PORT"]), log_level="trace"
    )
