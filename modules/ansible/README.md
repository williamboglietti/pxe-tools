# Ansible facultatif

Ce document décrit le déploiement Docker. Une intégration native expérimentale
par paquet additionnel est décrite dans [NATIVE.md](NATIVE.md).

Le socle ne contient ni Ansible Core ni collections. Ce module prépare un environnement Python dans un volume persistant distinct ; l’appliance existante le monte en lecture seule et son worker lance les playbooks sous le compte local non privilégié `ansible`. **Le déploiement utilise toujours un seul conteneur permanent.** L’image de préparation ci-dessous ne sert qu’aux commandes ponctuelles et s’arrête après chacune d’elles.

Un playbook est du code de confiance : il peut agir sur les machines et les fichiers accessibles à son compte. Un volume n’est pas une barrière d’isolation et ne remplace pas la revue des playbooks, des accès et des secrets. La sortie des tâches peut exposer des secrets dans les journaux ; les playbooks doivent masquer les tâches sensibles, notamment avec `no_log`. L’interface ne crée pas elle-même le runtime et n’a pas accès au socket Docker.

## Préparer un environnement

L’administrateur fournit un répertoire d’archives `.whl` compatibles et un fichier `requirements.lock`. Chaque ligne doit figer exactement une distribution et son empreinte SHA-256, y compris toutes les dépendances transitives :

```text
ansible-core==VERSION_EXACTE --hash=sha256:EMPREINTE_HEXADECIMALE_DE_64_CARACTERES
```

Les archives et le fichier de verrouillage doivent avoir été authentifiés indépendamment avant utilisation. Une empreinte calculée à partir de l’archive téléchargée seule ne prouve pas sa provenance. Aucun verrouillage de production ni collection n’est fourni ici. La préparation refuse les liens, les sources et les dépendances absentes ; elle n’accède pas au réseau lors de l’installation.

Depuis la racine du projet, construire l’image de préparation et créer un volume séparé :

```console
docker build -f modules/ansible/Dockerfile -t pxe-tools-ansible-prepare:local .
docker volume create pxe-tools-ansible-runtime
```

Le compte `ansible` de l’image de préparation et celui de l’appliance doivent avoir le même UID et GID ; leurs valeurs par défaut sont `1001:1001`. Le chemin du volume doit rester `/var/lib/pxe-ansible`, car les scripts Python de l’environnement mémorisent ce chemin.

Après avoir remplacé le chemin de la source par votre répertoire d’archives :

```console
docker run --rm --network none --read-only --tmpfs /tmp:rw,exec \
  --cap-drop ALL --security-opt no-new-privileges \
  -v pxe-tools-ansible-runtime:/var/lib/pxe-ansible \
  -v /chemin/absolu/vers/wheelhouse:/wheelhouse:ro \
  pxe-tools-ansible-prepare:local install --wheelhouse /wheelhouse
```

La commande affiche un identifiant `env-…`. Vérifier le résultat puis sélectionner explicitement cet environnement :

```console
docker run --rm --network none --read-only --tmpfs /tmp:rw,exec \
  --cap-drop ALL --security-opt no-new-privileges \
  -v pxe-tools-ansible-runtime:/var/lib/pxe-ansible \
  pxe-tools-ansible-prepare:local activate IDENTIFIANT
docker run --rm --network none --read-only --tmpfs /tmp:rw,exec \
  --cap-drop ALL --security-opt no-new-privileges \
  -v pxe-tools-ansible-runtime:/var/lib/pxe-ansible \
  pxe-tools-ansible-prepare:local status
```

Le gestionnaire vérifie notamment les permissions, l’interpréteur, la version de Python et l’architecture. Il ne supprime pas les anciens environnements ; une autre version installée peut être sélectionnée pour revenir en arrière. Une mise à jour de l’image ou de Python peut rendre un environnement incompatible et nécessiter une nouvelle préparation. Ces vérifications ne constituent pas une attestation de tous les fichiers installés.

## Raccorder le volume à l’appliance

Définir explicitement l’image locale de l’appliance et le volume préparé, puis ajouter le fichier Compose facultatif :

```console
export PXE_TOOLS_IMAGE=IMAGE_LOCALE_CHOISIE
export PXE_ANSIBLE_VOLUME=pxe-tools-ansible-runtime
docker compose -f compose/docker-compose.yml -f compose/ansible-runtime.yml up -d
```

Le volume runtime est monté en lecture seule dans le service `pxe-tools` existant. Un volume de travail séparé conserve le dépôt et les playbooks sous `/home/ansible/ansible`. L’exécution dépend toujours de l’activation explicite dans les paramètres Ansible et du rôle demandé pour la machine. L’interface affiche une observation récente du worker ; celui-ci revérifie le runtime au lancement. Un worker occupé ou arrêté peut rendre l’état affiché indéterminé.

L’URL Git peut être laissée vide pour utiliser des playbooks déjà placés dans le volume de travail, sans synchronisation. Les fichiers doivent être accessibles au compte `ansible`. Lorsque l’URL est renseignée, le worker refuse la synchronisation et l’exécution si l’origine Git effective ou la branche du clone ne correspond pas exactement aux paramètres. La publication des modèles exige aussi que la destination effective de publication soit identique à l’URL configurée. Les réécritures d’URL, plusieurs origines ou une URL de publication distincte peuvent donc nécessiter une configuration explicite différente. La publication avec jeton GitHub est refusée si des réécritures Git sont configurées, ou si le dépôt SSH ne vise pas GitHub. Changer l’URL dans l’interface ne remplace pas le clone : il faut préserver les éventuels changements locaux et préparer un clone correspondant. Le clonage initial est tenté au démarrage du worker, pas lors de l’enregistrement du formulaire ; l’approvisionnement initial et les accès Git doivent être préparés séparément. Le paramètre `ANSIBLE_WORKDIR` du worker détermine le chemin effectivement utilisé ; en cas de personnalisation, le chemin de diagnostic de l’interface doit lui correspondre.

Pour des connexions SSH, préparer séparément un dossier privé appartenant au compte `ansible` avec la clé `ansible_automation` et un `known_hosts` contenant uniquement les identités vérifiées. Ajouter explicitement le montage :

```console
export PXE_ANSIBLE_SSH_DIR=/chemin/absolu/vers/ssh-verifie
docker compose -f compose/docker-compose.yml -f compose/ansible-runtime.yml \
  -f compose/ansible-ssh.yml up -d
```

Le montage SSH est en lecture seule. Les hôtes inconnus sont refusés ; ne recopiez pas aveuglément une clé obtenue par le réseau. Le clonage Git peut nécessiter sa propre identité et ses propres clés d’hôtes vérifiées pour le processus Git du worker. Les clés privées et les accès à de véritables cibles SSH restent à valider de bout en bout.

Le déverrouillage Vault demandé dans l’interface est à usage unique. Le worker le transmet au processus Ansible par un descripteur anonyme en mémoire et ne le conserve pas dans le volume. Dans le parcours actuel, chaque exécution attend ce déverrouillage, même si le playbook ne contient pas de secret Vault.

Les collections, la gestion guidée des clés, la mise à jour des dépendances et l’exécution sur des cibles distantes restent à développer ou à valider. Les essais locaux ne constituent pas une qualification de production ni une autorisation de redistribuer les archives ou dépendances tierces.
