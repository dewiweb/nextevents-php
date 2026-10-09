<?php
/**
 * Rendu des diapos — port de nextevents/slide.py + media.py.
 * Produit des HTML autonomes (fontes + image en base64) : pas de PNG,
 * le navigateur du kiosk fait le rendu.
 */

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/oa.php';

const INK = '#141414';
const CARD_COLOR_DEFAULT = ['#efeae6', '#bfbbb8'];  // pale-grey
const CARD_COLORS = [
    'yellow' => ['#f6e3bb', '#d5bb85'],
    'beige'  => ['#f6e3bb', '#d5bb85'],
    'green'  => ['#c6d2c9', '#9daa9f'],
    'red'    => ['#e3c2b7', '#c99483'],
    'blue'   => ['#e2dff0', '#beb7e1'],
];

const SPEC_ICONS = [
    'Date' => 'calendar', 'Séances' => 'calendar', 'Durée' => 'timer',
    'Lieu' => 'pin', 'Tarif' => 'ticket', 'Public' => 'group',
    'Accessibilité' => 'accessibility',
];
const SPEC_ORDER = ['Date', 'Durée', 'Lieu', 'Tarif', 'Public',
                    'Accessibilité'];

// paths SVG des icônes — recopiés de slide.py
const ICONS = [
    'calendar' => 'M8 5.75c-.41 0-.75-.34-.75-.75V2c0-.41.34-.75.75-.75s.75.34.75.75v3c0 .41-.34.75-.75.75Zm8 0c-.41 0-.75-.34-.75-.75V2c0-.41.34-.75.75-.75s.75.34.75.75v3c0 .41-.34.75-.75.75Zm4.5 4.09h-17c-.41 0-.75-.34-.75-.75s.34-.75.75-.75h17c.41 0 .75.34.75.75s-.34.75-.75.75ZM16 22.75H8c-3.65 0-5.75-2.1-5.75-5.75V8.5c0-3.65 2.1-5.75 5.75-5.75h8c3.65 0 5.75 2.1 5.75 5.75V17c0 3.65-2.1 5.75-5.75 5.75ZM8 4.25c-2.86 0-4.25 1.39-4.25 4.25V17c0 2.86 1.39 4.25 4.25 4.25h8c2.86 0 4.25-1.39 4.25-4.25V8.5c0-2.86-1.39-4.25-4.25-4.25H8Z',
    'pin' => 'M12 14.17c-2.13 0-3.87-1.73-3.87-3.87S9.87 6.44 12 6.44s3.87 1.73 3.87 3.87-1.74 3.86-3.87 3.86Zm0-6.23c-1.3 0-2.37 1.06-2.37 2.37s1.06 2.37 2.37 2.37 2.37-1.06 2.37-2.37S13.3 7.94 12 7.94ZM12 22.76a5.97 5.97 0 0 1-4.13-1.67c-2.95-2.84-6.21-7.37-4.98-12.76C4 3.44 8.27 1.25 12 1.25h.01c3.73 0 8 2.19 9.11 7.09 1.22 5.39-2.04 9.91-4.99 12.75A5.97 5.97 0 0 1 12 22.76Zm0-20.01c-2.91 0-6.65 1.55-7.64 5.91C3.28 13.37 6.24 17.43 8.92 20a4.426 4.426 0 0 0 6.17 0c2.67-2.57 5.63-6.63 4.57-11.34-1-4.36-4.75-5.91-7.66-5.91Z',
    'ticket' => 'M17 20.75H7c-4.41 0-5.75-1.34-5.75-5.75v-.5c0-.41.34-.75.75-.75.96 0 1.75-.79 1.75-1.75S2.96 10.25 2 10.25c-.41 0-.75-.34-.75-.75V9c0-4.41 1.34-5.75 5.75-5.75h10c4.41 0 5.75 1.34 5.75 5.75v1c0 .41-.34.75-.75.75-.96 0-1.75.79-1.75 1.75s.79 1.75 1.75 1.75c.41 0 .75.34.75.75 0 4.41-1.34 5.75-5.75 5.75ZM2.75 15.16c.02 3.44.73 4.09 4.25 4.09h10c3.34 0 4.15-.59 4.24-3.59a3.25 3.25 0 0 1-2.49-3.16c0-1.53 1.07-2.82 2.5-3.16V9c0-3.57-.67-4.25-4.25-4.25H7c-3.52 0-4.23.65-4.25 4.09 1.43.34 2.5 1.63 2.5 3.16 0 1.53-1.07 2.82-2.5 3.16v.5c0 4.41-1.34 5.75-5.75 5.75ZM10 7.25c-.41 0-.75-.34-.75-.75V4c0-.41.34-.75.75-.75s.75.34.75.75v2.5c0 .41-.34.75-.75.75v2.5c0 .42-.34.75-.75.75Zm0 7.33c-.41 0-.75-.34-.75-.75v-3.67c0-.41.34-.75.75-.75s.75-.34.75-.75v3.67c0 .41.34.75.75.75v5c0 .41-.34.75-.75.75Zm3-11H9c-.41 0-.75-.34-.75-.75s.34-.75.75-.75h6c.41 0 .75.34.75.75s-.34.75-.75.75Z',
    // sprite-timer du site (« Durée : … » sur les fiches événement)
    'timer' => 'M12 22.75c-5.24 0-9.5-4.26-9.5-9.5s4.26-9.5 9.5-9.5 9.5 4.26 9.5 9.5-4.26 9.5-9.5 9.5Zm0-17.5c-4.41 0-8 3.59-8 8s3.59 8 8 8 8-3.59 8-8-3.59-8-8-8ZM12 13.75c-.41 0-.75-.34-.75-.75V8c0-.41.34-.75.75-.75s.75.34.75.75v5c0 .41-.34.75-.75.75Zm3-11H9c-.41 0-.75-.34-.75-.75s.34-.75.75-.75h6c.41 0 .75.34.75.75s-.34.75-.75.75Z',
    'group' => 'M16.5 7.25h-.119c-1.732-.054-3.025-1.392-3.025-3.042a3.057 3.057 0 0 1 3.053-3.053 3.063 3.063 0 0 1 3.052 3.053A3.05 3.05 0 0 1 16.51 7.26c0-.01 0-.01-.01-.01Zm-.091-4.73a1.678 1.678 0 0 0-.064 3.356c.009-.01.082-.01.165 0a1.68 1.68 0 0 0-.101-3.355Zm.1 11.487a6.05 6.05 0 0 1-1.072-.092.687.687 0 1 1 .238-1.357c1.128.193 2.32-.018 3.117-.55.431-.284.66-.641.66-.999 0-.357-.238-.706-.66-.99-.797-.531-2.007-.742-3.144-.54a.68.68 0 0 1-.798-.56.688.688 0 0 1 .56-.797c1.494-.266 3.043.018 4.143.751.807.541 1.274 1.311 1.274 2.136 0 .816-.458 1.595-1.274 2.145-.834.55-1.916.853-3.043.853ZM5.474 7.25h-.018a3.047 3.047 0 0 1-2.952-3.043 3.065 3.065 0 0 1 3.052-3.062 3.063 3.063 0 0 1 3.053 3.052 3.035 3.035 0 0 1-2.943 3.053l-.192-.688.064.688h-.064Zm.092-1.375c.055 0 .1 0 .155.009.816-.037 1.531-.77 1.531-1.677a1.678 1.678 0 1 0-1.769 1.677c.01-.01.046-.01.083-.01Zm-.102 8.13c-1.127 0-2.209-.302-3.043-.852-.807-.54-1.274-1.32-1.274-2.145 0-.816.467-1.595 1.274-2.136 1.1-.733 2.65-1.017 4.143-.751a.688.688 0 1 1 .238 1.357 6.05 6.05 0 0 1-.798-.56.68.68 0 0 1-.798.56c-1.137-.202-2.337.009-3.144.54-.431.284-.66.633-.66.99 0 .358.238.715.66 1 .797.531 1.989.742 3.117.55a.688.688 0 1 1 .238 1.357 6.05 6.05 0 0 1-1.073.09Zm5.537.092h-.119c-1.732-.055-3.025-1.393-3.025-3.043a3.057 3.057 0 0 1 3.053-3.053 3.063 3.063 0 0 1 3.052-3.062 3.063 3.063 0 0 1 3.053-3.053c0-.01 0-.01-.009-.01Zm-.091-4.73a1.678 1.678 0 0 0-.065 3.356c.01-.009.083-.009.166 0a1.68 1.68 0 0 0 1.585-1.677c0-.917-.751-1.678-1.686-1.678Zm.09 11.495c-1.1 0-2.2-.284-3.052-.861-.807-.541-1.274-1.311-1.274-2.136 0-.816.458-1.604 1.274-2.145-.853.568-1.953.861-3.053.861Zm-2.291-3.987c-.431.284-.66.641-.66.999 0 .357.238.706.66.99 1.237.834 3.336.834 4.574 0 .43-.284.66-.642.66-1 0-.357-.238-.705-.66-.99-1.228-.833-3.328-.824-4.574 0Z',
    'accessibility' => 'M8.786 11.192v3.724l-3.981 4.485a2.49 2.49 0 0 0 3.726 3.307L12 18.799l3.47 3.909a2.49 2.49 0 0 0 3.725-3.307l-3.98-4.485v-3.724h3.995a2.29 2.29 0 0 0 0-4.58H4.79a2.29 2.29 0 1 0 0 4.58h3.996Zm1.5 3.914v-4.414a1 1 0 0 0-1-1H4.79a.79.79 0 0 1 0-1.58h14.42a.79.79 0 0 1 0 1.58h-4.496a1 1 0 0 0-1 1v4.414a1 1 0 0 0 .252.664l4.107 4.627a.99.99 0 1 1-1.482 1.315l-3.843-4.33a1 1 0 0 0-1.496 0l-3.843 4.33a.99.99 0 1 1-1.482 1.315l4.107-4.627a 1 1 0 0 0 .252.664ZM12 4.833A1.167 1.167 0 1 0 12 2.5a1.167 1.167 0 0 0 0 2.333Zm0 1.5A2.667 2.667 0 1 0 12 1a2.667 2.667 0 0 0 0 5.333Z',
];
const ICON_VIEWBOX = ['group' => '0 0 22 22'];
const ICON_PATH_ATTRS = ['accessibility' => ' fill-rule="evenodd" clip-rule="evenodd"'];

const TEMPLATES = [
    'landscape' => 'slide_template.html',
    'portrait' => 'slide_template_portrait.html',
    'portrait-screen' => 'slide_template_portrait_screen.html',
];
const BASE_CSS_IMPORT = '@import "slide_base.css";';

const FONTS = [
    'regular' => '/build/app/shop/fonts/OldschoolGrotesk-Regular-subset.914288c7.woff2',
    'medium' => '/build/app/shop/fonts/OldschoolGrotesk-Medium-subset.1b1f1e8e.woff2',
];
const SITE_BASE = 'https://www.leschampslibres.fr';


function icon_svg($name) {
    $path = ICONS[$name] ?? ICONS['calendar'];
    $vb = ICON_VIEWBOX[$name] ?? '0 0 24 24';
    $attrs = ICON_PATH_ATTRS[$name] ?? '';
    return '<svg viewBox="' . $vb . '" width="42" height="42" '
         . 'aria-hidden="true"><path' . $attrs . ' d="' . $path
         . '" fill="currentColor"/></svg>';
}

function md_inline($text) {
    /** Markdown-lite → HTML inline : échappé d'abord, puis **gras**,
     *  *italique*, [lien](url) → libellé souligné, ## titre → <b>. */
    $t = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $t = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $t);
    $t = preg_replace('/__(.+?)__/s', '<b>$1</b>', $t);
    $t = preg_replace('/(?<!\w)\*(.+?)\*(?!\w)/s', '<em>$1</em>', $t);
    $t = preg_replace('/\[(.+?)\]\([^)]*\)/s', '<u>$1</u>', $t);
    $t = preg_replace('/^#+\s*(.+?)$/m', '<b>$1</b>', $t);
    return str_replace("\n", '<br>', $t);
}

function truncate_text($text, $limit = 400) {
    if (mb_strlen($text) <= $limit) return $text;
    $cut = mb_substr($text, 0, $limit);
    $pos = mb_strrpos($cut, ' ');
    if ($pos !== false) $cut = mb_substr($cut, 0, $pos);
    return rtrim($cut, '.,;:!?') . '…';
}

function asset_svg($name) {
    $p = ASSET_DIR . '/' . $name;
    return is_file($p) ? file_get_contents($p) : '';
}

function ensure_fonts() {
    /** Fontes du site téléchargées une fois → base64 pour @font-face.
     *  Échec toléré : le gabarit retombe sur Arial. */
    $dir = CACHE_DIR . '/fonts';
    @mkdir($dir, 0777, true);
    $out = [];
    foreach (FONTS as $name => $url) {
        $f = $dir . '/' . basename($url);
        if (!is_file($f)) {
            try {
                [$data] = http_get(SITE_BASE . $url);
                file_put_contents($f, $data);
            } catch (Exception $e) {
                $out[$name] = '';
                continue;
            }
        }
        $out[$name] = base64_encode(file_get_contents($f));
    }
    return $out;
}

function download_image($ev) {
    /** Image → data URI. Cache local par nom de fichier (les URLs
     *  sont versionnées — jamais retéléchargées). */
    $url = $ev['image'] ?? null;
    if (!$url) { $ev['img_data'] = null; return $ev; }
    $f = CACHE_DIR . '/img/' . basename(parse_url($url, PHP_URL_PATH));
    try {
        if (is_file($f)) {
            $data = file_get_contents($f);
            $mime = 'image/jpeg';
        } else {
            [$data, $mime] = http_get($url);
            @mkdir(dirname($f), 0777, true);
            file_put_contents($f, $data);
            if (!$mime) $mime = 'image/jpeg';
        }
        $ev['img_data'] = "data:$mime;base64," . base64_encode($data);
    } catch (Exception $e) {
        $ev['img_data'] = null;
    }
    return $ev;
}

function slide_template($orientation) {
    /** Gabarit + charte commune (l'@import est remplacé à la volée). */
    static $cache = [];
    if (!isset($cache[$orientation])) {
        $src = file_get_contents(
            ASSET_DIR . '/' . TEMPLATES[$orientation]);
        if (strpos($src, BASE_CSS_IMPORT) === false)
            throw new RuntimeException(
                TEMPLATES[$orientation] . ' : import '
                . BASE_CSS_IMPORT . ' introuvable');
        $cache[$orientation] = str_replace(
            BASE_CSS_IMPORT,
            file_get_contents(ASSET_DIR . '/slide_base.css'), $src);
    }
    return $cache[$orientation];
}

function tpl_substitute($src, $vars) {
    /** Équivalent PHP de string.Template.substitute (Python) — les
     *  gabarits viennent de l'app de bureau, on garde leur syntaxe :
     *    $$       → $ littéral (utilisé dans les commentaires d'en-tête)
     *    $name    → valeur
     *    ${name}  → idem, forme accolée (utilisée pour $h1_size)
     *  Ordre important : $$ protégé en \x01 AVANT la substitution,
     *  restauré après — sinon $$font_regular serait remplacé. */
    $src = str_replace('$$', "\x01", $src);
    $src = preg_replace_callback(
        '/\$\{(\w+)\}/', fn($m) => '$' . $m[1], $src);
    $repl = [];
    foreach ($vars as $k => $v) $repl['$' . $k] = $v;
    return str_replace("\x01", '$', strtr($src, $repl));
}

function slide_html($ev, $fonts, $orientation = 'landscape') {
    $portrait = strpos($orientation, 'portrait') === 0;
    [$bg, $dark] = CARD_COLORS[$ev['color'] ?? ''] ?? CARD_COLOR_DEFAULT;
    $tag = $ev['tag'] ?? ($ev['specs']['Catégorie'] ?? 'Événement');
    // les temps forts se déroulent à plusieurs endroits : le lieu
    // (Hall) est trompeur — on ne l'affiche pas
    $keys = array_filter(SPEC_ORDER, function ($k) use ($ev, $tag) {
        return isset($ev['specs'][$k])
            && !($k === 'Lieu' && $tag === 'Temps fort');
    });
    $specs_html = '';
    foreach ($keys as $k)
        $specs_html .= '<div class="spec">' . icon_svg(SPEC_ICONS[$k])
            . '<span>' . htmlspecialchars($ev['specs'][$k], ENT_QUOTES,
                                          'UTF-8')
            . '</span></div>';

    // taille du titre adaptée à sa longueur — les seuils sont
    // calibrés sur les métriques de chaque gabarit (cf. slide.py)
    $n = mb_strlen($ev['title']);
    if ($portrait)
        $h1 = $orientation === 'portrait-screen'
            ? ($n < 50 ? 70 : ($n < 80 ? 57 : 46))
            : ($n < 50 ? 76 : ($n < 80 ? 62 : 50));
    else
        $h1 = $n < 50 ? 80 : ($n < 80 ? 64 : 52);
    $specs_cls = count($keys) >= 4 ? 'specs specs--tight' : 'specs';

    $credit = htmlspecialchars($ev['credit'] ?? '', ENT_QUOTES, 'UTF-8');
    if (!empty($ev['img_data'])) {
        $media = '<img class="photo" src="' . $ev['img_data'] . '" alt="">'
               . ($credit ? '<div class="credit">' . $credit . '</div>'
                          : '');
    } else {
        // pas d'image : bloc visuel de remplacement — cercles
        // concentriques + logo, dans la variante foncée de la carte
        $media = '<div class="photo photo--empty" style="background:'
               . $dark . '">'
               . '<svg viewBox="0 0 780 970" preserveAspectRatio="xMidYMid slice">'
               . '<g fill="none" stroke="rgba(255,255,255,.42)" stroke-width="86">'
               . '<circle cx="-60" cy="1030" r="340"/><circle cx="-60" cy="1030" r="560"/>'
               . '<circle cx="-60" cy="1030" r="780"/></g>'
               . '<g fill="none" stroke="' . $dark . '" stroke-width="60" opacity=".55">'
               . '<circle cx="850" cy="-40" r="260"/><circle cx="850" cy="-40" r="440"/></g>'
               . '</svg><div class="ph-logo">' . asset_svg('logo-full.svg')
               . '</div></div>';
    }

    return tpl_substitute(slide_template($orientation), [
        'font_regular' => $fonts['regular'] ?? '',
        'font_medium' => $fonts['medium'] ?? '',
        'ink' => INK, 'bg' => $bg, 'dark' => $dark,
        'media' => $media,
        'tag' => htmlspecialchars($tag, ENT_QUOTES, 'UTF-8'),
        'h1_size' => $h1,
        'title' => htmlspecialchars($ev['title'], ENT_QUOTES, 'UTF-8'),
        'desc' => md_inline(truncate_text(
            ($ev['desc_md'] ?? '') ?: ($ev['desc'] ?? ''))),
        'specs_cls' => $specs_cls,
        'specs_html' => $specs_html,
        'logo_mark' => asset_svg('logo-mark.svg'),
    ]);
}

function slugify($text, $maxlen = 40) {
    /** Titre → fragment de nom de fichier : minuscules, accents
     *  translittérés, tirets. 40 car. max — le nom reste lisible dans
     *  le manifest et stable entre deux générations. */
    static $map = ['à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e',
                   'ë'=>'e','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u',
                   'û'=>'u','ü'=>'u','ç'=>'c','œ'=>'oe','æ'=>'ae'];
    $s = mb_strtolower($text);
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim(str_replace(["'", '’'], '', $s), '-');
    $s = mb_substr($s, 0, $maxlen);
    return trim($s, '-') ?: 'event';
}

function slide_name($ev, $idx) {
    /** Nom stable : date + titre — l'ordre alphabétique suit la
     *  chronologie, un événement garde son nom entre générations. */
    $d = $ev['specs']['Date'] ?? '';
    $prefix = sprintf('zz%02d', $idx);
    if (preg_match('#(\d{2})/(\d{2})/(\d{2})#', $d, $m))
        $prefix = "20{$m[3]}-{$m[2]}-{$m[1]}";
    if (preg_match('#(\d{1,2})h(\d{2})?#', $d, $t))
        $prefix .= sprintf('-%02dh%s', (int)$t[1],
                           $t[2] ?? '00');
    if (!empty($ev['pinned']))
        // multi-jours en cours : 'slide-00-' trie avant 'slide-20…'
        $prefix = '00-' . $prefix;
    return "slide-$prefix-" . slugify($ev['title']);
}

function apply_spec_prefs($events) {
    /** Préférences d'affichage des specs — appliquées APRÈS collecte,
     *  donc identiques quelle que soit la source :
     *   - SPECS_SHOW      : clés autorisées (vide = toutes ; « Date »
     *                       est toujours conservée — requise pour le
     *                       nommage des fichiers)
     *   - SPEC_OVERRIDES  : « Clé = valeur »/ligne — remplace ou ajoute
     *                       une spec (corriger un Lieu qui désigne le
     *                       bâtiment plutôt que la salle)
     *   - SPEC_DROPS      : fragments à virgules retirés des valeurs —
     *                       les specs sont des listes jointes par
     *                       « · » : on enlève un item sans perdre les
     *                       autres (ex. « Dispositifs d'écoute
     *                       amplifiée »)
     *  Une clé forcée mais masquée par SPECS_SHOW n'est pas ajoutée. */
    $shown = array_filter(array_map('trim', explode(',', SPECS_SHOW)));
    $show = $shown ? array_flip($shown) : null;
    $drops = array_filter(array_map('trim', explode(',', SPEC_DROPS)));
    $over = parse_kv(SPEC_OVERRIDES);
    if (!$show && !$over && !$drops) return $events;
    foreach ($events as &$ev) {
        $specs = $ev['specs'] ?? [];
        if ($show !== null)
            $specs = array_filter($specs, fn($k) =>
                $k === 'Date' || isset($show[$k]), ARRAY_FILTER_USE_KEY);
        foreach ($specs as $k => $v) {
            if ($k === 'Date' || !$drops) continue;
            $kept = array_filter(array_map('trim', explode(' · ', $v)),
                fn($p) => $p && !in_array($p, $drops));
            if ($kept) $specs[$k] = implode(' · ', $kept);
            else unset($specs[$k]);
        }
        foreach ($over as $k => $v)
            if ($v !== '' && ($show === null || isset($show[$k])
                              || $k === 'Date'))
                $specs[$k] = $v;
        $ev['specs'] = $specs;
    }
    unset($ev);
    return $events;
}

function render_set($events, $fonts, $fmt) {
    /** Écrit les html/slide-*.html + manifest.txt d'un format dans
     *  datas/nextevent/<fmt>/ et supprime les fichiers obsolètes.
     *
     *  IMPORTANT : les diapos passées disparaissent du manifest (les
     *  fichiers sont supprimés) et les nouvelles apparaissent — la
     *  page kiosk re-poll le manifest toutes les 15 s, la rotation
     *  s'adapte seule sans toucher au kiosk. */
    $dest = DATA_DIR . '/' . $fmt;
    $html_dir = $dest . '/html';
    @mkdir($html_dir, 0777, true);

    $expected = [];
    $used = [];
    foreach ($events as $i => $ev) {
        $name = slide_name($ev, $i + 1);
        while (isset($used[$name])) $name .= '-' . ($i + 1);
        $used[$name] = true;
        $expected["$name.html"] = true;
        file_put_contents(
            "$html_dir/$name.html", slide_html($ev, $fonts, $fmt));
    }
    foreach (glob("$html_dir/*.html") ?: [] as $p)
        if (!isset($expected[basename($p)])) unlink($p);

    // manifeste — uploadé/lu en dernier par la page d'affichage
    $names = array_keys($expected);
    sort($names);
    file_put_contents($dest . '/manifest.txt',
                      implode("\n", $names) . "\n");
    return $names;
}
