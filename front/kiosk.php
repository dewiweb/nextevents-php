<?php
/**
 * front/kiosk.php — wrapper d'alternance jauges ↔ diapos, pour un
 * kiosk qui ne sait afficher qu'UNE url au démarrage.
 *
 * Au lieu d'intégrer le slideshow DANS la page jauges (nécessite de
 * modifier le code existant + le mécanisme de rafraîchissement de la
 * page encapsulée peut tuer l'iframe), c'est CETTE page qui encapsule
 * la page jauges dans
 * une iframe jamais déchargée — le rafraîchissement interne du code
 * existant (reload, AJAX, websocket…) tourne en tâche de fond, les
 * données restent toujours fraîches. Les diapos sont superposées par
 * cycles, pilotés par le postMessage('nextevents:done') du player.
 *
 * Query : ?jauges=<url>   page à encapsuler — ou constante
 *           KIOSK_JAUGES (config.php), qui a priorité
 *         ?every=60       secondes de jauges entre deux passages
 *         ?limit=2        diapos par passage (0 = tout le manifest)
 *         ?msg=jauges:done  si posé, la bascule part dès que la page
 *           encapsulée émet parent.postMessage('jauges:done','*') —
 *           durée d'affichage adaptative côté existant (pagination
 *           variable) ; ?every= fait alors office de borne max.
 *         ?fmt=portrait-screen  &delay=&tdur=&transition=  → slideshow
 *         ?layout=split&dir=v|h   pas d'alternance : jauges 2/3 et
 *           diapos 1/3 visibles en permanence, chacune dans sa iframe
 *           (v = bandeau vertical pour écran paysage, h = horizontal
 *           pour écran portrait ; fmt adapté sauf ?fmt= explicite)
 *
 * Le kiosk pointe sur : /front/kiosk.php?jauges=<url-de-la-page>
 * Si aucune diapo n'est générée, on reste sur les jauges — l'écran
 * ne montre jamais « aucune diapo ».
 */

require_once dirname(__DIR__) . '/config.php';
// proxies qui préfixent les requêtes du chemin de ce script :
// /front/kiosk.php/front/slideshow.php → délègue au vrai fichier
require_once dirname(__DIR__) . '/inc/pathinfo.php';

// KIOSK_JAUGES (config.php) fixe la page encapsulée : une fois
// renseignée, ?jauges= est ignoré — l'iframe superviseur ne peut pas
// être détournée vers une URL arbitraire. Vide = ?jauges= requis.
$jauges = KIOSK_JAUGES !== '' ? KIOSK_JAUGES
                            : trim($_GET['jauges'] ?? '');
if ($jauges !== '') {
    // URL absolue http(s), protocole-relatif ou chemin local — tout
    // sauf un scheme exotique (javascript:, data:)
    $scheme = parse_url($jauges, PHP_URL_SCHEME);
    if ($scheme !== null && !in_array(strtolower($scheme),
                                      ['http', 'https'], true))
        $jauges = '';
}
if ($jauges === '') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><title>kiosk</title>'
       . '<body style="font:18px sans-serif;margin:3em">'
       . '<p><b>kiosk.php</b> — paramètre <code>jauges</code> manquant'
       . ' ou invalide (ou constante <code>KIOSK_JAUGES</code> à'
       . ' renseigner dans <code>config.php</code>).</p>'
       . '<p>Ex. : <code>?jauges=https://'
       . 'jauges.exemple.fr/affichage.php&every=60&limit=2'
       . '&fmt=portrait-screen</code></p>';
    exit;
}
header('Cache-Control: no-store');

$every = max(10, (float)($_GET['every'] ?? 60));
$limit = max(0, (int)($_GET['limit'] ?? 2));
$fmt   = $_GET['fmt'] ?? FORMATS[0];
$fmt   = in_array($fmt, FORMATS, true) ? $fmt : FORMATS[0];
// défauts du config.php (SLIDE_*) — la query surcharge si posée
$delay = max(1, (int)($_GET['delay'] ?? SLIDE_DELAY));
$tdur  = max(0, (int)($_GET['tdur'] ?? SLIDE_TRANS_MS));
$trans = $_GET['transition'] ?? SLIDE_TRANSITION;
$trans = in_array($trans, ['fade', 'slide', 'none'], true)
       ? $trans : 'fade';

// — mode « split » : jauges + diapos visibles en permanence, chacune
// dans sa propre iframe (pas d'alternance — voile et postMessage de
// coordination n'ont plus lieu d'être). ?layout=split&dir=v|h :
//   v = jauges 2/3 à gauche + diapos 1/3 à droite (écran paysage)
//   h = jauges 2/3 en haut + diapos 1/3 en bas (écran portrait)
// fmt par défaut adapté au bandeau (portrait si dir=v, landscape si
// dir=h) — surchargeable par ?fmt=.
if (($_GET['layout'] ?? '') === 'split') {
    $dir = ($_GET['dir'] ?? 'v') === 'h' ? 'h' : 'v';
    if (!isset($_GET['fmt']))
        $fmt = $dir === 'v'
             ? (in_array('portrait-screen', FORMATS, true)
                ? 'portrait-screen' : FORMATS[0])
             : (in_array('landscape', FORMATS, true)
                ? 'landscape' : FORMATS[0]);
    $src = dirname($_SERVER['SCRIPT_NAME']) . '/slideshow.php'
         . '?fmt=' . rawurlencode($fmt)
         . "&delay=$delay&tdur=$tdur&transition=$trans";
    $mf = dirname(__DIR__) . "/datas/nextevent/$fmt/manifest.txt";
    $has = is_file($mf)
        && count(array_filter(array_map('trim', file($mf)))) > 0;
    $css = $dir === 'v' ? 'grid-template-columns:2fr 1fr'
                        : 'grid-template-rows:2fr 1fr';
    $full = $dir === 'v' ? 'grid-template-columns:1fr 0fr'
                         : 'grid-template-rows:1fr 0fr';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
       . '<title>Kiosk split</title><style>'
       . 'html,body{margin:0;height:100%;background:#000;'
       . 'overflow:hidden}'
       . '#split{display:grid;height:100%}'
       . 'iframe{width:100%;height:100%;border:0;display:block;'
       . 'overflow:hidden}'
       . '</style></head><body><div id="split" style="'
       . ($has ? $css : $full) . '">'
       . '<iframe src="' . htmlspecialchars($jauges) . '"></iframe>'
       . '<iframe src="' . htmlspecialchars($src) . '"></iframe>'
       . '</div><script>'
       // manifest vide → jauges plein écran ; re-poll périodique pour
       // restaurer le split dès que des diapos sont (re)générées
       . 'const el=document.getElementById("split"),'
       . 'SPLIT=' . json_encode($css) . ',FULL=' . json_encode($full) . ';'
       . 'async function poll(){try{const r=await(await fetch('
       . '"slideshow.php?manifest=' . $fmt
       . '",{cache:"no-store"})).json();'
       . 'el.style.cssText=r.slides.length?SPLIT:FULL}catch(e){}}'
       . 'setInterval(poll,60000);'
       . '</script></body></html>';
    exit;
}

$msg = (string)($_GET['msg'] ?? '');
// chemin absolu depuis la racine web (un src relatif casserait si la
// page est appelée avec un slash final)
$src = dirname($_SERVER['SCRIPT_NAME']) . '/slideshow.php'
     . '?fmt=' . rawurlencode($fmt) . '&once=1&hold=1'
     . "&limit=$limit&delay=$delay&tdur=$tdur&transition=$trans";
$mft = dirname($_SERVER['SCRIPT_NAME']) . '/slideshow.php'
     . '?manifest=' . rawurlencode($fmt);
?>
<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><title>Kiosk</title>
<style>
html,body{margin:0;height:100%;background:#000;overflow:hidden}
iframe{position:fixed;inset:0;width:100%;height:100%;border:0}
#nx{display:none}
/* voile noir au-dessus des deux iframes : chaque bascule est un fondu
   AU NOIR (montée, échange dessous, redescente) — pointer-events:none
   pour ne jamais bloquer les clics des pages encapsulées */
#veil{position:fixed;inset:0;background:#000;opacity:1;z-index:10;
 pointer-events:none;transition:opacity <?= $tdur ?>ms ease}
</style></head><body>
<iframe id="jauges"></iframe>
<iframe id="nx"></iframe>
<div id="veil"></div>
<script>
const EVERY = <?= $every * 1000 ?>;
const LIMIT = <?= $limit ?>;
const DELAY_MS = <?= $delay * 1000 ?>;
const TDUR_MS = <?= $tdur ?>;
const SRC = <?= json_encode($src) ?>;
const MFT = <?= json_encode($mft) ?>;
const MSG = <?= json_encode($msg) ?>;   // '' = bascule sur timer
const jauges = document.getElementById('jauges');
const nx = document.getElementById('nx');
const veil = document.getElementById('veil');
// nxDead : l'iframe diapos ne peut pas servir un groupe — player pas
// encore chargé (boot) ou once=1 terminé ('ending'/'done' reçu) : une
// bascule doit alors le RECHARGER plutôt que le réveiller.
// nxReady : sa 1re diapo est réellement rendue ('nextevents:ready').
// veilPeak : le voile noir a fini sa montée — le contenu peut changer.
let onDiapos = false, nxDead = true, nxReady = false, veilPeak = false,
    watchdog = null, jaugesTimer = null, fadeout = null;

// src posé par JS et non dans le HTML : une iframe déclarée avec src
// charge même si on prévoyait de la retarder
jauges.src = <?= json_encode($jauges) ?>;

function go() {
  try { nx.contentWindow.postMessage('nextevents:go', '*'); }
  catch (e) {}
}

/* Toutes les bascules passent par le VOILE noir : montée (TDUR) →
   échange du contenu dessous → redescente — un fondu au noir propre
   dans les deux sens, jamais de fondu croisé jauges ↔ diapos.
   La page jauges n'est JAMAIS masquée ni déchargée : elle continue de
   vivre en dessous (refresh interne actif), on ne coupe rien. */

// voile en haut + diapo rendue : on montre les diapos sous le noir
// puis le voile redescend — l'apparition se fait depuis le noir
function revealNx() {
  veilPeak = false;
  nx.style.display = 'block';
  go();   // réveille le player (hold=1) — diapo déjà rendue
  veil.style.opacity = '0';
}

/* Phase jauges : visible, et l'iframe diapos PRÉCHARGE le groupe
   suivant — hold=1 fait que le player pose sa 1re diapo puis attend
   le postMessage 'nextevents:go' : la bascule est instantanée.
   Déclencheur de la bascule : timer EVERY ms — ou plus tôt le
   postMessage MSG émis par la page encapsulée (pagination variable
   côté existant : il ajoute parent.postMessage(MSG,'*') en fin
   d'affichage ; EVERY fait alors office de borne max). */
function startJauges() {
  onDiapos = false;
  clearTimeout(watchdog); watchdog = null;
  clearTimeout(fadeout);
  if (nx.style.display !== 'none') {
    // retour depuis les diapos : fondu au noir, puis échange dessous
    veil.style.opacity = '1';
    fadeout = setTimeout(() => {
      nx.style.display = 'none';
      nx.src = SRC; nxReady = false;   // précharge le groupe suivant
      veil.style.opacity = '0';        // jauges revient depuis le noir
      veilPeak = false;
    }, TDUR_MS);
  } else {
    // déjà sur les jauges (boot, manifest vide, retry) : pas de voile
    // à remonter — on précharge juste le player si besoin, et on
    // laisse le voile redescendre (il est levé au chargement initial)
    if (nxDead) { nx.src = SRC; nxReady = false; }
    veil.style.opacity = '0';
    veilPeak = false;
  }
  clearTimeout(jaugesTimer);
  jaugesTimer = setTimeout(switchToDiapos, EVERY);
}

async function switchToDiapos() {
  if (onDiapos) return;
  clearTimeout(jaugesTimer);
  clearTimeout(fadeout);   // retour aux jauges encore en cours : le
                           // voile est déjà levé — on enchaîne dessous
  // pas de diapo générée → on reste sur les jauges (gen.php a
  // peut-être échoué) : nouvel essai au prochain cycle
  try {
    const m = await (await fetch(MFT, {cache: 'no-store'})).json();
    if (!m.slides.length) { startJauges(); return; }
    // watchdog : si le player ne rend jamais la main (done perdu,
    // player planté), retour forcé = durée du groupe + marge
    const n = LIMIT || m.slides.length;
    watchdog = setTimeout(startJauges, n * (DELAY_MS + TDUR_MS) + 10000);
    onDiapos = true;
    veil.style.opacity = '1';             // montée vers le noir
    // player mort (groupe fini) ou pas encore chargé : on recharge
    // PENDANT la montée — la diapo est généralement prête quand le
    // voile arrive en haut ; sinon 'ready' décidera de la révélation
    if (nxDead) { nx.src = SRC; nxReady = false; }
    setTimeout(() => {
      veilPeak = true;
      // player chargé → sa diapo est posée (hold) : on peut révéler.
      // Rechargement encore en vol : 'ready' (ou le filet du load)
      // déclenchera revealNx dès la diapo effectivement rendue
      if (!nxDead || nxReady) revealNx();
    }, TDUR_MS);
  } catch (e) {
    startJauges();   // serveur injoignable : on retentera
  }
}

// player fraîchement chargé → contenu utilisable. Filet de sécurité :
// un player qui n'émet pas 'ready' (ancienne version) serait révélé
// tard — après un délai on le considère prêt plutôt que d'attendre
// jusqu'au watchdog
nx.addEventListener('load', () => {
  nxDead = false;
  if (onDiapos) setTimeout(() => {
    if (onDiapos && nx.style.display === 'none') {
      nxReady = true;
      if (veilPeak) revealNx();
    }
  }, 2000);
});

window.addEventListener('message', e => {
  // le player entame son fondu de fin : le voile monte tout de suite —
  // le fondu interne du player se poursuit dessous, le retour jauges
  // est un seul fondu au noir. Dès ce message le player est mort
  // (done posé) : une bascule devra le recharger
  if (e.data === 'nextevents:ending') {
    nxDead = true;
    if (onDiapos) startJauges();
    return;
  }
  if (e.data === 'nextevents:done') {
    nxDead = true;   // once=1 : ce player ne rejouera pas
    if (onDiapos) startJauges();   // 'ending' a souvent déjà relancé
    return;
  }
  // diapo réellement rendue dans l'iframe cachée : si le voile est en
  // haut en attente d'un player rechargé, on révèle maintenant
  if (e.data === 'nextevents:ready') {
    nxReady = true;
    if (veilPeak && onDiapos && nx.style.display === 'none') revealNx();
    return;
  }
  // la page encapsulée signale la fin de SON affichage : bascule
  if (MSG && e.source === jauges.contentWindow && e.data === MSG)
    switchToDiapos();
});

startJauges();
</script>
</body></html>
