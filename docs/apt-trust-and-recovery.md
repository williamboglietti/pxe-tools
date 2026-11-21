# Dépôt APT : état de la rotation et reprise des instantanés

Ce document décrit l'état de la distribution au 18 novembre 2026 et prépare la
résolution de [pxetools.com #2](https://github.com/williamboglietti/pxetools.com/issues/2).
Il ne donne pas l'autorisation d'effectuer une rotation ou une restauration en
production. Les modifications de clés, secrets, workflows et déploiements
nécessitent leur propre validation et une autorisation ciblée.

## Rotation de la signature : blocages constatés

La clé publique actuellement servie possède une clé primaire et une sous-clé
de signature. Le bootstrap du site épingle l'empreinte de la primaire, puis
l'installateur place la clé vérifiée dans
`/etc/apt/keyrings/pxe-tools-archive-keyring.gpg` et utilise `Signed-By`. Il ne
met pas automatiquement à jour cette clé chez les clients existants. Une
nouvelle sous-clé ajoutée au certificat public n'est donc pas connue d'un
client qui a conservé l'ancien certificat. Le test isolé ci-dessous démontre ce
comportement avec des clés jetables :

```sh
python3 tests/integration/test_apt_signing_rotation.py -v
```

Le même test confirme que `ci-sign-apt-candidate.py` refuse par défaut un
trousseau CI contenant deux sous-clés privées. Le mode de transition préparé
importe séparément l'ancien certificat public, contrôle les deux empreintes et
refuse tout ajout de matériel privé ; il n'est pas raccordé à la publication.
Par ailleurs :

- `build-local-apt-repository.py` exige **par défaut** que l'instantané
  précédent soit signé par la même sous-clé que le suivant. Un mode explicite
  de répétition permet de distinguer les deux sous-clés sous une primaire
  épinglée ; il n'est pas raccordé au workflow de production ;
- les workflows de production épinglent encore les empreintes courantes. La
  récupération préparée accepte d'autres identités uniquement si le trousseau,
  la primaire et le signataire sont tous fournis explicitement ; ses valeurs
  par défaut restent inchangées. La vérification de provenance accepte une
  deuxième sous-clé uniquement si elle est explicitement épinglée ; la
  signature reste vérifiée contre le signataire attendu ;
- le désinstallateur suit la provenance du trousseau par une empreinte du
  contenu. Les fixtures testent la mise à jour en place et le remplacement
  atomique : dans les deux cas le trousseau modifié est conservé, car son
  témoin d'origine ne prouve plus les nouveaux octets.

Changer la clé de production, le secret CI ou une seule empreinte entraînerait
donc un blocage de publication ou des clients incapables de vérifier le dépôt.
**La rotation transparente n'est pas implémentée et aucune rotation réelle n'a
été effectuée.** La répétition isolée peut être lancée sur un environnement
Linux jetable avec `PXE_APT_ROTATION_FIXTURE=1 bash
tests/integration/test_apt_rotation_rehearsal.sh`. Elle génère ses propres
clés et paquets ; elle ne modifie aucun état APT système ni aucun secret. Le
workflow de vérification des PR l'exécute sans accès à l'environnement de
production ; le workflow de publication possède aussi un mode de répétition
manuel décrit plus bas. Une nouvelle primaire, une sous-clé renouvelée et une
compromission exigent des plans distincts ; un certificat compromis ne doit pas
être utilisé pour authentifier sans examen sa propre relève.

### Travail requis pour une transition sûre

1. Définir le modèle de confiance et la méthode de vérification indépendante
   des empreintes, y compris la conservation et la révocation des clés. Décider
   comment les clients existants recevront le nouveau certificat **avant** que
   le dépôt soit exclusivement signé par la nouvelle sous-clé ; le certificat
   servi aux nouvelles installations ne modifie pas les clients déjà installés.
2. Concevoir et revoir une transition explicite : vérifier l'ancien instantané
   avec l'ancienne identité attendue et signer le suivant avec la nouvelle,
   sans autoriser un signataire arbitraire. Traiter séparément la récupération,
   la preuve de provenance, la validation statique, la publication et les
   chemins client. Préserver les contrôles d'objets immuables et les refus sur
   toute identité ou provenance inattendue.
3. Tester avec des clés jetables et de vrais clients APT isolés : ancien client,
   certificat mis à jour, nouveau client, mauvaise empreinte, sous-clé inconnue,
   instantané précédent invalide, interruption et reprise. Inclure la
   cohabitation ou la propagation des versions à l'edge et le comportement du
   désinstallateur après mise à jour du trousseau.
4. Préparer les changements précis de code, des deux dépôts, des workflows et
   des secrets, ainsi que le plan de retour et la réception des clients. La
   modification des secrets et l'exécution de la transition de production
   nécessitent une autorisation ciblée ; ne pas contourner les protections.

### Inspecter les certificats avant une transition

`packaging/native/check-apt-key-transition.py` examine deux fichiers publics
locaux dans un environnement GnuPG temporaire. Il exige trois empreintes
complètes distinctes et compare les structures : ancien certificat contenant la
primaire et l'ancienne sous-clé, nouveau contenant la même primaire et les deux
sous-clés épinglées. Il refuse notamment fichiers de clé secrète et liens
symboliques, et affiche les SHA-256 des octets inspectés. Les empreintes doivent
avoir été obtenues par un canal indépendant ; l'outil ne les authentifie pas.
Il ne vérifie pas l'usage des fichiers par APT, ne modifie aucun trousseau client
et ne constitue pas une procédure de révocation ou de migration.

```sh
python3 packaging/native/check-apt-key-transition.py \
  --old-certificate /chemin/vers/ancien-public.gpg \
  --new-certificate /chemin/vers/nouveau-public.gpg \
  --primary-fingerprint EMPREINTE_PRIMAIRE_VERIFIEE \
  --old-signing-fingerprint EMPREINTE_ANCIENNE_SOUS_CLE \
  --new-signing-fingerprint EMPREINTE_NOUVELLE_SOUS_CLE
```

### Préparer la distribution aux clients existants

Cette séquence prépare une transition mais ne constitue pas une autorisation de
rotation. Elle concerne uniquement l'ajout d'une sous-clé certifiée
par une primaire non compromise ; le cas d'une nouvelle primaire ou d'une clé
compromise exige un autre modèle de confiance.

1. Obtenir par un canal indépendant les empreintes complètes de la primaire et
   des deux sous-clés, ainsi que l'identité et l'empreinte du certificat public
   proposé. Contrôler les signatures de certification, les dates et révocations
   dans un trousseau GnuPG isolé. Un téléchargement HTTPS depuis le même dépôt
   ne constitue pas à lui seul une vérification indépendante.
2. Vérifier sans modifier le système la source APT effective, son `Signed-By`,
   les éventuels autres dépôts ou alias qui utilisent le même fichier, les liens
   symboliques et les témoins de création. Si la propriété, la configuration ou
   l'usage exclusif ne peuvent pas être établis, préserver le fichier et demander
   une inspection : ajouter une sous-clé à un trousseau partagé pourrait élargir
   la confiance d'un autre dépôt.
3. Préparer une mise à jour atomique et récupérable : conserver l'ancien
   certificat, vérifier les octets attendus immédiatement avant remplacement,
   écrire un nouveau fichier protégé et contrôler ses permissions et son
   empreinte. Définir un journal et une reprise pour chaque interruption. Le
   témoin par lien physique et empreinte créé par l'installateur doit être
   traité explicitement ; il ne faut ni le réécrire aveuglément ni prétendre
   que le désinstallateur est autorisé à effacer un nouveau certificat inconnu.
4. Prouver sur un client isolé l'acceptation des métadonnées signées par
   l'ancienne puis la nouvelle sous-clé, et le refus en cas d'empreinte,
   certificat ou signature erronés. Tester aussi le remplacement interrompu,
   les configurations partagées et la désinstallation. Ne pas interpréter
   `apt update` seul comme une installation ou un boot PXE réussis.
5. Définir la gestion des clients absents ou hors ligne et la période de
   coexistence avant toute signature exclusive par la nouvelle sous-clé. Une
   propagation partielle des versions du Worker doit être prise en compte ;
   le maintien de la seule nouvelle signature peut interrompre les anciens
   clients. La révocation de l'ancienne sous-clé et toute intervention réelle
   suivent une décision de production distincte.

Le candidat `packaging/native/prepare-client-key-transition.py` dispose d'un
mode `--apply-system` explicite, réservé à root et exigeant l'option
`--acknowledge-independent-fingerprints`. Avant remplacement, il prend les
verrous de l'installateur et de dpkg, vérifie les témoins, la configuration APT
effective, les empreintes et les signatures des deux index fournis. Le nouveau
certificat doit également accepter l'ancienne signature. Il refuse les sources
partagées ou ambiguës et les fichiers montés séparément, recontrôle l'état avant
le remplacement atomique, conserve le témoin initial et signale un état
incertain si la synchronisation échoue après remplacement. Une nouvelle
tentative après remplacement est refusée et exige une inspection manuelle.
Le fichier mis à jour n'est pas revendiqué par l'ancien témoin : le
désinstallateur le conserve.

Un mode `--inspect-system` prépare une vérification **en lecture seule** d'une
installation existante : il lit la configuration APT effective, les témoins et
les certificats et index fournis ; il ne remplace rien. L'inspection ne prend
pas de verrou, ne garantit pas que l'état restera inchangé et ne détecte pas les
usages éventuels d'un fichier par une invocation APT avec configuration
alternative. Ce mode n'a été vérifié que sur fixtures ; il ne remplace pas la
vérification indépendante des empreintes et n'a pas été exécuté sur les
machines de William. Les modes système ne prennent pas de chemin racine
alternatif. L'exécution du candidat sur les machines existantes nécessite une
transition distinctement autorisée ; elle n'a pas été effectuée ici.

La fixture vérifie les refus, les interruptions et l'accès APT après
remplacement aux index signés par l'ancienne et par la nouvelle sous-clé. Ce
test reproduit une alternance locale des index, sans mesurer une propagation
réelle du Worker. Les essais du chemin d'application utilisent uniquement des
arbres jetables, avec des interruptions et des conflits de verrous simulés. Le
candidat ne détecte pas tous les usages de configurations APT alternatives,
vérifie pas les révocations à partir d'une source extérieure et ne coordonne pas
les processus qui ignorent ses verrous. Il exige désormais un index récent signé
par la nouvelle sous-clé ; l'ancienne preuve peut être expirée. Le contrôle de
fraîcheur dépend de l'horloge du client. La procédure, les garanties de reprise
et la réception sur systèmes représentatifs restent à revoir.

Le workflow de vérification des PR répète localement la construction, la
signature et la préparation des assets statiques avec des clés jetables. La
présente PR prépare aussi dans le workflow APT un mode manuel explicite
`transition_rehearsal` : il lance uniquement cette répétition et exclut les jobs
`prepare` et `publish`, même sur `main`. Il utilise un groupe de concurrence
séparé et ne reçoit ni environnement de production ni secret. Ce mode assemble
un dossier de code avec sommes de contrôle, exécute les tests sur sa propre
copie, puis conserve ce candidat comme artefact Actions temporaire pour revue.
Il ne contient ni certificat ni secret ; ses sommes seules n'authentifient pas
sa provenance. Ce mode ne raccorde pas encore une rotation réelle au signataire
de publication et ne déploie ni certificat ni Worker. Aucun basculement de
production n'est livré.

Le job de publication prépare aussi les données listées dans
`packaging/ci/client-transitions.json`. Cette liste est vide par défaut : le
chemin actuel et les assets restent inchangés. Pour une transition future, un
changement de dépôt séparé devrait ajouter les fichiers publics et leurs
identités vérifiées à cette liste, après revue et décision ciblée. Le hook
vérifie les certificats et signatures et n'accepte que les quatre fichiers
attendus. Il publie les données sous un chemin contenant un condensat de leur
contenu et de leurs identités ; toute modification exige donc un nouveau chemin.
Les entrées précédentes doivent rester dans la liste et les fichiers conservés
aux publications suivantes, tant que les clients hors ligne en ont besoin.
Une suppression ou un retour à une version antérieure peut rendre une preuve
indisponible : conserver aussi une copie vérifiée hors Worker. Les sommes et le
chemin ne remplacent pas une vérification indépendante des empreintes.
Les [assets Cloudflare appartiennent à une version du Worker](https://developers.cloudflare.com/workers/static-assets/)
et peuvent être servis depuis des versions différentes pendant la propagation.
Après un futur déploiement avec des entrées configurées, le workflow attend aussi
la présence des quatre objets exacts ; ce contrôle est borné et effectué depuis
un seul point de vue, pas une preuve de propagation mondiale. La liste vide ne
déclenche aucune requête supplémentaire.

#### Client resté hors ligne

1. Vérifier la date du client et, par un canal indépendant actuel, la primaire,
   les deux sous-clés, leur état de révocation et l'identité du dossier de
   transition. Une clé révoquée ou compromise exige un autre plan.
2. Récupérer les données publiées, ou une copie archivée préalablement vérifiée,
   et le programme client par un canal vérifié. Les transporter sur un support
   de confiance si le client reste déconnecté. La preuve de l'ancienne
   signature peut être historique et expirée.
3. Fournir également un `InRelease` récent signé par la nouvelle sous-clé. Si
   l'index du dossier archivé a expiré, obtenir l'index courant par un canal de
   confiance et le transférer ; ne pas contourner le contrôle de date. Sans
   preuve récente vérifiable, laisser la clé installée inchangée et attendre.
4. Sur une machine autorisée, inspecter puis appliquer explicitement le
   candidat avec les empreintes vérifiées. En cas d'état incertain ou de refus,
   conserver les témoins et demander une inspection. Après reconnexion, un
   `apt update` séparé valide le dépôt courant ; la migration seule ne prouve
   ni sa disponibilité, ni l'installation d'un paquet.

Le candidat actuel traite une seule transition depuis le certificat attesté par
le témoin initial. Il ne réenchaîne pas automatiquement plusieurs rotations :
un client ayant manqué plusieurs générations nécessite un plan spécifique et
une inspection manuelle. La disponibilité et la validité futures des preuves
conservées doivent être vérifiées avant chaque nouvelle transition.

Le candidat contient aussi `stage-client-transition-data.py`, qui prépare
localement un dossier de deux certificats publics et deux index signés après
vérification des empreintes et des trois signatures attendues. Le manifeste
indique les empreintes des fichiers, mais n'authentifie ni sa provenance ni les
identités qu'il contient : elles doivent être vérifiées indépendamment. La
répétition crée et vérifie ce dossier avec des clés jetables ; aucun de ces
fichiers ne constitue un certificat de production ou une distribution réelle.

## Instantanés : ce qui est vérifié

Le [run planifié 44077918347](https://github.com/williamboglietti/pxe-tools/actions/runs/44077918347)
du 17 novembre 2026 a réussi les étapes de récupération de l'instantané,
signature, conservation persistante, déploiement et contrôle public. Le script
`packaging/ci/worker-snapshot.py` sélectionne l'asset le plus récent de la
release `apt-snapshots`, exige sa concordance exacte avec l'`InRelease` public,
puis vérifie l'archive, les signatures, le manifeste et les objets ; il refuse
un retour automatique à un instantané plus ancien. La préparation de la PR
valide l'extraction dans un répertoire temporaire avant de rendre la destination
disponible ; une signature ou un objet invalide ne laisse pas d'instantané
partiel utilisable. Une fixture signée répète l'interruption avant déploiement,
le refus d'un état public ancien, puis la reprise après concordance. Les tests
`tests/test_worker_snapshot.py` couvrent notamment les archives dangereuses,
une réponse publique ancienne et le refus d'amorcer sur erreur réseau.

Le 18 novembre, une lecture isolée a téléchargé l'asset
`snapshot-44077918347-1.tar.gz`, vérifié sa taille et son SHA-256 GitHub,
comparé son `InRelease` au document public et vérifié signatures, manifeste,
index, objets by-hash et paquets. L'essai s'est limité à des fichiers sous
`/tmp` : il ne constitue pas une restauration ou un déploiement réels. Sur le
Mac utilisé, GnuPG 2.5 a laissé le résultat clair dans un fichier `.part` ; la
validation locale a utilisé une adaptation ponctuelle pour comparer son contenu.
La procédure CI reste celle du runner Linux existant.
Les deux paquets publics bêta 8 ont aussi été téléchargés en lecture seule : leurs SHA-256 correspondent au manifeste suivi. La clé publique servie est identique à la copie suivie par le projet. Ces comparaisons ne sont pas une installation APT cliente.

En cas d'échec, conserver les preuves, vérifier les versions réellement actives,
l'`InRelease` public, son expiration et les derniers snapshots signés. Ne pas
relancer aveuglément une ancienne exécution, supprimer un snapshot ni supposer
qu'un run rouge signifie absence de déploiement. La documentation existante
`packaging/ci/README.md` décrit les garde-fous et le seuil de rotation des
assets. [Cloudflare précise](https://developers.cloudflare.com/workers/versions-and-deployments/rollbacks/)
qu'un rollback est un déploiement et que sa propagation prend du temps ; il
peut servir une ancienne collection d'assets et des métadonnées expirées. Il ne
réconcilie pas à lui seul l'archive GitHub et le dépôt public. Une restauration
réelle exige donc un scénario, une revue et une autorisation ciblée.

## Réception APT : portée des preuves

- Le [contrôle APT en lecture seule du 18 novembre](https://github.com/williamboglietti/pxe-tools/actions/runs/44379836320)
  utilise un état client vide et la clé suivie : APT a vérifié les métadonnées
  publiques puis téléchargé les deux paquets bêta 8, dont les empreintes
  correspondent au manifeste. Aucun paquet n'a été installé et ce résultat ne
  reçoit ni l'installateur complet ni toutes les distributions.
- La [CI du site](https://github.com/williamboglietti/pxetools.com/actions/runs/44358444883)
  a exécuté l'installateur contre une fixture signée isolée. Elle couvre des
  refus d'empreinte et d'objets altérés ; il s'agit d'un paquet synthétique,
  pas de la bêta 8 publiée.
- Le test `tests/integration/test_apt_release_candidate.sh` construit des
  dépôts et paquets jetables, utilise un état APT distinct, simule l'installation,
  conserve les liens immuables lors d'un changement de version et refuse les
  index ou paquets altérés. Le run planifié ci-dessus l'a exécuté en CI. La
  répétition de transition utilise également un état APT vide, des clés
  jetables et une simulation d'installation du paquet synthétique. Elle simule
  le remplacement atomique d'un trousseau à un emplacement fixe : une
  interruption avant remplacement laisse l'ancienne clé active et le témoin
  de l'ancienne clé est conservé. Cette fixture ne fournit pas de programme
  de mise à jour destiné aux clients existants.
- La [réception bêta 7 sur Zorin](apt-upgrade-lifecycle.md#réception-des-paquets-canoniques-sur-zorin)
  et les essais des versions antérieures ont des périmètres propres. La bêta 8
  n'a pas bénéficié d'une nouvelle campagne native multi-OS, d'une installation
  sur client vierge de chaque plateforme ou d'une réinstallation PXE complète.

Pour une future réception, consigner pour chaque distribution l'environnement
isolé, la version exacte, la source du paquet, la signature et l'empreinte,
les étapes réellement exécutées, ainsi que le résultat et les limites. Les
fixtures synthétiques, le paquet public et un démarrage PXE réel ne sont pas
interchangeables. Ne pas utiliser les machines de William comme banc jetable.
