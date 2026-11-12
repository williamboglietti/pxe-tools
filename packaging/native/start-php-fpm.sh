#!/bin/sh
set -eu
for binary in /usr/sbin/php-fpm8.6 /usr/sbin/php-fpm8.5 /usr/sbin/php-fpm8.4 /usr/sbin/php-fpm8.3 /usr/sbin/php-fpm8.2; do
    if [ -x "$binary" ]; then exec "$binary" --nodaemonize --fpm-config /usr/lib/pxe-tools/native/php-fpm.conf; fi
done
echo 'PHP-FPM compatible introuvable.' >&2
exit 1
