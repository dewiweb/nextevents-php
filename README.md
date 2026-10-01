# nextevents-php

Diaporama des prochains événements des **Champs Libres**, servi en HTML
directement — port PHP léger de
[nextevents-desktop](https://github.com/dewiweb/nextevents-desktop),
pensé pour le serveur qui héberge déjà l'affichage des jauges.

Le navigateur du kiosk fait le rendu : **pas de PNG à générer**, pas de
Chromium headless, aucune dépendance — juste du PHP (cURL ou
`allow_url_fopen`) et un dossier inscriptible.

## Principe

```
OpenAgenda ──fetch──> gen.php ──> datas/nextevent/<fmt>/html/slide-*.html
                                  datas/nextevent/<fmt>/manifest.txt
                                        │
Chrome kiosk ──> slideshow.php ──> iframes + fondu, re-poll du manifest
```

- `gen.php` appelle l'**export public OpenAgenda** (aucune clé requise ;
  l'API v2 est supportée si `OA_API_KEY` est configurée), mappe les
  événements et écrit des **diapos HTML autonomes** (fontes Oldschool
  Grotesk + images embarquées en base64 — aucun asset externe requis).
- `slideshow.php` empile les diapos en `<iframe>`, les met à l'échelle
  du viewport (le gabarit est dessiné en pixels fixes) et les fait
  défiler en fondu.
- **Régénération paresseuse** : si `manifest.txt` a plus de
  `REFRESH_MIN` minutes, la page déclenche `gen.php` en tâche de fond.
  Aucune tâche planifiée nécessaire — un cron quotidien sur
  `php front/gen.php` reste possible en complément.

## Déploiement

Copier l'arborescence dans l'application PHP existante (par ex. à côté
de `front/jauges.php`) :

```
front/slideshow.php    ← page kiosk (ou iframe dans jauges.php)
front/gen.php          ← génération (lazy ou cron)
inc/*.php
assets/*.html|css|svg  ← gabarits de diapos (identiques au desktop)
datas/nextevent/       ← dossier inscriptible par PHP
config.php             ← agenda, catégories, délais, specs
```

Puis pointer le kiosk Chrome sur :

```
https://<serveur>/front/slideshow.php?fmt=portrait-screen
```

ou intégrer dans la page des jauges :

```html
<iframe src="/front/slideshow.php?fmt=portrait-screen"
        style="border:0; width:100%; height:100%"></iframe>
```

## Paramètres d'affichage (query)

| Param | Défaut | Description |
|---|---|---|
| `fmt` | `portrait-screen` | format des diapos |
| `delay` | `8` s | durée d'affichage par diapo |
| `transition` | `fade` | `fade`, `slide`, `none` |
| `tdur` | `1500` ms | durée de transition |

## Réglages (`config.php`)

Tous les réglages sont des constantes PHP — pas d'interface, on édite
le fichier. La plupart reprennent à l'identique les réglages de l'app
de bureau.

### Agenda

| Constante | Défaut | Description |
|---|---|---|
| `OA_AGENDA` | `'leschampslibres'` | slug lisible (`openagenda.com/fr/<slug>`) ou uid numérique |
| `OA_API_KEY` | `''` | vide → export public legacy ; renseignée → API v2 officielle (timings filtrés serveur, meilleure couverture) |
| `OA_CATEGORIES` | les 5 rubriques vitrine | valeurs `categorie` OpenAgenda : `rencontre`, `concert`, `projection`, `spectacle`, `evenement` (défaut) + `animation`, `atelier`, `atelier-4c`, `visite`, `exposition` |

### Génération

| Constante | Défaut | Description |
|---|---|---|
| `FORMATS` | `['portrait-screen', 'landscape']` | formats produits : `portrait-screen` (1080×1920, écran 9:16), `landscape` (1920×1080), `portrait` (A4) |
| `MAX_EVENTS` | `0` | nombre max d'événements par format (0 = tous) |
| `NEXT_LABEL` | `'Prochaine séance : '` | préfixe des événements récurrents (vide = date seule) |
| `SERIES_MAP` | `grandstemoins = Les grands témoins` | `keyword-oa = Libellé` par ligne — marque la série éditoriale |

### Specs affichées

Mêmes règles que l'app de bureau :

| Constante | Format | Description |
|---|---|---|
| `SPECS_SHOW` | `'Durée,Lieu,Tarif'` | liste à virgules des specs affichées ; vide = toutes ; `Date` est toujours conservée (nommage des fichiers) |
| `SPEC_OVERRIDES` | `'Lieu = Hall'` par ligne | force ou ajoute une spec — ex. corriger un lieu OA qui désigne le bâtiment et pas la salle |
| `SPEC_DROPS` | `'Dispositifs d''écoute amplifiée'` | items retirés à virgules — enlève une valeur d'une liste « · » sans masquer la spec entière |

### Diaporama

| Constante | Défaut | Description |
|---|---|---|
| `SLIDE_DELAY` | `8` | secondes d'affichage par diapo |
| `SLIDE_TRANSITION` | `'fade'` | `fade`, `slide` ou `none` |
| `SLIDE_TRANS_MS` | `1500` | durée de la transition |

Ces trois réglages sont les **défauts servis par le manifest** — la page
peut les surcharger par query sans toucher la config :
`?delay=6&transition=slide&tdur=800`.

### Fraîcheur et chemins

| Constante | Défaut | Description |
|---|---|---|
| `REFRESH_MIN` | `60` | âge max du manifest (minutes) avant régénération paresseuse |
| `DATA_DIR` | `datas/nextevent` | sortie des diapos — doit être inscriptible par PHP |
| `CACHE_DIR` | `datas/nextevent/cache` | cache fontes + images |
| `ASSET_DIR` | `assets` | gabarits — ne pas toucher |

## Différences avec l'app de bureau

- Pas de rendu PNG ni de « diapo du jour » — le kiosk affiche le HTML.
- Source unique : **OpenAgenda** (pas de scraping du site) → la
  couleur éditoriale des cartes n'est pas disponible, les diapos
  utilisent le fond neutre par défaut.
- Pas de synchro FTP/SMB : les fichiers restent sur le serveur.

## Sécurité

- Un seul flux sortant : HTTPS vers `openagenda.com` (+
  `leschampslibres.fr` pour les deux fichiers de fontes, mis en cache).
- Aucune clé exposée au navigateur ; l'export public ne requiert pas
  de clé.
- Aucun port ou service supplémentaire : du PHP dans l'appli existante.
