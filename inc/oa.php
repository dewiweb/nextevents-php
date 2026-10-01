<?php
/**
 * Source OpenAgenda — port de nextevents/oa.py.
 *
 * Chaîne de repli :
 *   1. API v2 officielle (si OA_API_KEY renseignée)
 *   2. export legacy events.json (public, sans clé — déprécié)
 *
 * Produit le même format d'événement que l'app de bureau :
 * title, url, tag, specs{Date, Durée, Lieu, Tarif, Public,
 * Accessibilité}, desc, desc_md, series, image, credit, _dt,
 * _dt_end, pinned.
 */

require_once __DIR__ . '/http.php';

const OA_API = 'https://api.openagenda.com/v2';
const OA_TZ = 'Europe/Paris';

// — Correspondances catégories ————————————————————————————————————
// La taxonomie OpenAgenda (colonne « categorie ») ne colle pas au
// wording du site : « evenement » OA s'affiche « Temps fort » sur les
// diapos, « atelier-4c » devient « Rendez-vous 4C ». Les valeurs
// filtrables sont celles de OA_CATEGORIES dans config.php.
const OA_TAG_LABEL = [
    'evenement' => 'Temps fort',
    'atelier-4c' => 'Rendez-vous 4C',
];

// mots-clés OA « déficiences » → mention d'accessibilité actionnable
const ACCESS_KEYWORDS = [
    'defautidif_lsf' => 'Interprétation en LSF',
    'defauditif_amp' => 'Dispositifs d\'écoute amplifiée',
    'defvisuel' => 'Audiodescription',
];

const PUBLIC_LABEL = ['Tous publics' => 'Tout public'];

// mentions d'accessibilité dans le texte libre (port _ACCESS_PATTERNS)
const ACCESS_PATTERNS = [
    ['#interpr[ée]t\w*\s+en\s+LSF|langue des signes#i', 'Interprétation en LSF'],
    ['#audiodescri\w*|audio-description#i', 'Audiodescription'],
    ['#surtitr#i', 'Surtitrage'],
    ['#boucle magn[ée]tique|collier magn[ée]tique|casque d\'amplification#i',
     'Dispositifs d\'écoute amplifiée'],
];


// ———————————————————— formatage ————————————————————

function oa_norm_ws($text) {
    /** Espaces invisibles OpenAgenda (zero-width \xE2\x80\x8B,
     *  insécable \xC2\xA0) → espace normal, sinon « 15h00Où » quand le
     *  saut de ligne markdown est réduit. Conserve les marqueurs
     *  markdown — version destinée au rendu HTML (cf. md_inline). */
    if (!$text) return '';
    return trim(str_replace(["\xE2\x80\x8B", "\xC2\xA0"], ' ', $text));
}

function oa_clean_md($text) {
    /** Markdown-lite OA → texte plat (extraction, specs).
     *  Le rendu utilise md_inline() qui, lui, INTERPRÈTE le markdown. */
    $text = oa_norm_ws($text);
    $text = preg_replace('/\*\*(.+?)\*\*/s', '$1', $text);
    $text = preg_replace('/__(.+?)__/s', '$1', $text);
    $text = preg_replace('/(?<!\w)\*(.+?)\*(?!\w)/s', '$1', $text);
    $text = preg_replace('/\[(.+?)\]\([^)]*\)/s', '$1', $text);
    $text = preg_replace('/^#+\s*/m', '', $text);
    return trim($text);
}

function oa_local($iso) {
    /** ISO 8601 (UTC ou +0X:00) → [année, mois, jour, h, min] en heure
     *  de Paris. 'Z' est normalisé car DateTime le tolère mal selon
     *  les versions. Accepte aussi 'now' (utilisé pour « aujourd'hui »). */
    $d = new DateTime(str_replace('Z', '+00:00', $iso));
    $d->setTimezone(new DateTimeZone(OA_TZ));
    return [(int)$d->format('Y'), (int)$d->format('m'), (int)$d->format('d'),
            (int)$d->format('G'), (int)$d->format('i')];
}

function oa_day_key($t) { return $t[0] * 10000 + $t[1] * 100 + $t[2]; }

function oa_ts($t) {
    return mktime($t[3], $t[4], 0, $t[1], $t[2], $t[0]);
}

function oa_fmt_day($t) {
    return sprintf('%02d/%02d/%02d', $t[2], $t[1], $t[0] % 100);
}

function oa_fmt_time($t) {
    return str_replace('h00', 'h', sprintf('%dh%02d', $t[3], $t[4]));
}

function oa_duration($begin, $end) {
    $mins = (oa_ts($end) - oa_ts($begin)) / 60;
    if ($mins <= 0 || $mins >= 240) return '';  // ≥ 4 h = ouverture du lieu
    $h = intdiv((int)$mins, 60);
    $m = (int)$mins % 60;
    return $m ? "{$h}h" . sprintf('%02d', $m) : ($h ? "{$h}h" : "{$mins} min");
}

function oa_date_spec($timings, $next_label) {
    /** Spec Date + nb séances + durée + bornes + épingle.
     *  timings : [[begin_local, end_local], ...] triés par début.
     *  Retourne [date_spec, nb, durée, _dt, _dt_end, pinned]. */
    $today = oa_day_key(oa_local('now'));
    // créneaux dont la FIN est aujourd'hui ou plus tard — un événement
    // commencé ce matin mais finissant ce soir reste « à venir »
    $future = array_values(array_filter(
        $timings, fn($t) => oa_day_key($t[1]) >= $today));
    if (!$future) $future = $timings;  // tout est passé : dernière séance
    $first_b = $timings[0][0];
    $last_e = $timings[count($timings) - 1][1];
    $span = (oa_ts([$last_e[0], $last_e[1], $last_e[2], 0, 0])
           - oa_ts([$first_b[0], $first_b[1], $first_b[2], 0, 0])) / 86400;

    $days = [];
    foreach ($timings as $t) $days[oa_day_key($t[0])] = true;
    $durs = [];
    foreach ($timings as $t)
        $durs[] = (oa_ts($t[1]) - oa_ts($t[0])) / 60;
    sort($durs);
    $med = $durs[intdiv(count($durs), 2)];

    // un timing = bloc d'ouverture de journée (≥ 4 h) → vraie expo
    // permanente ; des séances courtes très nombreuses (planétarium :
    // ~1640 × 60 min sur 2 ans) sont programmées — « Prochaine
    // séance » leur convient mieux
    $open_blocks = $med >= 240;
    if ($span > 300 && $open_blocks && count($days) >= $span * .5)
        return ['Exposition permanente', 0, '', null, null, false];

    $contiguous = count($days) > 1 && $span > 0
        && count($days) >= $span * .8
        && ($open_blocks || $span <= 120);
    if ($contiguous) {
        // événement multi-jours (temps fort, expo temporaire)
        $spec = 'Du ' . oa_fmt_day($first_b) . ' au ' . oa_fmt_day($last_e);
        $pinned = oa_day_key($first_b) <= $today
               && $today <= oa_day_key($last_e);
        return [$spec, 0, '', $first_b, $last_e, $pinned];
    }
    // séance(s) ponctuelle(s) : la prochaine porte la date — préfixée
    // « Prochaine séance : » (NEXT_LABEL) quand l'événement est récurrent
    $nx = $future[0];
    $spec = oa_fmt_day($nx[0]) . ' à ' . oa_fmt_time($nx[0]);
    if (count($future) > 1 && $next_label)
        $spec = $next_label . $spec;
    return [$spec, count($future), oa_duration($nx[0], $nx[1]),
            $nx[0], $nx[0], false];
}

function oa_timings_pairs($raw) {
    /** Normalise timings v2 (begin/end) et legacy (start/end). */
    $out = [];
    foreach ($raw ?: [] as $t) {
        $b = $t['begin'] ?? $t['start'] ?? null;
        $e = $t['end'] ?? null;
        if ($b && $e) $out[] = [oa_local($b), oa_local($e)];
    }
    usort($out, fn($a, $b) => $a[0] <=> $b[0]);
    return $out;
}

function oa_extract_access($text) {
    $out = [];
    foreach (ACCESS_PATTERNS as [$pat, $label])
        if (preg_match($pat, $text) && !in_array($label, $out))
            $out[] = $label;
    return $out;
}

function parse_kv($text) {
    $out = [];
    foreach (explode("\n", $text ?: '') as $line) {
        $line = trim($line);
        if (!$line || $line[0] === '#' || strpos($line, '=') === false)
            continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        if ($k) $out[$k] = trim($v);
    }
    return $out;
}


// ———————————————————— mapping événement ————————————————————

function oa_base_map($cat_value, $cat_label, $public_label, $kws, $cond,
                     $timings, $title, $url, $desc, $desc_long, $image,
                     $credit, $lieu, $access_codes) {
    /** Construit l'événement normalisé — v2 et legacy convergent ici.
     *  Produit : title, url, tag, specs{}, desc(_long/_md), series,
     *  image, credit, _dt/_dt_end (bornes locales), pinned, _oa_cat. */
    $pairs = oa_timings_pairs($timings);
    if ($pairs) {
        [$date_spec, $n, $dur, $dt, $dt_end, $pinned] =
            oa_date_spec($pairs, NEXT_LABEL);
    } else {
        // aucun créneau : comme « Exposition permanente » du site
        $date_spec = 'Exposition permanente';
        $dur = '';
        $dt = $dt_end = null;
        $pinned = false;
    }

    $specs = ['Date' => $date_spec];
    if ($pairs && $dur) $specs['Durée'] = $dur;
    if ($lieu) $specs['Lieu'] = $lieu;
    if ($cond) $specs['Tarif'] = mb_strtoupper(mb_substr($cond, 0, 1))
                               . mb_substr($cond, 1);
    if ($public_label) {
        $pub = PUBLIC_LABEL[$public_label] ?? $public_label;
        $specs['Public'] = $pub;
    }

    $tag = OA_TAG_LABEL[$cat_value] ?? ($cat_label ?: 'Événement');
    if ($tag === 'Temps fort')
        unset($specs['Lieu']);  // multi-sites : le lieu prête à confusion

    $desc_md = oa_norm_ws($desc) ?: oa_norm_ws($desc_long);
    $desc = oa_clean_md($desc);
    $desc_long = oa_clean_md($desc_long);

    // accessibilité : mots-clés structurés OA ∪ mentions dans le texte
    $access_labels = [];
    foreach (ACCESS_KEYWORDS as $k => $lbl)
        if (in_array($k, $kws)) $access_labels[] = $lbl;
    foreach (oa_extract_access($desc_long) as $lbl)
        if (!in_array($lbl, $access_labels)) $access_labels[] = $lbl;
    if ($access_labels)
        $specs['Accessibilité'] = implode(' · ', $access_labels);

    $series = '';
    foreach (parse_kv(SERIES_MAP) as $k => $lbl)
        if (in_array($k, $kws)) { $series = $lbl; break; }

    return [
        'title' => $title, 'url' => $url, 'tag' => $tag, 'color' => null,
        'specs' => $specs, 'desc' => $desc, 'desc_long' => $desc_long,
        'desc_md' => $desc_md,
        'series' => $series, 'image' => $image, 'credit' => $credit,
        '_dt' => $dt, '_dt_end' => $dt_end, 'pinned' => $pinned,
        '_oa_cat' => $cat_value, 'keywords' => $kws,
    ];
}

function oa_map_v2($e, $cat_opts, $pub_opts, $agenda) {
    $cat_id = $e['categorie'] ?? null;
    [$cat_value, $cat_label] = $cat_opts[$cat_id] ?? [null, null];
    $pub_ids = $e['publics'] ?? [];
    if (is_int($pub_ids)) $pub_ids = [$pub_ids];
    $pubs = [];
    foreach ($pub_ids as $i)
        if (isset($pub_opts[$i])) $pubs[] = $pub_opts[$i];
    $kws = array_values(array_filter(
        ($e['keywords']['fr'] ?? []) ?: []));
    $cond = $e['conditions']['fr'] ?? '';
    // accessibilité du lieu : v2 renvoie soit un objet {code: bool},
    // soit une liste [codes] selon les agendas — on normalise
    $acc = $e['accessibility'] ?? [];
    $acc_codes = is_array($acc) && array_keys($acc) !== range(0, count($acc) - 1)
        ? array_keys(array_filter($acc)) : array_values($acc);
    $img = $e['image'] ?? [];
    $image = isset($img['filename'])
        ? ($img['base'] ?? '') . $img['filename'] : null;
    foreach ($img['variants'] ?? [] as $v)
        if (($v['type'] ?? '') === 'full')
            $image = ($img['base'] ?? '') . $v['filename'];
    $uid = $e['uid'] ?? null;
    $url = $e['canonicalUrl']
        ?? ($uid ? "https://openagenda.com/$agenda/events/{$uid}_"
                 . ($e['slug'] ?? '') : '');
    $ev = oa_base_map(
        $cat_value, $cat_label, implode(' · ', $pubs), $kws, $cond,
        $e['timings'] ?? null,
        $e['title']['fr'] ?? '', $url,
        $e['description']['fr'] ?? '',
        ($e['longDescription']['fr'] ?? '')
            ?: ($e['description']['fr'] ?? ''),
        $image, $e['imageCredits'] ?? '',
        $e['location']['name'] ?? '', $acc_codes);
    // public + « dès N ans »
    $age = $e['age'] ?? null;
    if ($pubs && !empty($age['min']))
        $ev['specs']['Public'] .= ' · dès ' . $age['min'] . ' ans';
    if (empty($e['timings'])) {
        // timings indisponibles même sur le détail : le texte
        // « dateRange » éditorial vaut mieux que « permanente »
        $dr = trim(preg_replace('/\s*undefined\s*/', ' ',
                   $e['dateRange']['fr'] ?? ''), " ,");
        if ($dr) $ev['specs']['Date'] = $dr;
    }
    return $ev;
}

function oa_map_legacy($e) {
    $cat_value = $cat_label = null;
    $pubs = [];
    foreach ($e['tagGroups'] ?? [] as $g)
        foreach ($g['tags'] ?? [] as $t) {
            if (($g['slug'] ?? '') === 'categorie') {
                $cat_value = $t['slug'] ?? null;
                $cat_label = $t['label'] ?? null;
            } elseif (($g['slug'] ?? '') === 'publics') {
                $pubs[] = $t['label'] ?? '';
            }
        }
    $kws = array_values(array_filter(
        ($e['keywords']['fr'] ?? []) ?: []));
    $ev = oa_base_map(
        $cat_value, $cat_label,
        implode(' · ', array_filter($pubs)), $kws,
        $e['conditions']['fr'] ?? '', $e['timings'] ?? null,
        $e['title']['fr'] ?? '', $e['canonicalUrl'] ?? '',
        $e['description']['fr'] ?? '',
        ($e['longDescription']['fr'] ?? '')
            ?: ($e['description']['fr'] ?? ''),
        $e['originalImage'] ?? ($e['image'] ?? null),
        $e['imageCredits'] ?? '', $e['locationName'] ?? '',
        $e['accessibility'] ?? []);
    $age = $e['age'] ?? null;
    if (!empty($ev['specs']['Public']) && !empty($age['min']))
        $ev['specs']['Public'] .= ' · dès ' . $age['min'] . ' ans';
    return $ev;
}


// ———————————————————— fetch ————————————————————

function oa_resolve_uid($agenda) {
    /** Le legacy export veut l'uid numérique ; la config accepte le
     *  slug lisible — extrait de la page publique de l'agenda. */
    if (ctype_digit((string)$agenda)) return (int)$agenda;
    [$html] = http_get("https://openagenda.com/fr/$agenda");
    if (preg_match('#agendas/(\d+)#', $html, $m))
        return (int)$m[1];
    throw new RuntimeException("uid de l'agenda « $agenda » introuvable");
}

function oa_v2_events($agenda, $key) {
    /** API v2 : schéma (libellés) puis événements en cours + à venir. */
    $a = http_json(OA_API . "/agendas/$agenda?key=" . urlencode($key));
    $cat_opts = $pub_opts = [];
    foreach ($a['schema']['fields'] ?? [] as $f) {
        $opts = [];
        foreach ($f['options'] ?? [] as $o)
            $opts[$o['id']] = [$o['value'] ?? null,
                               $o['label']['fr'] ?? null];
        if ($f['field'] === 'categorie') $cat_opts = $opts;
        elseif ($f['field'] === 'publics')
            foreach ($opts as $i => $o) $pub_opts[$i] = $o[1];
    }
    // « en cours + à venir » ; pagination par curseur after[]
    $events = [];
    $after = null;
    do {
        $q = "key=" . urlencode($key) . "&size=300&detailed=1"
           . "&relative[]=current&relative[]=upcoming";
        if ($after) $q .= '&after[]=' . urlencode($after);
        $d = http_json(OA_API . "/agendas/$agenda/events?$q");
        $batch = $d['events'] ?? [];
        $events = array_merge($events, $batch);
        $after = $d['after'] ?? null;
    } while ($batch && $after);
    // la liste omet `timings` quand il y en a trop (rdv4c : ~40/an)
    foreach ($events as &$e)
        if (empty($e['timings']) && !empty($e['firstTiming'])) {
            try {
                $d = http_json(OA_API . "/agendas/$agenda/events/"
                    . $e['uid'] . '?key=' . urlencode($key));
                $full = $d['event'] ?? $d;
                if (!empty($full['timings']))
                    $e['timings'] = $full['timings'];
            } catch (Exception $x) { /* dateRange servira de spec */ }
        }
    unset($e);
    return array_map(
        fn($e) => oa_map_v2($e, $cat_opts, $pub_opts, $agenda), $events);
}

function oa_legacy_events($agenda) {
    /** Export public legacy (sans clé) : tout l'historique, filtré
     *  côté client aux événements pas terminés. */
    $uid = oa_resolve_uid($agenda);
    $today = oa_day_key(oa_local('now'));
    $events = [];
    $offset = 0;
    do {
        $d = http_json("https://openagenda.com/agendas/$uid/events.json"
                       . "?limit=100&offset=$offset");
        $batch = $d['events'] ?? [];
        $events = array_merge($events, $batch);
        $offset += count($batch);
    } while ($batch && $offset < ($d['total'] ?? 0));
    $out = [];
    foreach ($events as $e) {
        $pairs = oa_timings_pairs($e['timings'] ?? null);
        if (!$pairs) continue;
        if (oa_day_key($pairs[count($pairs) - 1][1]) < $today)
            continue;  // terminé — l'export n'a pas de filtre serveur
        $out[] = oa_map_legacy($e);
    }
    return $out;
}

function oa_list_events() {
    /** Événements OA filtrés par catégorie, triés épinglés puis date. */
    if (OA_API_KEY)
        $events = oa_v2_events(OA_AGENDA, OA_API_KEY);
    else
        $events = oa_legacy_events(OA_AGENDA);
    $wanted = array_flip(OA_CATEGORIES);
    $events = array_values(array_filter(
        $events, fn($e) => isset($wanted[$e['_oa_cat'] ?? ''])));
    usort($events, function ($a, $b) {
        $pa = $a['pinned'] ? 0 : 1;
        $pb = $b['pinned'] ? 0 : 1;
        if ($pa !== $pb) return $pa - $pb;
        return ($a['_dt'] ?? [9999, 12, 31, 23, 59])
            <=> ($b['_dt'] ?? [9999, 12, 31, 23, 59]);
    });
    return $events;
}
