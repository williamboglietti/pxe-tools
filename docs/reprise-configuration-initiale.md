# Configuration initiale interrompue après la première visite

Sur la bêta 4, une visite de l'interface avant l'assistant peut créer une base
SQLite vide. Au redémarrage suivant, le service peut créer `db.conf`, puis
échouer sur `presets.static_cidr absente`. Un fichier `db.conf` contenant
seulement un pilote SQLite et un chemin ne prouve pas qui l'a créé : la même
configuration peut avoir été fournie par un administrateur. La taille de la
base seule ne démontre pas non plus qu'elle ne contient aucune donnée.

Quand `db.conf` existe déjà et que l'initialisation échoue, le paquet conserve
la configuration et la base et refuse de démarrer. Il ne propose pas encore de
reprise automatique de ce cas. Ne supprimez ni ne remplacez `db.conf`, la base,
ses fichiers `-wal`, `-shm` ou `-journal`, la clé maîtresse ou les autres
fichiers persistants pour forcer le démarrage.

## Établir l'état et préserver l'original

1. Prévoyez une fenêtre d'arrêt, arrêtez les services PXE Tools et assurez-vous
   qu'aucun autre processus n'utilise la base. Inventoriez aussi les chemins
   des données, la configuration, la clé maîtresse, les certificats, la MOK,
   les journaux et les surcharges systemd. Ne publiez pas leurs contenus.
2. Conservez une copie protégée de l'ensemble de l'instance, configuration et
   fichiers SQLite auxiliaires compris. Vérifiez qu'elle est lisible et
   conservez le paquet installé. La procédure de [sauvegarde et restauration](backup-restore.md)
   décrit les catégories à inventorier ; son export peut refuser une base
   incomplète ou des journaux présents. Un tel refus ne doit pas être contourné
   en supprimant ces journaux.
3. Examinez sur une **copie isolée** le chemin sélectionné par `db.conf`, les
   fichiers auxiliaires et l'intégrité SQLite. Utilisez une ouverture en lecture
   seule ; si un journal ou un WAL non vide est présent, si l'intégrité échoue,
   si des objets de schéma existent ou si un processus peut écrire, arrêtez
   l'analyse et conservez tous les fichiers. Une inspection `immutable` qui
   ignore un WAL ne prouve pas l'absence de données dans ce WAL.

Même si la copie révèle une base intègre sans objet et qu'aucun journal n'est
présent, ce constat ne prouve pas que les autres fichiers de l'instance sont
jetables. Comparez les résultats à votre inventaire et à vos sauvegardes.

## Reprendre sans modifier l'instance d'origine

Si l'inventaire confirme que vous souhaitez repartir de zéro, préparez une
**instance neuve et isolée**, sur une autre VM ou un autre disque, avec un
paquet corrigé dont la version et la provenance ont été vérifiées. Ne recopiez
pas automatiquement l'ancien `db.conf`, la base, la clé maîtresse, la MOK ou
la configuration réseau : leur réutilisation et la migration des autres
données nécessitent une procédure adaptée, qui n'est pas encore validée pour
ce cas. Lancez l'assistant de la nouvelle instance et vérifiez ses services
avant de décider de son raccordement au réseau PXE.

Conservez l'original arrêté et sa copie protégée pour examen ou retour arrière.
Cette reprise crée une instance distincte ; elle ne restaure ni les comptes,
machines, paramètres ou clés de l'ancienne. La correspondance des clés et
secrets et le transfert sélectif ne sont pas établis par le constat d'une base
vide. La procédure n'a pas encore été exécutée de bout en bout pour ce cas.
