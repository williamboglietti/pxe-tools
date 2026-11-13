#!/bin/sh
# Render boot templates; dnsmasq configuration has its own startup service.
exec /opt/pxe-tools/scripts/bootstrap-render-templates.sh
