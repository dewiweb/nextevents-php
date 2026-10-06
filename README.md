# nextevents-php

Affiche les **prochains événements des Champs Libres** en diaporama
HTML — port PHP léger de
[nextevents-desktop](https://github.com/dewiweb/nextevents-desktop),
pensé pour le serveur qui héberge déjà l'affichage des jauges.

C'est le navigateur du kiosk qui fait le rendu : **pas de PNG à
générer**, pas de Chromium, aucune dépendance — juste PHP et un
dossier inscriptible.

## En 30 secondes

```
OpenAgenda ──> gen.php ──> datas/nextevent/<fmt>/html/slide-*.html
                                 + manifest.txt
kiosk Chrome ─> slideshow.php ─> affiche les diapos en fondu
```

- `front/gen.php` récupère les événements OpenAgenda (export public,
  **aucune clé requise**) et écrit des diapos HTML autonomes —
  fontes et images embarquées en base64.
- `front/slideshow.php` les fait défiler. Si les données sont trop
  vieilles (`REFRESH_MIN`), il relance `gen.php` tout seul —
  **aucun cron nécessaire**.
- Si OpenAgenda est en panne, les dernières diapos connues continuent
  de tourner — l'écran ne se vide jamais.

## Déploiement en 3 étapes

**1. Copier l'arborescence** dans l'application PHP existante, en
conservant l'organisation relative (`slideshow.php` référence
`../datas/` et `../config.php`) :

```
config.php             ← les réglages, à éditer
front/slideshow.php    ← la page à afficher sur le kiosk
front/gen.php          ← la génération (appelée toute seule)
inc/  assets/          ← code et gabarits, ne pas toucher
datas/nextevent/       ← sortie, doit être INSCRIPTIBLE par PHP
```

**2. Forcer une première génération** depuis le navigateur :

```
https://<serveur>/front/gen.php?force=1
```

→ doit répondre `{"status":"ok","events":N,...}`. Si `error`, le
message dit pourquoi (HTTP, écriture…).

**3. Ouvrir le diaporama** :

```
https://<serveur>/front/slideshow.php?fmt=portrait-screen
```

→ les diapos défilent en plein écran. C'est fini.

### Vérifier avant de déployer

| Besoin | Vérification |
|---|---|
| **PHP ≥ 7.4** | `php -v` — le code utilise `fn()` et `??`. PHP plus ancien → nous contacter |
| `mbstring` | quasi toujours présent |
| `curl` ou `allow_url_fopen` | requêtes HTTPS vers openagenda.com |
| `datas/` sous la racine web | ouvrir `/datas/nextevent/<fmt>/html/<fichier>.html` dans le navigateur : la diapo doit s'afficher |

## Intégrer dans la page des jauges

Le scénario : `jauges → 2 diapos → jauges → 2 diapos suivantes → …`

Exemple **complet** à adapter — la page jauges décide quand montrer
les diapos, le slideshow la prévient quand il a fini :

```html
<!-- dans jauges.php -->
<div id="jauges">… contenu actuel des jauges …</div>
<iframe id="nx" style="border:0;position:fixed;inset:0;display:none">
</iframe>
<script>
const nx = document.getElementById('nx');
const jauges = document.getElementById('jauges');
const SRC = '/front/slideshow.php?fmt=portrait-screen&limit=2&once=1';

// toutes les 60 s : cacher les jauges, lancer 2 diapos
setInterval(() => {
  jauges.style.display = 'none';
  nx.style.display = 'block';
  nx.src = SRC;    // recharge la MÊME url
}, 60000);

// le slideshow a fini ses 2 diapos : retour aux jauges
window.addEventListener('message', e => {
  if (e.data !== 'nextevents:done') return;
  nx.style.display = 'none';
  jauges.style.display = '';
});
</script>
```

> ⚠️ Ne mettez pas `src` sur l'iframe au chargement de la page :
> cachée mais active, elle consommerait le premier groupe de diapos
> en restant invisible. Posez `src` à la première alternation
> (comme ci-dessus).

Comment ça marche :

- `limit=2` → le slideshow ne joue que **2 diapos**.
- `once=1` → à la fin, il envoie `postMessage('nextevents:done')`
  à la page mère puis se fige.
- **Sans `offset` dans l'URL**, le slideshow mémorise tout seul où il
  en est (`localStorage`) : chaque rechargement reprend au groupe
  **suivant**, et boucle au début après les dernières. La page mère
  n'a rien à compter — elle recharge simplement la même URL, et peut
  couper l'iframe à n'importe quel moment.

Une **page de démo** reproduit ce cycle avec de fausses jauges :
`front/jauges-demo.php` (paramètres `?every=&limit=&delay=&fmt=`) —
pratique pour valider l'alternance en local avec `php -S` avant
d'intégrer dans la vraie page.

> ⚠️ **Ne pas mettre `offset` dans l'URL.** Sa présence désactive la
> reprise automatique : avec `offset=0` fixe, le diaporama rejoue
> indéfiniment les mêmes diapos. Si vous préférez piloter la position
> vous-même, incrémentez `offset` à chaque cycle — le nombre total de
> diapos est lisible via `slideshow.php?manifest=<fmt>`
> (`r.slides.length`).

## Paramètres de l'URL

| Paramètre | Défaut | Effet |
|---|---|---|
| `fmt` | `portrait-screen` | format : `portrait-screen` (1080×1920), `landscape` (1920×1080) |
| `delay` | `8` s | durée d'affichage par diapo |
| `transition` | `fade` | `fade`, `slide`, `none` |
| `tdur` | `1500` ms | durée de transition |
| `limit` | tout | joue seulement N diapos |
| `offset` | — | démarre à la diapo N (**désactive la reprise auto**) |
| `once` | — | `once=1` : s'arrête après `limit` diapos + `postMessage` vers la page mère |
| `manifest=<fmt>` | — | renvoie le JSON `{slides, delay, transition, tdur}` au lieu de la page |

## Réglages (`config.php`)

Tout se règle dans `config.php` (constantes PHP commentées) — pas
d'interface. Les plus utiles :

| Constante | Défaut | Rôle |
|---|---|---|
| `OA_AGENDA` | `'leschampslibres'` | agenda OpenAgenda (slug ou uid) |
| `OA_API_KEY` | `''` | vide → export public ; renseignée → API v2 officielle (plus complète) |
| `OA_CATEGORIES` | les 5 vitrines | catégories OA : `rencontre`, `concert`, `projection`, `spectacle`, `evenement` + `animation`, `atelier`, `atelier-4c`, `visite`, `exposition` possibles |
| `FORMATS` | `['portrait-screen','landscape']` | formats générés |
| `MAX_EVENTS` | `12` | diapos max par format (`0` = toutes) |
| `DAY_OFFSET_MIN` / `MAX` | `0` / `null` | fenêtre en jours sur la prochaine séance — `1,1` = demain seul |
| `NEXT_LABEL` | `'Prochaine séance : '` | préfixe des récurrents (vide = date seule) |
| `SERIES_MAP` | `grandstemoins = …` | `keyword-oa = Libellé` par ligne — marque la série |
| `SPECS_SHOW` | `''` | specs affichées, virgules (vide = toutes ; `Date` toujours gardée) |
| `SPEC_OVERRIDES` | `''` | `Clé = valeur` par ligne — force/ajoute une spec |
| `SPEC_DROPS` | `''` | items retirés d'une spec, virgules — ex. `'Dispositifs d\'écoute amplifiée'` enlève cette mention d'`Accessibilité` sans masquer `LSF · Surtitrage` |
| `SLIDE_DELAY` / `SLIDE_TRANSITION` / `SLIDE_TRANS_MS` | `8` / `fade` / `1500` | défauts servis par le manifest (surchargeables en query) |
| `REFRESH_MIN` | `60` | âge max des données avant régénération auto |
| `CACHE_IMG_DAYS` | `90` | purge du cache images (`0` = jamais) |
| `DATA_DIR` / `CACHE_DIR` / `ASSET_DIR` | `datas/…` / `assets` | chemins — `DATA_DIR` doit être inscriptible |

## Dépannage

| Symptôme | Cause probable | Correctif |
|---|---|---|
| « repart toujours sur les 2 premières diapos » | `offset=` présent dans l'URL | le retirer — la reprise est automatique |
| | kiosk qui purge les données de site | autoriser `localStorage` pour le domaine |
| « aucune diapo » à l'écran | `gen.php` jamais lancé ou en erreur | appeler `gen.php?force=1`, lire `error` |
| | `datas/` hors racine web | ouvrir un `slide-*.html` directement pour vérifier |
| `gen.php` renvoie `error` | OpenAgenda injoignable / pas de sortie HTTPS | vérifier `curl`/`allow_url_fopen` et le firewall |
| écriture impossible | `datas/nextevent/` non inscriptible | droits en écriture pour PHP |
| page blanche / erreur 500 | PHP < 7.4 | `php -v` — adapter la syntaxe (nous contacter) |
| les diapos ne se renouvellent pas | `REFRESH_MIN` trop grand | un `cron` sur `php front/gen.php` force la mise à jour |

En CLI : `php front/gen.php` force une génération (cron possible) ;
`php -l` sur les fichiers vérifie la syntaxe avant déploiement.

## Détails techniques

- **Purge automatique** : les `slide-*.html` hors programme sont
  supprimés à chaque génération ; `manifest.txt` est réécrit ; le
  cache images est purgé après `CACHE_IMG_DAYS` jours sans usage.
- **`gen.php`** est verrouillé par `flock` (pas de double exécution) ;
  `?force=1` peut être restreint par IP côté serveur web.
- **Sécurité** : un seul flux sortant (HTTPS vers `openagenda.com` +
  `leschampslibres.fr` pour les fontes, mises en cache) ; aucune clé
  exposée ; aucun port ni service supplémentaire.
- **Différences avec l'app de bureau** : pas de PNG ni de « diapo du
  jour » ; source OpenAgenda uniquement (pas de scraping du site →
  fond neutre sur les diapos) ; pas de synchro FTP/SMB.
