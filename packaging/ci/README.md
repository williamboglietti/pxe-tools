# Chaîne de publication APT

Le dépôt de production prévu est `https://packages.pxetools.com/apt/`. La clé
publique est distribuée à `https://packages.pxetools.com/pxe-tools-archive-keyring.gpg` ; son empreinte principale attendue est
`CD8B4493876B4FBD1009FE2DB195E5DE026807EC`. Les clients doivent vérifier cette
empreinte par un canal indépendant avant d'utiliser `Signed-By`.

La cible dont le parcours complet a été testé est Debian 13 amd64. Ne pas
présenter Ubuntu ou les machines physiques comme validés sur la seule base de
ces essais. MySQL/MariaDB ne sont pas pris en charge dans ce paquet ; une
configuration historique est conservée mais le démarrage est refusé.

## Première version

1. Fusionner sur `main` protégée le code applicatif audité, les tests et la
   version `packaging/ci/release-version.txt`. Lancer manuellement `Build
   reviewed APT package` sur ce commit C. Ce workflow appelle les tests de
   comportement avant de construire et conserver l'artefact `pxe-debian-package`.
2. Télécharger cet artefact, vérifier son contenu, son empreinte SHA-256 et son
   comportement réel. Créer un tag de release vers C et une release GitHub en
   brouillon ; y joindre exactement le fichier `.deb` issu du run. La version
   Debian interne est `0.1.0~beta1` et le nom d'asset compatible GitHub est
   `pxe-tools_0.1.0-beta1_amd64.deb`.
3. Après validation des droits et du contenu exact, publier la release bêta.
   Ajouter à `main` protégée `packaging/ci/release-manifest.json`, suivant
   `release-manifest.example.json` : version, tag, commit C complet, numéro du
   run de construction et SHA-256 exact du fichier .deb. Ce manifeste est une
   approbation de ces octets ; ne pas y recopier une valeur non vérifiée.
4. Publier d'abord la release source `sources-2026-11-12` contenant les cinq
   fichiers conformes à `static-helpers-sources.json`. Le workflow source seul
   ne publie aucun paquet ni métadonnée APT. Vérifier que l'offre est accessible
   à `https://packages.pxetools.com/sources/static-helpers/2026-11-12/`.
5. Lancer `Publish signed APT repository` sur `main`. Il exige une release et
   un run de construction réussis au commit C, compare le `.deb` à l'artefact
   du run tant qu'il est disponible, signe l'index avec la sous-clé dédiée et
   vérifie l'offre des sources et la clé par HTTPS avant de publier `InRelease`.
   Tester ensuite `apt update`, installation, interface et PXE sur les VM de
   laboratoire isolées avant d'annoncer la version.

Les secrets `APT_SIGNING_KEY` et `APT_SIGNING_PASSPHRASE` appartiennent à
l'environnement `production`. Le job de publication R2 reçoit uniquement
`R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY` et la variable `R2_ENDPOINT` ; son
jeton doit être limité aux objets du bucket `pxe-tools-packages`.

## Renouvellement et conservation

La CI ne reconstruit pas le paquet lors d'un renouvellement : elle retélécharge
l'asset approuvé, vérifie son digest épinglé et sa version interne. Elle signe
un `Release` valable sept jours, avec le SHA du paquet, le commit et le run de
construction comme preuve signée. Le cron se déclenche tous les deux jours.
Lorsque l'artefact temporaire du run a expiré, la preuve de provenance et
l'index by-hash d'une publication précédente doivent être vérifiés avant de
renouveler. La suppression du run de construction ou de la release GitHub, une
clé révoquée ou un blocage du domaine peut arrêter le renouvellement ; surveiller
les échecs du workflow et intervenir avant expiration.

Les paquets, sources et index by-hash sont créés sous des noms immuables et ne
sont jamais supprimés par ce workflow. Un registre lie chaque version Debian
au SHA-256 de son paquet ; une tentative de réutiliser cette version pour
d'autres octets est rejetée. `InRelease` est écrit en dernier avec une condition
sur sa version précédente. Une concurrence ou une erreur de transfert peut
faire échouer la publication sans rendre l'ancien `InRelease` invalide. Les
clients anciens qui utilisent `Release`/`Release.gpg` peuvent temporairement
voir une incompatibilité entre ces deux objets pendant leur remplacement et
refuser la mise à jour ; ils ne doivent pas ignorer l'erreur de signature.

Le domaine R2 doit contourner les caches Cloudflare, y compris les réponses 404 ;
un `Cache-Control` sur les objets ne suffit pas à neutraliser une règle de cache
plus prioritaire. Une copie d'un objet déjà en cache peut sinon rester ancienne.
Le workflow vérifie l'accès HTTPS public aux sources et à la clé avant la mise
à jour ; il ne remplace pas un test APT externe après publication.
