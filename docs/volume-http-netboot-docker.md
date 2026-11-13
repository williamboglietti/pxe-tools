# Requalifier un ancien volume HTTP Docker

La nouvelle image exige que `/srv/http` et tous ses descendants servis, notamment `netboot`, `static-bin` et les scripts PHP rendus, appartiennent à root sans écriture par `www-data`. Seuls les alias internes de `netboot` sont admis. Le code exécuté depuis `/app` appartient aussi à root dans l'image. Un marqueur root dans le volume distingue une racine préparée pour cette règle. Au démarrage, l’application refuse un volume ancien ou ambigu avant de lancer PHP-FPM, nginx, dnsmasq et le worker. Elle ne change aucune permission du volume.

## Préparation hors production

Planifier une fenêtre où aucun client PXE ne démarre et conserver l’image applicative précédente pour le retour arrière. Identifier le nom réel du volume HTTP avec `docker compose -f compose/docker-compose.yml config` et `docker volume ls` ; ne pas déduire son nom d’un exemple. Vérifier aussi que les autres volumes persistants, notamment la base SQLite et la clé maître, sont sauvegardés ensemble selon la procédure de sauvegarde de l’installation.

Créer une sauvegarde du volume HTTP **en lecture seule** avant tout changement. Par exemple, depuis la racine du dépôt, avec une image applicative déjà présente localement et un répertoire de sauvegarde privé :

```bash
old_volume='NOM_REEL_DU_VOLUME_HTTP'
backup_dir='/CHEMIN_PRIVE_DE_SAUVEGARDE'
PXE_TOOLS_IMAGE='IMAGE_LOCALE_PRECEDENTE:TAG'
test -d "$backup_dir"
docker run --rm --network none --pull never --entrypoint /bin/tar \
  --mount "type=volume,src=$old_volume,dst=/old,readonly" \
  --mount "type=bind,src=$backup_dir,dst=/backup" \
  "$PXE_TOOLS_IMAGE" -C /old -cpf /backup/pxe-http-before.tar .
```

Conserver ce fichier avec l’image précédente et relever son empreinte SHA-256. Ne pas copier les anciens fichiers `netboot` vers une nouvelle racine sans contrôler leur provenance et leur intégrité ; leur statut `downloaded` en base ne prouve pas leurs octets.

## Bascule manuelle réversible recommandée

Créer un volume HTTP neuf au moyen d’un fichier Compose local non versionné. Le montage du volume neuf reçoit les fichiers préparés par la nouvelle image, dont le marqueur root et le répertoire `netboot` vide. Exemple de fichier `compose.http-clean.local.yml` à créer hors du dépôt suivi :

```yaml
services:
  pxe-tools:
    volumes:
      - pxe_http_clean:/srv/http
volumes:
  pxe_http_clean: {}
```

Avant le démarrage, examiner le résultat de `docker compose -f compose/docker-compose.yml -f compose.http-clean.local.yml config` et confirmer qu’un seul montage cible `/srv/http`, avec le nouveau volume. Conserver le volume précédent intact. Démarrer la nouvelle image uniquement pendant la fenêtre de maintenance, puis vérifier dans ses journaux que la garde HTTP a accepté la racine et que les services attendus démarrent.

La base conserve les lignes des images précédemment téléchargées alors que le nouveau volume ne contient pas leurs fichiers. Depuis l’interface d’administration, relancer explicitement chaque téléchargement nécessaire et attendre son contrôle d’intégrité et son état `downloaded` avant de remettre des machines sur le réseau PXE. Vérifier au moins un démarrage PXE isolé avant de rouvrir le service aux clients habituels. Ne pas lancer une migration SQL ou un `chown -R` pour contourner le refus de la garde.

## Retour arrière

Si la bascule échoue, arrêter la nouvelle instance pendant la même fenêtre de maintenance, reprendre l’image applicative précédente **et son ancien montage HTTP** en retirant l’override local, puis vérifier son état avant de remettre le PXE à disposition. Le volume ancien et sa sauvegarde restent disponibles ; le volume neuf peut être conservé pour analyse. Ne pas démarrer la nouvelle image avec l’ancien volume tant que sa requalification n’a pas été examinée et validée séparément.
