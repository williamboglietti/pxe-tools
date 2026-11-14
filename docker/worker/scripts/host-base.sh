#!/bin/sh
# SPDX-License-Identifier: LGPL-3.0-only
# Declared base only: it does not certify APT sources or compatibility.
pxe_tools_detect_base() {
    case "${ID:-}:${VERSION_ID:-}:${VERSION_CODENAME:-}" in
        debian:12:bookworm) printf '%s\n' debian:bookworm; return ;;
        debian:13:trixie) printf '%s\n' debian:trixie; return ;;
        ubuntu:24.04:noble) printf '%s\n' ubuntu:noble; return ;;
        ubuntu:26.04:resolute) printf '%s\n' ubuntu:resolute; return ;;
        ubuntu:*|debian:*) return ;;
    esac
    case " ${ID_LIKE:-} " in
        *' ubuntu '*)
            case "${UBUNTU_CODENAME:-}" in
                noble|resolute) printf 'ubuntu:%s\n' "$UBUNTU_CODENAME" ;;
            esac ;;
    esac
}

if [ "${1:-}" = --detect ]; then
    unset ID ID_LIKE VERSION_ID VERSION_CODENAME UBUNTU_CODENAME
    . /etc/os-release
    pxe_tools_detect_base
fi
