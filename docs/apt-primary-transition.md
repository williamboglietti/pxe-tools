# Préparer la transition de clé primaire APT

La transition conserve l’URL du dépôt et le chemin figurant dans le champ
`Signed-By`. Le trousseau temporaire autorise l’ancienne et la nouvelle clé
pour cette source. Tant que le dépôt sert l’index actuel, les clients préparés
continuent de l’authentifier.

## Préparer le trousseau

Depuis un checkout authentifié de la PR #82, assembler les deux exports binaires
publics. Comparer le SHA-256 avec une copie indépendante de cette procédure.

```bash
cat packaging/ci/pxe-tools-public.gpg packaging/ci/pxe-tools-next-public.gpg > pxe-tools-transition.gpg
printf '%s\n' '33eb2c01abd4ab5b9db21117f774dabafbb61103801529d7b9d00b80bfd97efb  pxe-tools-transition.gpg' | sha256sum -c
gpg --batch --show-keys --with-fingerprint --with-subkey-fingerprint pxe-tools-transition.gpg
```

| Clé | Primaire | Sous-clé de signature |
| --- | --- | --- |
| Ancienne | `CD8B4493876B4FBD1009FE2DB195E5DE026807EC` | `76CA2883C9DACEB52D9E806A1F2D96C9062271AB` |
| Nouvelle | `17410C3B457E574257281551CFC35C536A61C3CF` | `A807812C7D83085301C630CD1DC963B9AF9C6908` |

Vérifier l’index public actuel avec ce fichier avant de le copier sur un
client. Utiliser un chemin absolu pour `gpgv --keyring`.

## Client existant

1. Choisir d’abord un client Debian 13 amd64 autorisé. Vérifier que sa seule
   source PXE Tools pointe vers `https://packages.pxetools.com/apt`,
   suite `stable`, et utilise uniquement
   `Signed-By: /etc/apt/keyrings/pxe-tools-archive-keyring.gpg`. Vérifier que les
   répertoires et fichiers sont des chemins système ordinaires appartenant à
   root, que le trousseau ne sert à aucune autre source et qu’il contient
   seulement l’ancienne clé attendue. Traiter à part toute configuration
   personnalisée, tout montage de fichier et tout client déjà migré.
2. Suspendre les opérations APT pendant le changement et prendre le verrou
   `/run/lock/pxe-tools-apt-install.lock` en tant que root. Sauvegarder le
   trousseau installé et ses attributs dans un emplacement root. Après une
   nouvelle vérification du SHA-256, préparer le fichier en mode `0644` dans
   le répertoire de destination et le mettre en place par renommage atomique.
   Laisser les témoins de création en place. Le remplacement du fichier change
   son inode : le désinstallateur peut donc préserver le trousseau modifié.
3. Vérifier `apt-get update` sur la seule source PXE Tools. Après la bascule,
   refaire le contrôle avec l’index signé par la nouvelle clé. Tester le
   téléchargement des paquets approuvés. Le raccourci d’installation refuse
   un trousseau déjà présent s’il diffère du fichier attendu. Pendant la
   transition, utiliser APT sur les clients migrés et valider séparément le
   parcours de la nouvelle installation.
4. En cas d’échec avant la bascule, suivre le retour arrière ci-dessous. Après
   la bascule, garder le trousseau de transition pendant le délai de retour
   arrière. Un client qui a manqué la préparation aura besoin du trousseau par
   un canal authentifié avant que `apt update` fonctionne à nouveau.

## Installation atomique sur le pilote

Après les contrôles de l’étape 1 et pendant le créneau convenu, exécuter depuis
le répertoire contenant le trousseau vérifié. Conserver le chemin de la
sauvegarde affiché à la fin.

```bash
sudo flock -n /run/lock/pxe-tools-apt-install.lock bash -s -- "$PWD/pxe-tools-transition.gpg" <<'BASH'
set -euo pipefail
target=/etc/apt/keyrings/pxe-tools-archive-keyring.gpg
source=/etc/apt/sources.list.d/pxe-tools.sources
expected=33eb2c01abd4ab5b9db21117f774dabafbb61103801529d7b9d00b80bfd97efb
for path in /etc/apt/keyrings /etc/apt/sources.list.d "$target" "$source"; do
    [[ ! -L "$path" && $(stat -c '%u' -- "$path") == 0 ]]
done
for path in "$target" "$source"; do
    [[ -f "$path" ]] && ! mountpoint -q -- "$path"
done
grep -Fx 'Signed-By: /etc/apt/keyrings/pxe-tools-archive-keyring.gpg' "$source"
backup=$(mktemp -d /var/backups/pxe-tools-keyring.XXXXXXXX)
echo "Sauvegarde du trousseau : $backup"
cp -p -- "$target" "$backup/original.gpg"
mkdir -m 0700 "$backup/gnupg"
gpg --homedir "$backup/gnupg" --batch --with-colons --with-subkey-fingerprint \
    --show-keys "$backup/original.gpg" > "$backup/key-metadata"
actual=$(awk -F: '$1 == "fpr" { print $10 }' "$backup/key-metadata")
[[ "$actual" == $'CD8B4493876B4FBD1009FE2DB195E5DE026807EC\n76CA2883C9DACEB52D9E806A1F2D96C9062271AB' ]]
! grep -Eq '^(sec|ssb):' "$backup/key-metadata"
staged=$(mktemp /etc/apt/keyrings/.pxe-tools-transition.XXXXXXXX)
trap '[[ ! -e "$staged" ]] || unlink "$staged"' EXIT
install -m 0644 -o root -g root -- "$1" "$staged"
printf '%s  %s\n' "$expected" "$staged" | sha256sum -c
cmp -s -- "$target" "$backup/original.gpg"
mv -T -- "$staged" "$target"
BASH

sudo apt-get -o 'Dir::Etc::sourcelist=/etc/apt/sources.list.d/pxe-tools.sources' \
  -o 'Dir::Etc::sourceparts=-' -o 'APT::Get::List-Cleanup=0' \
  -o 'APT::Update::Error-Mode=any' update
```

## Retour arrière sur le pilote

Suspendre à nouveau les opérations APT. Utiliser le chemin exact de la
sauvegarde root affiché lors de la migration. La restauration exige que
l’ancienne clé vérifie encore l’index public. Si le témoin de création existe
et correspond à la sauvegarde, la restauration réutilise son inode. Sur une
installation sans témoin, elle restaure le fichier et ses attributs.

```bash
backup=/var/backups/pxe-tools-keyring.REMPLACER
sudo flock -n /run/lock/pxe-tools-apt-install.lock bash -s -- "$backup" <<'BASH'
set -euo pipefail
backup=$1
target=/etc/apt/keyrings/pxe-tools-archive-keyring.gpg
expected=33eb2c01abd4ab5b9db21117f774dabafbb61103801529d7b9d00b80bfd97efb
[[ "$backup" =~ ^/var/backups/pxe-tools-keyring\.[[:alnum:]]+$ ]]
[[ -d "$backup" && ! -L "$backup" && $(stat -c '%u:%a' -- "$backup") == 0:700 ]]
for path in "$target" "$backup/original.gpg"; do
    [[ -f "$path" && ! -L "$path" && $(stat -c '%u' -- "$path") == 0 ]]
done
[[ ! -L /etc/apt/keyrings && $(stat -c '%u' -- /etc/apt/keyrings) == 0 ]]
! mountpoint -q -- "$target"
printf '%s  %s\n' "$expected" "$target" | sha256sum -c
work=$(mktemp -d /etc/apt/keyrings/.pxe-tools-rollback.XXXXXXXX)
trap 'if [[ -e "$work/key.gpg" ]]; then unlink "$work/key.gpg"; fi; if [[ -e "$work/InRelease" ]]; then unlink "$work/InRelease"; fi; rmdir "$work"' EXIT
curl --proto '=https' --fail --silent --show-error --max-time 30 \
  https://packages.pxetools.com/apt/dists/stable/InRelease -o "$work/InRelease"
gpgv --keyring "$backup/original.gpg" "$work/InRelease"
old_sha=$(sha256sum "$backup/original.gpg" | cut -d' ' -f1)
anchor="/etc/apt/keyrings/.pxe-tools-archive-keyring.${old_sha}.created"
if [[ -e "$anchor" || -L "$anchor" ]]; then
    [[ -f "$anchor" && ! -L "$anchor" && $(stat -c '%u' -- "$anchor") == 0 ]]
    ! mountpoint -q -- "$anchor"
    cmp -- "$backup/original.gpg" "$anchor"
    ln -- "$anchor" "$work/key.gpg"
else
    cp -p -- "$backup/original.gpg" "$work/key.gpg"
fi
mv -T -- "$work/key.gpg" "$target"
BASH

sudo apt-get -o 'Dir::Etc::sourcelist=/etc/apt/sources.list.d/pxe-tools.sources' \
  -o 'Dir::Etc::sourceparts=-' -o 'APT::Get::List-Cleanup=0' \
  -o 'APT::Update::Error-Mode=any' update
```

## Conditions avant publication

- Valider le client pilote, la distribution du trousseau et la procédure de
  rattrapage des clients hors ligne. Vérifier les conséquences sur
  l’installateur et le désinstallateur.
- Coordonner le changement d’empreinte de `pxetools.com` et l’URL publique du
  trousseau pour les nouvelles installations. Le site attend une seule
  primaire dans le fichier qu’il télécharge.
- Vérifier l’accès du jeton de `prod` au Worker, vérifier la correspondance du
  dernier instantané avec l’index public, contrôler `Valid-Until` et choisir
  un créneau de bascule. Conserver l’ancien instantané, la référence de la
  version Worker et les secrets de `production` pendant le délai de retour.
- Après accord de publication, fusionner la #82 et exécuter manuellement
  le workflow de publication avec `primary_transition=true` et
  `bootstrap=false`. La reprise normale attend déjà la nouvelle signature.
  Une exécution planifiée ne peut donc pas effectuer le premier changement.
  Entre la fusion et ce lancement manuel, le renouvellement échouerait devant
  l’ancienne signature. Enchaîner les deux pendant le créneau validé, avec une
  marge suffisante avant la valeur `Valid-Until` relevée ce jour-là.
- Contrôler la signature, le trousseau servi, les téléchargements depuis le
  dépôt et les clients pilotes. Vérifier le premier renouvellement normal
  avant d’engager le retrait de l’ancien environnement ou trousseau.
