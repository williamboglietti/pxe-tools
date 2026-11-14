# Trousseau de secours Debian pour Ubuntu 24.04 et Zorin OS 18

`debian-archive-2025.1.gpg` est la copie inchangée de
`/usr/share/keyrings/debian-archive-keyring.gpg` issue du paquet Debian
`debian-archive-keyring` version `2025.1`, installé depuis les index Debian 13
authentifiés. SHA-256 :
`506b815cbb32d9b6066b4a2aa524071e071761e7e7f68c3ac74f3061ba852017`.
La comparaison octet pour octet avec l'extraction du [paquet Debian officiel](https://deb.debian.org/debian/pool/main/d/debian-archive-keyring/debian-archive-keyring_2025.1_all.deb)
a également réussi ; le SHA-256 de ce `.deb` est
`9ea7778e443144ca490668737a8ab22dd3e748bb99e805e22ec055abeb3c7fac`.

Le [paquet source Debian](https://packages.debian.org/source/trixie/debian-archive-keyring)
publie la provenance et le fichier de droits ; celui-ci précise que les clés du
trousseau ne sont pas soumises au droit d'auteur. Seul le trousseau est repris.

Le worker choisit cette copie uniquement pour la base Ubuntu noble identifiée
et quand la version
installée du paquet Ubuntu est exactement `2023.4ubuntu1`, dont le trousseau
ne vérifie pas les signatures actuelles de Debian 13. Une autre version du
paquet utilise le trousseau système ; une valeur `DEBIAN_ARCHIVE_KEYRING`
explicitement configurée est toujours prioritaire. Une absence de clé ou une
signature invalide provoque toujours un refus.

Ce fichier figé ne reçoit pas automatiquement les retraits et rotations de
clés Debian. Il doit être revu et mis à jour avec PXE Tools lors d'une rotation
ou révocation, après contrôle de la provenance et des signatures. Un
administrateur peut configurer un trousseau Debian récent et authentifié via
`DEBIAN_ARCHIVE_KEYRING` dans `/var/lib/pxe-tools-native/network.env`, puis redémarrer le
worker. Le téléchargement ne doit jamais contourner la validation `gpgv`.
