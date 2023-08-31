FROM python:3.11-bookworm

COPY requirements.txt /
ADD /src/ /app/

RUN \
    apt-get update && \
    apt-get install -y \
    ffmpeg libsm6 libxext6 gifsicle

RUN \
  python3 -m pip install -r requirements.txt && rm -rf ~/.cache && rm requirements.txt

WORKDIR /app
ENTRYPOINT ["python3", "main.py"]
