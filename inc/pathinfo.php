<?php
/**
 * inc/pathinfo.php — mini-routeur PATH_INFO.
 *
 * À require en tout début des pages « frontales » (kiosk.php,
 * jauges-demo.php). Certains proxies ou prévisualiseurs préfixent
 * toutes les requêtes du chemin du script servi
 * (/front/kiosk.php/front/slideshow.php → PATH_INFO=/front/slideshow.php) :
 * sans délégation, le navigateur recevrait une copie de la page
 * mère à la place du fichier demandé (iframe qui charge elle-même,
 * manifest JSON remplacé par du HTML…).
 *
 * Délègue : /front/*.php → exécuté, /datas/… → statique avec MIME.
 * Sans PATH_INFO : no-op, la page suit son cours normal.
 * Garde-fous : pas de segment caché (/.git, /.., /.gen.lock), .php
 * uniquement dans front/, statique uniquement sous datas/ (la sortie
 * générée — jamais le code, la config ou le dépôt).
 */
$pi = $_SERVER['PATH_INFO'] ?? '';
if ($pi !== '' && $pi !== '/') {
    $norm = fn($p) => str_replace('\\', '/', (string)$p);
    $root  = $norm(realpath(dirname(__DIR__)));
    $front = $norm(realpath($root . '/front'));
    $data  = $norm(realpath($root . '/datas'));
    $self  = $norm(realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $f = realpath($root . $pi);                  // /front/x.php, /datas/…
    if ((!$f || !is_file($f)) && is_file(dirname($self) . $pi))
        $f = realpath(dirname($self) . $pi);     // /slideshow.php, /gen.php
    $f = $norm($f);
    if ($f && is_file($f) && strpos($f, $root) === 0
        && $f !== $self
        && !preg_match('#(^|/)\.[^/]*#', $pi)) {
        if (substr($f, -4) === '.php' && dirname($f) === $front) {
            require $f;                          // front/*.php → exécuté
        } elseif (substr($f, -4) !== '.php'
                  && $data && strpos($f, $data . '/') === 0) {
            $mime = ['html'=>'text/html','css'=>'text/css',
                'js'=>'text/javascript','json'=>'application/json',
                'png'=>'image/png','jpg'=>'image/jpeg','svg'=>'image/svg+xml',
                'webp'=>'image/webp','gif'=>'image/gif','ico'=>'image/x-icon',
                'woff'=>'font/woff','woff2'=>'font/woff2',
                'txt'=>'text/plain; charset=utf-8']
                [strtolower(pathinfo($f, PATHINFO_EXTENSION))]
                ?? 'application/octet-stream';
            header("Content-Type: $mime");
            header('Content-Length: ' . filesize($f));
            readfile($f);
        } else {
            http_response_code(404);
            echo 'not found';
        }
        exit;
    }
}
unset($pi, $norm, $root, $front, $data, $self, $f, $mime);
