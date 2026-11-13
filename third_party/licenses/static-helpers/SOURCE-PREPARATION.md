# Static PXE helper source preparation

PXE Tools' `dmidecode`, `smartctl`, `badblocks` and `chvt` executables are
separate third-party programs. They are statically linked and retain the
copyright and licence notices in this directory; the main project's licence
does not replace their licences. In particular, `chvt` is the `chvt` applet of
the Debian `busybox-static` executable.

The following source packages and versions were collected from authenticated
Debian source indexes for this candidate. The SHA-256 values identify their
Debian source descriptors; each descriptor contains checksums for its source
archives, whose sizes and SHA-256 values were checked against it.

| Debian source descriptor | SHA-256 |
| --- | --- |
| `busybox_1.37.0-6.dsc` | `3ba2c096eef0c391f744f093edbe0df7b21ab118652487cc076818e92cb57e8e` |
| `dmidecode_3.7-1.dsc` | `8c381f884bcd20d54bc01af697a2ee71eb3a5d79dd081fdf1bb8a01ba2f80842` |
| `e2fsprogs_1.47.2-3.dsc` | `860abb653ecbe01a11bb7e41c9e09a45e83847bffa585f7a3d3c0f9401c9d4bb` |
| `gcc-14_14.2.0-19.dsc` | `7db1e202cce66617e9134a662c877a3a773de5b612fd52179815877e0ecfb847` |
| `glibc_2.41-12+deb13u4.dsc` | `0915324aa646bb99abfaa9aa6ecb734b30babd28454a3e51f3152912370fbd3e` |
| `smartmontools_7.4-3.dsc` | `f412e784ea01700bc555dbe6666239bc0d38e5c11e3269db7e04f6fad19b8f81` |

The copied BusyBox binary is from Debian `busybox-static` version
`1:1.37.0-6+b9`; its Debian metadata identifies `glibc` version
`2.41-12+deb13u4` as a built-using dependency. The other executables were
built in a Debian 13 environment; the published source archive records the
source files, compilation recipe, selected compiler and library package
versions and notices. Its SHA-256 is
`c60a1e260da0cc7719184c3c7ecc2008bda6384f2d6f2c93f3a17c2cab87e28b`.
The archive is available at the release directory below.

## Source and relink materials

Release distribution must include the matching source and relink materials
alongside the binaries. The immutable source offer and direct download links are documented at:

https://github.com/williamboglietti/pxe-tools/releases/tag/sources-2026-11-12

The release must include all five files and must retain them for at least as
long as the corresponding binaries are distributed:

- `pxe-tools-static-helper-sources.tar` — SHA-256
  `c60a1e260da0cc7719184c3c7ecc2008bda6384f2d6f2c93f3a17c2cab87e28b`;
- `pxe-tools-static-helper-relink-materials.tar.gz` — SHA-256
  `c28079542fd55e34d29ec919ed48ad65460bfcd7ead021dce674c9b70d9504f1`;
- `relink-helpers.sh` and `README.md`, whose SHA-256 digests are listed in
  `SHA256SUMS` alongside the archives;
- `SHA256SUMS` for verifying all four files.

The source archive includes the six descriptors above, their referenced
source files, build recipe, toolchain metadata and notices. The relink archive
contains the corresponding build trees, object files, configuration and link
maps; its standalone script accepts a user-supplied `libc.a`. Both archives
and all listed files must be accessible before any matching binary is released.

In a Debian 13 amd64 test environment with the recorded GCC and glibc versions,
the script reproduced the shipped `dmidecode`, `smartctl` and `badblocks`
bit-for-bit using the original library. Rebuilt BusyBox differs from the shipped
Debian binary in `.note.gnu.build-id`, `.gnu_debuglink` and `.comment`; after
removing those three metadata sections from both files, the results were
identical. A second test added a marker to an object loaded from `libc.a`;
each relinked executable included that marker. These tests establish the
described reconstruction and relink cases, not correctness for every possible
library change. See the release directory's README and the licence texts for
details.
