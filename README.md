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

Ces outils ne publient encore rien : la provenance d'un paquet de release, la
conservation durable du dernier instantané entre exécutions, le renouvellement
des métadonnées, les autorisations Cloudflare et le workflow de déploiement réel
restent à raccorder avant la sortie du produit.

Le code de ces outils est distribué sous LGPL-3.0 ; consulter COPYING et
COPYING.LESSER. Les paquets de test sont générés localement et fictifs.
