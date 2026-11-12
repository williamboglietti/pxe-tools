# PXE Tools — préparation de la CI APT

Ce dépôt contient pour l’instant uniquement les outils et tests de préparation de
la signature APT. L’application PXE Tools existante est en cours de révision pour
sa publication ; ce dépôt ne fournit pas encore de version installable.

Le workflow manuel de vérification signe un paquet **fictif** et ne publie ni
ne téléverse le dépôt produit. Il permet de vérifier la sous-clé de production
sans utiliser ni exposer la clé principale. Les modifications proposées sont
vérifiées avec des clés jetables avant l’étape de signature protégée.

`packaging/native/prepare-static-apt.py` contrôle une copie d'un instantané signé
avec une clé publique et les empreintes principales et de signature attendues. Il
vérifie les signatures, les index, les paquets, les objets immuables et la taille
des fichiers avant de préparer un nouveau dossier contenant les actifs statiques
et la seule clé publique. La CI exécute ce contrôle sur un paquet fictif, puis
teste à blanc `packaging/cloudflare/wrangler.jsonc` avec Wrangler.

Le workflow manuel `APT release preparation`, limité à `main` protégée et à
l'environnement `production`, pourra préparer un instantané lorsqu'une release
publique stable fournira un paquet Debian amd64. Il vérifie l'empreinte SHA-256
indiquée par GitHub, signe avec la sous-clé CI existante, valide les fichiers et
exécute Wrangler à blanc. Un numéro d'exécution précédente permet de reprendre
son instantané vérifié. Le résultat est un artefact GitHub temporaire, conservé
90 jours au maximum ; ce mécanisme n'est pas un stockage durable de production.

Ces outils ne publient encore rien : la provenance de construction du paquet,
la conservation durable du dernier instantané entre exécutions, le renouvellement
des métadonnées, les autorisations Cloudflare et le workflow de déploiement réel
restent à raccorder avant la sortie du produit.

Le code de ces outils est distribué sous LGPL-3.0 ; consulter COPYING et
COPYING.LESSER. Les paquets de test sont générés localement et fictifs.
