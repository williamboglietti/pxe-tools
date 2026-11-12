#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Forward one QEMU guestfwd connection to the private fixture socket."""
import os
import selectors
import socket
import sys

if len(sys.argv) != 2:
    raise SystemExit(2)
with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as peer:
    peer.connect(sys.argv[1])
    selector = selectors.DefaultSelector()
    selector.register(sys.stdin.buffer, selectors.EVENT_READ, 'guest')
    selector.register(peer, selectors.EVENT_READ, 'mirror')
    while selector.get_map():
        for key, _ in selector.select(timeout=30):
            if key.data == 'guest':
                data = os.read(sys.stdin.fileno(), 65536)
                if data:
                    peer.sendall(data)
                else:
                    selector.unregister(sys.stdin.buffer)
                    peer.shutdown(socket.SHUT_WR)
            else:
                data = peer.recv(65536)
                if not data:
                    selector.close()
                    raise SystemExit(0)
                view = memoryview(data)
                while view:
                    view = view[os.write(sys.stdout.fileno(), view):]
