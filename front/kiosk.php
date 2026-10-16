<?php
/**
 * front/kiosk.php — wrapper d'alternance jauges ↔ diapos, pour un
 * kiosk qui ne sait afficher qu'UNE url au démarrage.
 *
 * Au lieu d'intégrer le slideshow DANS la page jauges (nécessite de
 * modifier le code existant + le mécanisme de rafraîchissement de la
 * page encapsulée peut
 * tuer l'iframe), c'est CETTE page qui encapsule la page jauges dans
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
iframe{position:fixed;inset:0;width:100%;height:100%;border:0;
 transition:opacity <?= $tdur ?>ms ease}
#nx{display:none;opacity:0}
</style></head><body>
<iframe id="jauges"></iframe>
<iframe id="nx"></iframe>
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
let onDiapos = false, watchdog = null, jaugesTimer = null;

// src posé par JS et non dans le HTML : une iframe déclarée avec src
// charge même si on prévoyait de la retarder
jauges.src = <?= json_encode($jauges) ?>;

function go() {
  try { nx.contentWindow.postMessage('nextevents:go', '*'); }
  catch (e) {}
}

/* Phase jauges : visible, et l'iframe diapos PRÉCHARGE le groupe
   suivant — hold=1 fait que le player pose sa 1re diapo puis attend
   le postMessage 'nextevents:go' : la bascule est instantanée, sans
   latence de chargement.
   Déclencheur de la bascule : timer EVERY ms — ou plus tôt le
   postMessage MSG émis par la page encapsulée (pagination variable
   côté existant : il ajoute parent.postMessage(MSG,'*') en fin
   d'affichage ; EVERY fait alors office de borne max). */
/* Les deux iframes sont empilées plein écran : la bascule est un
   fondu d'opacité de l'iframe diapos par-dessus les jauges — la page
   jauges n'est JAMAIS masquée (elle continue de vivre en dessous, son
   refresh interne tourne), on ne coupe rien. */
function startJauges() {
  onDiapos = false;
  clearTimeout(watchdog); watchdog = null;
  nx.style.opacity = '0';                    // fondu de sortie
  setTimeout(() => {
    nx.style.display = 'none';
    nx.src = SRC;   // préchargement du groupe suivant (même URL → reload)
  }, TDUR_MS);
  clearTimeout(jaugesTimer);
  jaugesTimer = setTimeout(switchToDiapos, EVERY);
}

async function switchToDiapos() {
  if (onDiapos) return;
  clearTimeout(jaugesTimer);
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
    nx.style.display = 'block';
    requestAnimationFrame(() => { nx.style.opacity = '1'; });
    go();   // réveille le player préchargé — diapo déjà rendue
  } catch (e) {
    startJauges();   // serveur injoignable : on retentera
  }
}

// player fini de charger APRÈS la bascule (boot, chargement lent) :
// on relance 'go' — le player mémorise aussi le go arrivé trop tôt
nx.addEventListener('load', () => { if (onDiapos) go(); });

window.addEventListener('message', e => {
  if (e.data === 'nextevents:done') { startJauges(); return; }
  // la page encapsulée signale la fin de SON affichage : bascule
  if (MSG && e.source === jauges.contentWindow && e.data === MSG)
    switchToDiapos();
});

startJauges();
</script>
</body></html>
