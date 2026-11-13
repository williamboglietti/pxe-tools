# Sauvegarde et restauration en veille

Cette procédure native couvre une instance SQLite arrêtée. Elle produit une
archive OpenPGP chiffrée et authentifiée, puis prépare une restauration dans un
nouveau répertoire privé. La base complète est incluse : comptes, condensats,
inventaire, secrets, paramètres et historiques. La clé maîtresse qui protège
les secrets en base est incluse avec elle. Ces quatre catégories nécessitent
chacune une confirmation explicite et ne peuvent pas encore être dissociées.

Les fichiers HTTP, TFTP, les certificats TLS, les clés MOK et les données du
module Ansible sont des choix séparés. Chacun peut contenir des données
sensibles. Les surcharges systemd, les certificats stockés ailleurs, les anciens
MOK et les fichiers extérieurs aux chemins choisis ne sont pas inclus. Les paramètres
réseau locaux tels que `/var/lib/pxe-tools-native/network.env` sont à inventorier
et à reconfigurer séparément après examen, sans recopier aveuglément les chemins.
Inventoriez-les séparément ; ne considérez pas l'archive comme complète si
votre instance en dépend. MySQL/MariaDB ne sont pas pris en charge.

## Sauvegarder une instance native

Prévoyez une fenêtre d'arrêt et vérifiez qu'aucun autre programme n'écrit dans
la base ou les répertoires retenus. L'outil ne peut pas arrêter les services ni
prouver l'absence d'écrivain externe. Il refuse les journaux SQLite encore
présents et détecte certains changements pendant la capture. Les chemins
ci-dessous sont ceux par défaut ; adaptez-les si votre instance diffère.

Dans un terminal root :

```sh
apt install --no-install-recommends gpg gpg-agent openssl
install -d -m 0700 /root/pxe-backups
systemctl stop pxe-tools.target pxe-tools-admin.target pxe-tools-worker.service pxe-tools-dnsmasq-tail.service pxe-tools-nginx.service pxe-tools-pxe-http.service pxe-tools-php.service pxe-tools-dnsmasq.service pxe-tools-init.service
python3 /usr/lib/pxe-tools/app/cli/recovery-backup.py export \
  --db /var/lib/pxe-tools/pxe-tools.sqlite \
  --master-key /etc/pxe-tools/master.key \
  --output /root/pxe-backups/instance.gpg \
  --confirm-stopped --include-accounts --include-inventory \
  --include-secrets --include-master-key \
  --db-config /etc/pxe-tools/db.conf --include-db-config --confirm-db-config-matches \
  --mok-dir /var/lib/pxe-tools-native/mok --include-mok \
  --http-root /var/lib/pxe-tools-http --include-http \
  --tftp-root /var/lib/pxe-tools-tftp --include-tftp \
  --tls-root /var/lib/pxe-tools-native/tls --include-tls
```

L'exemple demande chaque composant ; retirez les deux options d'un composant
absent ou que vous ne voulez pas sauvegarder. Le chemin de sortie doit être
absolu, nouveau et situé dans un répertoire appartenant à l'utilisateur courant
et non modifiable par d'autres. La correspondance entre la base, sa clé et le
fichier de configuration reste à confirmer par l'administrateur. Sans MOK
restaurée, les clients Secure Boot déjà enrôlés peuvent nécessiter un nouvel
enrôlement. Le lien natif prédéfini `http/static-bin` est exclu : le paquet le
recrée au démarrage. Les alias netboot générés par les téléchargeurs sont conservés uniquement sous
la forme `netboot/<codename>/<arch> -> ../<distro>/<version>/<arch>` : la cible
doit être un répertoire inclus contenant `linux` et `initrd.gz`. Leur archive
utilise le format 4 ; les lecteurs plus anciens la refusent. Tous les autres
liens symboliques, les fichiers spéciaux et les permissions spéciales sont
refusés, à l’export comme à la restauration. Une ancienne archive contenant des
liens non pris en charge ne peut pas être restaurée par cette version ;
conservez la source et examinez ces liens avant une nouvelle sauvegarde.

Les chemins HTTP, MOK, TLS et TFTP indiqués sont ceux des nouvelles installations natives
protégées. Une installation antérieure peut encore utiliser respectivement
`/var/lib/pxe-tools/http`, `/etc/pxe-tools/mok`, `/var/lib/pxe-tools/tls` et `/var/lib/pxe-tools/tftp` : identifiez les chemins
effectivement configurés et sélectionnez-les explicitement à l'export. Aucune
donnée de l'ancienne instance n'est déplacée ni supprimée par la sauvegarde.

Pour inclure les données du complément Ansible natif, ajouter explicitement
`--ansible-root /var/lib/pxe-tools-ansible --include-ansible`. Cette sélection
inclut notamment les clés SSH, les playbooks et leurs variables. Pour Docker,
les volumes et montages du module peuvent être distincts et doivent être
inventoriés séparément ; cette commande n'est pas une migration Docker validée.

La phrase de passe est demandée sans affichage ; conservez-la séparément de
l'archive. Sa perte rend la sauvegarde irrécupérable. Pour automatiser, l'option
`--passphrase-fd N`, placée avant `export` ou `restore`, lit une ligne dans un
descripteur non terminal déjà protégé. Ne mettez jamais la phrase de passe dans
les arguments, l'historique ou les journaux. L'archive utilise OpenPGP AES-256
et son contrôle d'intégrité ; elle n'identifie pas son auteur.

L'arrêt étant volontaire, redémarrez les services nécessaires après la capture
selon votre configuration, par exemple `systemctl start pxe-tools-admin.target`
et, seulement si le réseau PXE doit être réactivé,
`systemctl start pxe-tools.target`. Vérifiez leur état et réalisez régulièrement un essai de
restauration isolé.

## Restaurer sur une installation native neuve

Ne restaurez jamais directement dans une instance active. Sur une Debian 13
amd64 neuve où PXE Tools n'est pas encore installé, préparez les deux répertoires
vides avant l'installation du paquet. Leur présence empêche son démarrage
automatique. Les commandes suivantes sont à exécuter dans un terminal root ;
elles s'arrêtent si l'un des chemins existe déjà :

```sh
set -eu
test ! -e /etc/pxe-tools && test ! -L /etc/pxe-tools
test ! -e /var/lib/pxe-tools && test ! -L /var/lib/pxe-tools
mkdir /etc/pxe-tools /var/lib/pxe-tools
apt install --no-install-recommends pxe-tools gpg gpg-agent
python3 /usr/lib/pxe-tools/native/configure-secure-tftp.py
install -d -m 0700 /root/pxe-restore
python3 /usr/lib/pxe-tools/app/cli/recovery-backup.py restore \
  --input /root/pxe-backups/instance.gpg \
  --output-dir /root/pxe-restore/standby
```

Le chemin d'entrée désigne la copie de l'archive transférée sur cette machine.
La restauration vérifie son authentification et l'intégrité SQLite avant de
publier le répertoire. Elle révoque les jetons d'actions et de sessions de boot,
interrompt les opérations en attente, désactive le DHCP proxy et impose une
nouvelle confirmation de l'assistant réseau. Elle désactive également Ansible
et son webhook en conservant l'URL du dépôt pour une éventuelle réactivation explicite. Elle
empêche aussi la relance automatique des anciennes publications GitHub en
échec, dont les journaux restent conservés. Elle
ne modifie pas l'archive, ne
remplace pas un chemin existant et ne démarre aucun service.
Si les fichiers HTTP n'ont pas été sélectionnés, les images enregistrées sont
désactivées et marquées en échec pour éviter d'annoncer des fichiers absents ;
elles doivent être vérifiées et retéléchargées avant utilisation.

Vérifiez le manifeste et les fichiers sélectionnés dans `standby`. Pour les
chemins natifs par défaut, et uniquement si les deux répertoires créés ci-dessus
sont toujours vides, installer la copie en veille :

```sh
set -eu
test -z "$(ls -A /etc/pxe-tools)" && test -z "$(ls -A /var/lib/pxe-tools)"
test -z "$(ls -A /var/lib/pxe-tools-http)" && test -z "$(ls -A /var/lib/pxe-tools-tftp)"
# Contrôler uniquement les arbres HTTP/TFTP effectivement sélectionnés :
python3 /usr/lib/pxe-tools/app/cli/check-restored-publication.py \
  --http /root/pxe-restore/standby/http --tftp /root/pxe-restore/standby/tftp
install -m 0600 /root/pxe-restore/standby/master.key /etc/pxe-tools/master.key
install -m 0600 /root/pxe-restore/standby/database.sqlite /var/lib/pxe-tools/pxe-tools.sqlite
# Si ces éléments ont été sélectionnés :
cp -a /root/pxe-restore/standby/http/. /var/lib/pxe-tools-http/
install -d -o root -g root -m 0700 /var/lib/pxe-tools-native/tls
install -o root -g root -m 0644 /root/pxe-restore/standby/tls/cert.pem /var/lib/pxe-tools-native/tls/cert.pem
install -o root -g root -m 0600 /root/pxe-restore/standby/tls/key.pem /var/lib/pxe-tools-native/tls/key.pem
cp -a /root/pxe-restore/standby/tftp/. /var/lib/pxe-tools-tftp/
mkdir -m 0700 /var/lib/pxe-tools-native/mok
cp -a /root/pxe-restore/standby/MOK.key /root/pxe-restore/standby/MOK.pem /root/pxe-restore/standby/MOK.der /var/lib/pxe-tools-native/mok/
cmp /root/pxe-restore/standby/MOK.der /var/lib/pxe-tools-native/mok/MOK.der
# Synchroniser les fichiers restaurés avant de démarrer ou redémarrer la machine.
sync
systemctl start pxe-tools-admin.target
systemctl is-active pxe-tools-init.service pxe-tools-php.service pxe-tools-nginx.service pxe-tools-worker.service
curl -fsS -o /dev/null -w "HTTP %{http_code}\n" http://127.0.0.1/
```

Le contrôle refuse les chemins ou fichiers non détenus par root, les liens autres
que les alias netboot validés ci-dessus, et
les permissions d'écriture accordées au groupe ou à d'autres utilisateurs, y
compris sur les répertoires parents. Pour TFTP seulement, il accepte l'écriture
de groupe sur `pxelinux.cfg` et `.pxe-menu.lock` : ces deux éléments sont repris
par le worker pour le groupe web lors du rendu. Les autres éléments doivent aussi
être lisibles, et les répertoires traversables, par les services web et TFTP. Il ne corrige rien : conservez l'archive et
examinez la copie en veille si un refus survient. Maintenez cette copie et ses
parents à l'abri des écritures non autorisées jusqu'à la fin de la copie.

Si un des composants optionnels n'a pas été exporté, adaptez le contrôle, la copie et
vérifiez ses conséquences avant de démarrer. La configuration SQLite archivée
peut contenir l'ancien chemin : elle est conservée pour inspection, mais n'est
pas recopiée dans cet exemple. Le démarrage régénère une configuration pour
le chemin natif par défaut. Un chemin personnalisé doit être vérifié et adapté
manuellement. L'outil conserve les permissions ordinaires des arbres, pas leurs
propriétaires, ACL, attributs étendus ou liens physiques. Vérifiez les droits et
la concordance entre les données et la clé avant de passer en production.
Le programme de préparation des chemins protégés refuse les répertoires ou le
marqueur existants : il ne doit pas être relancé sur une instance déjà installée.
Pour une ancienne MOK, comparer aussi l'empreinte du certificat exporté à celle
de l'instance d'origine ; une nouvelle clé ne doit pas être générée à sa place.

Si l'arbre `ansible` a été inclus, restaurez-le séparément après installation du
complément et vérification des chemins. Le compte du complément peut avoir un
identifiant différent sur la cible : attribuez les fichiers au compte prévu par
le module, sans réutiliser aveuglément des UID/GID numériques. Vérifiez en
particulier les permissions et l'usage des clés SSH avant de reprendre des tâches.

Utilisez sur la destination une version du paquet qui reconnaît les catégories
sélectionnées. Les versions précédentes peuvent refuser les archives contenant TLS,
Ansible ou les alias netboot ; conservez une copie du paquet compatible avec vos sauvegardes.

Connectez-vous avec le compte restauré, revoyez les paramètres réseau et les
fichiers de démarrage, puis confirmez explicitement toute activation PXE. Les
fichiers HTTP/TFTP sauvegardés peuvent contenir d'anciennes données générées et
doivent être examinés avant leur remise en service. Une migration guidée entre
Docker et une machine native, la bascule et le retour arrière automatiques ne
sont pas encore implémentés.

L'outil limite la base à 512 Mio et les arbres sélectionnés à 6 Gio et 20 000
entrées au total. Il utilise temporairement des copies en clair dans un
répertoire privé voisin de la sortie : une coupure brutale peut les laisser sur
disque, ainsi qu'un répertoire restauré partiel. Prévoir de l'espace et traiter
ces fichiers comme sensibles ; leur effacement physique n'est pas garanti.
