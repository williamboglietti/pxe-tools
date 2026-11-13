FROM debian:13-slim@sha256:a72b4433de1cf7d6bae0c4cee74d0547f58601a643b3375f070c51600d63b6b2

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
       ca-certificates curl gnupg gpgv php-cli php-curl php-mysql php-sqlite3 \
       python3 python3-jinja2 python3-nacl sqlite3 \
    && rm -rf /var/lib/apt/lists/*
