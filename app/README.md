# Orchestrator Admin App

Interface web + API pour piloter le serveur PXE.

## Architecture

```
/srv/admin/                     # ce répertoire (déployé par Ansible)
├── public/                     # DocumentRoot nginx :8080
│   ├── index.php              # Front controller (router minimal)
│   ├── htmx.min.js            # HTMX local
│   ├── toast.js               # Toast notifications (window.PxeToast)
│   ├── style.tailwind.css     # Tailwind v4 build (cf. scripts/build-css.sh)
│   └── style.css              # Composants legacy (en cours de migration)
├── src/                       # Code application
│   ├── Db.php                 # PDO singleton SQLite
│   ├── Router.php             # Routes + dispatch
│   ├── Auth.php               # Stub Authentik forward auth
│   ├── View.php               # Render layout / partial / json / redirect
│   ├── Models/                # PDO data access
│   │   ├── Machine.php
│   │   ├── Preset.php
│   │   └── Installation.php
│   ├── Controllers/
│   │   ├── MachineController.php
│   │   ├── PresetController.php
│   │   ├── InstallationController.php
│   │   └── PxeController.php  # endpoints /api/discovery, /api/install-{start,end}
│   └── Views/                 # PHP templates
│       ├── layout.php
│       ├── machines/{index,show,new}.php
│       ├── presets/{index,edit}.php
│       └── installations/{index,show,_log}.php
├── db/
│   └── schema.sql             # CREATE TABLE IF NOT EXISTS — idempotent
├── worker.py                  # Worker Ansible (systemd : pxe-tools-worker.service)
└── discover.sh                # Script discovery boot (déployé aussi dans /srv/http/)
```

## Endpoints

### UI (port 8080)

| Méthode | Path                      | Action |
|--|--|--|
| GET     | `/`                       | Liste des machines |
| GET     | `/machines/{id}`          | Détail machine + assign preset |
| POST    | `/machines`               | Créer machine manuellement |
| PUT     | `/machines/{id}`          | Update (preset, hostname...) |
| POST    | `/machines/{id}/wipe`     | Reset status → `discovered` |
| DELETE  | `/machines/{id}`          | Supprimer |
| GET     | `/presets`                | Liste presets |
| GET     | `/presets/new` / `/{id}`  | Form create/edit |
| POST/PUT| `/presets[/{id}]`         | Save |
| DELETE  | `/presets/{id}`           | Supprimer |
| GET     | `/installations`          | Historique installs |
| GET     | `/installations/{id}`     | Log streaming (HTMX poll 2s) |

### API PXE (port 80 — vhost PXE + port 8080)

Pas d'auth — appelés par les machines en cours d'install.

| Méthode | Path                | Body / Query | Action |
|--|--|--|--|
| POST    | `/api/discovery`    | JSON `{mac, inventory, disks, interfaces}` | Crée/update machine en `discovered` |
| GET/POST| `/api/install-start`| `?mac=...` | status → `installing`, créer row `installations` |
| GET/POST| `/api/install-end`  | `?mac=...&status=success\|failed` | status → `installed`/`failed`, enqueue Ansible |

## DB SQLite

Fichier : `/var/lib/pxe-tools/pxe-tools.sqlite` (`www-data:ansible 0660`).

Tables : `machines`, `presets`, `installations` (cf [db/schema.sql](db/schema.sql)).

Mode rollback journal (pas WAL). Le directory parent doit être writable par www-data + ansible (mode `0775`, group `www-data`, et user `ansible` ajouté à ce groupe).

## États d'une machine

```
unknown ──(MAC vue PXE)──▶ discovered ──(admin save preset)──▶ configured
configured ──(early_cmd POST)──▶ installing ──(late_cmd POST)──▶ installed
installed ──(worker exec ansible)──▶ provisioned   /   ──(KO)──▶ failed
```

## Worker

`pxe-tools-worker.service` (systemd, user `ansible`) poll `installations.ansible_status='pending'`, lance `ansible-playbook playbooks/<role>.yml --limit <hostname>`, stream le stdout dans la table.

`/home/ansible/ansible/` doit contenir un repo Ansible (avec `playbooks/`, `inventory/`, `ansible.cfg`). Cloner ou monter selon besoin.

## Proxy inverse et origine des formulaires

La protection CSRF compare l’origine du formulaire à l’en-tête `Host`. Si le
proxy remplace `Host` et transmet le nom public dans `X-Forwarded-Host`, il faut
déclarer explicitement les adresses ou CIDR des **pairs réseau réellement vus
par PXE Tools** dans `AUTHENTIK_TRUSTED_PROXY_CIDRS`, même lorsque
`AUTH_MODE=local`. Malgré son nom historique, cette liste sert aussi au
contrôle de l’origine en mode local. Sans liste valide, l’en-tête transmis est
ignoré et un formulaire dont l’origine ne correspond pas à `Host` est refusé.
La liste accepte IPv4 et IPv6 séparées par des virgules, mais refuse notamment
les entrées mal formées et les réseaux qui acceptent toutes les adresses.
Ne pas y placer les adresses des navigateurs : `X-Forwarded-For` ne détermine
pas si le pair est fiable.

Exemple de variable d’environnement, à adapter à l’adresse effective du proxy :

```text
AUTHENTIK_TRUSTED_PROXY_CIDRS=192.0.2.7/32
```

Cette déclaration signifie que l’on fait confiance au proxy : il doit supprimer
ou remplacer les en-têtes `X-Forwarded-Host`, `X-Forwarded-Proto` et
`X-Forwarded-User` fournis par les clients, selon la configuration vérifiée du
service. Restreindre l’accès direct à l’administration et ne déclarer que les
proxys administrés. Le jeton CSRF reste obligatoire, même pour un proxy fiable.
La connexion locale et les cas de pair fiable/non fiable ont été testés avec le
véritable routeur sur une boucle locale isolée ; ces essais simulaient les
en-têtes et ne validaient pas un proxy externe ou un fournisseur SSO réel.

## Authentik via proxy externe

Avec `AUTH_MODE=authentik`, `AUTHENTIK_TRUSTED_PROXY_CIDRS` doit lister les
adresses réelles des proxys qui exécutent l'authentification. Le proxy doit
remplacer `X-Forwarded-User` par l'identité vérifiée par l'outpost et l'accès
direct aux ports admin doit être limité. L'accès navigateur doit utiliser HTTPS ;
le proxy écrase `X-Forwarded-Proto` selon le protocole extérieur vérifié et
`X-Forwarded-Host` selon l'hôte public prévu. Sans HTTPS détecté, le cookie de
session n'est pas marqué `Secure`. Une configuration absente ou invalide
refuse l'accès ; les formulaires restent protégés par CSRF. Voir
`docker/pxe-tools/nginx/admin-authentik.conf.example` et le README principal.

## Bêta UX/UI locale

La branche `codex/ux-beta` contient la refonte de la console. Voir la [procédure de démonstration, les preuves et les limites matérielles](../docs/ux-beta/livraison.md). La démo web utilise des données temporaires, sans worker ni accès aux machines réelles.
