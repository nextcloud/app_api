"""Simplest example of files_dropdown_menu, notification without using framework."""

import asyncio
import datetime
import hashlib
import hmac
import json
import os
import tempfile
import typing
from random import choice
from string import ascii_lowercase, ascii_uppercase, digits
from urllib.parse import quote, urlencode

import cv2
import httpx
import imageio
import numpy
import uvicorn
import xxhash
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
    letters = ascii_lowercase + ascii_uppercase + digits
    return "".join(choice(letters) for _ in range(size))


def sign_request(method: str, url_params: str, headers: dict, data: typing.Optional[bytes], user="") -> None:
    data_hash = xxhash.xxh64()
    if data and method != "GET":
        data_hash.update(data)

    sign_headers = {
        "AE-VERSION": "1.0.0",
        "EX-APP-ID": os.environ["APP_ID"],
        "EX-APP-VERSION": os.environ["APP_VERSION"],
        "NC-USER-ID": user,
        "AE-DATA-HASH": data_hash.hexdigest(),
        "AE-SIGN-TIME": str(int(datetime.datetime.now(datetime.timezone.utc).timestamp())),
    }
    if not sign_headers["NC-USER-ID"]:
        sign_headers.pop("NC-USER-ID")

    request_to_sign = (
        method.encode("UTF-8")
        + url_params.encode("UTF-8")
        + json.dumps(sign_headers, separators=(",", ":")).encode("UTF-8")
    )
    hmac_sign = hmac.new(os.environ["APP_SECRET"].encode("UTF-8"), request_to_sign, digestmod=hashlib.sha256)
    headers["AE-SIGNATURE"] = hmac_sign.hexdigest()
    headers.update(sign_headers)
    if "NC-USER-ID" in sign_headers:
        headers["NC-USER-ID"] = sign_headers["NC-USER-ID"]


def sign_check(request: Request) -> None:
    current_time = int(datetime.datetime.now(datetime.timezone.utc).timestamp())
    headers = {
        "AE-VERSION": request.headers["AE-VERSION"],
        "EX-APP-ID": request.headers["EX-APP-ID"],
        "EX-APP-VERSION": request.headers["EX-APP-VERSION"],
        "NC-USER-ID": request.headers.get("NC-USER-ID", ""),
        "AE-DATA-HASH": request.headers["AE-DATA-HASH"],
        "AE-SIGN-TIME": request.headers["AE-SIGN-TIME"],
    }
    if not headers["NC-USER-ID"]:
        headers.pop("NC-USER-ID")

    if headers["EX-APP-VERSION"] != os.environ["APP_VERSION"]:
        raise ValueError(f"Invalid EX-APP-VERSION:{headers['EX-APP-VERSION']} <=> {os.environ['APP_VERSION']}")

    request_time = int(headers["AE-SIGN-TIME"])
    if request_time < current_time - 5 * 60 or request_time > current_time + 5 * 60:
        raise ValueError(f"Invalid AE-SIGN-TIME:{request_time} <=> {current_time}")

    query_params = f"?{request.url.components.query}" if request.url.components.query else ""
    request_to_sign = (
        request.method.upper() + request.url.components.path + query_params + json.dumps(headers, separators=(",", ":"))
    )
    hmac_sign = hmac.new(
        os.environ["APP_SECRET"].encode("UTF-8"), request_to_sign.encode("UTF-8"), digestmod=hashlib.sha256
    ).hexdigest()
    if hmac_sign != request.headers["AE-SIGNATURE"]:
        raise ValueError(f"Invalid AE-SIGNATURE:{hmac_sign} != {request.headers['AE-SIGNATURE']}")

    data_hash = xxhash.xxh64()
    data = asyncio.run(request.body())
    if data:
        data_hash.update(data)
    if data_hash.hexdigest() != headers["AE-DATA-HASH"]:
        raise ValueError(f"Invalid AE-DATA-HASH:{data_hash.hexdigest()} !={headers['AE-DATA-HASH']}")


def make_ocs_call(
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
    path_params = f"{quote(path)}?{urlencode(params, True)}"
    sign_request(method, path_params, headers, data_bytes, kwargs.get("user", ""))
    return httpx.request(
        method,
        url=os.environ["NEXTCLOUD_URL"] + path,
        params=params,
        content=data_bytes,
        headers=headers,
        cookies={"XDEBUG_SESSION": "PHPSTORM"},
    )


def nc_log(log_lvl: int, content: str):
    make_ocs_call(
        "POST", "/ocs/v1.php/apps/app_ecosystem_v2/api/v1/log", json_data={"level": log_lvl, "message": content}
    )


def create_notification(user_id: str, subject: str, message: str):
    params: dict = {
        "params": {
            "object": "app_ecosystem_v2",
            "object_id": random_string(56),
            "subject_type": "app_ecosystem_v2_ex_app",
            "subject_params": {
                "rich_subject": subject,
                "rich_subject_params": {},
                "rich_message": message,
                "rich_message_params": {},
            },
        }
    }
    make_ocs_call(
        method="POST", path=f"/ocs/v1.php/apps/app_ecosystem_v2/api/v1/notification", json_data=params, user=user_id
    )


def convert_video_to_gif(input_file: UiActionFileInfo, user_id: str):
    dav_get_file_path = f"/files/{user_id}/{input_file.directory}/{input_file.name}"
    dav_save_file_path = os.path.splitext(dav_get_file_path)[0] + ".gif"
    nc_log(2, f"Processing:{input_file.user_path} -> {dav_save_file_path}")
    try:
        with tempfile.NamedTemporaryFile(mode="w+b") as tmp_in:
            # nc.files.download2stream(input_file, tmp_in)  # to-do
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
                # nc.files.upload_stream(save_path, tmp_out)  # to-do
                nc_log(2, "Result uploaded")
                create_notification(
                    user_id,
                    f"{input_file.name} finished!",
                    f"{os.path.splitext(input_file.name)[0] + '.gif'} is waiting for you!",
                )
    except Exception as e:
        nc_log(3, str(e))
        create_notification(user_id, "Error occurred", "Error information was written to log file")


@APP.post("/video_to_gif")
async def video_to_gif(
    file: UiFileActionHandlerInfo,
    request: Request,
    background_tasks: BackgroundTasks,
):
    try:
        sign_check(request)
    except ValueError:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED)
    background_tasks.add_task(convert_video_to_gif, file.actionFile, request.headers["NC-USER-ID"])
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
            a = make_ocs_call(
                "POST",
                "/ocs/v1.php/apps/app_ecosystem_v2/api/v1/files/actions/menu",
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
            a = make_ocs_call(
                "DELETE",
                "/ocs/v1.php/apps/app_ecosystem_v2/api/v1/files/actions/menu",
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
