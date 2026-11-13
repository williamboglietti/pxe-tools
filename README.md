# PXE Tools

PXE Tools fournit une interface web pour découvrir des machines et installer
Debian par le réseau. Le socle comprend les services PXE, l'interface et son
worker. Ansible n'est pas installé par le paquet ; son module complémentaire
n'est pas encore publié.

La première cible native vérifiée est **Debian 13 amd64**. Les essais couvrent
une installation APT, une installation Debian PXE et Secure Boot sur des VM
Hyper-V isolées. Le matériel physique, Ubuntu et les autres distributions ne
sont pas encore validés. Consultez les limites et instructions de sécurité dans
[la documentation native](docs/native-preparation.md).

## Installation avec APT

Téléchargez la clé publique, puis vérifiez son empreinte avant de l'utiliser :

```sh
sudo apt update
sudo apt install --no-install-recommends ca-certificates curl gpg
curl -fsSLo pxe-tools-archive-keyring.gpg https://packages.pxetools.com/pxe-tools-archive-keyring.gpg
gpg --show-keys --fingerprint pxe-tools-archive-keyring.gpg
```

L'empreinte attendue de la clé principale est :

```text
CD8B 4493 876B 4FBD 1009 FE2D B195 E5DE 0268 07EC
```

Après comparaison, ajoutez le dépôt et installez le paquet :

```sh
sudo install -m 0644 pxe-tools-archive-keyring.gpg /usr/share/keyrings/pxe-tools-archive-keyring.gpg
echo 'deb [signed-by=/usr/share/keyrings/pxe-tools-archive-keyring.gpg] https://packages.pxetools.com/apt/ stable main' | sudo tee /etc/apt/sources.list.d/pxe-tools.list >/dev/null
sudo apt update
sudo apt install --no-install-recommends pxe-tools
```

Ouvrez ensuite `http://adresse-du-serveur:8080/` et suivez l'assistant. Aucun
proxy DHCP PXE n'est activé avant votre confirmation réseau. Un serveur DHCP
approprié et un réseau PXE sont nécessaires. Si un autre service occupe le
port 80, l'assistant permet d'examiner le conflit ; l'arrêt de `nginx.service`
nécessite une confirmation explicite.

Pour Secure Boot, la clé MOK de votre instance doit être enrôlée sur chaque
client concerné avant d'utiliser le chargeur signé par cette instance.

## Données et limites

La base SQLite, la clé de chiffrement et les fichiers persistants doivent être
sauvegardés ensemble. Une procédure de [sauvegarde chiffrée et restauration
en veille](docs/backup-restore.md) est disponible en ligne de commande. Une
désinstallation du paquet conserve les données.
MySQL et MariaDB sont actuellement désactivés : une configuration existante
est conservée mais n'est pas migrée automatiquement.

Le code du socle est distribué sous [LGPL-3.0](COPYING.LESSER). Les composants
tiers et leurs notices sont recensés dans [THIRD-PARTY.md](THIRD-PARTY.md).
Les sources et matériaux de reliage des utilitaires embarqués sont disponibles
dans [la notice et les archives correspondantes](https://github.com/williamboglietti/pxe-tools/releases/tag/sources-2026-11-12).
