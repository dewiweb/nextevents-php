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
    /** Manifest absent ou plus vieux que REFRESH_MIN minutes → la
     *  régénération est due. C'est ce test qui rend le cron facultatif. */
    $f = DATA_DIR . "/$fmt/manifest.txt";
    return !is_file($f)
        || (time() - filemtime($f)) > REFRESH_MIN * 60;
}

function gen_locked($force) {
    $lock = fopen(DATA_DIR . '/.gen.lock', 'c');
    if (!$lock) throw new RuntimeException('lock indisponible');
    // flock non bloquant : si une génération tourne déjà (appel
    // simultané depuis un autre kiosk ou un cron), on sert le manifest
    // existant — jamais de double run concurrent
    if (!flock($lock, LOCK_EX | LOCK_NB))
        return ['status' => 'busy'];
    try {
        // re-test sous verrou : un autre run vient peut-être de finir
        $todo = array_values(array_filter(
            FORMATS, fn($f) => $force || manifest_stale($f)));
        if (!$todo) return ['status' => 'fresh'];

        $events = oa_list_events();
        // fenêtre J+N → J+M sur la prochaine séance (ex. annonce des
        // événements de demain : DAY_OFFSET_MIN=1, MAX=1)
        if (DAY_OFFSET_MIN || DAY_OFFSET_MAX !== null) {
            $t0 = oa_local('now');
            $today_ts = mktime(0, 0, 0, $t0[1], $t0[2], $t0[0]);
            $events = array_values(array_filter($events, function ($e) use ($today_ts) {
                if (empty($e['_dt'])) return true;  // permanente : toujours
                $day_ts = mktime(0, 0, 0, $e['_dt'][1], $e['_dt'][2],
                                 $e['_dt'][0]);
                $diff = round(($day_ts - $today_ts) / 86400);
                return $diff >= DAY_OFFSET_MIN
                    && (DAY_OFFSET_MAX === null || $diff <= DAY_OFFSET_MAX);
            }));
        }
        if (MAX_EVENTS) $events = array_slice($events, 0, MAX_EVENTS);
        $events = apply_spec_prefs($events);
        if (!$events)
            throw new RuntimeException('aucun événement trouvé');

        foreach ($events as &$ev) $ev = download_image($ev);
        unset($ev);
        $fonts = ensure_fonts();

        // purge : les html/ obsolètes sont supprimés par render_set ;
        // ici on nettoie le cache images (> CACHE_IMG_DAYS — les URLs
        // OA sont versionnées, un fichier non retéléchargé depuis
        // N jours n'est plus utilisé par un événement courant)
        if (CACHE_IMG_DAYS)
            foreach (glob(CACHE_DIR . '/img/*') ?: [] as $f)
                if (is_file($f)
                    && time() - filemtime($f) > CACHE_IMG_DAYS * 86400)
                    @unlink($f);

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
