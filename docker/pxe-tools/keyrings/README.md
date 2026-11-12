# Keyrings de confiance — vérification d'intégrité des images OS (F-05)

Clés publiques **officielles** des autorités de signature des images CD/installer
Debian et Ubuntu. Elles servent à `gpgv` (cf. `docker/worker/scripts/lib-image-verify.sh`)
pour valider les `SHA256SUMS` détachés (`.sign` Debian / `.gpg` Ubuntu) des ISO et
du firmware **avant** que le worker ne promeuve un artefact comme servable.

Le netboot Debian (kernel/initrd direct) n'utilise PAS ces fichiers : sa racine de
confiance est `debian-archive-keyring` (paquet apt, signe l'`InRelease` des suites).

> Ces blobs sont des **clés publiques** — rien de secret. Ils sont committés
> (jamais fetchés au runtime) pour qu'aucun téléchargement de clé ne soit
> lui-même MITM-able.

## Contenu (fingerprints épinglés)

`pxe-debian-cd.gpg` — sha256 `ebc17824b528f0057f6cf0b3547db60de5809d328be8e605a1f396246f80f78a`
- `10460DAD76165AD81FBC0CE9988021A964E6EA7D` — Debian CD signing key
- `DF9B9C49EAA9298432589D76DA87E80D6294BE9B` — Debian CD signing key
- `F41D30342F3546695F65C66942468F4009EA8AC3` — Debian Testing CDs Automatic Signing Key

`pxe-ubuntu-cd.gpg` — sha256 `b18339267171c54f4270ceedb4655af062f4d5fc18c6b1acf1db427d186242fc`
- `C5986B4F1257FFA86632CBA746181433FBB75451` — Ubuntu CD Image Automatic Signing Key
- `843938DF228D22F7B3742BC0D94AA3F0EFE21092` — Ubuntu CD Image Automatic Signing Key (2012)

Sources autoritatives des fingerprints : <https://www.debian.org/CD/verify> et
<https://ubuntu.com/tutorials/tutorial/tutorial-how-to-verify-ubuntu>.

## Régénération (vérifiée end-to-end)

Reproductible dans un conteneur `debian:bookworm-slim` (l'export gpg n'est pas
bit-à-bit déterministe ; la garantie est la **validation gpgv** ci-dessous, pas
l'égalité d'octets) :

```sh
export GNUPGHOME=$(mktemp -d)
DEB="10460DAD76165AD81FBC0CE9988021A964E6EA7D DF9B9C49EAA9298432589D76DA87E80D6294BE9B F41D30342F3546695F65C66942468F4009EA8AC3"
UBU="C5986B4F1257FFA86632CBA746181433FBB75451 843938DF228D22F7B3742BC0D94AA3F0EFE21092"
for f in $DEB $UBU; do gpg --keyserver hkps://keyserver.ubuntu.com --recv-keys "$f"; done
gpg --export $DEB > pxe-debian-cd.gpg
gpg --export $UBU > pxe-ubuntu-cd.gpg

# Validation : les keyrings doivent valider de VRAIES signatures upstream.
gpgv --keyring ./pxe-ubuntu-cd.gpg <(curl -sL https://releases.ubuntu.com/22.04/SHA256SUMS.gpg) \
                                   <(curl -sL https://releases.ubuntu.com/22.04/SHA256SUMS)
gpgv --keyring ./pxe-debian-cd.gpg <(curl -sL https://cdimage.debian.org/cdimage/archive/13.4.0/amd64/iso-cd/SHA256SUMS.sign) \
                                   <(curl -sL https://cdimage.debian.org/cdimage/archive/13.4.0/amd64/iso-cd/SHA256SUMS)
```

Une archive/ISO signée par une clé CD **retirée** non présente ici fera échouer
`gpgv` (fail-closed → image `failed`) : remède = ajouter la clé concernée et
régénérer.
