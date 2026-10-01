<?php
/**
 * front/gen.php — génération des diapos.
 *
 * Appel :
 *  - par la page slideshow quand le manifest est périmé (lazy refresh,
 *    aucun cron nécessaire)
 *  - en cron/tâche planifiée : php front/gen.php  ou  GET ?force=1
 *
 * Verrou flock : un manifest périmé affiché pendant la régénération,
 * pas de double run concurrent. Répond en JSON.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/inc/oa.php';
require_once dirname(__DIR__) . '/inc/render.php';

set_time_limit(300);

function manifest_stale($fmt) {
    $f = DATA_DIR . "/$fmt/manifest.txt";
    return !is_file($f)
        || (time() - filemtime($f)) > REFRESH_MIN * 60;
}

function gen_locked($force) {
    $lock = fopen(DATA_DIR . '/.gen.lock', 'c');
    if (!$lock) throw new RuntimeException('lock indisponible');
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        // une génération tourne déjà → ne rien faire, le manifest
        // existant reste servi
        return ['status' => 'busy'];
    }
    try {
        // re-test sous verrou : un autre run vient peut-être de finir
        $todo = array_values(array_filter(
            FORMATS, fn($f) => $force || manifest_stale($f)));
        if (!$todo) return ['status' => 'fresh'];

        $events = oa_list_events();
        if (MAX_EVENTS) $events = array_slice($events, 0, MAX_EVENTS);
        $events = apply_spec_prefs($events);
        if (!$events)
            throw new RuntimeException('aucun événement trouvé');

        foreach ($events as &$ev) $ev = download_image($ev);
        unset($ev);
        $fonts = ensure_fonts();

        $done = [];
        foreach ($todo as $fmt)
            $done[$fmt] = count(render_set($events, $fonts, $fmt));
        return ['status' => 'ok', 'formats' => $done,
                'events' => count($events)];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

@mkdir(DATA_DIR, 0777, true);
try {
    $res = gen_locked(($_GET['force'] ?? '') === '1'
                      || PHP_SAPI === 'cli');
    http_response_code(200);
} catch (Throwable $e) {
    http_response_code(500);
    $res = ['status' => 'error', 'error' => $e->getMessage()];
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode($res, JSON_UNESCAPED_UNICODE);
