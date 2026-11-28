#!/usr/bin/env bash
# SPDX-License-Identifier: LGPL-3.0-only
# Run on a disposable Linux runner with Docker and no host PXE network.
set -euo pipefail

variant="${1:?apt or docker}"
container="pxe-rust-fresh-$variant-$$"
server_name=pxe-console-fixture
search_domain=lan.example.test
cleanup() {
    if docker inspect "$container" >/dev/null 2>&1; then
        if [ "$variant" = apt ]; then
            docker exec "$container" journalctl -u pxe-tools-pxe-http.service \
                -u pxe-tools-worker.service -u pxe-tools-apt-update.service --no-pager -n 60 || true
        else
            docker logs --tail 40 "$container" || true
        fi
        docker rm -fv "$container" >/dev/null
    fi
    if [ "$variant" = docker ] && docker network inspect "$container" >/dev/null 2>&1; then
        docker network rm "$container" >/dev/null
    fi
}
trap cleanup EXIT

case "$variant" in
    apt)
        docker run -d --name "$container" --privileged --cgroupns private \
            --hostname "$server_name" --dns-search "$search_domain" \
            --tmpfs /run --tmpfs /run/lock --tmpfs /tmp \
            --mount "type=bind,src=${PXE_GUIDED_DEB:?},dst=/var/tmp/pxe-tools.deb,readonly" \
            "${PXE_FRESH_APT_IMAGE:?}" /lib/systemd/systemd --unit=basic.target >/dev/null
        for _ in {1..45}; do
            if docker exec "$container" systemctl list-units --no-legend >/dev/null 2>&1; then break; fi
            sleep 1
        done
        docker exec -i -e DEBIAN_FRONTEND=noninteractive "$container" bash -es -- "${PXE_TEST_APT_VERSION:-0.1.0~guided1}" <<'APT_GUEST'
            apt-get update -qq
            apt-get install -y --no-install-recommends /var/tmp/pxe-tools.deb
            test "$(dpkg-query -W -f='${Version}' pxe-tools)" = "$1"
            systemctl is-active --quiet pxe-tools-admin.target
            systemctl is-active --quiet pxe-tools-worker.service
            if systemctl is-active --quiet pxe-tools-dnsmasq.service; then exit 1; fi
            # With setup still pending, dnsmasq renders an inert configuration.
            # Starting the event watcher exercises its real systemd dependencies.
            systemctl start pxe-tools-dnsmasq-tail.service
            systemctl is-active --quiet pxe-tools-dnsmasq-tail.service
            systemctl is-active --quiet pxe-tools-dnsmasq.service
            test -z "$(ss -Hlun '( sport = :67 or sport = :4011 )')"
            for unit in pxe-tools-php.service pxe-tools-nginx.service; do
                test "$(systemctl show --property=LoadState --value "$unit")" = not-found
                if systemctl is-failed --quiet "$unit"; then exit 1; fi
            done
            test -z "$(find /usr/lib/pxe-tools /opt/pxe-tools -type f \( -name '*.php' -o -name '*.php.j2' \) -print -quit)"
            test ! -e /usr/lib/pxe-tools/app/worker_legacy.py
            test ! -d /opt/pxe-tools/nginx
            test ! -d /run/php
            test -x /opt/pxe-tools/init/sqlite-init.sh
            test ! -e /opt/pxe-tools/init/php-init.sh
            test ! -e /opt/pxe-tools/init/render-legacy-boot-templates.sh
            test ! -e /opt/pxe-tools/init/render-nginx-conf.sh
            test ! -e /usr/lib/pxe-tools/native/reconcile-stock.py
            python3 - <<'PYMODULE'
import runpy
module = runpy.run_path('/usr/lib/pxe-tools/native/reconcile-nginx.py')
assert not hasattr(module['Reconciler'], 'reconcile')
PYMODULE
            systemctl stop pxe-tools-dnsmasq-tail.service pxe-tools-dnsmasq.service
APT_GUEST
        ;;
    docker)
        docker network create --internal "$container" >/dev/null
        docker run -d --name "$container" --network "$container" --cap-add NET_ADMIN \
            --hostname "$server_name" --dns-search "$search_domain" \
            "${PXE_RUST_GUIDED_DOCKER_IMAGE:?}" >/dev/null
        docker exec "$container" bash -ec '
            test -z "$(find /app /opt/pxe-tools /etc/s6-overlay/s6-rc.d -iname "*php*" -print -quit)"
            test -z "$(find /etc/s6-overlay/s6-rc.d -iname "*nginx*" -print -quit)"
            test -x /opt/pxe-tools/init/sqlite-init.sh
            test -f /etc/s6-overlay/s6-rc.d/bootstrap-tls/dependencies.d/sqlite-init
            test ! -e /app/worker_legacy.py
            test ! -d /etc/nginx
            test ! -d /run/php
        '
        ;;
    *) exit 2 ;;
esac

for _ in {1..60}; do
    if docker exec "$container" curl -kfsS --max-time 2 https://127.0.0.1/healthz >/dev/null 2>&1; then break; fi
    sleep 1
done
docker exec "$container" bash -ec '
    test "$(curl -fsS --max-time 5 -o /dev/null -w "%{redirect_url}" http://127.0.0.1/)" = "https://127.0.0.1/admin/"
    test "$(curl -kfsS --max-time 5 -o /dev/null -w "%{redirect_url}" https://127.0.0.1/admin/)" = "https://127.0.0.1/init/welcome"
    curl -kfsS --max-time 5 https://127.0.0.1/init/welcome >/dev/null
    test -s /run/pxe-tools-rust-guided/setup-code
    for executable in php php-fpm8.4 nginx ansible; do
        if command -v "$executable"; then exit 1; fi
    done
    test -z "$(ss -Hlun "( sport = :67 or sport = :4011 )")"
'
address=$(docker inspect --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$container")
test -n "$address"
docker exec "$container" curl -fsS --max-time 5 --cacert /run/pxe-tools-rust-guided/cert.pem \
    "https://$address/init/welcome" >/dev/null
docker exec "$container" bash -ec '
    variant="$1"
    address="$2"
    dns_name="$3"
    search_domain="$4"
    origin=https://127.0.0.1
    code=$(</run/pxe-tools-rust-guided/setup-code)
    [[ "$code" =~ ^[0-9a-f]{4}(-[0-9a-f]{4}){2}$ ]]
    welcome=$(curl -kfsS --max-time 5 "$origin/init/welcome")
    [[ "$welcome" == *"Bienvenue dans PXE Tools"* && "$welcome" == *"href=\"/init/code\""* ]]
    test "$(curl -kfsS --max-time 5 -o /dev/null -w "%{redirect_url}" "$origin/init/admin")" = "$origin/init/code"
    page=$(curl -kfsS --max-time 5 "$origin/init/code")
    [[ "$page" == *"/console-assets/style.css"* && "$page" == *"id=\"copy-setup-command\""* ]]
    test "$(grep -o "name=\"code\"" <<< "$page" | wc -l)" -eq 1
    [[ "$page" != *"name=\"username\""* && "$page" != *"$code"* ]]
    css=$(curl -kfsS --max-time 5 "$origin/console-assets/style.css")
    [[ "$css" == *".setup-command-row"* ]]
    if [[ "$variant" == apt ]]; then
        test "$(dpkg-query -S /usr/bin/pxe-tools)" = "pxe-tools: /usr/bin/pxe-tools"
        [[ "$page" == *"value=\"sudo pxe-tools setup\" readonly"* ]]
        output=$(pxe-tools setup)
        [[ "$output" == *"https://$address/init/welcome#code=$code"* ]]
        if runuser -u nobody -- pxe-tools setup; then exit 1; fi
    else
        [[ "$page" == *"value=\"cat /run/pxe-tools-rust-guided/setup-code\" readonly"* ]]
    fi
    request=(curl -ksS --max-time 5 -o /dev/null -w "%{http_code} %{redirect_url}" -H "Origin: $origin")
    account=(--data-urlencode "username=setup-fixture" --data-urlencode "password=fixture-only-password"
             --data-urlencode "password_confirm=fixture-only-password")
    wrong_code="0${code:1}"
    [[ "$wrong_code" != "$code" ]] || wrong_code="1${code:1}"
    test "$("${request[@]}" "$origin/init/code" --data-urlencode "code=$wrong_code")" = "403 "
    test "$(curl -ksS --max-time 5 -o /dev/null -w "%{http_code}" -H "Origin: https://unrelated.example.test" \
        --data-urlencode "code=$code" "$origin/init/code")" = 403
    test "$("${request[@]}" "$origin/init/admin" "${account[@]}")" = "403 "
    test "$("${request[@]}" -b "__Host-pxe-setup=0123456789abcdef0123456789abcdef" "$origin/init/admin" "${account[@]}")" = "403 "
    test ! -e /etc/pxe-tools/db.conf
    setup_cookie=$(mktemp)
    submitted="${code^^}"
    test "$("${request[@]}" -c "$setup_cookie" "$origin/init/code" --data-urlencode "code=${submitted//-/ }")" = "303 $origin/init/admin"
    grep -q "^#HttpOnly_.*__Host-pxe-setup" "$setup_cookie"
    test ! -e /etc/pxe-tools/db.conf
    account_page=$(curl -kfsS --max-time 5 -b "$setup_cookie" "$origin/init/admin")
    [[ "$account_page" == *"name=\"username\""* && "$account_page" != *"name=\"code\""* ]]
    test "$("${request[@]}" -b "$setup_cookie" "$origin/init/admin" "${account[@]}")" = "303 $origin/login"
    test "$("${request[@]}" -b "$setup_cookie" "$origin/init/admin" "${account[@]}")" = "403 "
    test "$("${request[@]}" "$origin/init/code" --data-urlencode "code=$code")" = "409 "
    rm "$setup_cookie"
    curl -kfsS --max-time 5 --user setup-fixture:fixture-only-password "$origin/admin/inventaire" >/dev/null

    tls_cert=/run/pxe-tools-rust-guided/cert.pem
    dns_origin="https://$dns_name"
    dns_client=(curl -sS --noproxy "*" --max-time 5 --cacert "$tls_cert" --resolve "$dns_name:443:$address")
    status=(-o /dev/null -w "%{http_code}")
    test "$(curl -fsS --noproxy "*" --max-time 5 --resolve "$dns_name:80:$address" -o /dev/null \
        -w "%{redirect_url}" "http://$dns_name/")" = "$dns_origin/admin/"
    test "$("${dns_client[@]}" "${status[@]}" --data "" -H "Origin: https://unrelated.example.test" "$dns_origin/login")" = 403
    test "$("${dns_client[@]}" "${status[@]}" --data "" -H "Origin: https://unrelated.example.test" \
        -H "Host: unrelated.example.test" "$dns_origin/login")" = 403
    fixture=$(mktemp -d)
    test "$("${dns_client[@]}" "${status[@]}" --cookie-jar "$fixture/session" -H "Origin: $dns_origin" \
        --data "username=setup-fixture&password=fixture-only-password" "$dns_origin/login")" = 303
    console=$("${dns_client[@]}" -f --cookie "$fixture/session" "$dns_origin/admin/inventaire")
    [[ "$console" == *"name=\"color-scheme\" content=\"light dark\""* ]]
    for theme in auto light dark; do
        [[ "$console" == *"name=\"theme-header\" value=\"$theme\" data-theme-choice"* ]]
        [[ "$console" == *"name=\"theme-drawer\" value=\"$theme\" data-theme-choice"* ]]
    done
    test "$("${dns_client[@]}" -f --cookie "$fixture/session" "$dns_origin/admin/notifications")" = "[]"
    accounts=$("${dns_client[@]}" -f --cookie "$fixture/session" "$dns_origin/admin/access/users")
    [[ "$accounts" == *"<fieldset class=\"permission-fields\" data-account-rights>"* ]]
    [[ "$accounts" == *"<fieldset class=\"permission-fields\" data-account-rights disabled>"* ]]
    admin_rights=${accounts#*data-account-rights disabled>}
    admin_rights=${admin_rights%%</fieldset>*}
    rights_count=$(grep -o "name=\"scope\"" <<< "$admin_rights" | wc -l)
    test "$rights_count" -gt 0
    test "$rights_count" -eq "$(grep -o " checked>" <<< "$admin_rights" | wc -l)"

    alias=inventory.example.test
    openssl req -x509 -nodes -newkey rsa:2048 -days 1 -subj "/CN=$alias" \
        -addext "subjectAltName=DNS:$alias,DNS:*.$search_domain" \
        -keyout "$fixture/key.pem" -out "$fixture/cert.pem" >/dev/null 2>&1
    test "$("${dns_client[@]}" "${status[@]}" --cookie "$fixture/session" -H "Origin: $dns_origin" \
        --data-urlencode "certificate@$fixture/cert.pem" --data-urlencode "private_key@$fixture/key.pem" \
        "$dns_origin/admin/certificates")" = 303
    alias_origin="https://$alias"
    alias_client=(curl -sS --noproxy "*" --max-time 5 --cacert "$fixture/cert.pem" --resolve "$alias:443:$address")
    test "$("${alias_client[@]}" "${status[@]}" -H "Origin: $alias_origin" --data "" "$alias_origin/login")" = 422
    test "$("${alias_client[@]}" "${status[@]}" -H "Host: unrelated.$search_domain" \
        -H "Origin: https://unrelated.$search_domain" --data "" "$alias_origin/login")" = 403
    test "$("${alias_client[@]}" "${status[@]}" --user setup-fixture:fixture-only-password \
        -H "Origin: $alias_origin" --data "restore_default=1" "$alias_origin/admin/certificates")" = 303
    "${dns_client[@]}" -f --cookie "$fixture/session" "$dns_origin/admin/inventaire" >/dev/null
    test "$("${dns_client[@]}" "${status[@]}" -H "Host: $alias" \
        -H "Origin: $alias_origin" --data "" "$dns_origin/login")" = 403
    printf "Browser login works on %s and a certificate alias is accepted only while active.\n" "$dns_name"
    if [[ "$variant" == apt ]]; then
        output=$(pxe-tools setup)
        [[ "$output" == *"https://$address/admin/"* && "$output" != *"$code"* ]]
    fi
' _ "$variant" "$address" "$server_name.$search_domain" "$search_domain"
if [[ "$variant" == apt ]]; then
    # Exercise the web updater with a signed package that pulls a new Debian dependency.
    docker exec -e DEBIAN_FRONTEND=noninteractive "$container" \
        apt-get install -y --no-install-recommends apt-utils gnupg
    docker cp tests/integration/test_apt_console_update.py "$container:/var/tmp/test_apt_console_update.py"
    docker exec "$container" python3 /var/tmp/test_apt_console_update.py "${PXE_TEST_APT_VERSION:-0.1.0~guided1}"
    docker exec -i "$container" bash -es <<'NETWORK_GUEST'
        set -euo pipefail
        systemctl stop pxe-tools-worker.service
        config=/etc/pxe-tools/db.conf
        db=/var/lib/pxe-tools/pxe-tools.sqlite
        binary=/usr/lib/pxe-tools/pxe-tools-rust
        ip addr add 198.51.100.43/24 dev eth0
        runuser -u www-data -- sqlite3 "$db" <<'SQL'
INSERT OR REPLACE INTO settings(key,value) VALUES
    ('pxe_enabled','on'),('pxe_interface','eth0'),('pxe_ip','192.0.2.42'),
    ('pxe_subnet','192.0.2.0/24'),('dhcp_proxy_enabled','on'),
    ('dhcp_proxy_network','192.0.2.0'),('dhcp_proxy_netmask','255.255.255.0');
SQL
        test "$(runuser -u www-data -- "$binary" --refresh-pxe-address "$config")" = suspended
        ip addr add 192.0.2.43/24 dev eth0
        test "$(runuser -u www-data -- "$binary" --refresh-pxe-address "$config")" = 192.0.2.43
        test "$(runuser -u www-data -- "$binary" --refresh-pxe-address "$config")" = 192.0.2.43
        test -z "$("$binary" --active-pxe-address "$config")"
        if runuser -u www-data -- "$binary" --finish-pxe-address "$config" 192.0.2.42; then
            exit 1
        fi
        # At startup this acknowledgement follows GRUB and template rendering.
        runuser -u www-data -- "$binary" --finish-pxe-address "$config" 192.0.2.43
        test "$("$binary" --active-pxe-address "$config")" = 192.0.2.43
        test "$(runuser -u www-data -- "$binary" --refresh-pxe-address "$config")" = unchanged
        ip addr del 192.0.2.43/24 dev eth0
        ip addr add 192.0.2.44/24 dev eth0
        ip addr add 192.0.2.45/24 dev eth0
        test "$(runuser -u www-data -- "$binary" --refresh-pxe-address "$config")" = suspended
        systemctl restart pxe-tools-pxe-http.service
        curl -fsS --max-time 5 --cacert /run/pxe-tools-rust-guided/cert.pem https://127.0.0.1/healthz >/dev/null
        overview=$(curl -kfsS --max-time 5 --user setup-fixture:fixture-only-password https://127.0.0.1/overview)
        [[ "$overview" == *"Démarrage PXE suspendu"* ]]
        test -z "$("$binary" --active-pxe-address "$config")"
        test -z "$(ss -Hlun '( sport = :67 or sport = :4011 )')"
        printf 'PXE renewed its address and left the console accessible with an ambiguous IPv4.\n'
NETWORK_GUEST
fi
docker exec -i "$container" bash -es -- "$variant" <<'CONSOLE_GUEST'
    set -euo pipefail
    variant=$1
    route=/settings/console
    origin=https://127.0.0.1
    trial=https://127.0.0.1:8443
    selected=https://127.0.0.1:9443
    client=(curl -ksS --max-time 5 --user setup-fixture:fixture-only-password)
    status=(-o /dev/null -w '%{http_code}')
    binary=/usr/lib/pxe-tools/pxe-tools-rust
    config=/etc/pxe-tools/db.conf

    post() {
        "${client[@]}" "${status[@]}" -H "Origin: $1" -d "$3" "$2$route"
    }
    configured() {
        "$binary" --console-https-port "$config"
    }
    redirect() {
        curl -fsS --max-time 5 -o /dev/null -w '%{redirect_url}' http://127.0.0.1/
    }
    wait_closed() {
        for _ in {1..20}; do
            if [[ -z "$(ss -Hln "( sport = :$1 )")" ]]; then return 0; fi
            sleep .2
        done
        return 1
    }

    test "$(curl -ksS --max-time 5 "${status[@]}" "$origin$route")" = 401
    test "$(post "$origin" "$origin" port=80)" = 422
    occupied=$(mktemp)
    python3 -u - "$occupied" <<'PY' &
import socket
import sys
import time
from pathlib import Path

with socket.socket() as server:
    server.bind(('127.0.0.1', 0))
    server.listen()
    Path(sys.argv[1]).write_text(str(server.getsockname()[1]))
    time.sleep(30)
PY
    occupant=$!
    trap 'kill "$occupant" 2>/dev/null || true; rm -f -- "$occupied"' EXIT
    for _ in {1..30}; do
        if [[ -s "$occupied" ]]; then break; fi
        sleep .1
    done
    test "$(post "$origin" "$origin" "port=$(<"$occupied")")" = 409
    kill "$occupant"
    wait "$occupant" 2>/dev/null || true
    rm -f -- "$occupied"
    trap - EXIT

    test "$(post "$origin" "$origin" port=8443)" = 303
    test "$(configured)" = 443
    pending=$("${client[@]}" -f "$origin$route")
    [[ "$pending" == *"$trial$route"* ]]
    token=$(sed -nE 's/.*name="cancel" value="([a-f0-9]{32})".*/\1/p' <<< "$pending")
    [[ "$token" =~ ^[a-f0-9]{32}$ ]]
    test "$(post "$origin" "$origin" "confirm=$token")" = 403
    test "$(post "$origin" "$trial" "confirm=$token")" = 403
    test "$(post "$origin" "$origin" port=9443)" = 409
    test "$(redirect)" = "$origin/admin/"
    if [[ "$variant" == docker && "$(uname -m)" == x86_64 ]]; then
        # Verify timer cleanup even with no browser request on the new port.
        sleep 122
    else
        test "$(post "$origin" "$origin" "cancel=$token")" = 303
    fi
    wait_closed 8443
    test "$(configured)" = 443
    "${client[@]}" -f "$origin$route" >/dev/null

    test "$(post "$origin" "$origin" port=9443)" = 303
    cookie=$(mktemp)
    trap 'rm -f -- "$cookie"' EXIT
    browser=(curl -ksS --max-time 5 -H 'Accept: text/html')
    test "$("${browser[@]}" "${status[@]}" -c "$cookie" -H "Origin: $origin" \
        -d "username=setup-fixture&password=fixture-only-password" "$origin/login")" = 303
    proof=$("${browser[@]}" -f -b "$cookie" "$selected$route")
    token=$(sed -nE 's/.*name="confirm" value="([a-f0-9]{32})".*/\1/p' <<< "$proof")
    [[ "$token" =~ ^[a-f0-9]{32}$ ]]
    test "$("${browser[@]}" "${status[@]}" -b "$cookie" -H "Origin: $selected" \
        -d "confirm=$token" "$selected$route")" = 303
    test "$(configured)" = 9443
    test "$(redirect)" = "$selected/admin/"
    test "$(curl -fsS --max-time 5 http://127.0.0.1/healthz)" = ok
    "${client[@]}" -f "$selected/admin/inventaire" >/dev/null
    wait_closed 443
    if [[ "$variant" == apt ]]; then
        systemctl restart pxe-tools-pxe-http.service
        "${client[@]}" -f "$selected$route" >/dev/null
        if pxe-tools web-port 8444; then exit 1; fi
        systemctl stop pxe-tools-pxe-http.service
        pxe-tools web-port 8444
        systemctl start pxe-tools-pxe-http.service
        "${client[@]}" -f "https://127.0.0.1:8444$route" >/dev/null
        output=$(pxe-tools setup)
        [[ "$output" == *":8444/admin/"* ]]
    fi
CONSOLE_GUEST
if [[ "$variant" == docker ]]; then
    docker restart "$container" >/dev/null
    for _ in {1..60}; do
        if docker exec "$container" /opt/pxe-tools/init/healthcheck.sh >/dev/null 2>&1; then break; fi
        sleep 1
    done
    docker exec "$container" /opt/pxe-tools/init/healthcheck.sh
    docker exec "$container" curl -kfsS --max-time 5 https://127.0.0.1:9443/healthz >/dev/null
fi
printf 'HTTPS port trial, confirmation and restart passed (%s)\n' "$variant"
docker exec -i "$container" bash -c 'cat > /tmp/pxe-catalog-curl' < tests/integration/fixtures/catalog_curl.py
docker exec -i "$container" bash -es -- "$variant" <<'CATALOG_GUEST'
    set -euo pipefail
    if [[ "$1" == apt ]]; then
        systemctl stop pxe-tools-worker.service
        http=/var/lib/pxe-tools-http
    else
        /command/s6-svc -d /run/service/worker
        http=/srv/http
    fi
    config=/etc/pxe-tools/db.conf
    database=$(python3 -c 'import json, sys; print(json.load(open(sys.argv[1]))["path"])' "$config")
    port=$(/usr/lib/pxe-tools/pxe-tools-rust --console-https-port "$config")
    origin="https://127.0.0.1:$port"
    client=(curl -kfsS --max-time 8 --user setup-fixture:fixture-only-password)
    route="$origin/settings/images"
    archive='https://cdimage.debian.org/cdimage/archive/13.7.0/amd64/iso-cd/debian-13.7.0-amd64-netinst.iso'
    # The APT guest mounts /tmp with noexec. Keep the executable on its root filesystem.
    fixture=$(mktemp -d /var/tmp/pxe-catalog.XXXXXXXX)
    trap 'rm -rf -- "$fixture"' EXIT
    install -m 0755 /tmp/pxe-catalog-curl "$fixture/curl"
    test "$("$fixture/curl" https://deb.debian.org/debian/dists/trixie/Release)" = 'Version: 13.8'
    PATH="$fixture:$PATH" /usr/lib/pxe-tools/pxe-tools-rust --refresh-catalog "$config"
    test "$(sqlite3 "$database" "SELECT count(DISTINCT distro) FROM catalog_entries")" = 6
    test "$(sqlite3 "$database" "SELECT group_concat(arch) FROM catalog_entries
      WHERE distro='debian' AND version_full='14-testing' AND codename='forky' AND channel='testing'")" = amd64
    test "$(sqlite3 "$database" "SELECT group_concat(arch) FROM catalog_entries
      WHERE distro='fedora' AND version_full='46' AND channel='stable'")" = amd64
    test "$(sqlite3 "$database" "SELECT count(*) FROM catalog_entries
      WHERE distro='fedora' AND version_full='43' AND channel='oldstable'")" = 2
    test "$(sqlite3 "$database" "SELECT group_concat(version_full || ':' || arch, ',') FROM (
      SELECT version_full, arch FROM catalog_entries WHERE distro='ubuntu'
      AND version_full IN ('24.04.6','24.04.7','24.04.10') ORDER BY CAST(substr(version_full,7) AS INTEGER)
    )")" = '24.04.6:amd64,24.04.7:arm64,24.04.10:amd64'
    entry=$(sqlite3 "$database" "SELECT id FROM catalog_entries
      WHERE distro='debian' AND version_full='13.7.0' AND arch='amd64' AND iso_url='$archive'")
    [[ "$entry" =~ ^[0-9]+$ ]]
    catalog=$("${client[@]}" "$route/add")
    [[ "$catalog" == *'13.7.0 <small>Archive</small>'* ]]
    [[ "$catalog" == *'Debian 14 · forky<span class="status-badge status-badge--active">Testing</span>'* ]]
    [[ "$catalog" == *'Debian 13 · trixie<span class="status-badge status-badge--success">Stable</span>'* ]]
    [[ "$catalog" == *'Debian 12 · bookworm<span class="status-badge status-badge--neutral">Oldstable</span>'* ]]
    [[ "$catalog" == *'13.8.0<span class="status-badge status-badge--active">Latest</span>'* ]]
    [[ "$catalog" == *'24.04.10 <small>Archive</small>'*'24.04.7</th>'* ]]
    [[ "$catalog" == *'14-testing</th>'* && "$catalog" != *'14-testing <small>Archive</small>'* ]]
    [[ "$catalog" == *"form=\"catalog-selection\" name=\"image\" value=\"$entry\""* ]]
    for tab in windows proxmox rhel9; do
        [[ "$catalog" == *"role=\"tab\" id=\"catalog-tab-$tab\" aria-controls=\"catalog-panel-$tab\""* ]]
    done
    [[ "$catalog" == *'id="catalog-panel-windows"'*'href="/settings/images/media?kind=windows-iso"'*'href="/settings/windows"'*'id="catalog-panel-proxmox"'* ]]
    [[ "$catalog" == *'id="catalog-panel-proxmox"'*'action="/settings/images/proxmox"'*'id="catalog-panel-rhel9"'* ]]
    [[ "$catalog" == *'id="catalog-panel-rhel9"'*'href="/settings/images/rhel"'*'href="/settings/images/media?kind=rhel9"'*'id="catalog-panel-centos"'* ]]
    post=(-o /dev/null -w '%{http_code}' -H "Origin: $origin")
    test "$("${client[@]}" "${post[@]}" --data "image=$entry" "$route/add")" = 303
    queued=$(sqlite3 "$database" "SELECT extract_method||'|'||iso_url||'|'||status
      FROM os_images WHERE distro='debian' AND version_full='13.7.0' AND arch='amd64'")
    test "$queued" = "iso_extract|$archive|pending"
    test "$("${client[@]}" "${post[@]}" --data '' "$route/refresh")" = 303
    pending=$("${client[@]}" "$route/refresh-button")
    [[ "$pending" == *'data-catalog-refresh="pending"'* ]]
    sqlite3 "$database" "UPDATE init_jobs SET status='running',progress_percent=42
      WHERE job_type='bootstrap:refresh_catalog_v2' AND status='pending'"
    current=$("${client[@]}" "$route/refresh-button")
    [[ "$current" == *'data-catalog-refresh="running"'* && "$current" == *'<progress value="42"'* ]]
    sqlite3 "$database" "UPDATE init_jobs SET status='success',progress_percent=100
      WHERE job_type='bootstrap:refresh_catalog_v2' AND status='running'"
    finished=$("${client[@]}" "$route/refresh-button")
    [[ "$finished" == *'data-catalog-refresh="success"'* && "$finished" == *'Catalogue à jour'* ]]
    sqlite3 "$database" "DELETE FROM os_images WHERE distro='debian' AND version_full='13.7.0' AND arch='amd64';
      DELETE FROM catalog_entries WHERE id=$entry"
    base="$http/netboot/debian/13.8.0/amd64"
    replacement="$http/netboot/debian/12.15.0/amd64"
    for path in "$base" "$replacement"; do
        install -d -m 0755 "$path"
        printf 'fixture' >"$path/linux"
        printf 'fixture' >"$path/initrd.gz"
    done
    rescue=$(sqlite3 "$database" "INSERT INTO os_images
      (distro,version,version_full,codename,major,arch,extract_method,enabled,status)
      VALUES ('debian','13.8.0','13.8.0','trixie',13,'amd64','direct',1,'downloaded') RETURNING id")
    protected=$("${client[@]}" "$route")
    [[ "$protected" == *'Dernière image de découverte et de secours pour cette architecture'* ]]
    [[ "$protected" != *"action=\"/settings/images/$rescue\""* ]]
    reply=$("${client[@]}" --no-fail -w '\n%{http_code}' -H "Origin: $origin" \
      --data '_method=DELETE' "$route/$rescue")
    [[ "$reply" == *'Dernière image de découverte et de secours pour cette architecture'*$'\n409' ]]
    alpine_version=$(python3 -c 'import json; print(json.load(open("/opt/pxe-tools/scripts/alpine-release.json"))["version"])')
    alpine_tree="$http/netboot/alpine-service/$alpine_version"
    for arch in amd64 arm64; do
        install -d -m 0755 "$alpine_tree/$arch"
        printf '%s\n' "$alpine_version" >"$alpine_tree/$arch/.alpine-ready"
        for file in linux initrd.gz modloop service.apkovl.tar.gz; do
            printf 'fixture' >"$alpine_tree/$arch/$file"
        done
    done
    [[ "$("${client[@]}" "$route")" != *"action=\"/settings/images/$rescue\""* ]]
    sqlite3 "$database" "INSERT INTO settings(key,value) VALUES('alpine_boot_menus_version','$alpine_version')"
    [[ "$("${client[@]}" "$route")" == *"action=\"/settings/images/$rescue\""* ]]
    sqlite3 "$database" "DELETE FROM settings WHERE key='alpine_boot_menus_version'"
    for arch in amd64 arm64; do
        rm -- "$alpine_tree/$arch/"{.alpine-ready,linux,initrd.gz,modloop,service.apkovl.tar.gz}
        rmdir -- "$alpine_tree/$arch"
    done
    rmdir -- "$alpine_tree" "$http/netboot/alpine-service"
    fallback=$(sqlite3 "$database" "INSERT INTO os_images
      (distro,version,version_full,codename,major,arch,extract_method,enabled,status)
      VALUES ('debian','12.15.0','12.15.0','bookworm',12,'amd64','direct',1,'downloaded') RETURNING id")
    [[ "$("${client[@]}" "$route")" == *"action=\"/settings/images/$rescue\""* ]]
    test "$("${client[@]}" "${post[@]}" --data '_method=DELETE' "$route/$rescue")" = 303
    sqlite3 "$database" "DELETE FROM init_jobs WHERE job_type='image:delete:$rescue';
      DELETE FROM os_images WHERE id IN ($rescue,$fallback)"
    for path in "$base" "$replacement"; do
        rm -- "$path/linux" "$path/initrd.gz"
        rmdir -- "$path"
    done
    printf 'Catalog tabs, refresh states and rescue protection passed (%s)\n' "$1"
CATALOG_GUEST
bash tests/integration/test_rust_multinetwork.sh "$container" "$variant"
printf 'Fresh Rust distribution ready for setup over HTTPS (%s)\n' "$variant"
if [[ "$variant" == apt && "${GITHUB_ACTIONS:-}" == true && "$(uname -m)" == x86_64 && -c /dev/kvm ]]; then
    docker exec -e DEBIAN_FRONTEND=noninteractive "$container" \
        apt-get install -y --no-install-recommends qemu-system-x86 ipxe-qemu
    docker exec -i -e PXE_DISPOSABLE_ALPINE_BOOT=1 "$container" bash -ec '
        set -a
        source /usr/lib/pxe-tools/native/default.env
        source /var/lib/pxe-tools-native/runtime.env
        set +a
        exec python3 -
    ' < tests/integration/test_alpine_service_boot.py
fi
