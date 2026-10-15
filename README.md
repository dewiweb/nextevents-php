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
front/kiosk.php        ← superviseur si le kiosk doit alterner
                         avec une page existante (voir plus bas)
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

Pour alterner avec une page existante (jauges…) sur un kiosk qui
n'accepte qu'une URL : voir **« Alterner avec une page existante »**
ci-dessous — l'option A (`kiosk.php`, superviseur, sans toucher au
code existant) est recommandée.

### Vérifier avant de déployer

| Besoin | Vérification |
|---|---|
| **PHP ≥ 7.4** | `php -v` — le code utilise `fn()` et `??`. PHP plus ancien → nous contacter |
| `mbstring` | quasi toujours présent |
| `curl` ou `allow_url_fopen` | requêtes HTTPS vers openagenda.com |
| `datas/` sous la racine web | ouvrir `/datas/nextevent/<fmt>/html/<fichier>.html` dans le navigateur : la diapo doit s'afficher |

## Alterner avec une page existante (jauges)

Le scénario : `jauges → 2 diapos → jauges → 2 diapos suivantes → …`

Deux façons d'y arriver :

- **Option A — recommandée** : `kiosk.php` supervise l'alternance —
  la page existante est encapsulée dans une iframe, **aucune
  modification de son code**. C'est cette page qui pilote tout.
- **Option B — alternative** : la page existante garde la main et
  intègre le slideshow dans une iframe — pour ceux qui veulent
  piloter l'alternance depuis leur propre code.

### Option A — superviseur `kiosk.php` (recommandée)

Le kiosk affiche **une seule URL** au démarrage : on la pointe sur
`front/kiosk.php`, qui encapsule la page jauges existante dans une
iframe jamais déchargée et y superpose les diapos par cycles.
**Aucune modification du code existant** — le mécanisme de
rafraîchissement de la page jauges (reload, AJAX…) tourne en tâche
de fond et les données restent fraîches :

```
https://<serveur>/front/kiosk.php?jauges=https://<jauges>/page.php&every=60&limit=2
```

- `jauges` : URL de la page à encapsuler — ou constante
  `KIOSK_JAUGES` dans `config.php`, qui a **priorité** (recommandé en
  prod : l'URL du kiosk reste propre et l'iframe ne peut pas être
  détournée vers une URL arbitraire)
- `every` : secondes de jauges entre deux passages (défaut 60)
- `msg` : si posé (ex. `msg=jauges:done`), la bascule part dès que la
  page encapsulée émet `parent.postMessage('jauges:done','*')` —
  durée d'affichage **adaptative** (pagination variable côté
  existant). `every` devient alors la borne max en l'absence du
  message
- `limit` : diapos par passage (défaut 2, `0` = tout le manifest)
- `fmt`, `delay`, `tdur`, `transition` : passés au slideshow

La bascule est **sans latence** : l'iframe diapos précharge le groupe
suivant pendant la phase jauges (`hold=1` du player) et le wrapper
envoie `nextevents:go` au moment de la bascule — la première diapo
est déjà rendue. Elle se fait en **fondu enchaîné** (durée `tdur`) :
les deux iframes étant empilées plein écran, le wrapper joue sur
l'opacité de l'iframe diapos par-dessus les jauges — la page jauges
n'est jamais masquée, son refresh interne continue en dessous.

Seule exigence : la page jauges doit accepter d'être chargée en
iframe (pas de `X-Frame-Options: DENY` ni `frame-ancestors` trop
strict sur le vhost qui la sert —
`curl -sI <url> | grep -iE 'x-frame|security'` pour vérifier). Si
aucune diapo n'est générée ou si le player ne rend pas la main, le
wrapper reste ou revient sur les jauges — jamais bloqué.

Une **page de démo** joue le rôle des jauges pour tester ce scénario
en local avec `php -S` — `front/jauges-demo.php` est une page
« jauges seules » (aucun code diapos), à encapsuler par `kiosk.php` :

```
/front/kiosk.php?jauges=/front/jauges-demo.php&every=15&limit=3&delay=3
```

Ses paramètres : `?refresh=10` simule la mise à jour interne des
données (montre que l'iframe encapsulée n'est jamais déchargée — le
compteur de présence en bas à droite ne repart pas à zéro) ;
`?signal=20` émet `parent.postMessage('jauges:done','*')` toutes les
N secondes — associé à `msg=jauges:done` côté `kiosk.php`, ça
reproduit une page à pagination variable dont la fin de cycle
déclenche la bascule :

```
/front/kiosk.php?jauges=/front/jauges-demo.php?signal=20&msg=jauges:done&every=60&limit=3&delay=3
```

La page démo gère aussi le `PATH_INFO` : si elle est appelée avec un
slash final ou derrière un proxy qui préfixe les requêtes du chemin
du script (`/front/jauges-demo.php/front/slideshow.php`), le fichier
demandé est quand même servi.

### Option B — la page existante garde la main (alternative)

À choisir si l'on préfère piloter l'alternance depuis le code de la
page jauges (ex. déclencheur métier propre à la page, ou l'URL du
kiosk non modifiable). Exemple **complet** à adapter — la page jauges
décide quand montrer les diapos, le slideshow la prévient quand il a
fini :

```html
<!-- dans jauges.php -->
<div id="jauges">… contenu actuel des jauges …</div>
<iframe id="nx" style="border:0;position:fixed;inset:0;display:none">
</iframe>
<script>
const nx = document.getElementById('nx');
const jauges = document.getElementById('jauges');
// hold=1 : le player pose sa 1re diapo puis attend le 'go' — on peut
// donc précharger l'iframe pendant que les jauges sont visibles et
// basculer SANS latence
const SRC = '/front/slideshow.php?fmt=portrait-screen&limit=2&once=1&hold=1';

// phase jauges : précharger le groupe suivant (même URL → le player
// reprend tout seul au groupe suivant via localStorage)
function preload() { nx.src = SRC; }

let onDiapos = false;

// bascule jauges → diapos : la 1re diapo est déjà rendue — le 'go'
// démarre les délais immédiatement, pas de noir ni d'attente
function showDiapos() {
  onDiapos = true;
  jauges.style.display = 'none';
  nx.style.display = 'block';
  nx.contentWindow.postMessage('nextevents:go', '*');
}
setInterval(showDiapos, 60000);   // ou votre propre déclencheur

// player fini de charger après la bascule (boot, réseau lent) :
// on relance le 'go' — le player mémorise aussi un go arrivé tôt
nx.addEventListener('load', () => {
  if (onDiapos) nx.contentWindow.postMessage('nextevents:go', '*');
});

// le slideshow a fini ses diapos : retour aux jauges + préchargement
// du groupe suivant
window.addEventListener('message', e => {
  if (e.data !== 'nextevents:done') return;
  onDiapos = false;
  nx.style.display = 'none';
  jauges.style.display = '';
  preload();
});
preload();
</script>
```

Comment ça marche :

- `limit=2` → le slideshow ne joue que **2 diapos**.
- `once=1` → à la fin, il envoie `postMessage('nextevents:done')`
  à la page mère puis se fige.
- `hold=1` → la 1re diapo est posée dès le chargement puis le player
  attend `postMessage('nextevents:go')` de la page mère — l'iframe
  peut donc être **préchargée** pendant la phase jauges et la bascule
  se fait sans latence (pas de noir, pas d'attente de chargement).
- Avec `transition=fade` (défaut), la première diapo du groupe
  apparaît en fondu depuis le noir et la dernière disparaît en
  fondu **avant** le `postMessage` — pas de coupure franche à
  l'alternance. `slide`/`none` gardent des bords francs.
- **Sans `offset` dans l'URL**, le slideshow mémorise tout seul où il
  en est (`localStorage`) : chaque rechargement reprend au groupe
  **suivant**, et boucle au début après les dernières. La page mère
  n'a rien à compter — elle recharge simplement la même URL, et peut
  couper l'iframe à n'importe quel moment.

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
| `hold` | — | `hold=1` : 1re diapo posée au chargement, démarrage au `postMessage('nextevents:go')` de la page mère |
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
| `CACHE_IMG_DAYS` | `30` | purge du cache images (`0` = jamais) |
| `KIOSK_JAUGES` | `''` | URL fixe encapsulée par `kiosk.php` (vide = `?jauges=` requis) |
| `GEN_TOKEN` | `''` | si renseigné, `gen.php?force=1` exige `&token=…` (cron) |
| `DATA_DIR` / `CACHE_DIR` / `ASSET_DIR` | `datas/…` / `assets` | chemins — `DATA_DIR` doit être inscriptible |

## Dépannage

| Symptôme | Cause probable | Correctif |
|---|---|---|
| « repart toujours sur les 2 premières diapos » | `offset=` présent dans l'URL | le retirer — la reprise est automatique |
| | kiosk qui purge les données de site | autoriser `localStorage` pour le domaine |
| « aucune diapo » à l'écran | `gen.php` jamais lancé ou en erreur | appeler `gen.php?force=1`, lire `error` |
| **latence / écran noir à la bascule** jauges → diapos | iframe chargée au moment de la bascule (player + diapo base64 à télécharger) | `hold=1` + précharger l'iframe pendant la phase jauges + envoyer `postMessage('nextevents:go')` à la bascule (exemple ci-dessus) |
| la **dernière diapo du groupe précédent flashe** avant les nouvelles | iframe réaffichée avant la fin de son rechargement | réassigner `src` en laissant l'iframe **cachée** et ne la montrer qu'à l'événement `load` (voir l'exemple ci-dessus) |
| flash « diapo portrait → paysage » au début d'un passage | ancienne version du player | `git pull` — depuis le fix, chaque diapo est masquée jusqu'à sa mise à l'échelle (`fit()`) |
| | `datas/` hors racine web | ouvrir un `slide-*.html` directement pour vérifier |
| `gen.php` renvoie `error` | OpenAgenda injoignable / pas de sortie HTTPS | vérifier `curl`/`allow_url_fopen` et le firewall |
| erreur `HTTP impossible : extension curl absente ET allow_url_fopen=0` | PHP compilé/configuré sans transport HTTP | activer `extension=curl` dans `php.ini` (recommandé) ou `allow_url_fopen=On` |
| erreur `SSL certificate problem` (local Windows) | PHP portable sans bundle CA | `curl.cainfo=` et `openssl.cafile=` → chemin d'un `cacert.pem` dans `php.ini`, ou `HTTP_VERIFY_SSL=false` dans `config.php` (test local seulement) |
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
  `?force=1` peut être restreint par IP côté serveur web ou par
  `GEN_TOKEN` dans `config.php` (`gen.php?force=1&token=…` — le cron
  inclut le token, le CLI n'est pas concerné).
- **`kiosk.php`** encapsule une page externe : fixer `KIOSK_JAUGES`
  en prod verrouille l'URL — le paramètre `?jauges=` est alors ignoré.
- **`jauges-demo.php`** est un outil de test : son routeur PATH_INFO
  n'exécute que `front/*.php` et ne sert en statique que `datas/`
  (jamais de dotfile, `.git/` inclus) — peut être omis du déploiement.
- **Sécurité** : un seul flux sortant (HTTPS vers `openagenda.com` +
  `leschampslibres.fr` pour les fontes, mises en cache) ; aucune clé
  exposée ; aucun port ni service supplémentaire.
- **Différences avec l'app de bureau** : pas de PNG ni de « diapo du
  jour » ; source OpenAgenda uniquement (pas de scraping du site →
  fond neutre sur les diapos) ; pas de synchro FTP/SMB.
