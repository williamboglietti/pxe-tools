# Module Ansible natif

Le socle PXE Tools ne dépend pas d’Ansible. Le paquet additionnel
`pxe-tools-ansible` utilise `ansible-core` fourni par la distribution : aucun
conteneur supplémentaire n’est nécessaire. Le worker existant lance les
playbooks sous le compte système non privilégié `pxe-tools-ansible`. Son
répertoire personnel et son espace de travail sont sous
`/var/lib/pxe-tools-ansible` et restent présents après purge du module.

Après avoir configuré le dépôt APT selon le [README](../../README.md#installation-avec-apt), installez le paquet facultatif :

```sh
sudo apt update
sudo apt install pxe-tools-ansible
sudo systemctl restart pxe-tools-worker.service
```

L’installation ne démarre ni ne redémarre le worker et n’active pas les
playbooks. Dans l’interface, ouvrez les paramètres Ansible, préparez les
playbooks et leurs accès, puis activez explicitement le module. Le worker
observe périodiquement le runtime ; il le vérifie à nouveau avant l’exécution.
Pour mettre à niveau ou retirer le module, arrêtez d’abord le worker :

```sh
sudo systemctl stop pxe-tools-worker.service
sudo apt remove pxe-tools-ansible
sudo systemctl start pxe-tools-worker.service
```

L’installation du module peut installer `ansible-core` depuis les dépôts de
la distribution configurés et authentifiés séparément. Sa suppression conserve
l’espace de travail et ne demande pas la suppression automatique d’Ansible Core.
Pour compiler localement un candidat sans le publier :

```sh
python3 packaging/native/build-local-ansible-package.py /chemin/absolu/nouveau.deb --release --version 0.1.0~beta2
```

La vérification du runtime refuse un exécutable Ansible non attribué au paquet
attendu et une base SQLite ou une clé principale accessible au compte
Ansible. Les permissions d’une base existante ne sont pas modifiées
implicitement. **Ces contrôles ne constituent pas un bac à sable** : un
playbook est du code de confiance, qui peut agir avec les droits et accès du
compte. Les journaux peuvent contenir des secrets ; utilisez `no_log` pour les
tâches sensibles. Préparez séparément les clés SSH et les identités d’hôtes
vérifiées. Le déverrouillage Vault dans l’interface est à usage unique et est
requis pour chaque exécution dans le parcours actuel.

Le paquet refuse un compte préexistant dont le groupe principal n’est pas le
groupe dédié ou qui possède des groupes supplémentaires ; il ne modifie pas
ce compte. Cette vérification ne détecte pas tous les droits accordés par des
ACL, règles sudoers, polices d’accès ou services déjà présents :
l’administrateur doit vérifier ces accès sur une machine existante.

Les collections, les installations sur des cibles SSH réelles et les autres
versions de distribution nécessitent leurs propres validations. Le module et
le socle doivent être compatibles : ce paquet demande la branche 0.1 du socle.
