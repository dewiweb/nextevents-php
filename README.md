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
kiosk Chrome ─> kiosk.php ─> alterne page existante (jauges)
                │               et diapos, fondus au noir (option A)
                └─ ou ─> slideshow.php ─> diapos seules
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
front/jauges-demo.php  ← page de test « jauges » (peut être omise)
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
| la page jauges accepte l'iframe (option A) | `curl -sI <url-jauges> \| grep -iE 'x-frame\|frame-ancestors'` → rien ; et même schéma `http`/`https` que le superviseur |

## Alterner avec une page existante (jauges)

Le scénario : `jauges → 2 diapos → jauges → 2 diapos suivantes → …`

Deux façons d'y arriver :

- **Option A — recommandée** : `kiosk.php` supervise l'alternance —
  la page existante est encapsulée dans une iframe, **aucune
  modification du code existant**. C'est le superviseur qui pilote
  tout.
- **Option B — alternative** : la page existante garde la main et
  intègre le slideshow dans une iframe — pour piloter l'alternance
  depuis le code existant.

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
- `every` : secondes de jauges entre deux passages (défaut 60,
  minimum 10)
- `msg` : si posé (ex. `msg=jauges:done`), la bascule part dès que la
  page encapsulée émet `parent.postMessage('jauges:done','*')` —
  durée d'affichage **adaptative** (pagination variable côté
  existant). `every` devient alors la borne max en l'absence du
  message
- `limit` : diapos par passage (défaut 2, `0` = tout le manifest)
- `fmt`, `delay`, `tdur`, `transition` : passés au slideshow —
  par défaut les `SLIDE_*` de `config.php`

Exemples, du plus simple au plus réglé :

```
# dev — cible passée en paramètre, tout le reste par défaut
# (60 s de jauges, 2 diapos, SLIDE_* du config)
/front/kiosk.php?jauges=https://<jauges>/page.php

# prod — KIOSK_JAUGES fixé dans config.php : 30 s de jauges,
# 3 diapos par passage
/front/kiosk.php?every=30&limit=3

# alternance pilotée par la page existante : la bascule part sur
# son postMessage, 90 s = borne max si le message n'arrive jamais
/front/kiosk.php?every=90&msg=jauges:done&limit=3

# affichage réglé différemment des défauts config pour CE kiosk :
# diapos 12 s, transition glissée de 800 ms
/front/kiosk.php?every=30&limit=3&delay=12&transition=slide&tdur=800

# tout le manifest à chaque passage, écran paysage
/front/kiosk.php?every=30&limit=0&fmt=landscape
```

La bascule est **sans latence** : l'iframe diapos précharge le groupe
suivant pendant la phase jauges (`hold=1` du player) et le wrapper
envoie `nextevents:go` au moment de la bascule — la première diapo
est déjà rendue. Chaque transition est un **fondu au noir** : un voile
noir plein écran monte (durée `tdur`), le contenu s'échange dessous,
puis le voile redescend (`tdur` encore — une bascule complète dure
donc ≈ 2×`tdur`). La page jauges n'est jamais masquée ni déchargée,
le refresh interne du code existant continue en dessous.

Deux exigences :

- la page encapsulée doit être une **vue « jauges seules »** — sans
  alternance interne vers d'autres contenus. Si la page existante
  gère déjà ses propres diapos ou sa pagination vers d'autres écrans,
  elle tournerait en parallèle du superviseur ; pointer alors une
  variante « jauges pures » (ex. `page.php?noevents=1` si le code
  offre ce paramètre, ou l'ancienne page avant intégration)
- la page jauges doit accepter d'être chargée en iframe (pas de
  `X-Frame-Options: DENY` ni `frame-ancestors` trop strict sur le
  vhost qui la sert — `curl -sI <url> | grep -iE 'x-frame|security'`
  pour vérifier) et partager le **même schéma** `http`/`https` que le
  superviseur

Si aucune diapo n'est générée ou si le player ne rend pas la main,
le wrapper reste ou revient sur les jauges — jamais bloqué.

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

**Mise en production (option A), pas à pas :**

1. Dans `config.php`, fixer la page encapsulée et le token de
   génération forcée :
   ```php
   define('KIOSK_JAUGES', 'https://<jauges>/page.php');
   define('GEN_TOKEN', '<secret>');   // si un cron appelle ?force=1
   ```
2. Vérifier que la page jauges pointée est une vue **« jauges
   seules »** (pas d'alternance interne vers d'autres contenus —
   sinon elle tournerait en parallèle du superviseur) et qu'elle
   accepte l'iframe + partage le même schéma que le superviseur
   (Chrome refuse une iframe `http` dans une page `https`) :
   ```
   curl -sI https://<jauges>/page.php | grep -iE 'x-frame|frame-ancestors'
   ```
   → aucune interdiction ne doit apparaître.
3. Pointer le navigateur du kiosk sur le superviseur :
   ```
   chrome --kiosk "https://<serveur>/front/kiosk.php?every=30&limit=3"
   ```
   (`jauges=` inutile — `KIOSK_JAUGES` fixe la cible ; ajouter
   `&msg=jauges:done` seulement si la page encapsulée émet le signal.)
4. Cron facultatif — les diapos se régénèrent déjà seules via
   `REFRESH_MIN`, mais pour forcer l'heure :
   ```
   0 10 * * * curl -s "https://<serveur>/front/gen.php?force=1&token=<secret>" >/dev/null
   ```
   ou en CLI, sans token : `php /chemin/front/gen.php`.

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
setInterval(showDiapos, 60000);   // ou le déclencheur existant

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
  se fait sans latence (pas d'attente de chargement).
- **Messages émis par le player** (la page mère les écoute ou non) :
  `nextevents:ready` dès qu'une diapo est réellement affichée (utile
  pour révéler une iframe préchargée sans flash),
  `nextevents:ending` au début du fondu de sortie de fin de groupe
  (le parent peut fondre en parallèle — utilisé par `kiosk.php`),
  puis `nextevents:done` à la toute fin.
- Avec `transition=fade` (défaut), la première diapo du groupe
  apparaît en fondu depuis le noir et la dernière disparaît en
  fondu **avant** le `postMessage('nextevents:done')` — pas de
  coupure franche pour une page mère qui masque l'iframe sec.
  `slide`/`none` gardent des bords francs.
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
| `delay` | `8` s | durée d'affichage par diapo (plancher 2 s côté player) |
| `transition` | `fade` | `fade`, `slide`, `none` — transitions **entre diapos** |
| `tdur` | `1500` ms | durée de chaque sens de transition — dans `kiosk.php`, pilote aussi le voile noir (une bascule ≈ 2×`tdur`) |
| `limit` | tout | joue seulement N diapos |
| `offset` | — | démarre à la diapo N (**désactive la reprise auto**) |
| `once` | — | `once=1` : s'arrête après `limit` diapos + `postMessage` vers la page mère |
| `hold` | — | `hold=1` : 1re diapo posée au chargement, démarrage au `postMessage('nextevents:go')` de la page mère |
| `manifest=<fmt>` | — | renvoie le JSON `{slides, delay, transition, tdur}` au lieu de la page |

**URL ou `config.php` ?** Les réglages se répartissent en trois
catégories — seules les deux premières sont à connaître :

- **`config.php`** : ce que l'exploitant règle **une fois** —
  `SLIDE_DELAY`, `SLIDE_TRANSITION`, `SLIDE_TRANS_MS`, `FORMATS`,
  `KIOSK_JAUGES`, `GEN_TOKEN`… Les paramètres URL `delay`, `tdur`,
  `transition`, `fmt` et `jauges` n'existent que pour **surcharger
  ces défauts au test**.
- **L'URL du kiosk** : les choix d'intégration **par écran** —
  `every`, `limit`, `msg` (rythme d'alternance, taille des groupes,
  déclencheur). Pas d'équivalent en config : deux kiosks peuvent
  vouloir des rythmes différents sur la même installation.
- **Protocole interne** — à ne pas régler à la main : `once`,
  `hold`, `offset` (reprise automatique), `manifest` (endpoint JSON),
  `force`/`token` (action de génération), `refresh`/`signal`
  (paramètres de la page de démo), et les `postMessage` de
  coordination `nextevents:go`/`ready`/`ending`/`done`.

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
| `SPEC_DROPS` | `''` | items retirés d'une spec, virgules — ex. `'Écoute amplifiée'` enlève cette mention d'`Accessibilité` sans masquer `LSF · Surtitrage` |
| `SLIDE_DELAY` / `SLIDE_TRANSITION` / `SLIDE_TRANS_MS` | `8` / `fade` / `1500` | défauts servis par le manifest (surchargeables en query) |
| `REFRESH_MIN` | `60` | âge max des données avant régénération auto |
| `CACHE_IMG_DAYS` | `30` | purge du cache images (`0` = jamais) |
| `KIOSK_JAUGES` | `''` | URL fixe encapsulée par `kiosk.php` (vide = `?jauges=` requis) |
| `GEN_TOKEN` | `''` | si renseigné, `gen.php?force=1` exige `&token=…` (cron) |
| `HTTP_VERIFY_SSL` | `true` | `false` = test local uniquement (PHP Windows sans bundle CA) |
| `DATA_DIR` / `CACHE_DIR` / `ASSET_DIR` | `datas/…` / `assets` | chemins — `DATA_DIR` doit être inscriptible |

## Dépannage

| Symptôme | Cause probable | Correctif |
|---|---|---|
| « repart toujours sur les 2 premières diapos » | `offset=` présent dans l'URL | le retirer — la reprise est automatique |
| | kiosk qui purge les données de site | autoriser `localStorage` pour le domaine |
| « aucune diapo » à l'écran | `gen.php` jamais lancé ou en erreur | appeler `gen.php?force=1`, lire `error` |
| **latence / écran noir à la bascule** jauges → diapos (intégration option B) | iframe chargée au moment de la bascule (player + diapo base64 à télécharger) | `hold=1` + précharger l'iframe pendant la phase jauges + envoyer `postMessage('nextevents:go')` à la bascule (exemple ci-dessus) — l'option A (`kiosk.php`) le fait déjà |
| la **dernière diapo du groupe précédent flashe** avant les nouvelles (option B) | iframe réaffichée avant la fin de son rechargement | réassigner `src` en laissant l'iframe **cachée** et ne la montrer qu'à l'événement `load` (voir l'exemple ci-dessus) |
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
