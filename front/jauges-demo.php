<?php
/**
 * front/jauges-demo.php — fausse page de jauges pour tester
 * l'alternance « jauges ↔ diapos » en local, avant intégration dans
 * la vraie page du SI. Reproduit le motif du README : la page mère
 * montre ses jauges, puis l'iframe du slideshow pendant `limit`
 * diapos, puis revient.
 *
 * Query : ?every=10 (secondes de jauges entre deux passages diapos)
 *         ?limit=2  (diapos par passage)
 *         ?delay=2  (durée d'affichage par diapo)
 *         ?fmt=landscape
 */

$every = max(4, (float)($_GET['every'] ?? 10));
$limit = max(1, (int)($_GET['limit'] ?? 2));
$delay = max(1, (int)($_GET['delay'] ?? 2));
$fmt   = $_GET['fmt'] ?? 'landscape';

// jauges fictives : libellé, occupation, capacité — données bidons,
// juste de quoi voir qu'on est sur la vue jauges
$jauges = [
    ['Grande salle', 187, 300],
    ['Hall',          42, 120],
    ['Bibliothèque',  96, 150],
    ['Café',          23,  80],
];
$src = "slideshow.php?fmt=" . rawurlencode($fmt)
     . "&limit=$limit&once=1&delay=$delay";
// NB : pas de src sur l'iframe au chargement — sinon le player
// consomme le premier groupe de diapos en restant invisible
?>
<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8">
<title>Jauges — démo alternance</title>
<style>
html,body{margin:0;height:100%;background:#111;color:#efeae6;
 font-family:sans-serif}
#jauges{height:100%;display:flex;flex-direction:column;
 align-items:center;justify-content:center;gap:38px}
#jauges h1{font-size:42px;font-weight:300;letter-spacing:.04em}
.jauge{width:60%;font-size:22px}
.jauge .bar{height:26px;background:#2a2a2a;border-radius:13px;
 margin-top:8px;overflow:hidden}
.jauge .fill{height:100%;border-radius:13px}
.pct{font-variant-numeric:tabular-nums;color:#9daa9f}
#nx{position:fixed;inset:0;width:100%;height:100%;border:0;
 display:none;background:#000}
#badge{position:fixed;top:10px;right:14px;font-size:13px;
 color:#666;z-index:9}
</style></head><body>
<div id="jauges">
  <h1>Fréquentation en direct</h1>
<?php foreach ($jauges as [$nom, $occ, $cap]):
    $pct = min(100, round($occ / $cap * 100));
    $col = $pct > 80 ? '#c99483' : ($pct > 50 ? '#f6e3bb' : '#9daa9f'); ?>
  <div class="jauge"><?= htmlspecialchars($nom) ?>
    <span class="pct"><?= $occ ?>/<?= $cap ?></span>
    <div class="bar"><div class="fill" style="width:<?= $pct ?>%;
      background:<?= $col ?>"></div></div>
  </div>
<?php endforeach; ?>
</div>
<iframe id="nx"></iframe>
<div id="badge"><span id="mode">jauges</span> — diapos dans
 <span id="cd"><?= (int)$every ?></span> s</div>
<script>
const EVERY = <?= $every * 1000 ?>;
const SRC = <?= json_encode($src) ?>;
const nx = document.getElementById('nx');
const jauges = document.getElementById('jauges');
let remain = <?= (int)$every ?>, onDiapos = false;

// compte à rebours jusqu'au prochain passage diapos
setInterval(() => {
  if (!onDiapos)
    document.getElementById('cd').textContent = --remain;
}, 1000);

// cycle : jauges EVERY ms → 2 diapos → retour jauges
setInterval(() => {
  onDiapos = true;
  jauges.style.display = 'none';
  nx.style.display = 'block';   // pas '' — le CSS pose display:none
  nx.src = SRC;                 // MÊME url → groupe suivant mémorisé
  document.getElementById('mode').textContent = 'diapos';
  remain = <?= (int)$every ?>;
  document.getElementById('cd').textContent = remain;
}, EVERY);

// le slideshow a fini ses diapos : retour aux jauges
window.addEventListener('message', e => {
  if (e.data !== 'nextevents:done') return;
  onDiapos = false;
  nx.style.display = 'none';
  jauges.style.display = '';
  document.getElementById('mode').textContent = 'jauges';
});
</script>
</body></html>
