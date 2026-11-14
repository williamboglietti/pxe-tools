# Configuration initiale interrompue après la première visite

Sur la bêta 4, une visite de l'interface avant l'assistant peut créer une base
SQLite vide. Au redémarrage suivant, le service peut créer `db.conf`, puis
échouer sur `presets.static_cidr absente`. Un fichier `db.conf` contenant
seulement un pilote SQLite et un chemin ne prouve pas qui l'a créé : la même
configuration peut avoir été fournie par un administrateur. La taille de la
base seule ne démontre pas non plus qu'elle ne contient aucune donnée.

Quand `db.conf` existe déjà et que l'initialisation échoue, le paquet conserve
la configuration et la base et refuse de démarrer. Il ne propose pas encore de
reprise automatique de ce cas. Ne supprimez ni ne remplacez `db.conf`, la base,
ses fichiers `-wal`, `-shm` ou `-journal`, la clé maîtresse ou les autres
fichiers persistants pour forcer le démarrage.

## Établir l'état et préserver l'original

1. Prévoyez une fenêtre d'arrêt, arrêtez les services PXE Tools et assurez-vous
   qu'aucun autre processus n'utilise la base. Inventoriez aussi les chemins
   des données, la configuration, la clé maîtresse, les certificats, la MOK,
   les journaux et les surcharges systemd. Ne publiez pas leurs contenus.
2. Conservez une copie protégée de l'ensemble de l'instance, configuration et
   fichiers SQLite auxiliaires compris. Vérifiez qu'elle est lisible et
   conservez le paquet installé. La procédure de [sauvegarde et restauration](backup-restore.md)
   décrit les catégories à inventorier ; son export peut refuser une base
   incomplète ou des journaux présents. Un tel refus ne doit pas être contourné
   en supprimant ces journaux.
3. Examinez sur une **copie isolée** le chemin sélectionné par `db.conf`, les
   fichiers auxiliaires et l'intégrité SQLite. Utilisez une ouverture en lecture
   seule ; si un journal ou un WAL non vide est présent, si l'intégrité échoue,
   si des objets de schéma existent ou si un processus peut écrire, arrêtez
   l'analyse et conservez tous les fichiers. Une inspection `immutable` qui
   ignore un WAL ne prouve pas l'absence de données dans ce WAL.

Par exemple, pour une copie montée sous `/mnt/pxe-inspection` avec les chemins
standards préservés, après arrêt des écrivains et examen du chemin enregistré,
la commande suivante refuse tout fichier auxiliaire et effectue l'inspection
SQLite sans écriture. Adaptez uniquement le répertoire de copie :

```sh
python3 - <<'PYTHON'
import os
from pathlib import Path
import sqlite3
import stat
from urllib.parse import quote

db = Path('/mnt/pxe-inspection/var/lib/pxe-tools/pxe-tools.sqlite')
for suffix in ('-wal', '-shm', '-journal'):
    if os.path.lexists(str(db) + suffix):
        raise SystemExit('Fichier auxiliaire présent : conserver et examiner.')
fd = os.open(db, os.O_RDONLY | os.O_NOFOLLOW)
try:
    before = os.fstat(fd)
    if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1:
        raise SystemExit('La base doit être un fichier ordinaire non lié.')
    uri = 'file:' + quote('/proc/self/fd/' + str(fd), safe='/') + '?mode=ro&immutable=1'
    with sqlite3.connect(uri, uri=True) as connection:
        integrity = connection.execute('PRAGMA integrity_check').fetchall()
        count = connection.execute('SELECT count(*) FROM sqlite_master').fetchone()[0]
    after = os.fstat(fd)
    fields = lambda s: (s.st_dev, s.st_ino, s.st_size, s.st_mtime_ns, s.st_ctime_ns)
    if (fields(before) != fields(after) or fields(after) != fields(os.stat(db, follow_symlinks=False))
            or any(os.path.lexists(str(db) + s) for s in ('-wal', '-shm', '-journal'))):
        raise SystemExit('Fichier ou journaux modifiés pendant l’inspection.')
    print('Intégrité :', integrity, '— objets de schéma :', count)
    if integrity != [('ok',)] or count != 0:
        raise SystemExit('La base ne répond pas aux critères de reprise vide.')
finally:
    os.close(fd)
PYTHON
```

Un résultat positif porte uniquement sur la copie et l'instant de l'inspection.
L'absence d'écrivain et la correspondance exacte avec l'original restent à
établir ; ne remplacez pas ces contrôles par le seul résultat de la commande.

Même si la copie révèle une base intègre sans objet et qu'aucun journal n'est
présent, ce constat ne prouve pas que les autres fichiers de l'instance sont
jetables. Comparez les résultats à votre inventaire et à vos sauvegardes.

## Reprendre manuellement une base démontrée vide

Cette procédure ne s'applique que si la configuration pointe vers la base
locale attendue, si l'inspection de la copie établit son intégrité et l'absence
de tout objet SQLite, et si aucun fichier `-wal`, `-shm` ou `-journal` n'est
présent. Elle demande une version corrigée contenant la reconnaissance des
bases initiales vides. Si une condition manque, conservez l'instance arrêtée
pour examen ; ne déduisez pas l'état de la base de sa taille. Il n'y a aucune
donnée SQLite à migrer dans ce cas, mais d'autres fichiers peuvent avoir de
la valeur.

Après la sauvegarde complète et les contrôles ci-dessus, choisissez un nouveau
répertoire de conservation privé, absent au départ. Cet exemple utilise
`/root/pxe-reprise-20261114` : adaptez son nom et vérifiez que le répertoire
parent n'est pas partagé. Les services doivent rester arrêtés et aucun autre
processus ne doit accéder à la base pendant cette étape.

```sh
sudo bash -eu <<'BASH'
test ! -e /root/pxe-reprise-20261114
test ! -L /root/pxe-reprise-20261114
install -d -m 0700 -o root -g root /root/pxe-reprise-20261114
test -f /etc/pxe-tools/db.conf
test ! -L /etc/pxe-tools/db.conf
sha256sum /etc/pxe-tools/db.conf /var/lib/pxe-tools/pxe-tools.sqlite
stat -c '%n %s %u:%g %a' /etc/pxe-tools/db.conf /var/lib/pxe-tools/pxe-tools.sqlite
mv -n /etc/pxe-tools/db.conf /root/pxe-reprise-20261114/db.conf
test ! -e /etc/pxe-tools/db.conf
test ! -L /etc/pxe-tools/db.conf
sha256sum /root/pxe-reprise-20261114/db.conf /var/lib/pxe-tools/pxe-tools.sqlite
stat -c '%n %s %u:%g %a' /root/pxe-reprise-20261114/db.conf /var/lib/pxe-tools/pxe-tools.sqlite
BASH
```

Comparez les empreintes, tailles, propriétaires et modes relevés avant et
après ; en cas de différence ou d'erreur, arrêtez la procédure et gardez les
copies pour examen. Cette opération met seulement la configuration hors de son
chemin actif ; elle ne supprime ni n'initialise la base. Conservez le fichier
archivé et la sauvegarde complète. Relancez l'administration, contrôlez
individuellement les services et ouvrez l'assistant :

```sh
sudo systemctl start pxe-tools-admin.target
sudo systemctl status pxe-tools-init.service pxe-tools-php.service pxe-tools-nginx.service pxe-tools-worker.service
```

L'initialisation de la base intervient lors de la validation explicite dans
l'assistant. Vérifiez ensuite le redémarrage des services et la configuration
avant toute activation PXE. Ne remettez pas l'ancienne configuration par-dessus
une nouvelle ; tout retour arrière doit être préparé depuis la sauvegarde,
services arrêtés, sans écrasement non vérifié.

Ce parcours a été essayé sur une copie hors ligne d'une VM Ubuntu 24.04 :
une configuration de 77 octets a été déplacée dans un répertoire root privé ;
son empreinte et ses droits ont été conservés. L'assistant a initialisé la
base après confirmation, puis les services et le rendu au démarrage ont réussi.
La VM d'origine est restée arrêtée et inchangée. Le test ne valide ni les autres
distributions, ni une base contenant des données, ni un démarrage PXE complet.

## Reprendre sans modifier l'instance d'origine

Si l'inventaire confirme que vous souhaitez repartir de zéro, préparez une
**instance neuve et isolée**, sur une autre VM ou un autre disque, avec un
paquet corrigé dont la version et la provenance ont été vérifiées. Ne recopiez
pas automatiquement l'ancien `db.conf`, la base, la clé maîtresse, la MOK ou
la configuration réseau : leur réutilisation et la migration des autres
données nécessitent une procédure adaptée, qui n'est pas encore validée pour
ce cas. Lancez l'assistant de la nouvelle instance et vérifiez ses services
avant de décider de son raccordement au réseau PXE.

Conservez l'original arrêté et sa copie protégée pour examen ou retour arrière.
Cette reprise crée une instance distincte ; elle ne restaure ni les comptes,
machines, paramètres ou clés de l'ancienne. La correspondance des clés et
secrets et le transfert sélectif ne sont pas établis par le constat d'une base
vide. La procédure n'a pas encore été exécutée de bout en bout pour ce cas.
