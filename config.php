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

// — Transport HTTP ———————————————————————————————————————————————
// cURL est utilisé s'il est présent, sinon les wrappers URL
// (file_get_contents — nécessite allow_url_fopen=1). Sur un PHP
// local Windows sans bundle CA, les requêtes HTTPS échouent en
// SSL : renseigner curl.cainfo/openssl.cafile dans php.ini, ou
// passer false ici UNIQUEMENT pour tester en local.
define('HTTP_VERIFY_SSL', true);

// — Catégories affichées (valeurs « categorie » OpenAgenda) ————————
// vitrine historique : les 5 rubriques principales. Ajouter
// 'animation','atelier','atelier-4c','visite' pour tout couvrir.
define('OA_CATEGORIES', [
    'rencontre', 'concert', 'projection', 'spectacle', 'evenement',
]);

// — Génération ——————————————————————————————————————————————————
// formats produits : 'portrait-screen' (9:16 pour l'écran vertical),
// 'portrait-tall' (1080×2160 — écran plus élancé), 'portrait-uhd'
// (2160×3840 — résolution native 4K portrait : iframe directe sans
// scaling), 'landscape' (16:9), ou plusieurs
define('FORMATS', ['portrait-screen', 'landscape', 'portrait-uhd']);
// nombre max d'événements par format (0 = sans limite)
define('MAX_EVENTS', 12);
// fenêtre en jours sur la PROCHAINE séance : 1 = « à partir de
// demain » (annonce J+1) ; DAY_OFFSET_MAX null = pas de borne haute.
// Ex. programme de demain seul : MIN=1, MAX=1.
define('DAY_OFFSET_MIN', 0);
define('DAY_OFFSET_MAX', null);
// préfixe des événements récurrents (vide = date seule)
define('NEXT_LABEL', 'Prochaine séance : ');
// format d'affichage de la spec Date (mêmes choix que l'app de
// bureau) : 'compact' 27/10/26 | 'noyear' 27/10 | 'short'
// « mar. 27 oct. » | 'long' « mardi 27 octobre » — l'heure, le
// préfixe récurrent et « Du … au … » sont conservés
define('DATE_FORMAT', 'long');
// specs affichées (vide = toutes ; 'Date' est toujours conservée)
define('SPECS_SHOW', '');
// valeurs forcées : « Clé = valeur » par ligne
define('SPEC_OVERRIDES', '');
// items retirés d'une spec sans la masquer (séparés par virgules) —
// ex. 'Écoute amplifiée'
define('SPEC_DROPS', '');
// séries éditoriales : « keyword-oa = Libellé » par ligne
define('SERIES_MAP', "grandstemoins = Les grands témoins\n");
// source de la description (mêmes choix que l'app de bureau) :
// 'auto' = markdown OA si présent, chapeau sinon ; 'chapeau' = texte
// court ; 'long' = description détaillée ; 'full' = chapeau puis
// description détaillée (le chapeau OA sert d'accroche à la longue).
// 'long'/'full' prennent tout leur sens avec 'portrait-tall' qui a
// la place de les afficher.
define('DESC_MODE', 'full');
// troncature de la description en caractères (0 = borne sûre 400) ;
// le budget lignes du gabarit (--desc-lines) borne de toute façon
define('DESC_MAX', 0);

// — Diaporama ————————————————————————————————————————————————————
define('SLIDE_DELAY', 8);        // secondes par diapo
define('SLIDE_TRANSITION', 'fade'); // 'fade', 'slide' ou 'none'
define('SLIDE_TRANS_MS', 1500);  // durée de transition

// — Fraîcheur ————————————————————————————————————————————————————
// le manifest est régénéré s'il a plus de N minutes (à l'appel de la
// page ou d'un cron qui tape front/gen.php — les deux fonctionnent)
define('REFRESH_MIN', 60);
// purge du cache images : fichiers non retéléchargés depuis N jours
// supprimés à chaque génération (0 = jamais purgé ; les URLs OA sont
// versionnées → un fichier inutilisé reste pur inoccupé)
define('CACHE_IMG_DAYS', 30);

// — Superviseur kiosk (front/kiosk.php) ————————————————————————————
// page existante encapsulée (jauges). Si renseignée, le paramètre
// ?jauges= de l'URL est IGNORÉ : l'iframe superviseur ne peut pas
// être détournée vers une URL arbitraire. Vide = ?jauges= requis
// (pratique en dev, à renseigner en prod).
define('KIOSK_JAUGES', '');

// — Contrôle d'accès ———————————————————————————————————————————————
// si renseigné, GET gen.php?force=1 exige en plus &token=<valeur>
// (le cron doit alors l'inclure dans l'URL). Sans effet sur la
// régénération interne du player (sans force) ni sur le CLI
// (php front/gen.php).
define('GEN_TOKEN', '');

// — Chemins (relatifs à la racine du projet) ——————————————————————
define('DATA_DIR', __DIR__ . '/datas/nextevent');
define('ASSET_DIR', __DIR__ . '/assets');
define('CACHE_DIR', DATA_DIR . '/cache');
