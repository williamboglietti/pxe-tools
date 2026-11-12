# PXE Tools — préparation de la CI APT

Ce dépôt contient pour l’instant uniquement les outils et tests de préparation de
la signature APT. L’application PXE Tools existante est en cours de révision pour
sa publication ; ce dépôt ne fournit pas encore de version installable.

Le workflow manuel de vérification signe un paquet **fictif** et ne publie ni
ne téléverse le dépôt produit. Il permet de vérifier la sous-clé de production
sans utiliser ni exposer la clé principale. Les modifications proposées sont
vérifiées avec des clés jetables avant l’étape de signature protégée.

Le code de ces outils est distribué sous LGPL-3.0 ; consulter COPYING et
COPYING.LESSER. Les paquets de test sont générés localement et fictifs.
