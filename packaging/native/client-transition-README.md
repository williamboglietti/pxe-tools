# Candidat de transition du certificat APT

Ce dossier est un candidat de code pour une transition **préparée mais non
activée**. Il ne contient aucun certificat, index signé ni secret. Ne pas
l'exécuter sur une machine de production avant revue et autorisation de la
transition concernée. `SHA256SUMS` permet de vérifier l'intégrité des fichiers,
mais pas leur authenticité : vérifier indépendamment la provenance du dossier.

La commande `python3 packaging/native/prepare-client-key-transition.py --help`
décrit les paramètres. Il faut notamment obtenir par un canal indépendant les
empreintes complètes et vérifiées de la primaire et des deux sous-clés, ainsi
que les anciens et nouveaux index signés. `--inspect-system` ne modifie pas APT.
`--apply-system` demande root et une confirmation explicite de ces empreintes ;
il ne télécharge rien et ne déclenche pas de signature ni de rotation serveur.
Le nouvel index fourni doit être signé par la nouvelle sous-clé et sa date
`Valid-Until` doit être future selon l'horloge du client ; l'ancien index peut
être une preuve historique expirée. Les configurations APT alternatives, les
reprises après interruption et la procédure de production doivent encore être
revues avant une utilisation réelle.

`python3 packaging/native/stage-client-transition-data.py --help` décrit la
préparation d'un dossier local contenant les deux certificats publics, les
deux index signés et leurs empreintes. Le programme vérifie les signatures
avant de produire le dossier ; il n'envoie rien. Son manifeste n'authentifie
pas ses propres empreintes et ne démontre pas la fraîcheur des index : les
vérifications indépendantes restent nécessaires côté opérateur et client.

Pour un client hors ligne, transporter les données publiques sur un support
vérifié et obtenir un index actuel signé par la nouvelle sous-clé. Si l'index
fourni est expiré, obtenir une preuve récente depuis une source de confiance ;
ne pas désactiver le contrôle de date. Vérifier l'horloge, la révocation des
clés et les empreintes sur un canal indépendant. L'application du certificat
ne reconnecte pas le client et ne constitue pas un `apt update` réussi.
