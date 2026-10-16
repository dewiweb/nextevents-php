<?php
/**
 * front/jauges-demo.php — fausse page de jauges « pure », pour tester
 * le scénario A : front/kiosk.php encapsule CETTE page dans une
 * iframe jamais déchargée et y superpose les diapos par cycles.
 * Ici aucun code diapos — comme la vraie page jauges du SI.
 *
 * Démo : /front/kiosk.php?jauges=/front/jauges-demo.php&every=15&limit=3&delay=3
 *        /front/kiosk.php?jauges=/front/jauges-demo.php?signal=20&msg=jauges:done
 *
 * Query : ?refresh=10  simule la mise à jour interne de la page
 *           (horloge + chiffres qui bougent) — montre que l'iframe
 *           encapsulée continue de vivre sous le superviseur
 *         ?signal=20   émet parent.postMessage('jauges:done','*')
 *           toutes les N secondes — déclencheur adaptatif du kiosk
 *           (paramètre msg=) : la bascule part à la fin de SON cycle
 *           plutôt qu'au timer fixe
 */

// proxies qui préfixent les requêtes du chemin de ce script :
// /front/jauges-demo.php/front/slideshow.php → délègue au vrai
// fichier (routeur partagé, cf. inc/pathinfo.php)
require_once dirname(__DIR__) . '/inc/pathinfo.php';

$refresh = max(2, (int)($_GET['refresh'] ?? 10));
$signal  = max(0, (int)($_GET['signal'] ?? 0));

// jauges fictives : libellé, occupation, capacité — données bidons,
// juste de quoi voir qu'on est sur la vue jauges
$jauges = [
    ['Grande salle', 187, 300],
    ['Hall',          42, 120],
    ['Bibliothèque',  96, 150],
    ['Café',          23,  80],
];
?>
<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8">
<title>Jauges — démo encapsulée</title>
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
#badge{position:fixed;top:10px;right:14px;font-size:13px;
 color:#666;z-index:9}
#uptime{position:fixed;bottom:10px;right:14px;font-size:13px;
 color:#555}
</style></head><body>
<div id="jauges">
  <h1>Fréquentation en direct</h1>
<?php foreach ($jauges as $i => [$nom, $occ, $cap]):
    $pct = min(100, round($occ / $cap * 100));
    $col = $pct > 80 ? '#c99483' : ($pct > 50 ? '#f6e3bb' : '#9daa9f'); ?>
  <div class="jauge"><?= htmlspecialchars($nom) ?>
    <span class="pct"><span id="occ<?= $i ?>"><?= $occ ?></span>/<?= $cap ?></span>
    <div class="bar"><div class="fill" id="fill<?= $i ?>"
      style="width:<?= $pct ?>%;background:<?= $col ?>"></div></div>
  </div>
<?php endforeach; ?>
</div>
<div id="badge">refresh simulé dans <span id="cd"><?= $refresh ?></span> s</div>
<div id="uptime">page chargée il y a <span id="up">0</span> s
 — jamais rechargée</div>
<script>
const REFRESH = <?= $refresh * 1000 ?>;
const SIGNAL = <?= $signal * 1000 ?>;
const t0 = Date.now();
let remain = <?= $refresh ?>;
const caps = <?= json_encode(array_column($jauges, 2)) ?>;
const cols = p => p > 80 ? '#c99483' : (p > 50 ? '#f6e3bb' : '#9daa9f');

// « la page vit » : horloge de présence — si l'iframe était rechargée
// par le superviseur à chaque cycle, ce compteur repartirait à 0
setInterval(() => {
  document.getElementById('up').textContent =
    Math.round((Date.now() - t0) / 1000);
}, 1000);

// refresh interne simulé (la vraie page fait son AJAX/reload pareil,
// en tâche de fond sous le superviseur) : chiffres + barres bougent
setInterval(() => {
  document.getElementById('cd').textContent = --remain;
  if (remain > 0) return;
  remain = <?= $refresh ?>;
  caps.forEach((cap, i) => {
    const occ = Math.round(cap * (0.15 + Math.random() * 0.7));
    document.getElementById('occ' + i).textContent = occ;
    const f = document.getElementById('fill' + i);
    f.style.width = Math.round(occ / cap * 100) + '%';
    f.style.background = cols(occ / cap * 100);
  });
}, 1000);

// déclencheur adaptatif : si ?signal=N, la page annonce la fin de
// SON cycle au superviseur (kiosk.php?msg=jauges:done) plutôt que
// d'attendre le timer fixe — reproduit une pagination variable
if (SIGNAL) setInterval(() => {
  try { parent.postMessage('jauges:done', '*'); } catch (e) {}
}, SIGNAL);
</script>
</body></html>
