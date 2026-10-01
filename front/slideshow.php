<?php
/**
 * front/slideshow.php — diaporama plein écran pour kiosk Chrome.
 *
 * Cycle les html/slide-*.html générées (iframes empilées + fondu),
 * re-lit le manifest en continu, déclenche la régénération via
 * gen.php quand le manifest est périmé — aucun cron requis.
 *
 * Query : ?fmt=portrait-screen|landscape  ?delay=8  ?tdur=1500
 *         ?transition=fade|slide|none
 * JSON  : ?manifest=<fmt> → {slides, delay, transition, tdur}
 */

require_once dirname(__DIR__) . '/config.php';

$fmt = $_GET['fmt'] ?? ($_GET['manifest'] ?? FORMATS[0]);
$fmt = in_array($fmt, FORMATS, true) ? $fmt : FORMATS[0];

// Les diapos sont servies en statique : le player iframe
// '../datas/nextevent/<fmt>/html/<fichier>' — chemin RELATIF depuis
// /front/. Si ce fichier est déplacé (autre dossier, autre depth),
// ajuster $base ci-dessous ET vérifier que datas/ est sous webroot.
$base = '../datas/nextevent/' . $fmt;
$dir = dirname(__DIR__) . '/datas/nextevent/' . $fmt;

// — endpoint JSON pour le polling du player —
if (isset($_GET['manifest'])) {
    $slides = [];
    $mf = "$dir/manifest.txt";
    if (is_file($mf))
        $slides = array_values(array_filter(
            array_map('trim', file($mf))));
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'slides' => $slides, 'delay' => SLIDE_DELAY,
        'transition' => SLIDE_TRANSITION, 'tdur' => SLIDE_TRANS_MS,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// — régénération paresseuse : manifest périmé → gen.php côté JS —
$mf = "$dir/manifest.txt";
$stale = !is_file($mf) || (time() - filemtime($mf)) > REFRESH_MIN * 60;
?>
<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><title>Diaporama</title>
<style>
html,body{margin:0;height:100%;background:#000;overflow:hidden}
.slide{position:absolute;inset:0}
.slide iframe{width:100%;height:100%;border:0;transform-origin:0 0}
#empty{position:absolute;inset:0;display:none;
 place-items:center;color:#8f8c8a;font:28px sans-serif}
</style></head><body>
<div id="empty">aucune diapo</div>
<script>
const FMT = <?= json_encode($fmt) ?>;
const BASE = <?= json_encode($base) ?>;
const STALE = <?= $stale ? 'true' : 'false' ?>;
const REFRESH_S = <?= REFRESH_MIN * 60 ?>;
const P = new URLSearchParams(location.search);
const qDelay = parseFloat(P.get('delay') || '');
const qTrans = P.get('transition') || '';
const qTdur = parseInt(P.get('tdur') || '');

let slides = [], idx = -1, cur = null, timer = null;
let delay = 8, trans = 'fade', dur = 1500;

/* La diapo est dessinée en pixels fixes (1080×1920) : on met
   l'iframe à l'échelle du viewport réel, centrée. */
function fit(f) {
  const d = f.contentDocument;
  const cs = f.contentWindow.getComputedStyle(d.documentElement);
  const w = parseFloat(cs.width) || d.documentElement.scrollWidth || 1080;
  const h = parseFloat(cs.height) || d.documentElement.scrollHeight || 1920;
  const s = Math.min(innerWidth / w, innerHeight / h);
  f.style.width = w + 'px';
  f.style.height = h + 'px';
  f.style.transform =
    `translate(${(innerWidth - w * s) / 2}px,` +
    `${(innerHeight - h * s) / 2}px) scale(${s})`;
}

function show(name) {
  const wrap = document.createElement('div');
  wrap.className = 'slide';
  const f = document.createElement('iframe');
  f.src = BASE + '/html/' + encodeURIComponent(name);
  f.onload = () => fit(f);
  wrap.appendChild(f);
  document.body.appendChild(wrap);
  const old = cur;
  if (old && trans === 'fade') {
    wrap.style.opacity = 0;
    wrap.getBoundingClientRect();  // reflow : commit du style initial
    wrap.style.transition = `opacity ${dur}ms`;
    wrap.style.opacity = 1;
    old.style.transition = `opacity ${dur}ms`;
    old.style.opacity = 0;
    setTimeout(() => old.remove(), dur + 100);
  } else if (old && trans === 'slide') {
    wrap.style.transform = 'translateX(100%)';
    wrap.getBoundingClientRect();
    wrap.style.transition = `transform ${dur}ms`;
    wrap.style.transform = 'translateX(0)';
    old.style.transition = `transform ${dur}ms`;
    old.style.transform = 'translateX(-100%)';
    setTimeout(() => old.remove(), dur + 100);
  } else if (old) {
    old.remove();
  }
  cur = wrap;
}

function next() {
  if (!slides.length) return;
  idx = (idx + 1) % slides.length;
  show(slides[idx]);
  arm();
}

function arm() {
  clearTimeout(timer);
  timer = setTimeout(next, Math.max(2, delay) * 1000);
}

async function poll() {
  try {
    const r = await (await fetch(
      'slideshow.php?manifest=' + FMT, {cache: 'no-store'})).json();
    delay = qDelay || r.delay || 8;
    trans = qTrans || r.transition || 'fade';
    dur = qTdur || r.tdur || 1500;
    const changed = JSON.stringify(r.slides) !== JSON.stringify(slides);
    slides = r.slides;
    document.getElementById('empty').style.display =
      slides.length ? 'none' : 'grid';
    if (changed && (!slides.length || !slides.includes(slides[idx]))) {
      idx = -1;  // diapo courante supprimée : repartir proprement
      next();
    }
    if (idx < 0 && slides.length) next();
  } catch (e) { /* serveur coupé : on réessaiera */ }
}

/* régénération : gen.php vérifie lui-même la péremption et pose un
   verrou — l'appel est idempotent, fire-and-forget */
function regen() {
  fetch('gen.php', {cache: 'no-store'}).catch(() => {});
}

addEventListener('resize',
  () => cur && fit(cur.querySelector('iframe')));
setInterval(poll, 15000);
setInterval(regen, REFRESH_S * 1000);
if (STALE) regen();
poll();
</script>
</body></html>
