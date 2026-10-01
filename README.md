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

- `OA_AGENDA` : slug ou uid numérique de l'agenda
- `OA_API_KEY` : vide = export public legacy ; renseignée = API v2
- `OA_CATEGORIES` : catégories OA affichées
- `FORMATS` : `['portrait-screen', 'landscape']`
- `SPECS_SHOW` / `SPEC_OVERRIDES` / `SPEC_DROPS` : mêmes règles que
  l'app de bureau (masquer/forcer des specs, retirer un item)
- `NEXT_LABEL` : préfixe des événements récurrents
- `REFRESH_MIN` : âge max du manifest avant régénération

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
