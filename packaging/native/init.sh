#!/bin/sh
set -eu
# Validate all root-owned publication roots before any privileged creation or
# rendering. Existing installations with untrusted ancestors must be migrated
# explicitly; their data is preserved.
for directory in "$HTTP_ROOT" "$TFTP_ROOT" "$PXE_TOOLS_DATA_DIR"; do
    python3 /opt/pxe-tools/scripts/validate-native-runtime.py tftp "$directory"
done
# Create only absent directories; never replace existing user data or links.
for directory in /var/lib/pxe-tools "$HTTP_ROOT" "$TFTP_ROOT" /var/log/pxe-tools; do
    if [ -L "$directory" ] || { [ -e "$directory" ] && [ ! -d "$directory" ]; }; then
        echo "Répertoire requis invalide : $directory" >&2; exit 1
    fi
    [ -d "$directory" ] || install -d -m 0755 "$directory"
done
if [ ! -e "$HTTP_ROOT/static-bin" ] && [ ! -L "$HTTP_ROOT/static-bin" ]; then
    ln -s /usr/lib/pxe-tools/static-bin "$HTTP_ROOT/static-bin"
fi
/bin/sh /opt/pxe-tools/init/php-init.sh
/bin/sh /opt/pxe-tools/init/bootstrap-tls.sh
/bin/bash /opt/pxe-tools/scripts/bootstrap-render-templates.sh
