# Logos de distributions

Ce dossier contient les fichiers SVG des logos affichés dans les badges
distribution (table `/settings/images`, picker `/settings/images/add`, etc.).

## Source

Les SVG ont été indiqués comme provenant du projet **Simple Icons**
(https://simpleicons.org/), une bibliothèque ouverte de pictogrammes.
Le projet Simple Icons est distribué sous CC0 1.0, mais précise que cela
ne signifie pas que tous les pictogrammes bénéficient individuellement de
cette licence. Le titulaire du projet a confirmé les droits d'utilisation des
fichiers actuels. Les attributions et conditions identifiées sont documentées
dans `third_party/licenses/distribution-logos.md` ; voir également :
https://github.com/simple-icons/simple-icons/blob/develop/DISCLAIMER.md.
Les marques restent la propriété de leurs titulaires.

## Mise à jour

Pour rafraîchir les logos depuis le CDN Simple Icons :

```bash
./scripts/download-distro-logos.sh
```

Le script remplace les fichiers existants depuis une source susceptible de
changer. Vérifier la provenance, les licences et les droits de chaque nouvelle
version avant de l'inclure dans une distribution.

## Ajouter une nouvelle distribution

1. Ajouter une entrée dans `scripts/download-distro-logos.sh` :
   ```
   "<code>|<slug Simple Icons>|<hex sans #>"
   ```
   Le `code` doit correspondre à un identifiant utilisé côté DB
   (`os_images.distro`), le `slug` à un slug existant sur simpleicons.org,
   le `hex` à la couleur de marque (qui doit aussi être déclarée dans
   `.distro-icon-<code>` du fichier `app/public/style.css`).

2. Relancer le script.

3. Commit + push.

## Comportement runtime

`View::distroLogo($code)` (cf. `app/src/View.php`) vérifie si
`<code>.svg` existe ici :
- Présent → sert le SVG via `<img>` (style transparent, classe
  `.distro-icon--svg` dans `style.css`).
- Absent → fallback sur le carré coloré + initiale en majuscule
  (`.distro-icon-<code>`).

Un cache-buster `?v=mtime` est ajouté à l'URL pour éviter que le
navigateur cache une ancienne version après mise à jour du SVG.
