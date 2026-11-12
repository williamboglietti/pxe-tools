#!/usr/bin/env bash
set -euo pipefail
# This fixed native-only action never accepts shell arguments from the web UI.
if [[ "${PXE_SERVICE_MANAGER:-}" != systemd || ! -d /run/systemd/system ]]; then
  echo 'Action réservée à une installation native systemd.' >&2
  exit 1
fi
exec /usr/bin/python3 /opt/pxe-tools/scripts/native-release-nginx-port.py
