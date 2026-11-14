#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Compare fixture certificates allowing only Ed25519 signature MPI padding."""
from pathlib import Path
import sys


def signature_body(body: bytes) -> bytes:
    if len(body) < 10 or body[0] != 4 or body[2] != 22:
        return body
    offset = 6 + int.from_bytes(body[4:6], 'big')
    if offset + 2 > len(body):
        raise ValueError('truncated hashed signature data')
    offset += 2 + int.from_bytes(body[offset:offset + 2], 'big')
    if offset + 2 > len(body):
        raise ValueError('truncated unhashed signature data')
    offset += 2  # signed hash prefix
    result = bytearray(body[:offset])
    for _ in range(2):
        if offset + 2 > len(body):
            raise ValueError('truncated signature MPI')
        bits = int.from_bytes(body[offset:offset + 2], 'big')
        offset += 2
        size = (bits + 7) // 8
        if offset + size > len(body):
            raise ValueError('truncated signature MPI payload')
        raw = body[offset:offset + size]
        value = raw.lstrip(b'\0')
        offset += size
        if not value:
            raise ValueError('empty signature MPI')
        canonical_bits = 8 * (len(value) - 1) + value[0].bit_length()
        if len(raw) > 32 or (raw == value and bits != canonical_bits) or (
                raw != value and (len(raw) != 32 or bits != 256)):
            raise ValueError('unexpected Ed25519 signature MPI encoding')
        result.extend(canonical_bits.to_bytes(2, 'big'))
        result.extend(value)
    if offset != len(body):
        raise ValueError('unexpected signature data')
    return bytes(result)


def packets(data: bytes):
    if len(data) > 131072:
        raise ValueError('fixture certificate too large')
    result = []
    pos = 0
    while pos < len(data):
        start = pos
        ctb = data[pos]
        pos += 1
        if not ctb & 0x80:
            raise ValueError('invalid packet header')
        if ctb & 0x40:
            tag = ctb & 0x3f
            if pos >= len(data):
                raise ValueError('truncated packet length')
            first = data[pos]
            pos += 1
            if first < 192:
                length, kind = first, 1
            elif first < 224:
                if pos >= len(data):
                    raise ValueError('truncated packet length')
                length, kind = ((first - 192) << 8) + data[pos] + 192, 2
                pos += 1
            elif first == 255:
                if pos + 4 > len(data):
                    raise ValueError('truncated packet length')
                length, kind = int.from_bytes(data[pos:pos + 4], 'big'), 5
                pos += 4
            else:
                raise ValueError('partial packet lengths unsupported')
        else:
            tag, kind = (ctb >> 2) & 0x0f, ctb & 3
            if kind == 3:
                raise ValueError('indeterminate packet length unsupported')
            width = (1, 2, 4)[kind]
            if pos + width > len(data):
                raise ValueError('truncated packet length')
            length = int.from_bytes(data[pos:pos + width], 'big')
            pos += width
        end = pos + length
        if end > len(data):
            raise ValueError('truncated packet')
        body = data[pos:end]
        normalized = signature_body(body) if tag == 2 else body
        # Preserve the exact packet encoding unless MPI padding changed it.
        if normalized == body:
            result.append(('raw', data[start:end]))
        else:
            result.append(('signature', ctb, kind, normalized))
        pos = end
    return result


def comparable_packets(data: bytes):
    # Both inputs must use the same normalized representation for signatures,
    # including signatures whose MPIs were already canonical.
    output = []
    for packet in packets(data):
        if packet[0] == 'raw':
            raw = packet[1]
            ctb = raw[0]
            tag = (ctb & 0x3f) if ctb & 0x40 else ((ctb >> 2) & 0x0f)
            if tag == 2:
                # Reparse the single packet with an explicit normalized form.
                output.append(normalized_signature_packet(raw))
            else:
                output.append(packet)
        else:
            output.append(packet)
    return output


def normalized_signature_packet(raw: bytes):
    ctb = raw[0]
    if ctb & 0x40:
        first = raw[1]
        kind = 1 if first < 192 else 2 if first < 224 else 5
        header = 1 + kind
    else:
        kind = ctb & 3
        header = 1 + (1, 2, 4)[kind]
    return ('signature', ctb, kind, signature_body(raw[header:]))


def main():
    if len(sys.argv) != 3:
        raise ValueError('two public fixture certificates required')
    if comparable_packets(Path(sys.argv[1]).read_bytes()) != comparable_packets(Path(sys.argv[2]).read_bytes()):
        raise ValueError('fixture certificates differ beyond signature MPI zero padding')


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError) as error:
        raise SystemExit(str(error))
