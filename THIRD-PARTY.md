# Inventaire des composants tiers

Le socle PXE Tools est distribué sous LGPL version 3 ; les composants tiers
conservent leurs propres licences et conditions. Les notices et textes
correspondants sont inclus sous `third_party/licenses/`. Pour les outils
statiques, la distribution des binaires doit toujours être accompagnée de la
mise à disposition des sources et des matériaux de reliage décrits ci-dessous.

| Élément présent dans la préparation | Constat | Reste à vérifier |
| --- | --- | --- |
| `app/public/htmx.min.js` | Identique octet pour octet à `dist/htmx.min.js` de htmx v1.9.12. Licence amont Zero-Clause BSD, reproduite dans `third_party/licenses/htmx-0BSD.txt`. | Vérifier les éventuelles mises à jour et conserver la notice dans les artefacts. |
| `app/public/style.tailwind.css` | La feuille générée porte l’en-tête Tailwind CSS v4.3.3, MIT. La licence amont correspondante est dans `third_party/licenses/tailwindcss-MIT.txt`. La génération de la copie de préparation complète avec l’outil officiel v4.3.3 a produit une feuille identique octet pour octet. | Épingler et vérifier l’outil utilisé par les scripts de développement ; qualifier séparément les ressources provenant d’autres projets. |
| `app/public/img/distros/*.svg` | Les tracés correspondent à une révision identifiée de Simple Icons ; le titulaire du projet confirme les droits d'utilisation des fichiers actuels. Les références, attributions et textes identifiés figurent dans `third_party/licenses/distribution-logos.md` et les textes CC BY-SA associés. | Conserver ces notices et vérifier séparément tout nouveau logo ou toute nouvelle version avant redistribution. |
| `docker/pxe-tools/static-bin/{smartctl,badblocks,dmidecode,chvt}` | Les quatre exécutables proviennent des sources ou paquets Debian identifiés dans leur README. Les notices correspondantes sont sous `third_party/licenses/static-helpers/`. | Les sources et les moyens de reliage doivent être mis à disposition à l'emplacement indiqué dans `third_party/licenses/static-helpers/SOURCE-PREPARATION.md` et conservés avec les versions correspondantes. Les essais SMART et de diagnostic sur matériel réel restent à compléter. |
| `s6-overlay` dans l’image Docker | Version 3.2.0.0, notice ISC amont dans `third_party/licenses/s6-overlay-ISC.txt` ; notices ISC de dix composants aux versions épinglées dans `third_party/licenses/s6-bundled/`. | Vérifier les licences et notices des autres composants liés, notamment BearSSL et la chaîne musl, et conserver les sources correspondantes lorsque requis. |
| Dépendances installées par APT | Le paquet expérimental déclare notamment nginx, dnsmasq, PHP, GRUB, shim, Syslinux et plusieurs bibliothèques via les paquets de distribution. | Vérifier séparément leurs notices et obligations pour chaque artefact ou image distribués ; ne pas attribuer ces composants à la licence du socle. |
| Code et éléments repris | La base est issue de l’application existante et de travaux de préparation. Le titulaire a confirmé les droits sur le contenu applicatif identifié pendant la revue des contributions. | Vérifier séparément les composants tiers, les éléments graphiques, la provenance de la feuille de style et les autres contributions éventuelles. Aucun commit privé ne doit être repris dans l’historique public. |

La comparaison du fichier htmx a été faite avec le fichier amont du tag v1.9.12.
Le texte Tailwind a été obtenu depuis le tag v4.3.3. Le texte CC0 provient du
projet Simple Icons ; il ne couvre pas automatiquement les marques ou chaque
icône, qui peuvent avoir des conditions propres.
Aucune des recherches automatiques ne remplace la revue des contenus et des
licences des fichiers effectivement publiés.

Le `chvt` dont la source n'était pas retrouvée a été remplacé par l'applet du
paquet Debian `busybox-static` identifié. Les sources correspondantes, la
configuration, les objets et les résultats de reliage sont détaillés dans la
notice des outils statiques. Les tests n'établissent pas l'équivalence de
tous les usages sur du matériel physique.

Revue supplémentaire du 12 novembre 2026 : les sept tracés SVG des
distributions correspondent aux tracés Simple Icons à la révision
`cd796c98565fc466923fc1128b962e133fa3c612`. Le manifeste amont indique
notamment des conditions spécifiques pour Debian, Fedora et Rocky Linux ; une
licence non renseignée pour une icône ne vaut pas autorisation. Le titulaire du
projet confirme disposer des droits nécessaires pour les logos actuels ; les
notices d'attribution ont été préparées séparément.

L’image de préparation construite sur Debian 13 contenait 219 paquets de la
distribution, chacun avec un fichier `copyright`. Cette observation est liée à
cette construction précise et ne garantit pas les versions d’une future image.
Les archives s6-overlay v3.2.0.0 correspondent aux empreintes épinglées ; la
publication amont identifie leurs composants et une chaîne x86_64-musl. Les
notices propres aux composants embarqués et les obligations relatives aux
sources restent à compléter avant toute redistribution de l’image.

Les notices MIT de BearSSL à la révision annoncée et de musl à la révision
identifiée dans une chaîne amont correspondante ont été ajoutées dans
`third_party/licenses/s6-bundled/`. La correspondance exacte de cette chaîne
avec la construction publiée et les composants GCC restent à vérifier ; la correspondance exacte demeure à établir avant la distribution d’une image Docker.

Les textes complets LGPL 2 et LGPL 2.1 associés aux notices des outils statiques
sont inclus. Les preuves de construction et de reliage, leur portée et leurs
limites sont précisées dans `static-helpers/SOURCE-PREPARATION.md`. Les archives sont disponibles à l’adresse indiquée dans cette notice et doivent
rester accessibles tant que les exécutables correspondants sont distribués.
