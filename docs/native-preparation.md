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

L'assistant prépare une clé MOK propre à l'instance pour signer GRUB. Pour un
client Secure Boot, l'administrateur doit enrôler la clé publique de cette
instance dans le client. Le test documenté utilise une MOK jetable et un client
Hyper-V ; la procédure d'enrôlement varie selon le matériel.

## Mise à niveau et conservation des données

La mise à niveau est refusée si les services PXE Tools sont encore actifs. Dans
une fenêtre de maintenance, arrêtez-les avant la mise à niveau, puis redémarrez les
services appropriés une fois l'opération terminée :

```sh
sudo systemctl stop pxe-tools.target pxe-tools-admin.target pxe-tools-worker.service pxe-tools-dnsmasq-tail.service pxe-tools-nginx.service pxe-tools-pxe-http.service pxe-tools-php.service pxe-tools-dnsmasq.service pxe-tools-init.service
sudo apt install --only-upgrade pxe-tools
sudo systemctl start pxe-tools-admin.target
# Si le réseau PXE avait déjà été configuré et doit être réactivé :
sudo systemctl start pxe-tools.target
```

La suppression et la purge préservent les données sous `/var/lib/pxe-tools`, la
configuration et les clés sous `/etc/pxe-tools`, ainsi que les journaux. Une
sauvegarde cohérente doit couvrir ensemble la base, la clé de chiffrement, les
fichiers persistants et, si elle doit être réutilisée, la clé MOK. Une procédure
[de sauvegarde chiffrée et de restauration en veille](backup-restore.md) est
disponible pour SQLite ; la migration guidée reste à développer.

SQLite est la seule base prise en charge. Une configuration MySQL/MariaDB
existante est refusée avant connexion ; elle et ses données sont conservées,
sans migration automatique vers SQLite.

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
le fichier optionnel `/etc/pxe-tools/network.env`. Il peut contenir par exemple
`PXE_INTERFACE=eth1` et `PXE_IP=192.0.2.1` ; l’ancien nom `ORCHESTRATOR_IP`
reste accepté pour l’adresse. Une clé présente dans SQLite, même vide, prime
sur cet environnement. Un ancien override systemd attaché à un seul service
n’est pas transmis automatiquement aux autres : reporter ses valeurs réseau
dans ce fichier commun. Sans valeurs cohérentes le service HTTP refuse de
démarrer.
