#!/usr/bin/env bash
# SPDX-License-Identifier: LGPL-3.0-only
set -euo pipefail
[[ -f /.dockerenv && "$(id -u)" = "$(id -u www-data)" && ! -e /etc/pxe-tools/db.conf ]] || {
  echo 'A disposable container running as www-data is required.' >&2; exit 1;
}
[[ "$(ls -1 /sys/class/net)" = lo ]] || { echo 'Network must be disabled.' >&2; exit 1; }
root=$(cd "$(dirname "$0")/../.." && pwd)
fixture=$(mktemp -d /tmp/pxe-web-delete-XXXXXXXX)
trap 'rm -rf -- "$fixture"' EXIT
mkdir -p "$fixture/writable/netboot/fixture/1/amd64"
printf 'fixture-linux' > "$fixture/writable/netboot/fixture/1/amd64/linux"
printf 'fixture-initrd' > "$fixture/writable/netboot/fixture/1/amd64/initrd.gz"
sqlite3 "$fixture/database.sqlite" < "$root/app/db/schema.sqlite.sql"
sqlite3 "$fixture/database.sqlite" "INSERT INTO os_images(id,distro,version,version_full,arch,enabled,status) VALUES(1,'fixture','1','1','amd64',1,'downloaded'),(2,'fixture','2','2','amd64',1,'downloaded');"
env PXE_APP_DIR="$root/app" PXE_DB_DRIVER=sqlite PXE_DB_PATH="$fixture/database.sqlite" HTTP_ROOT="$fixture/writable" php -r '
spl_autoload_register(function ($c) { if (str_starts_with($c,"Admin\\")) require getenv("PXE_APP_DIR")."/src/".str_replace("\\","/",substr($c,6)).".php"; });
$image=\Admin\Models\OsImage::findById(1);
if (!\Admin\Models\OsImage::webCanDeleteDirect($image)) exit(1);
\Admin\Models\OsImage::deleteWithFiles(1);
if (\Admin\Models\OsImage::findById(1)!==null) exit(2);
putenv("HTTP_ROOT=/usr/lib/pxe-fixture-not-present");
if (\Admin\Models\OsImage::webCanDeleteDirect(\Admin\Models\OsImage::findById(2))) exit(3);
'
test ! -e "$fixture/writable/netboot/fixture/1/amd64/linux"
printf '%s\n' 'Writable Docker-style volume deletes as www-data; protected native tree selects worker path.'
