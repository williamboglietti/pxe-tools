# Outils statiques du parcours PXE

Les scripts Discovery et de diagnostic récupèrent ces exécutables amd64 depuis
le serveur PXE. Ils remplacent les exécutables précédemment embarqués dont la
chaîne de construction n’était pas suffisamment établie.

| Fichier | Origine du candidat | Construction ou paquet |
| --- | --- | --- |
| `dmidecode` | Debian `dmidecode` source `3.7-1` | `make LDFLAGS=-static` |
| `smartctl` | Debian `smartmontools` source `7.4-3` | statique, sans libcap-ng ni systemd |
| `badblocks` | Debian `e2fsprogs` source `1.47.2-3` | statique, sans NLS ni bibliothèques partagées |
| `chvt` | Debian `busybox-static` `1:1.37.0-6+b9` | copie du binaire contenant l’applet `chvt` |

Les notices amont et Debian figurent sous `third_party/licenses/static-helpers/`.
Les archives de sources et de reliage, les versions de chaîne de compilation,
les condensats et les instructions sont décrits dans `SOURCE-PREPARATION.md`.
Elles doivent être publiées et maintenues disponibles avec les exécutables.
Trois exécutables ont été reconstruits à l'identique ; pour le binaire Debian
BusyBox, seuls trois champs de métadonnées ELF diffèrent. Les quatre ont été
reliés avec succès avec une libc modifiée de test ; voir la notice pour la
portée exacte de ces vérifications.

Sur une VM Hyper-V isolée, la découverte a téléchargé `dmidecode`, `smartctl`
et `chvt` et a remonté l’inventaire. `dmidecode` a été comparé à l’ancien outil
sur les types mémoire, processeur et système ; `badblocks` a été testé sur un
fichier fictif en lecture et en écriture. Ces essais ne valident pas les modes
SMART sur matériel réel ni tous les scénarios de diagnostic.
