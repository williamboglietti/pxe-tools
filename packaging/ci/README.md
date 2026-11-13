# Publication APT par Worker

Le dépôt prévu est `https://packages.pxetools.com/apt/`, avec la clé publique à
`https://packages.pxetools.com/pxe-tools-archive-keyring.gpg` (empreinte
`CD8B4493876B4FBD1009FE2DB195E5DE026807EC`). Debian 13 amd64 est la seule
plateforme dont le parcours a été validé ; MySQL et MariaDB ne sont pas pris en
charge par le paquet natif.

Le paquet est construit sur main protégée après les tests applicatifs. La
release GitHub et `release-manifest.json` identifient le commit, le run et le
SHA-256 du paquet approuvé. Le workflow compare les octets à l'artefact de ce
run, puis signe un index APT valable sept jours avec la sous-clé de production.
Quand l'artefact de construction a expiré, le renouvellement exige une preuve
signée correspondante dans le dépôt précédemment publié.

L'offre des sources des utilitaires doit rester accessible sous les cinq assets
de la release publique `sources-2026-11-12` ; la CI télécharge et vérifie leurs
empreintes avant publication. Les deux grosses archives dépassent la limite de
25 MiB par asset statique de Cloudflare et ne sont donc pas copiées dans le
Worker. Les notices applicatives pointent directement vers cette release.

Avant la première publication, créer une release GitHub publique persistante
`apt-snapshots` et configurer dans l'environnement GitHub `production` les
secrets `APT_SIGNING_KEY`, `APT_SIGNING_PASSPHRASE`, `CLOUDFLARE_API_TOKEN` et
la variable `CLOUDFLARE_ACCOUNT_ID`. Le jeton Cloudflare doit être limité au
Worker de distribution et à la zone du domaine. La première exécution manuelle
utilise l'option `bootstrap` seulement après vérification de l'absence de dépôt
antérieur. Le cron renouvelle ensuite les mêmes octets tous les deux jours.

Chaque publication conserve au préalable un snapshot signé dans la release
`apt-snapshots`. Au renouvellement, la CI retrouve le snapshot correspondant
le plus récent et exige sa correspondance exacte avec `InRelease` public ; elle
valide sa signature, conserve ses paquets et
index by-hash SHA256 et SHA512 et interdit de réutiliser une version avec des
octets différents. Ne pas supprimer ces assets : ils sont nécessaires au
renouvellement et à la conservation des liens clients. Un run concurrent est
sérialisé ; les déploiements effectués hors CI ne le sont pas et peuvent bloquer
la reprise. Après un échec entre la sauvegarde et le déploiement, la CI refuse
de revenir automatiquement à un snapshot plus ancien : vérifier l'état effectif
du Worker avant toute intervention sur la release. Surveiller les échecs et agir
avant l'expiration de l'index.

Le déploiement associe la nouvelle version du Worker à ses assets. La propagation
mondiale n'est pas instantanée ; l'ancienne et la nouvelle version peuvent
coexister temporairement selon le centre de données. Conserver les anciennes
URLs immuables permet aux clients avec un ancien index de continuer à fonctionner.
Il faut vérifier les réponses publiques, puis tester `apt update`, l'installation,
l'interface et PXE sur les VM isolées avant annonce. La limite de 25 MiB
s'applique aussi à chaque futur paquet, et le nombre d'assets conservés est
limité par l'offre Cloudflare.
