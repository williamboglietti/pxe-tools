# Installation native et exploitation

Le paquet natif vise actuellement Debian 13 amd64 avec systemd. Il installe
l'application et ses dépendances sans Docker ni Ansible. L'installation APT,
l'assistant, une installation Debian PXE et Secure Boot ont été vérifiés sur
un banc Hyper-V isolé ; le matériel physique et les autres distributions ne
sont pas encore validés.

## Premier démarrage et réseau

Sur une machine sans données PXE Tools existantes, le paquet démarre
l'administration sur HTTP 8080. Le serveur HTTP PXE et le proxy DHCP ne sont
pas démarrés avant la confirmation de la configuration réseau dans l'assistant.
L'administrateur doit choisir l'interface et le sous-réseau adaptés et s'assurer
que son réseau dispose d'un serveur DHCP compatible.

Le serveur HTTP PXE utilise TCP 80. Si le nginx installé par Debian ou un autre
service occupe ce port, l'assistant signale le conflit. L'action proposée pour
`nginx.service` vérifie l'identité des processus et nécessite une confirmation
explicite : elle arrête ce service et désactive son démarrage automatique. Les
sites qui en dépendent deviennent alors indisponibles. Sa configuration reste en
place. Aucun autre service n'est arrêté automatiquement ; en présence d'un autre
occupant, résolvez le conflit avant d'activer le réseau PXE.

Les deux services nginx natifs stockent leurs fichiers temporaires dans leurs
répertoires respectifs sous `/run`. Ce répertoire peut être en mémoire (tmpfs) :
des transferts simultanés mis en tampon peuvent donc consommer de la mémoire et
remplir l'espace disponible, avec des erreurs de requête à la clé. Vérifiez la
taille et l'utilisation de `/run` selon la charge prévue ; la consommation sous
charge concurrente n'a pas été mesurée.

L'assistant prépare une clé MOK propre à l'instance pour signer GRUB. Pour un
client Secure Boot, l'administrateur doit enrôler la clé publique de cette
instance dans le client. Le test documenté utilise une MOK jetable et un client
Hyper-V ; la procédure d'enrôlement varie selon le matériel.

## Mise à niveau et conservation des données

La mise à niveau est refusée si les services PXE Tools sont encore actifs. Avant
de commencer, prévoyez une fenêtre d'arrêt, une sauvegarde chiffrée vérifiée et
conservez le paquet de la version précédente ainsi que les chemins et la
configuration existants pour préparer un éventuel retour arrière. La remise en
service et le retour arrière doivent être vérifiés pour votre instance.

Arrêtez les services dans la fenêtre de maintenance, puis mettez le paquet à
niveau :

```sh
sudo systemctl stop pxe-tools.target pxe-tools-admin.target pxe-tools-worker.service pxe-tools-dnsmasq-tail.service pxe-tools-nginx.service pxe-tools-pxe-http.service pxe-tools-php.service pxe-tools-dnsmasq.service pxe-tools-init.service
sudo apt install --only-upgrade pxe-tools
```

Une installation neuve reçoit les chemins protégés décrits ci-dessous lors de
la configuration du paquet. Pour une instance existante, la mise à niveau ne
crée ni ne migre automatiquement ces chemins. Si elle utilise encore des
répertoires ou des parents modifiables par le service web, l'initialisation
refuse de démarrer. Ne relancez pas les services avant d'avoir examiné les
chemins et la configuration et préparé une reprise adaptée. La migration en
place n'est pas encore validée ; conservez les données et chemins d'origine et
ne les écrasez pas pour tenter de forcer le démarrage.

Après une reprise préparée et vérifiée pour votre instance, contrôlez
individuellement les services utilisés, notamment `pxe-tools-init.service`,
`pxe-tools-php.service`, `pxe-tools-nginx.service` et
`pxe-tools-worker.service`, avec `systemctl status` et leurs journaux. L'état
actif de `pxe-tools-admin.target` ne suffit pas à confirmer leur démarrage.
Vérifiez aussi `pxe-tools-pxe-http.service` et `pxe-tools-dnsmasq.service` si
le réseau PXE doit être réactivé. Ne réactivez ce réseau qu'après contrôle et
confirmation explicite.

La suppression et la purge préservent les données sous `/var/lib/pxe-tools`, la
configuration et les clés sous `/etc/pxe-tools`, ainsi que les journaux. Une
sauvegarde cohérente doit couvrir ensemble la base, la clé de chiffrement, les
fichiers persistants et, si elle doit être réutilisée, la clé MOK. Une procédure
[de sauvegarde chiffrée et de restauration en veille](backup-restore.md) est
disponible pour SQLite ; la migration guidée reste à développer.

SQLite est la seule base prise en charge. Une configuration MySQL/MariaDB
existante est refusée avant connexion ; elle et ses données sont conservées,
sans migration automatique vers SQLite.

### Chemins natifs protégés et instances existantes

Sur une nouvelle installation, le TFTP se trouve dans
`/var/lib/pxe-tools-tftp`, les fichiers HTTP dans `/var/lib/pxe-tools-http`,
les rendus dans `/var/lib/pxe-tools-native/rendered` et la MOK dans
`/var/lib/pxe-tools-native/mok`.
Le fichier root `/var/lib/pxe-tools-native/runtime.env` transmet ces chemins
aux unités systemd. Le programme de préparation refuse tout chemin existant :
aucune donnée n'est remplacée, déplacée ou supprimée automatiquement.

Le répertoire `pxelinux.cfg` reste accessible en écriture au groupe du service
web pour supprimer immédiatement le menu d'une machine effacée ou fusionnée.
Le rendu du worker et ces suppressions sont coordonnés par un verrou protégé ;
les fichiers générés sont remplacés sans suivre de lien de destination. Un
compte web compromis peut néanmoins modifier les menus BIOS présents dans ce
répertoire : limitez l'accès à l'interface et protégez le réseau PXE. Les
chargeurs et les autres répertoires de la racine TFTP restent protégés.
Le fichier de verrou `.pxe-menu.lock`, placé à la racine TFTP et détenu par
root, coordonne les opérations concurrentes. Ne le supprimez pas pendant le
fonctionnement : son remplacement créerait un verrou différent de celui déjà
utilisé par les traitements en cours. En cas d'erreur de verrouillage,
contrôlez son propriétaire et les journaux des services avant toute intervention.

Une instance héritée sans ce marqueur conserve ses anciens chemins. Si ses
répertoires TFTP, HTTP, de rendus ou MOK, ou leurs parents, sont modifiables par
le service web, l'initialisation et les tâches natives refusent les écritures
privilégiées jusqu'à leur migration explicite.
Une migration demande une fenêtre d'arrêt et une sauvegarde chiffrée vérifiée
selon [la procédure de restauration](backup-restore.md), qui décrit une cible
neuve et ne constitue pas une procédure de migration en place validée.
Préparez une copie dans des emplacements protégés, sans écraser les anciens ; confirmez
la correspondance clé privée/certificat et l'empreinte de la MOK avec une
référence administrateur indépendante ou les clients déjà enrôlés. L'ancien
parent étant modifiable par le service web, sa seule présence n'établit pas
l'authenticité de sa MOK. Vérifiez les données restaurées et gardez les chemins
d'origine pour le retour arrière ; ne réactivez le PXE qu'après confirmation
explicite. Ne régénérez pas la MOK pour cette migration : les clients devraient
alors être réenrôlés.

## Proxy inverse

Le contrôle d'origine des formulaires accepte `X-Forwarded-Host` uniquement
lorsque le pair réseau qui se connecte à PHP figure dans la liste explicite
`AUTHENTIK_TRUSTED_PROXY_CIDRS`. Cette règle s'applique aussi à l'authentification
locale. La liste accepte des adresses ou CIDR IPv4/IPv6 séparés par des virgules ;
elle ne doit pas désigner les clients. Une liste vide, invalide ou attrape-tout
n'autorise aucun proxy.

Pour la configuration native, créez un override avec
`sudo systemctl edit pxe-tools-php.service`, en adaptant par exemple :

```ini
[Service]
Environment="AUTHENTIK_TRUSTED_PROXY_CIDRS=192.0.2.7/32"
```

Le proxy autorisé doit supprimer ou remplacer les en-têtes `X-Forwarded-Host`,
`X-Forwarded-Proto` et `X-Forwarded-User` fournis par ses clients. Limitez aussi
l'accès direct aux ports d'administration. Les essais couvrent les proxys
simulés ; ils ne constituent pas une validation d'un fournisseur SSO réel.


## Fraîcheur des métadonnées Debian netboot

Sur Ubuntu 24.04 avec le paquet `debian-archive-keyring` version
`2023.4ubuntu1`, le worker utilise une copie du trousseau Debian 2025.1 fournie
par PXE Tools, car la version Ubuntu ne reconnaît pas toutes les signatures
actuelles de Debian 13. Le [détail de sa provenance et de sa maintenance](../packaging/native/keyrings/README.md)
est conservé avec le trousseau. Un trousseau configuré explicitement via
`DEBIAN_ARCHIVE_KEYRING` reste prioritaire ; le système utilise sinon son propre
trousseau pour les autres versions du paquet. Aucun échec de signature n'est
contourné. La prise en charge complète d'Ubuntu reste à valider avant annonce.

Pour le démarrage de clients Debian depuis un hôte Ubuntu, le shim Ubuntu ne
fait pas confiance à la signature Debian du noyau. Le worker prépare donc un
couple shim et MokManager Debian, sans installer leurs paquets ni modifier les
sources APT du système : il utilise un état APT temporaire et un trousseau
Debian explicite, valide les métadonnées et paquets, exige que les deux paquets
aient été construits avec la même version de shim, puis compare le shim au
fichier netboot couvert par les sommes de l'installateur Debian. Une erreur de
signature, de fraîcheur, de contenu ou de correspondance interrompt la mise à
jour avant remplacement du couple précédent. Pendant le bref remplacement des
deux fichiers, un client pourrait subir un échec de démarrage ; il ne faut pas
désactiver Secure Boot pour contourner cet échec. Si les archives Debian ne
sont pas disponibles, le renouvellement du couple ne peut pas être vérifié.

Avant de servir les fichiers de démarrage Debian, PXE Tools vérifie la
signature de `InRelease`, la fraîcheur des champs authentifiés, puis les
empreintes des fichiers. Si `Valid-Until` existe, une date expirée est
refusée. Si ce champ est absent, les métadonnées dont la date dépasse 180 jours
sont refusées. Une date située à plus de cinq minutes dans le futur, absente,
malformée ou dupliquée est également refusée.

Cette vérification exige une horloge système fiable. La limite de 180 jours
est une politique de PXE Tools : Debian ne garantit pas qu'une suite stable
sera actualisée dans ce délai. Si Debian ne republie pas à temps les
métadonnées stables, la récupération de l'image échouera jusqu'à leur mise à
jour ou à une évolution réfléchie de cette politique. Ce contrôle réduit la
fenêtre de rejeu sans garantir qu'une métadonnée signée encore acceptée
correspond à la toute dernière version disponible.


Le service HTTP PXE natif écoute uniquement sur l’adresse IPv4 `pxe_ip`
configurée pour l’interface PXE, ainsi que sur `127.0.0.1` pour les rappels
locaux de l’installation. Avant le démarrage, il vérifie que cette
adresse est effectivement présente sur l’interface, avec une attente maximale
de 30 secondes ; sinon, le démarrage refuse d’exposer les fichiers et le service DHCP/TFTP reste arrêté. Après
tout changement de réseau, appliquer la configuration via l’interface pour
recréer l’écoute. Un autre service lié à toutes les adresses sur le port 80
peut empêcher le démarrage : inspecter le conflit et le résoudre explicitement.
La liaison à une adresse ne filtre pas l’interface d’arrivée : un client d’un
autre réseau peut joindre cette adresse si elle est routée. Si l’adresse est
déplacée après le démarrage, le service ne le détecte pas automatiquement.
Utilisez un pare-feu restrictif et réappliquez la configuration après tout
changement réseau pour maintenir l’isolation souhaitée.

Pour une configuration héritée sans clés `pxe_interface` ou `pxe_ip` dans
SQLite, les trois services natifs HTTP PXE, dnsmasq et worker lisent également
le fichier optionnel protégé `/var/lib/pxe-tools-native/network.env`. Il peut contenir par exemple
`PXE_INTERFACE=eth1` et `PXE_IP=192.0.2.1` ; l’ancien nom `ORCHESTRATOR_IP`
reste accepté pour l’adresse. Une clé présente dans SQLite, même vide, prime
sur cet environnement. Un ancien override systemd attaché à un seul service
n’est pas transmis automatiquement aux autres : reporter ses valeurs réseau
dans ce fichier commun. Sans valeurs cohérentes le service HTTP refuse de
démarrer.

L'ancien fichier `/etc/pxe-tools/network.env` n'est plus chargé par les unités :
son répertoire parent est modifiable par le service web et ne peut pas servir
de source fiable pour l'environnement d'un processus privilégié. Après arrêt,
l'administrateur doit examiner les anciennes valeurs et reporter explicitement
uniquement les réglages requis dans le nouveau fichier root. Ne recopiez pas
automatiquement un fichier dont l'origine ou le contenu sont incertains.
