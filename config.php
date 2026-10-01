<?php
/**
 * nextevents-php — configuration.
 *
 * Port PHP léger de nextevents-desktop pour le serveur de jauges :
 * récupère les événements OpenAgenda, génère des diapos HTML autonomes
 * (fontes + images en base64) et les sert en diaporama pour un kiosk
 * Chrome. Aucune dépendance, aucune clé API requise (export public).
 */

// — Agenda OpenAgenda ——————————————————————————————————————————
// slug lisible (https://openagenda.com/fr/<slug>) ou uid numérique
define('OA_AGENDA', 'leschampslibres');
// clé API v2 facultative : si renseignée, l'API officielle est
// utilisée (timings filtrés côté serveur, meilleure couverture)
define('OA_API_KEY', '');

// — Catégories affichées (valeurs « categorie » OpenAgenda) ————————
// vitrine historique : les 5 rubriques principales. Ajouter
// 'animation','atelier','atelier-4c','visite' pour tout couvrir.
define('OA_CATEGORIES', [
    'rencontre', 'concert', 'projection', 'spectacle', 'evenement',
]);

// — Génération ——————————————————————————————————————————————————
// formats produits : 'portrait-screen' (9:16 pour l'écran vertical),
// 'landscape' (16:9) ou les deux
define('FORMATS', ['portrait-screen', 'landscape']);
// nombre max d'événements par format (0 = sans limite)
define('MAX_EVENTS', 0);
// préfixe des événements récurrents (vide = date seule)
define('NEXT_LABEL', 'Prochaine séance : ');
// specs affichées (vide = toutes ; 'Date' est toujours conservée)
define('SPECS_SHOW', '');
// valeurs forcées : « Clé = valeur » par ligne
define('SPEC_OVERRIDES', '');
// items retirés d'une spec sans la masquer (séparés par virgules) —
// ex. 'Dispositifs d'écoute amplifiée'
define('SPEC_DROPS', '');
// séries éditoriales : « keyword-oa = Libellé » par ligne
define('SERIES_MAP', "grandstemoins = Les grands témoins\n");

// — Diaporama ————————————————————————————————————————————————————
define('SLIDE_DELAY', 8);        // secondes par diapo
define('SLIDE_TRANSITION', 'fade'); // 'fade' ou 'slide'
define('SLIDE_TRANS_MS', 1500);  // durée de transition

// — Fraîcheur ————————————————————————————————————————————————————
// le manifest est régénéré s'il a plus de N minutes (à l'appel de la
// page ou d'un cron qui tape front/gen.php — les deux fonctionnent)
define('REFRESH_MIN', 60);

// — Chemins (relatifs à la racine du projet) ——————————————————————
define('DATA_DIR', __DIR__ . '/datas/nextevent');
define('ASSET_DIR', __DIR__ . '/assets');
define('CACHE_DIR', DATA_DIR . '/cache');
