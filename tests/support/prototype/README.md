# Outils de vérification et bancs QEMU des tests d'installation

Ces fichiers proviennent d'un prototype antérieur de PXE Tools, sous LGPL-3.0. Seuls les tests d'intégration les utilisent : vérification de la chaîne de signature Debian, construction d'un ensemble de démarrage vérifié et bancs QEMU isolés. L'application ne les charge pas et ils ne sont pas installés par les paquets.

Ils gardent la disposition d'origine (`app/` et `tests/integration/`), dont dépendent leurs chemins relatifs internes. Les tests d'installation les placent en tête de `sys.path`, et les bancs conteneurisés montent ce dossier en lecture seule sur `/fixture-prototype`.

Ne pas les modifier pour les besoins de l'application : un changement ici modifie ce que vérifient les tests.


## Lancement sur une VM jetable dédiée

Ces bancs créent des invités et des disques temporaires ; ne les exécutez que
sur une VM de test dédiée, sans volumes ni interfaces de production. Les
invités QEMU utilisent un réseau restreint ou un commutateur de fixture ;
leurs seuls disques sont créés pour le test. Le miroir de fixture télécharge
les fichiers Debian via le réseau de la VM hôte et vérifie leurs signatures.
Prévoir QEMU x86, qemu-img, Python 3 avec Jinja2, PHP CLI avec SQLite, cpio,
xz, OpenSSL, gpgv et un trousseau d'archive Debian fiable et récent.

Depuis la racine du checkout, par exemple sur Debian compatible :

```bash
python3 tests/integration/test_full_install_mirror.py
python3 tests/unit/test_prototype_debian_netboot_freshness.py
python3 tests/integration/test_private_guard_real_partman.py --keyring /usr/share/keyrings/debian-archive-keyring.gpg
python3 tests/integration/test_private_full_debian_install.py --keyring /usr/share/keyrings/debian-archive-keyring.gpg
```

Le banc `test_private_pxe_debian_install.py` doit être lancé à l'intérieur
d'un conteneur jetable dédié disposant des mêmes dépendances, avec le checkout
monté en lecture seule et sans volume de production. Dans ce conteneur :

```bash
PXE_DISPOSABLE_PXE_INSTALL=1 python3 tests/integration/test_private_pxe_debian_install.py --keyring /usr/share/keyrings/debian-archive-keyring.gpg
```

Le rendu utilise par défaut l'application `app/` de ce checkout. Une variable
`PXE_APP_DIR` explicitement définie peut choisir un autre répertoire applicatif
de fixture ; elle ne désigne jamais le prototype et ne doit pas viser une
installation de production. L'ancienne variable `PXE_PROTOTYPE_ROOT` n'est
plus nécessaire : les outils de test sont inclus dans ce dossier. Les quatre
bancs conteneurisés demandent en outre Docker et une image de fixture portant
le nom explicitement contrôlé par chaque script ; ils refusent de démarrer en
l'absence de leur variable d'isolation. Ne pas contourner ces vérifications.
