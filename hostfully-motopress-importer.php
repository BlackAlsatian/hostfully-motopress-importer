<?php

/**
 * Plugin Name: Hostfully → MotoPress Importer (Temporary)
 * Description: One-time importer for Hostfully properties into MotoPress.
 * Version: 0.24
 * Author: Black Alsatian
 * Author URI: https://www.blackalsatian.co.za
 * Plugin URI: https://www.blackalsatian.co.za
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 */

if (!defined('ABSPATH')) exit;

const HOSTFULLY_MPHB_OPT_SETTINGS = 'hostfully_mphb_settings';
const HOSTFULLY_MPHB_OPT_QUEUE    = 'hostfully_mphb_queue';
const HOSTFULLY_MPHB_OPT_PROGRESS = 'hostfully_mphb_progress';
const HOSTFULLY_MPHB_OPT_MAP_AMENITIES = 'hostfully_mphb_map_amenities';
const HOSTFULLY_MPHB_OPT_ATTR_DEFS = 'hostfully_mphb_attr_defs';
const HOSTFULLY_MPHB_OPT_LAST_ERROR = 'hostfully_mphb_last_error';
const HOSTFULLY_MPHB_OPT_LEGACY_BEDROOM_CLEANED = 'hostfully_mphb_legacy_bedroom_cleaned';

// Polyfill for PHP < 8.1
if (!function_exists('array_is_list')) {
    function array_is_list(array $array): bool
    {
        $i = 0;
        foreach ($array as $k => $_) {
            if ($k !== $i++) return false;
        }
        return true;
    }
}

function hostfully_mphb_get_last_error(): string
{
    $err = get_option(HOSTFULLY_MPHB_OPT_LAST_ERROR, '');
    return is_string($err) ? $err : '';
}

function hostfully_mphb_set_last_error(string $message): void
{
    update_option(HOSTFULLY_MPHB_OPT_LAST_ERROR, $message, false);
}

function hostfully_mphb_clear_last_error(): void
{
    delete_option(HOSTFULLY_MPHB_OPT_LAST_ERROR);
}

function hostfully_mphb_should_capture_fatal(): bool
{
    if (defined('DOING_AJAX') && DOING_AJAX) {
        $action = $_REQUEST['action'] ?? '';
        return is_string($action) && strpos($action, 'hostfully_mphb_') === 0;
    }

    if (is_admin() && isset($_GET['page']) && $_GET['page'] === 'hostfully-import') {
        return true;
    }

    return false;
}

function hostfully_mphb_shutdown_capture_fatal(): void
{
    if (!hostfully_mphb_should_capture_fatal()) return;

    $err = error_get_last();
    if (!$err || !is_array($err)) return;

    $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($err['type'] ?? 0, $fatal_types, true)) return;

    $message = 'Fatal error: ' . ($err['message'] ?? 'unknown error');
    if (!empty($err['file'])) $message .= ' in ' . $err['file'];
    if (!empty($err['line'])) $message .= ':' . $err['line'];

    hostfully_mphb_set_last_error($message);
}

register_shutdown_function('hostfully_mphb_shutdown_capture_fatal');

/**
 * =========
 * SETTINGS
 * =========
 */
function hostfully_mphb_default_settings(): array
{
    return [
        'api_key'    => '',
        'agency_uid' => '',
        // Hostfully reference docs are currently published under /api/v3.2.
        // Some endpoints behave differently on /api/v3.
        'base_url'   => 'https://api.hostfully.com/api/v3.2',
        'max_photos' => 8,
        'bulk_limit' => 10,
        'api_page_limit' => 100,
        'allow_enrich_api' => 0,
        'amenities_cache_hours' => 24,
        'verbose_log' => 0,
    ];
}

function hostfully_mphb_settings(): array
{
    $defaults = hostfully_mphb_default_settings();
    $saved = get_option(HOSTFULLY_MPHB_OPT_SETTINGS, []);
    if (!is_array($saved)) $saved = [];
    return array_merge($defaults, $saved);
}

function hostfully_mphb_update_settings(array $new): void
{
    $defaults = hostfully_mphb_default_settings();

    $clean = [
        'api_key'    => sanitize_text_field($new['api_key'] ?? ''),
        'agency_uid' => sanitize_text_field($new['agency_uid'] ?? ''),
        'base_url'   => esc_url_raw($new['base_url'] ?? $defaults['base_url']),
        'max_photos' => max(0, (int)($new['max_photos'] ?? $defaults['max_photos'])),
        'bulk_limit' => max(1, (int)($new['bulk_limit'] ?? $defaults['bulk_limit'])),
        'api_page_limit' => min(100, max(1, (int)($new['api_page_limit'] ?? ($defaults['api_page_limit'] ?? 100)))),

        // Progressive enrichment toggles
        'allow_enrich_api' => !empty($new['allow_enrich_api']) ? 1 : 0,
        'amenities_cache_hours' => min(168, max(1, (int)($new['amenities_cache_hours'] ?? ($defaults['amenities_cache_hours'] ?? 24)))),
        'verbose_log' => !empty($new['verbose_log']) ? 1 : 0,
    ];

    update_option(HOSTFULLY_MPHB_OPT_SETTINGS, $clean, false);
}

function hostfully_mphb_log_debug(array &$log, string $message): void
{
    $cfg = hostfully_mphb_settings();
    if (empty($cfg['verbose_log'])) return;
    $log[] = $message;
}

/**
 * =========
 * API WRAP
 * =========
 */
function hostfully_mphb_api_get(string $url)
{
    $cfg = hostfully_mphb_settings();
    if (empty($cfg['api_key'])) {
        return new WP_Error('hostfully_no_key', 'Hostfully API key not set.');
    }

    return wp_remote_get($url, [
        'headers' => [
            'X-HOSTFULLY-APIKEY' => $cfg['api_key'],
            'Accept'             => 'application/json',
        ],
        'timeout' => 30,
    ]);
}

function hostfully_mphb_api_get_json(string $url, array &$headers = [], int &$status = 0, string &$raw_body = '')
{
    $response = hostfully_mphb_api_get($url);
    if (is_wp_error($response)) return $response;

    $status   = (int) wp_remote_retrieve_response_code($response);
    $raw_body = (string) wp_remote_retrieve_body($response);

    $headers_obj = wp_remote_retrieve_headers($response);
    $headers = [];
    if ($headers_obj) {
        foreach ($headers_obj as $k => $v) {
            $headers[strtolower($k)] = is_array($v) ? implode(',', $v) : (string) $v;
        }
    }

    $data = json_decode($raw_body, true);
    if (!is_array($data)) {
        return new WP_Error('hostfully_bad_json', 'Hostfully response was not valid JSON.');
    }
    if (isset($data['apiErrorMessage'])) {
        return new WP_Error('hostfully_api_error', (string) $data['apiErrorMessage']);
    }

    return $data;
}

function hostfully_mphb_extract_next_cursor(array $data, array $headers): string
{
    // Common header candidates (best-effort)
    foreach (['x-next-cursor', 'x-nextcursor', 'next-cursor', 'x-cursor-next', 'x-next-page-cursor'] as $hk) {
        if (!empty($headers[$hk])) return trim((string) $headers[$hk]);
    }

    // Common body candidates (best-effort)
    foreach ([
        $data['nextCursor'] ?? null,
        $data['next_cursor'] ?? null,
        $data['cursor']['next'] ?? null,
        $data['pagination']['nextCursor'] ?? null,
        $data['extensions']['nextCursor'] ?? null, // documented for GraphQL responses, but sometimes appears elsewhere
    ] as $candidate) {
        if (is_string($candidate) && $candidate !== '') return trim($candidate);
    }

    return '';
}

/**
 * =========
 * DATA HELPERS
 * =========
 */
function hostfully_mphb_get_properties(): array
{
    $cfg = hostfully_mphb_settings();
    if (empty($cfg['agency_uid'])) return [];

    $base = rtrim($cfg['base_url'], '/') . '/properties';

    // Hostfully V3 uses cursor pagination (`_limit`, `_cursor`) on paginated endpoints. 
    $limit  = min(100, max(1, (int)($cfg['api_page_limit'] ?? 100)));
    $cursor = '';
    $all    = [];
    $seen   = []; // dedupe by uid, just in case

    // Hard safety limit: 500 pages (way more than you’ll ever need for 109 properties)
    for ($i = 0; $i < 500; $i++) {
        $url = add_query_arg([
            'agencyUid' => $cfg['agency_uid'],
            '_limit'    => $limit,
        ], $base);

        if ($cursor !== '') {
            $url = add_query_arg(['_cursor' => $cursor], $url);
        }

        $headers = [];
        $status  = 0;
        $raw     = '';
        $data = hostfully_mphb_api_get_json($url, $headers, $status, $raw);

        if (is_wp_error($data)) {
            // Fail closed: return whatever we got so far, instead of bricking the UI.
            return $all;
        }

        $page = $data['properties'] ?? [];
        if (!is_array($page) || empty($page)) {
            break;
        }

        foreach ($page as $p) {
            $uid = (string)($p['uid'] ?? '');
            if (!$uid || isset($seen[$uid])) continue;
            $seen[$uid] = true;
            $all[] = $p;
        }

        $next = hostfully_mphb_extract_next_cursor($data, $headers);
        if ($next === '' || $next === $cursor) {
            break;
        }

        $cursor = $next;
    }

    return $all;
}


function hostfully_mphb_get_imported_uids(): array
{
    $posts = get_posts([
        'post_type'      => 'mphb_room_type',
        'posts_per_page' => -1,
        'meta_key'       => '_hostfully_property_uid',
        'fields'         => 'ids',
    ]);

    $uids = [];
    foreach ($posts as $post_id) {
        $uid = get_post_meta($post_id, '_hostfully_property_uid', true);
        if ($uid) $uids[] = $uid;
    }
    return $uids;
}

function hostfully_mphb_find_existing_post_id(string $property_uid): int
{
    $existing = get_posts([
        'post_type'      => 'mphb_room_type',
        'meta_key'       => '_hostfully_property_uid',
        'meta_value'     => $property_uid,
        'fields'         => 'ids',
        'posts_per_page' => 1,
    ]);

    return !empty($existing) ? (int)$existing[0] : 0;
}

/**
 * =========
 * MPHB HELPERS (Rate + Unit)
 * =========
 */
function hostfully_mphb_find_existing_rate_id(string $property_uid): int
{
    $existing = get_posts([
        'post_type'      => 'mphb_rate',
        'meta_key'       => '_hostfully_property_uid',
        'meta_value'     => $property_uid,
        'fields'         => 'ids',
        'posts_per_page' => 1,
    ]);

    return !empty($existing) ? (int)$existing[0] : 0;
}

function hostfully_mphb_find_existing_room_id(string $property_uid): int
{
    $existing = get_posts([
        'post_type'      => 'mphb_room',
        'meta_key'       => '_hostfully_property_uid',
        'meta_value'     => $property_uid,
        'fields'         => 'ids',
        'posts_per_page' => 1,
    ]);

    return !empty($existing) ? (int)$existing[0] : 0;
}

function hostfully_mphb_ensure_all_year_season_meta(int $season_id, array &$log): void
{
    if (!$season_id) return;

    $year = (int)date('Y');
    $start = $year . '-01-01';
    $end = $year . '-12-31';

    $changed = false;
    $start_meta = get_post_meta($season_id, 'mphb_start_date', true);
    $end_meta = get_post_meta($season_id, 'mphb_end_date', true);

    if (empty($start_meta)) {
        update_post_meta($season_id, 'mphb_start_date', $start);
        $changed = true;
    }
    if (empty($end_meta)) {
        update_post_meta($season_id, 'mphb_end_date', $end);
        $changed = true;
    }

    if (get_post_meta($season_id, 'mphb_repeat', true) === '') {
        update_post_meta($season_id, 'mphb_repeat', '1');
        $changed = true;
    }
    if (get_post_meta($season_id, 'mphb_repeat_type', true) === '') {
        update_post_meta($season_id, 'mphb_repeat_type', 'annually');
        $changed = true;
    }
    if (get_post_meta($season_id, 'mphb_repeat_period', true) === '') {
        update_post_meta($season_id, 'mphb_repeat_period', 'year');
        $changed = true;
    }
    if (get_post_meta($season_id, 'mphb_repeat_until_date', true) === '') {
        update_post_meta($season_id, 'mphb_repeat_until_date', '');
        $changed = true;
    }
    if (get_post_meta($season_id, 'mphb_days', true) === '') {
        // MotoPress expects a serialized array of weekday indexes (0-6) for "All days".
        update_post_meta($season_id, 'mphb_days', 'a:7:{i:0;s:1:"0";i:1;s:1:"1";i:2;s:1:"2";i:3;s:1:"3";i:4;s:1:"4";i:5;s:1:"5";i:6;s:1:"6";}');
        $changed = true;
    }

    if ($changed) {
        $log[] = 'Season price: ensured "All Year" season meta (start/end + repeat).';
    }
}

function hostfully_mphb_find_default_season_id(array &$log): int
{
    $seasons = get_posts([
        'post_type'      => 'mphb_season',
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);

    if (empty($seasons)) {
        $season_id = wp_insert_post([
            'post_type'   => 'mphb_season',
            'post_status' => 'publish',
            'post_title'  => 'All Year',
            'post_name'   => 'all-year',
        ]);

        if (is_wp_error($season_id) || !$season_id) {
            $log[] = 'Season price: no seasons found and failed to auto-create "All Year".';
            return 0;
        }

        hostfully_mphb_ensure_all_year_season_meta((int)$season_id, $log);
        $log[] = 'Season price: auto-created season "All Year" (ID ' . (int)$season_id . ').';
        return (int)$season_id;
    }

    $preferred = null;
    foreach ($seasons as $season) {
        $title = strtolower((string)$season->post_title);
        $slug = strtolower((string)$season->post_name);
        if (strpos($title, 'all year') !== false || strpos($title, 'all-year') !== false || strpos($title, 'all season') !== false) {
            $preferred = $season;
            break;
        }
        if (strpos($slug, 'all-year') !== false || strpos($slug, 'all-season') !== false) {
            $preferred = $season;
            break;
        }
    }

    $chosen = $preferred ?: $seasons[0];
    hostfully_mphb_ensure_all_year_season_meta((int)$chosen->ID, $log);
    $log[] = 'Season price: using season "' . $chosen->post_title . '" (ID ' . (int)$chosen->ID . ').';
    return (int)$chosen->ID;
}

function hostfully_mphb_build_season_price_payload(float $daily_rate, int $adults, int $children): array
{
    return [
        'periods' => [1],
        'prices' => [(float)$daily_rate],
        'base_adults' => $adults,
        'base_children' => $children,
        'extra_adult_prices' => [''],
        'extra_child_prices' => [''],
        'enable_variations' => false,
        'variations' => [],
    ];
}

function hostfully_mphb_upsert_rate_season_price(int $rate_id, int $season_id, float $daily_rate, int $adults, int $children, array &$log): void
{
    if (!$rate_id || !$season_id) return;

    $existing = get_post_meta($rate_id, 'mphb_season_prices', true);
    $season_prices = [];

    if (is_string($existing) && $existing !== '') {
        $maybe = @unserialize($existing);
        if (is_array($maybe)) {
            $season_prices = $maybe;
        }
    } elseif (is_array($existing)) {
        $season_prices = $existing;
    }

    $payload = hostfully_mphb_build_season_price_payload($daily_rate, $adults, $children);
    $season_key = (string)$season_id;
    $updated = false;

    if (!empty($season_prices)) {
        foreach ($season_prices as $idx => $row) {
            if (!is_array($row)) continue;
            $row_season = (string)($row['season'] ?? '');
            if ($row_season === $season_key) {
                $season_prices[$idx]['price'] = $payload;
                $updated = true;
                break;
            }
        }
    }

    if (!$updated) {
        $season_prices[] = [
            'season' => $season_key,
            'price'  => $payload,
        ];
    }

    update_post_meta($rate_id, 'mphb_season_prices', $season_prices);
    $log[] = 'Season price set for season ID ' . $season_id . ' (base price ' . $daily_rate . ').';
}

function hostfully_mphb_upsert_rate(int $room_type_id, array $property, float $daily_rate, int $adults, int $children, array &$log): int
{
    $property_uid = (string)($property['uid'] ?? '');
    if (!$property_uid) return 0;

    $existing_rate_id = hostfully_mphb_find_existing_rate_id($property_uid);

    $rate_title = 'Standard – ' . ($property['name'] ?? 'Imported Property');

    $rate_args = [
        'post_type'   => 'mphb_rate',
        'post_status' => 'publish',
        'post_title'  => $rate_title,
    ];
    if ($existing_rate_id) $rate_args['ID'] = $existing_rate_id;

    $rate_id = wp_insert_post($rate_args);

    if (is_wp_error($rate_id) || !$rate_id) {
        $log[] = 'Rate create/update failed.';
        return 0;
    }

    $rate_id = (int)$rate_id;
    $log[] = ($existing_rate_id ? 'Updated rate OK: ' : 'Created rate OK: ') . $rate_id;

    // Mark rate for update-safe reimports
    update_post_meta($rate_id, '_hostfully_property_uid', $property_uid);

    // Base price (best-effort)
    update_post_meta($rate_id, 'mphb_price', (string)(int)round($daily_rate));

    // Best-effort currency (MotoPress may ignore this depending on configuration)
    $currency = $property['pricing']['currency'] ?? 'ZAR';
    update_post_meta($rate_id, 'mphb_currency', sanitize_text_field($currency));

    // Link rate to room type (set a few common keys)
    update_post_meta($rate_id, 'mphb_room_type_id', (int)$room_type_id);
    update_post_meta($rate_id, 'mphb_room_type', (int)$room_type_id);
    update_post_meta($rate_id, '_mphb_room_type_id', (int)$room_type_id);

    // Ensure a season price row exists so the UI shows the base price.
    $season_id = hostfully_mphb_find_default_season_id($log);
    if ($season_id) {
        hostfully_mphb_upsert_rate_season_price($rate_id, $season_id, $daily_rate, $adults, $children, $log);
    }

    return $rate_id;
}

function hostfully_mphb_upsert_room_unit(int $room_type_id, array $property, array &$log): int
{
    $property_uid = (string)($property['uid'] ?? '');
    if (!$property_uid) return 0;

    $existing_room_id = hostfully_mphb_find_existing_room_id($property_uid);

    $room_title = 'Unit 1 – ' . ($property['name'] ?? 'Imported Property');

    $room_args = [
        'post_type'   => 'mphb_room',
        'post_status' => 'publish',
        'post_title'  => $room_title,
    ];
    if ($existing_room_id) $room_args['ID'] = $existing_room_id;

    $room_id = wp_insert_post($room_args);

    if (is_wp_error($room_id) || !$room_id) {
        $log[] = 'Accommodation unit create/update failed.';
        return 0;
    }

    $room_id = (int)$room_id;
    $log[] = ($existing_room_id ? 'Updated accommodation unit OK: ' : 'Created accommodation unit OK: ') . $room_id;

    // Mark unit for update-safe reimports
    update_post_meta($room_id, '_hostfully_property_uid', $property_uid);

    // Link unit to room type (set a few common keys)
    update_post_meta($room_id, 'mphb_room_type_id', (int)$room_type_id);
    update_post_meta($room_id, 'mphb_room_type', (int)$room_type_id);
    update_post_meta($room_id, '_mphb_room_type_id', (int)$room_type_id);

    return $room_id;
}

/**
 * =========
 * MEDIA HELPERS
 * =========
 */
function hostfully_mphb_ensure_media_includes(): void
{
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
}

function hostfully_mphb_sideload_image(string $url, int $parent_post_id, string $forced_filename, array &$log)
{
    hostfully_mphb_ensure_media_includes();

    $tmp = download_url($url, 20);
    if (is_wp_error($tmp)) {
        $log[] = 'Download failed: ' . $tmp->get_error_message();
        return $tmp;
    }

    $file_array = [
        'name'     => $forced_filename,
        'tmp_name' => $tmp,
    ];

    $attach_id = media_handle_sideload($file_array, $parent_post_id);

    if (is_wp_error($attach_id)) {
        $log[] = 'Sideload failed: ' . $attach_id->get_error_message();
        @unlink($tmp);
        return $attach_id;
    }

    return (int)$attach_id;
}

/**
 * =================
 * TAXONOMY IMPORT (Amenities / Tags / etc.)
 * =================
 * For now we only handle Amenities → MotoPress "Accommodation → Amenities" taxonomy.
 * MotoPress taxonomy slug for amenities is typically: mphb_room_type_facility. 
 */
function hostfully_mphb_upsert_terms(string $taxonomy, array $names, array &$log): array
{
    $term_ids = [];

    foreach ($names as $name) {
        $name = trim((string) $name);
        if ($name === '') continue;

        $existing = term_exists($name, $taxonomy);
        if (is_array($existing) && !empty($existing['term_id'])) {
            $term_ids[] = (int) $existing['term_id'];
            continue;
        }
        if (is_int($existing)) {
            $term_ids[] = $existing;
            continue;
        }

        $created = wp_insert_term($name, $taxonomy);
        if (is_wp_error($created)) {
            $log[] = "Amenity term create failed ({$taxonomy}): {$name} — " . $created->get_error_message();
            continue;
        }

        $term_ids[] = (int)($created['term_id'] ?? 0);
    }

    return array_values(array_unique(array_filter(array_map('intval', $term_ids))));
}


/**
 * =======================
 * HOSTFULLY ⇄ WP MAPPINGS
 * =======================
 * Keep stable ID mappings so we don’t depend on names (humans rename stuff).
 */
function hostfully_mphb_get_amenity_map(): array
{
    $map = get_option(HOSTFULLY_MPHB_OPT_MAP_AMENITIES, []);
    return is_array($map) ? $map : [];
}

function hostfully_mphb_set_amenity_map(array $map): void
{
    update_option(HOSTFULLY_MPHB_OPT_MAP_AMENITIES, $map, false);
}


/**
 * Convert Hostfully amenity code like HAS_AIR_CONDITIONING to a human label.
 */
function hostfully_mphb_prettify_amenity_code(string $code): string {
    $code = trim($code);
    if ($code === '') return '';
    $code = preg_replace('/^(HAS|IS|WITH)_/i', '', $code);
    $code = str_replace('_', ' ', $code);
    $code = strtolower($code);
    // Special-case common abbreviations
    $code = preg_replace('/\btv\b/i', 'TV', $code);
    $code = preg_replace('/\bwi fi\b/i', 'WiFi', $code);
    $code = preg_replace('/\bwifi\b/i', 'WiFi', $code);
    // Title case
    $code = ucwords($code);
    // restore some uppercase words
    $code = preg_replace('/\bTv\b/', 'TV', $code);
    return $code;
}

function hostfully_mphb_upsert_amenity_term(string $amenity_uid, string $name, array &$log): int
{
    $taxonomy = 'mphb_room_type_facility';
    if (!taxonomy_exists($taxonomy)) return 0;

    $amenity_uid = trim($amenity_uid);
    $name = trim($name);
    if ($name === '') return 0;

    $map = hostfully_mphb_get_amenity_map();
    if ($amenity_uid !== '' && !empty($map[$amenity_uid])) {
        $term_id = (int)$map[$amenity_uid];
        if ($term_id && get_term($term_id, $taxonomy)) return $term_id;
    }

    // Try to find by meta first (in case map got nuked)
    if ($amenity_uid !== '') {
        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'meta_query' => [[
                'key'   => '_hostfully_amenity_uid',
                'value' => $amenity_uid,
            ]],
            'fields' => 'ids',
            'number' => 1,
        ]);
        if (!is_wp_error($terms) && !empty($terms)) {
            $term_id = (int)$terms[0];
            $map[$amenity_uid] = $term_id;
            hostfully_mphb_set_amenity_map($map);
            return $term_id;
        }
    }

    // Fallback: try by slug/name
    $existing = term_exists($name, $taxonomy);
    if (is_array($existing) && !empty($existing['term_id'])) {
        $term_id = (int)$existing['term_id'];
    } else {
        $created = wp_insert_term($name, $taxonomy);
        if (is_wp_error($created)) {
            $log[] = "Amenity term create failed ({$taxonomy}): {$name} — " . $created->get_error_message();
            return 0;
        }
        $term_id = (int)($created['term_id'] ?? 0);
    }

    if ($term_id && $amenity_uid !== '') {
        update_term_meta($term_id, '_hostfully_amenity_uid', $amenity_uid);
        $map[$amenity_uid] = $term_id;
        hostfully_mphb_set_amenity_map($map);
    }

    return $term_id;
}

/**
 * Sync the global Hostfully amenities catalog into MotoPress amenities taxonomy.
 * Uses Hostfully `/amenities` list endpoint (cursor paginated in v3). 
 */
function hostfully_mphb_sync_amenities_catalog(array &$log): array
{
    $cfg = hostfully_mphb_settings();

    $taxonomy = 'mphb_room_type_facility';
    if (!taxonomy_exists($taxonomy)) {
        $log[] = "Amenity taxonomy not found ({$taxonomy}). Is MotoPress Hotel Booking active?";
        return ['created' => 0, 'updated' => 0, 'total' => 0];
    }

    $base = rtrim($cfg['base_url'], '/') . '/amenities';

    $limit  = min(100, max(1, (int)($cfg['api_page_limit'] ?? 100)));
    $cursor = '';
    $total  = 0;
    $created = 0;
    $updated = 0;

    for ($i = 0; $i < 500; $i++) {
        $url = add_query_arg(['_limit' => $limit], $base);
        if ($cursor !== '') $url = add_query_arg(['_cursor' => $cursor], $url);

        $headers = [];
        $status  = 0;
        $raw     = '';
        $data = hostfully_mphb_api_get_json($url, $headers, $status, $raw);

        if (is_wp_error($data)) {
            $msg = $data->get_error_message();
            $raw_msg = strtolower($msg . ' ' . $raw);
            if (
                strpos($raw_msg, 'hotel_or_property_uid_required') !== false ||
                (strpos($raw_msg, 'propertyuid') !== false && strpos($raw_msg, 'hoteluid') !== false && strpos($raw_msg, 'required') !== false)
            ) {
                $log[] = 'Amenity catalog endpoint requires propertyUid or hotelUid.';
                return ['created' => 0, 'updated' => 0, 'total' => 0, 'error' => 'requires_property_or_hotel'];
            }

            $log[] = 'Amenity catalog sync failed: ' . $msg;
            $log[] = 'Amenity catalog sync URL: ' . $url . ' (HTTP ' . $status . ')';
            if ($raw !== '') {
                $log[] = 'Amenity catalog sync body: ' . mb_substr($raw, 0, 400);
            }
            return ['created' => 0, 'updated' => 0, 'total' => 0, 'error' => 'request_failed'];
        }

        // Hostfully endpoints sometimes return a wrapped object (e.g. { amenities: [...] })
        // and sometimes return a plain list. Support both.
        $page = $data['amenities'] ?? $data['items'] ?? null;
        if ($page === null && array_is_list($data)) {
            $page = $data;
        }
        if (!is_array($page)) $page = [];
        if (!is_array($page) || empty($page)) break;

        foreach ($page as $a) {
            $uid  = (string)($a['uid'] ?? '');
            $name = (string)($a['name'] ?? $a['label'] ?? '');
            if ($name === '') continue;

            $before_map = hostfully_mphb_get_amenity_map();
            $before_term = ($uid && !empty($before_map[$uid])) ? (int)$before_map[$uid] : 0;

            $term_id = hostfully_mphb_upsert_amenity_term($uid, $name, $log);
            if ($term_id) {
                $total++;
                if ($before_term && $before_term === $term_id) $updated++;
                else $created++;
            }
        }

        $next = hostfully_mphb_extract_next_cursor($data, $headers);
        if ($next === '' || $next === $cursor) break;
        $cursor = $next;
    }

    $log[] = "Amenity catalog synced. Total processed: {$total} (created/linked: {$created}, updated/linked: {$updated}).";
    return ['created' => $created, 'updated' => $updated, 'total' => $total];
}

/**
 * Fallback catalog sync by iterating properties and calling `/available-amenities?propertyUid=...`.
 * This avoids ambiguity where `/amenities` may require propertyUid/hotelUid in some environments.
 */
function hostfully_mphb_sync_amenities_catalog_from_properties(array &$log): array
{
    $cfg = hostfully_mphb_settings();

    $taxonomy = 'mphb_room_type_facility';
    if (!taxonomy_exists($taxonomy)) {
        $log[] = "Amenity taxonomy not found ({$taxonomy}). Is MotoPress Hotel Booking active?";
        return ['created' => 0, 'updated' => 0, 'total' => 0];
    }

    $properties = hostfully_mphb_get_properties();
    if (empty($properties)) {
        $log[] = 'No properties found to derive amenities from.';
        return ['created' => 0, 'updated' => 0, 'total' => 0];
    }

    $limitCalls = 1000; // hard safety
    $calls = 0;

    $unique = []; // uid|name key
    $created = 0;
    $updated = 0;
    $total   = 0;

    foreach ($properties as $p) {
        $property_uid = (string)($p['uid'] ?? '');
        if ($property_uid === '') continue;

        $cache_hours = (int)($cfg['amenities_cache_hours'] ?? 24);
        $cache_key = 'hostfully_mphb_avail_amen_' . md5($property_uid);
        $cached = get_transient($cache_key);

        $items = null;

        if (is_array($cached)) {
            $items = $cached;
        } else {
            // Be kind to the API: throttle + cache
            if ($calls >= $limitCalls) break;
            $calls++;

            // Be kind to the API: throttle + cache
if ($calls >= $limitCalls) break;
$calls++;

$debugThis = ($calls <= 3); // log verbose details for first few calls

// 1) Try Available Amenities (documented as property-scoped)
$url1 = rtrim($cfg['base_url'], '/') . '/available-amenities';
$url1 = add_query_arg(['propertyUid' => $property_uid], $url1);

$headers = [];
$status  = 0;
$raw     = '';
$data = hostfully_mphb_api_get_json($url1, $headers, $status, $raw);

if ($debugThis) {
    $log[] = "Available amenities URL: {$url1} (HTTP {$status})";
    if ($raw !== '') $log[] = 'Available amenities body: ' . mb_substr($raw, 0, 400);
}

if (is_wp_error($data)) {
    $log[] = "Available amenities failed for property {$property_uid}: " . $data->get_error_message();
    $items = [];
} else {
    // Support multiple shapes
    $items = $data['amenities'] ?? $data['availableAmenities'] ?? $data['items'] ?? null;
    if ($items === null && array_is_list($data)) $items = $data;
    if (!is_array($items)) $items = [];
}

// 2) If empty, try /amenities filtered by propertyUid (some accounts/APIs require this)
if (empty($items)) {
    $url2 = rtrim($cfg['base_url'], '/') . '/amenities';
    $url2 = add_query_arg(['propertyUid' => $property_uid, '_limit' => min(100, max(1, (int)($cfg['api_page_limit'] ?? 100)))], $url2);

    $headers2 = [];
    $status2  = 0;
    $raw2     = '';
    $data2 = hostfully_mphb_api_get_json($url2, $headers2, $status2, $raw2);

    if ($debugThis) {
        $log[] = "Amenities (filtered) URL: {$url2} (HTTP {$status2})";
        if ($raw2 !== '') $log[] = 'Amenities (filtered) body: ' . mb_substr($raw2, 0, 400);
    }

    if (!is_wp_error($data2)) {
        $items2 = $data2['amenities'] ?? $data2['items'] ?? null;
        if ($items2 === null && array_is_list($data2)) $items2 = $data2;
        if (is_array($items2) && !empty($items2)) $items = $items2;
    } elseif ($debugThis) {
        $log[] = "Amenities (filtered) failed for property {$property_uid}: " . $data2->get_error_message();
    }
}

// 3) If still empty, try Custom Amenities (if the account uses custom amenity objects)
if (empty($items)) {
    $url3 = rtrim($cfg['base_url'], '/') . '/custom-amenities';
    // Docs say "given object UID and type"; common naming is objectUid/objectType.
    $url3 = add_query_arg(['objectUid' => $property_uid, 'objectType' => 'PROPERTY'], $url3);

    $headers3 = [];
    $status3  = 0;
    $raw3     = '';
    $data3 = hostfully_mphb_api_get_json($url3, $headers3, $status3, $raw3);

    if ($debugThis) {
        $log[] = "Custom amenities URL: {$url3} (HTTP {$status3})";
        if ($raw3 !== '') $log[] = 'Custom amenities body: ' . mb_substr($raw3, 0, 400);
    }

    if (!is_wp_error($data3)) {
        $items3 = $data3['customAmenities'] ?? $data3['amenities'] ?? $data3['items'] ?? null;
        if ($items3 === null && array_is_list($data3)) $items3 = $data3;
        if (is_array($items3) && !empty($items3)) $items = $items3;
    } elseif ($debugThis) {
        $log[] = "Custom amenities failed for property {$property_uid}: " . $data3->get_error_message();
    }
}

// Cache whatever we found (even if empty) to avoid repeating calls during testing.
set_transient($cache_key, is_array($items) ? $items : [], HOUR_IN_SECONDS * max(1, $cache_hours));

// small throttle to avoid hammering admin-ajax + API
usleep(150000); // 150ms
        }

        if (!is_array($items) || empty($items)) continue;

        
foreach ($items as $a) {
    // Hostfully returns different shapes depending on endpoint:
    // - /amenities, /custom-amenities: { uid, name }
    // - /available-amenities: { amenity: "HAS_TV", category: "...", channels: {...} }
    $code = (string)($a['amenity'] ?? $a['code'] ?? '');
    $uid  = (string)($a['uid'] ?? ($code !== '' ? $code : ''));
    $name = (string)($a['name'] ?? $a['label'] ?? '');

    if ($name === '' && $code !== '') {
        $name = hostfully_mphb_prettify_amenity_code($code);
    }
    if ($name === '') continue;

    $key = ($uid !== '' ? $uid : strtolower($name));
    $unique[$key] = ['uid' => $uid, 'name' => $name];
}

    }

    if (empty($unique)) {
        $log[] = 'No amenities discovered from properties (available-amenities returned empty).';
        $log[] = "Amenity catalog synced. Total processed: 0 (created/linked: 0, updated/linked: 0).";
        return ['created' => 0, 'updated' => 0, 'total' => 0];
    }

    foreach ($unique as $a) {
        $uid  = (string)($a['uid'] ?? '');
        $name = (string)($a['name'] ?? '');
        if ($name === '') continue;

        $before_map = hostfully_mphb_get_amenity_map();
        $before_term = ($uid && !empty($before_map[$uid])) ? (int)$before_map[$uid] : 0;

        $term_id = hostfully_mphb_upsert_amenity_term($uid, $name, $log);
        if ($term_id) {
            $total++;
            if ($before_term && $before_term === $term_id) $updated++;
            else $created++;
        }
    }

    $log[] = "Amenity catalog synced. Total processed: {$total} (created/linked: {$created}, updated/linked: {$updated}).";
    return ['created' => $created, 'updated' => $updated, 'total' => $total];
}

/**
 * Try syncing via `/amenities` first, but automatically fall back to property-derived sync if the API
 * complains about missing propertyUid/hotelUid (or any other mismatch).
 */
function hostfully_mphb_sync_amenities_catalog_safe(array &$log): array
{
    $res = hostfully_mphb_sync_amenities_catalog($log);

    if (!empty($res['error']) && $res['error'] === 'requires_property_or_hotel') {
        $log[] = 'Switching to fallback sync via available-amenities per property…';
        return hostfully_mphb_sync_amenities_catalog_from_properties($log);
    }

    return $res;
}


function hostfully_mphb_fetch_amenities_for_property(array $property, array &$log): array
{
    // Prefer data already present in the property payload (fast, no extra API calls).
    $candidates = [
        $property['amenities'] ?? null,
        $property['amenity'] ?? null,
        $property['features'] ?? null,
        $property['amenityUids'] ?? null,
        $property['amenitiesUids'] ?? null,
    ];

    $items = [];

    foreach ($candidates as $c) {
        if (!is_array($c) || empty($c)) continue;

        hostfully_mphb_log_debug($log, 'Amenities: using amenity data already present in property payload.');
        foreach ($c as $item) {
            if (is_string($item)) {
                $name = trim($item);
                if ($name !== '') $items[] = ['uid' => '', 'name' => $name];
                continue;
            }

            if (is_array($item)) {
                $uid  = (string)($item['uid'] ?? $item['amenityUid'] ?? '');
                $name = (string)($item['name'] ?? $item['label'] ?? '');
                $name = trim($name);
                if ($uid !== '' || $name !== '') $items[] = ['uid' => trim($uid), 'name' => $name];
            }
        }

        if (!empty($items)) {
            // Dedupe by uid/name combo
            $dedup = [];
            foreach ($items as $it) {
                $key = ($it['uid'] ?: 'n:'.strtolower($it['name']));
                $dedup[$key] = $it;
            }
            return array_values($dedup);
        }
    }

    // If we reach here, the property payload didn’t include amenities.
    // Optional enrichment: call Hostfully catalog endpoint filtered by propertyUid (cached).
    $cfg = hostfully_mphb_settings();
    if (empty($cfg['allow_enrich_api'])) {
        $log[] = 'Amenities: not in payload, and API enrichment disabled (settings).';
        return [];
    }

    if (empty($property['uid'])) return [];
    $property_uid = (string)$property['uid'];

    $cache_hours = (int)($cfg['amenities_cache_hours'] ?? 24);
    $cache_hours = min(168, max(1, $cache_hours));
    // Cache is per-property. Prefix includes a version tag so we can change parsing rules safely.
    $cache_key = 'hostfully_mphb_am_v2_' . md5($property_uid);

    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        $log[] = 'Amenities: loaded from cache.';
        hostfully_mphb_log_debug($log, 'Amenities: cache hit for property ' . $property_uid . '.');
        return $cached;
    }

    if (empty($cfg['agency_uid'])) return [];

    // Enrichment: property-specific amenities are NOT always returned by /available-amenities (that can behave like a catalog).
    // For this agency, /amenities requires propertyUid/hotelUid and returns the actual amenities set for the property.
    // We try endpoints in this order:
    // 1) /amenities?propertyUid=... (preferred: actual amenities)
    // 2) /custom-amenities?objectUid=...&objectType=PROPERTY (some accounts store extras here)
    // 3) /available-amenities?propertyUid=... (last resort)
    $tries = [];

    $limit = min(100, max(1, (int)($cfg['api_page_limit'] ?? 100)));

    $tries[] = [
        'label' => 'amenities',
        'url'   => add_query_arg([
            'propertyUid' => $property_uid,
            '_limit'      => $limit,
        ], rtrim($cfg['base_url'], '/') . '/amenities'),
    ];

    $tries[] = [
        'label' => 'custom-amenities',
        'url'   => add_query_arg([
            'objectUid'  => $property_uid,
            'objectType' => 'PROPERTY',
            '_limit'     => $limit,
        ], rtrim($cfg['base_url'], '/') . '/custom-amenities'),
    ];

    $tries[] = [
        'label' => 'available-amenities',
        'url'   => add_query_arg([
            'propertyUid' => $property_uid,
            '_limit'      => $limit,
        ], rtrim($cfg['base_url'], '/') . '/available-amenities'),
    ];

    $headers = [];
    $status  = 0;
    $raw     = '';
    $data    = null;
    $url     = '';
    $used    = '';

    foreach ($tries as $t) {
        $headers = [];
        $status  = 0;
        $raw     = '';
        $url     = $t['url'];

        $tmp = hostfully_mphb_api_get_json($url, $headers, $status, $raw);

        if (is_wp_error($tmp)) {
            $log[] = 'Amenities enrichment (' . $t['label'] . ') request failed: ' . $tmp->get_error_message();
            $log[] = 'Amenities enrichment URL: ' . $url . ' (HTTP ' . $status . ')';
            if ($raw !== '') $log[] = 'Amenities enrichment body: ' . mb_substr($raw, 0, 400);
            continue;
        }

        $maybe = $tmp['amenities'] ?? $tmp['items'] ?? null;
        if ($maybe === null && is_array($tmp) && array_is_list($tmp)) $maybe = $tmp;

        if (is_array($maybe) && !empty($maybe)) {
            $data = $tmp;
            $used = $t['label'];
            hostfully_mphb_log_debug($log, 'Amenities: fetched via /' . $used . ' (HTTP ' . $status . ').');
            break;
        }
    }

    if ($data === null) {
        $log[] = 'Amenities enrichment: none returned from amenities/custom-amenities/available-amenities.';
        return [];
    }

    $log[] = 'Amenities: enriched via API (' . $used . ') and cached.';

    $list = $data['amenities'] ?? $data['items'] ?? null;
    if ($list === null && array_is_list($data)) {
        $list = $data;
    }
    if (!is_array($list)) $list = [];
    if (!is_array($list) || empty($list)) {
        $log[] = 'Amenities enrichment: none returned.';
        $log[] = 'Amenities enrichment URL: ' . $url . ' (HTTP ' . $status . ')';
        if ($raw !== '') {
            $log[] = 'Amenities enrichment body: ' . mb_substr($raw, 0, 400);
        }
        return [];
    }

    $items = [];
    foreach ($list as $a) {
        if (is_string($a)) {
            $name = trim($a);
            if ($name !== '') $items[] = ['uid' => '', 'name' => $name];
            continue;
        }
        if (!is_array($a)) continue;

        // Hostfully /available-amenities shape (v3.2): { amenity: "HAS_TV", category: "...", channels: {...} }
        // IMPORTANT: In some accounts, /available-amenities behaves like a *catalog* of possible amenities.
        // Empirically, the amenities that are actually enabled for the property tend to have at least one
        // channel flag set to true. We filter on that when the source endpoint is "available-amenities".
        if (!empty($a['amenity']) && is_string($a['amenity'])) {
            $code = trim((string)$a['amenity']);

            if ($code === '') {
                continue;
            }

            if ($used === 'available-amenities') {
                $channels = $a['channels'] ?? null;
                $has_true = false;
                if (is_array($channels)) {
                    foreach ($channels as $v) {
                        if ($v === true || $v === 1 || $v === 'true' || $v === '1') {
                            $has_true = true;
                            break;
                        }
                    }
                }

                // Skip catalog-only entries with no channel flag indicating it's actually selected/used.
                if (!$has_true) {
                    continue;
                }
            }

            $items[] = ['uid' => $code, 'name' => hostfully_mphb_prettify_amenity_code($code)];
            continue;
        }

        $uid  = (string)($a['uid'] ?? '');
        $name = (string)($a['name'] ?? $a['label'] ?? '');
        $name = trim($name);
        if ($uid !== '' || $name !== '') $items[] = ['uid' => trim($uid), 'name' => $name];
    }    // Dedupe + cache
    $dedup = [];
    foreach ($items as $it) {
        $key = ($it['uid'] ?: 'n:'.strtolower($it['name']));
        $dedup[$key] = $it;
    }
    $items = array_values($dedup);

    set_transient($cache_key, $items, HOUR_IN_SECONDS * $cache_hours);

    return $items;
}

function hostfully_mphb_import_amenities(int $room_type_id, array $property, array &$log): void
{
    $taxonomy = 'mphb_room_type_facility';

    if (!taxonomy_exists($taxonomy)) {
        $log[] = "Amenity taxonomy not found ({$taxonomy}). Is MotoPress Hotel Booking active?";
        return;
    }

    $items = hostfully_mphb_fetch_amenities_for_property($property, $log);

    if (empty($items)) {
        $log[] = 'Amenities: none found.';
        return;
    }

    $term_ids = [];

    foreach ($items as $it) {
        if (!is_array($it)) continue;

        $uid  = trim((string)($it['uid'] ?? ''));
        $name = trim((string)($it['name'] ?? ''));

        if ($uid !== '') {
            $term_id = hostfully_mphb_upsert_amenity_term($uid, $name ?: $uid, $log);
            if ($term_id) $term_ids[] = $term_id;
            continue;
        }

        if ($name !== '') {
            // Name-only (no stable ID in payload). Create by name.
            $ids = hostfully_mphb_upsert_terms($taxonomy, [$name], $log);
            if (!empty($ids)) $term_ids = array_merge($term_ids, $ids);
        }
    }

    $term_ids = array_values(array_unique(array_filter(array_map('intval', $term_ids))));
    if (empty($term_ids)) {
        $log[] = 'Amenities: terms not created (check errors above).';
        return;
    }

    wp_set_object_terms($room_type_id, $term_ids, $taxonomy, false);

    $names = array_values(array_unique(array_filter(array_map(function ($it) {
        if (is_array($it) && !empty($it['name'])) return (string)$it['name'];
        return '';
    }, $items))));

    $log[] = 'Amenities assigned (' . count($term_ids) . '): ' . implode(', ', $names);
}

/**
 * =================
 * CORE IMPORT LOGIC
 * =================
 * Imports ONE property UID. Safe to run repeatedly (updates existing).
 */

function hostfully_mphb_import_categories_tags(int $room_type_id, array $property, array &$log): void
{
    // MotoPress usually registers these taxonomies for room types.
    $tax_category = 'mphb_room_type_category';
    $tax_tag      = 'mphb_room_type_tag';

    $category_terms = [];
    $tag_terms      = [];

    // Best-effort mapping from Hostfully property payload
    if (!empty($property['propertyType'])) $category_terms[] = (string)$property['propertyType'];
    if (!empty($property['roomType']))     $tag_terms[]      = (string)$property['roomType'];
    if (!empty($property['listingType']))  $tag_terms[]      = (string)$property['listingType'];

    $addr = $property['address'] ?? [];
    if (is_array($addr)) {
        if (!empty($addr['city']))  $tag_terms[] = (string)$addr['city'];
        if (!empty($addr['state'])) $tag_terms[] = (string)$addr['state'];
    }

    $category_terms = array_values(array_unique(array_filter(array_map('trim', $category_terms))));
    $tag_terms      = array_values(array_unique(array_filter(array_map('trim', $tag_terms))));

    if (taxonomy_exists($tax_category) && !empty($category_terms)) {
        $ids = hostfully_mphb_upsert_terms($tax_category, $category_terms, $log);
        if (!empty($ids)) {
            wp_set_object_terms($room_type_id, $ids, $tax_category, false);
            $log[] = 'Categories assigned: ' . implode(', ', $category_terms);
        }
    } else {
        if (!taxonomy_exists($tax_category)) $log[] = "Category taxonomy not found ({$tax_category}). Skipping.";
    }

    if (taxonomy_exists($tax_tag) && !empty($tag_terms)) {
        $ids = hostfully_mphb_upsert_terms($tax_tag, $tag_terms, $log);
        if (!empty($ids)) {
            wp_set_object_terms($room_type_id, $ids, $tax_tag, false);
            $log[] = 'Tags assigned: ' . implode(', ', $tag_terms);
        }
    } else {
        if (!taxonomy_exists($tax_tag)) $log[] = "Tag taxonomy not found ({$tax_tag}). Skipping.";
    }
}

/**
 * =================
 * ATTRIBUTE IMPORT (Room Attributes)
 * =================
 * MotoPress stores attributes as custom taxonomies prefixed with mphb_ra_.
 * Each taxonomy represents a single attribute (e.g. Bedrooms) and terms are the values.
 */
function hostfully_mphb_get_attribute_registry(): array
{
    $reg = get_option(HOSTFULLY_MPHB_OPT_ATTR_DEFS, []);
    return is_array($reg) ? $reg : [];
}

function hostfully_mphb_set_attribute_registry(array $reg): void
{
    update_option(HOSTFULLY_MPHB_OPT_ATTR_DEFS, $reg, false);
}

function hostfully_mphb_taxonomy_args_from_obj($obj): array
{
    if (!is_object($obj)) return [];

    $cap = $obj->cap ?? null;
    if (is_object($cap)) $cap = (array)$cap;
    if (!is_array($cap)) $cap = null;

    $rewrite = $obj->rewrite ?? null;
    if (is_object($rewrite)) $rewrite = (array)$rewrite;
    if (!is_array($rewrite) && !is_bool($rewrite) && $rewrite !== null) $rewrite = null;

    return [
        'public'              => $obj->public,
        'publicly_queryable'  => $obj->publicly_queryable,
        'show_ui'             => $obj->show_ui,
        'show_admin_column'   => $obj->show_admin_column,
        'show_in_nav_menus'   => $obj->show_in_nav_menus,
        'show_tagcloud'       => $obj->show_tagcloud,
        'hierarchical'        => $obj->hierarchical,
        'rewrite'             => $rewrite,
        'query_var'           => $obj->query_var,
        'show_in_rest'        => $obj->show_in_rest,
        'rest_base'           => $obj->rest_base,
        'rest_controller_class' => $obj->rest_controller_class,
        'capabilities'        => $cap,
        'meta_box_cb'         => $obj->meta_box_cb,
        'update_count_callback' => $obj->update_count_callback,
    ];
}

function hostfully_mphb_get_attribute_taxonomy_template_args(): array
{
    $taxes = get_object_taxonomies('mphb_room_type', 'objects');
    if (is_array($taxes)) {
        foreach ($taxes as $slug => $obj) {
            if (strpos($slug, 'mphb_ra_') === 0) {
                $args = hostfully_mphb_taxonomy_args_from_obj($obj);
                return array_filter($args, function ($v) {
                    return $v !== null;
                });
            }
        }
    }

    return [
        'public'            => false,
        'publicly_queryable'=> false,
        'show_ui'           => true,
        'show_admin_column' => false,
        'show_in_nav_menus' => false,
        'show_tagcloud'     => false,
        'hierarchical'      => false,
        'rewrite'           => false,
        'query_var'         => false,
        'show_in_rest'      => false,
    ];
}

function hostfully_mphb_register_attribute_taxonomy(string $slug, string $label, array $args = []): bool
{
    if (taxonomy_exists($slug)) return true;

    if (isset($args['capabilities']) && is_object($args['capabilities'])) {
        $args['capabilities'] = (array)$args['capabilities'];
    }
    if (isset($args['rewrite']) && is_object($args['rewrite'])) {
        $args['rewrite'] = (array)$args['rewrite'];
    }

    $base = hostfully_mphb_get_attribute_taxonomy_template_args();
    $labels = [
        'name'          => $label,
        'singular_name' => $label,
        'menu_name'     => $label,
    ];

    $final = array_merge($base, $args, [
        'labels' => $labels,
        'label'  => $label,
    ]);

    register_taxonomy($slug, ['mphb_room_type'], $final);

    return taxonomy_exists($slug);
}

function hostfully_mphb_attribute_post_slug_from_tax(string $tax_slug, string $label = ''): string
{
    $slug = $tax_slug;
    if (strpos($slug, 'mphb_ra_') === 0) {
        $slug = substr($slug, 8);
    }
    $slug = sanitize_title($slug);
    if ($slug === '' && $label !== '') {
        $slug = sanitize_title($label);
    }
    return $slug;
}

function hostfully_mphb_ensure_attribute_post(string $tax_slug, string $label, array &$log): int
{
    if (!post_type_exists('mphb_room_attribute')) return 0;

    $post_slug = hostfully_mphb_attribute_post_slug_from_tax($tax_slug, $label);
    if ($post_slug === '') return 0;

    $existing = get_posts([
        'post_type'      => 'mphb_room_attribute',
        'name'           => $post_slug,
        'posts_per_page' => 1,
        'post_status'    => 'any',
        'fields'         => 'ids',
    ]);

    if (!empty($existing)) return (int)$existing[0];

    $post_id = wp_insert_post([
        'post_type'   => 'mphb_room_attribute',
        'post_status' => 'publish',
        'post_title'  => $label ?: $post_slug,
        'post_name'   => $post_slug,
    ]);

    if (is_wp_error($post_id) || !$post_id) {
        $log[] = 'Attributes: failed to create attribute post for ' . $tax_slug . '.';
        return 0;
    }

    $log[] = 'Attributes: created attribute post ' . (int)$post_id . ' (' . ($label ?: $post_slug) . ').';
    return (int)$post_id;
}

function hostfully_mphb_cleanup_legacy_bedroom_term(array &$log): void
{
    $taxonomy = 'mphb_ra_bedroom';
    if (!taxonomy_exists($taxonomy)) return;

    if (get_option(HOSTFULLY_MPHB_OPT_LEGACY_BEDROOM_CLEANED)) {
        return;
    }

    $term = get_term_by('slug', 'bedroom', $taxonomy);
    if (!$term) {
        $term = get_term_by('name', 'Bedroom', $taxonomy);
    }

    if (!$term || is_wp_error($term)) return;

    $term_id = (int)$term->term_id;

    $object_ids = get_objects_in_term($term_id, $taxonomy);
    if (is_wp_error($object_ids)) {
        $log[] = 'Attributes: failed to load legacy Bedroom assignments — ' . $object_ids->get_error_message();
        return;
    }

    if (!empty($object_ids)) {
        $res = wp_remove_object_terms($object_ids, $term_id, $taxonomy);
        if (is_wp_error($res)) {
            $log[] = 'Attributes: failed to unassign legacy Bedroom term — ' . $res->get_error_message();
            return;
        }
        $log[] = 'Attributes: removed legacy Bedroom term from ' . count($object_ids) . ' room types.';
    }

    $deleted = wp_delete_term($term_id, $taxonomy);
    if (is_wp_error($deleted)) {
        $log[] = 'Attributes: failed to remove legacy Bedroom term — ' . $deleted->get_error_message();
        return;
    }

    $log[] = 'Attributes: removed legacy Bedroom term.';
    update_option(HOSTFULLY_MPHB_OPT_LEGACY_BEDROOM_CLEANED, 1, false);
}

function hostfully_mphb_register_attribute_taxonomies_from_registry(): void
{
    $reg = hostfully_mphb_get_attribute_registry();
    if (empty($reg)) return;

    foreach ($reg as $slug => $def) {
        $label = (string)($def['label'] ?? $def['name'] ?? $slug);
        if ($label === '') $label = $slug;
        hostfully_mphb_register_attribute_taxonomy($slug, $label);
    }
}

add_action('init', 'hostfully_mphb_register_attribute_taxonomies_from_registry', 20);

function hostfully_mphb_get_attribute_taxonomies(): array
{
    $taxes = get_object_taxonomies('mphb_room_type', 'objects');
    if (!is_array($taxes)) return [];

    $attrs = [];
    foreach ($taxes as $slug => $tax_obj) {
        if (strpos($slug, 'mphb_ra_') === 0) {
            $attrs[$slug] = $tax_obj;
        }
    }

    return $attrs;
}

function hostfully_mphb_extract_number($value): ?float
{
    if (is_int($value) || is_float($value)) return (float)$value;
    if (is_string($value)) {
        if (is_numeric($value)) return (float)$value;
        if (preg_match('/-?\d+(?:\.\d+)?/', $value, $m)) {
            return (float)$m[0];
        }
    }
    return null;
}

function hostfully_mphb_value_at_path(array $data, array $path)
{
    $cur = $data;
    foreach ($path as $k) {
        if (!is_array($cur) || !array_key_exists($k, $cur)) return null;
        $cur = $cur[$k];
    }
    return $cur;
}

function hostfully_mphb_find_numeric_in_paths(array $data, array $paths): ?float
{
    foreach ($paths as $path) {
        $val = hostfully_mphb_value_at_path($data, $path);
        $num = hostfully_mphb_extract_number($val);
        if ($num !== null) return $num;
    }
    return null;
}

function hostfully_mphb_sum_numeric_array($value): ?float
{
    if (!is_array($value)) return null;

    $sum = 0;
    $found = false;

    foreach ($value as $item) {
        if (is_numeric($item)) {
            $sum += (float)$item;
            $found = true;
            continue;
        }
        if (!is_array($item)) continue;

        foreach (['count', 'quantity', 'number', 'bedCount', 'beds', 'amount', 'qty'] as $k) {
            if (isset($item[$k]) && is_numeric($item[$k])) {
                $sum += (float)$item[$k];
                $found = true;
                break;
            }
        }
    }

    return $found ? $sum : null;
}

function hostfully_mphb_get_property_attribute_values(array $property): array
{
    $vals = [];

    $bedrooms = hostfully_mphb_find_numeric_in_paths($property, [
        ['bedrooms'],
        ['bedroom'],
        ['availability', 'bedrooms'],
        ['availability', 'bedroom'],
        ['availability', 'numBedrooms'],
        ['availability', 'numberOfBedrooms'],
        ['details', 'bedrooms'],
    ]);
    if ($bedrooms !== null) $vals['bedrooms'] = $bedrooms;

    $bathrooms = hostfully_mphb_find_numeric_in_paths($property, [
        ['bathrooms'],
        ['bathroom'],
        ['availability', 'bathrooms'],
        ['availability', 'bathroom'],
        ['availability', 'numBathrooms'],
        ['availability', 'numberOfBathrooms'],
        ['details', 'bathrooms'],
    ]);
    if ($bathrooms !== null) $vals['bathrooms'] = $bathrooms;

    $beds = hostfully_mphb_find_numeric_in_paths($property, [
        ['beds'],
        ['bedCount'],
        ['bedsCount'],
        ['availability', 'beds'],
        ['availability', 'bedCount'],
        ['availability', 'bedsCount'],
        ['details', 'beds'],
    ]);
    if ($beds === null && isset($property['beds'])) {
        $beds = hostfully_mphb_sum_numeric_array($property['beds']);
    }
    if ($beds !== null) $vals['beds'] = $beds;

    $max_guests = hostfully_mphb_find_numeric_in_paths($property, [
        ['availability', 'maxGuests'],
        ['availability', 'max_guests'],
        ['maxGuests'],
        ['max_guests'],
        ['details', 'maxGuests'],
    ]);
    if ($max_guests !== null) $vals['guests'] = $max_guests;

    $size_m2 = hostfully_mphb_find_numeric_in_paths($property, [
        ['size'],
        ['area'],
        ['squareMeters'],
        ['squareMeter'],
        ['square_meters'],
        ['sqm'],
        ['m2'],
        ['details', 'size'],
        ['details', 'area'],
    ]);
    $size_ft2 = hostfully_mphb_find_numeric_in_paths($property, [
        ['squareFeet'],
        ['squareFoot'],
        ['square_feet'],
        ['sqft'],
        ['sq_ft'],
        ['details', 'squareFeet'],
    ]);

    if ($size_m2 !== null) {
        $vals['size'] = ['value' => $size_m2, 'unit' => 'm2'];
    } elseif ($size_ft2 !== null) {
        $vals['size'] = ['value' => $size_ft2, 'unit' => 'sqft'];
    }

    return $vals;
}

function hostfully_mphb_attribute_definition_for_key(string $key): array
{
    switch ($key) {
        case 'bedrooms':
            return ['slug' => 'mphb_ra_bedroom', 'label' => 'Bedrooms'];
        case 'beds':
            return ['slug' => 'mphb_ra_bed', 'label' => 'Beds'];
        case 'bathrooms':
            return ['slug' => 'mphb_ra_bathroom', 'label' => 'Bathrooms'];
        case 'guests':
            return ['slug' => 'mphb_ra_guest', 'label' => 'Guests'];
        case 'size':
            return ['slug' => 'mphb_ra_size', 'label' => 'Size'];
        default:
            return [];
    }
}

function hostfully_mphb_attribute_key_for_taxonomy(string $tax_slug, $tax_obj): string
{
    $reg = hostfully_mphb_get_attribute_registry();
    if (isset($reg[$tax_slug]['key'])) {
        return (string)$reg[$tax_slug]['key'];
    }

    $label = '';
    if (is_object($tax_obj)) {
        $label = (string)($tax_obj->labels->singular_name ?? $tax_obj->label ?? $tax_obj->name ?? '');
    }

    $hay = strtolower($tax_slug . ' ' . $label);
    $hay = str_replace(['-', '_'], ' ', $hay);

    if (strpos($hay, 'bedroom') !== false) return 'bedrooms';
    if (preg_match('/\bbath(room)?s?\b/', $hay)) return 'bathrooms';
    if (preg_match('/\bbeds?\b/', $hay) && strpos($hay, 'bedroom') === false) return 'beds';
    if (preg_match('/\bguests?\b|\boccupancy\b|\bpersons?\b|\bsleeps?\b/', $hay)) return 'guests';
    if (preg_match('/\bsize\b|\barea\b|\bsqm\b|\bsq m\b|\bm2\b|\bsqft\b|\bsq ft\b|\bsquare (meters?|feet)\b/', $hay)) return 'size';

    return '';
}

function hostfully_mphb_find_taxonomy_slug_for_key(string $key, array $taxes): string
{
    $reg = hostfully_mphb_get_attribute_registry();
    foreach ($reg as $slug => $def) {
        if (!empty($def['key']) && $def['key'] === $key && taxonomy_exists($slug)) {
            return $slug;
        }
    }

    foreach ($taxes as $slug => $tax_obj) {
        $mapped = hostfully_mphb_attribute_key_for_taxonomy($slug, $tax_obj);
        if ($mapped === $key) return $slug;
    }

    return '';
}

function hostfully_mphb_ensure_attribute_taxonomy_for_key(string $key, array &$log): string
{
    $taxes = hostfully_mphb_get_attribute_taxonomies();
    $existing = hostfully_mphb_find_taxonomy_slug_for_key($key, $taxes);
    if ($existing !== '') {
        $reg = hostfully_mphb_get_attribute_registry();
        if (!isset($reg[$existing])) {
            $tax_obj = $taxes[$existing] ?? null;
            $label = is_object($tax_obj)
                ? (string)($tax_obj->labels->singular_name ?? $tax_obj->label ?? $tax_obj->name ?? $existing)
                : $existing;
            $reg[$existing] = ['label' => $label, 'key' => $key];
            hostfully_mphb_set_attribute_registry($reg);
        }
        $label = $reg[$existing]['label'] ?? $existing;
        hostfully_mphb_ensure_attribute_post($existing, (string)$label, $log);
        return $existing;
    }

    $def = hostfully_mphb_attribute_definition_for_key($key);
    if (empty($def['slug']) || empty($def['label'])) return '';

    $ok = hostfully_mphb_register_attribute_taxonomy($def['slug'], $def['label']);
    if (!$ok) {
        $log[] = 'Attributes: failed to create taxonomy ' . $def['slug'] . ' (' . $def['label'] . ').';
        return '';
    }

    $reg = hostfully_mphb_get_attribute_registry();
    $reg[$def['slug']] = ['label' => $def['label'], 'key' => $key];
    hostfully_mphb_set_attribute_registry($reg);

    $log[] = 'Attributes: created taxonomy ' . $def['slug'] . ' (' . $def['label'] . ').';
    hostfully_mphb_ensure_attribute_post($def['slug'], $def['label'], $log);
    return $def['slug'];
}

function hostfully_mphb_format_attribute_term(string $key, $value): string
{
    if ($value === null || $value === '') return '';

    if ($key === 'size') {
        if (is_array($value) && isset($value['value'])) {
            $v = hostfully_mphb_extract_number($value['value']);
            if ($v === null) return '';
            $unit = (string)($value['unit'] ?? 'm2');
            $unit = strtolower($unit) === 'sqft' ? 'sq ft' : 'm2';
            $num = (floor($v) == $v) ? (string)(int)$v : (string)$v;
            return $num . ' ' . $unit;
        }
        return '';
    }

    $num = hostfully_mphb_extract_number($value);
    if ($num === null) return '';

    $num_str = (floor($num) == $num) ? (string)(int)$num : (string)$num;

    switch ($key) {
        case 'bedrooms':
        case 'beds':
        case 'bathrooms':
        case 'guests':
            // For numeric attributes, keep the term as the value only (e.g., "2"),
            // since the taxonomy label already provides context.
            return $num_str;
        default:
            return $num_str;
    }
}

function hostfully_mphb_import_attributes(int $room_type_id, array $property, array &$log): void
{
    $vals = hostfully_mphb_get_property_attribute_values($property);
    if (!empty($vals)) {
        // Drop zero/empty numeric values (e.g., Beds: 0) to avoid junk terms.
        foreach ($vals as $k => $v) {
            if ($k === 'size' && is_array($v)) {
                $sv = hostfully_mphb_extract_number($v['value'] ?? null);
                if ($sv === null || $sv <= 0) unset($vals[$k]);
                continue;
            }
            $num = hostfully_mphb_extract_number($v);
            if ($num !== null && $num <= 0) {
                unset($vals[$k]);
            }
        }
    }

    if (empty($vals)) {
        $log[] = 'Attributes: no usable values found in Hostfully payload.';
        return;
    }

    // Ensure attribute taxonomies exist for keys we can populate.
    foreach (array_keys($vals) as $key) {
        hostfully_mphb_ensure_attribute_taxonomy_for_key($key, $log);
    }

    $taxes = hostfully_mphb_get_attribute_taxonomies();
    if (empty($taxes)) {
        $log[] = 'Attributes: no mphb_ra_* taxonomies found (even after create).';
        return;
    }

    $assigned = [];

    foreach ($taxes as $slug => $tax_obj) {
        $key = hostfully_mphb_attribute_key_for_taxonomy($slug, $tax_obj);
        if ($key === '' || !array_key_exists($key, $vals)) continue;

        $term_name = hostfully_mphb_format_attribute_term($key, $vals[$key]);
        if ($term_name === '') continue;

        $existing = term_exists($term_name, $slug);
        if (is_wp_error($existing)) {
            $log[] = "Attribute term lookup failed ({$slug}): " . $existing->get_error_message();
            continue;
        }

        if (is_array($existing) && !empty($existing['term_id'])) {
            $term_id = (int)$existing['term_id'];
        } elseif (is_int($existing)) {
            $term_id = $existing;
        } else {
            $created = wp_insert_term($term_name, $slug);
            if (is_wp_error($created)) {
                $log[] = "Attribute term create failed ({$slug}): {$term_name} — " . $created->get_error_message();
                continue;
            }
            $term_id = (int)($created['term_id'] ?? 0);
        }

        if ($term_id) {
            wp_set_object_terms($room_type_id, [$term_id], $slug, false);
            $label = is_object($tax_obj) ? (string)($tax_obj->labels->singular_name ?? $tax_obj->label ?? $slug) : $slug;
            $assigned[] = $label . ': ' . $term_name;
        }
    }

    if (empty($assigned)) {
        $log[] = 'Attributes: none assigned (no matching taxonomies/values).';
        return;
    }

    $log[] = 'Attributes assigned: ' . implode(', ', $assigned);

    // Clean up legacy manual term if it's now unused.
    hostfully_mphb_cleanup_legacy_bedroom_term($log);
}

function hostfully_mphb_upsert_service(string $key, string $title, float $price, array &$log): int
{
    $existing_id = 0;

    // Find by meta key
    $q = new WP_Query([
        'post_type'      => 'mphb_room_service',
        'posts_per_page' => 1,
        'post_status'    => 'any',
        'meta_key'       => '_hostfully_service_key',
        'meta_value'     => $key,
        'fields'         => 'ids',
    ]);

    if (!empty($q->posts)) $existing_id = (int)$q->posts[0];

    $args = [
        'post_type'   => 'mphb_room_service',
        'post_status' => 'publish',
        'post_title'  => $title,
    ];
    if ($existing_id) $args['ID'] = $existing_id;

    $service_id = wp_insert_post($args);
    if (is_wp_error($service_id) || !$service_id) {
        $log[] = "Service create/update failed for {$title}.";
        return 0;
    }

    $service_id = (int)$service_id;

    update_post_meta($service_id, '_hostfully_service_key', $key);

    // Mirror MotoPress service meta shape (from your dump)
    update_post_meta($service_id, 'mphb_price', (string)(int)round($price));
    update_post_meta($service_id, 'mphb_price_periodicity', 'once');
    update_post_meta($service_id, 'mphb_min_quantity', '1');
    update_post_meta($service_id, 'mphb_is_auto_limit', '0');
    update_post_meta($service_id, 'mphb_max_quantity', '0');
    update_post_meta($service_id, 'mphb_price_quantity', 'once');

    $log[] = ($existing_id ? 'Updated service OK: ' : 'Created service OK: ') . $service_id . " ({$title})";

    return $service_id;
}

function hostfully_mphb_assign_services_to_room_type(int $room_type_id, array $service_ids, array &$log): void
{
    $service_ids = array_values(array_unique(array_filter(array_map('intval', $service_ids))));
    if (empty($service_ids)) return;

    // Existing services may come back already unserialized (WP auto-unserializes post meta).
    // Older installs may store a serialized string. Handle both safely.
    $existing_raw = get_post_meta($room_type_id, 'mphb_services', true);

    if (is_array($existing_raw)) {
        $existing = $existing_raw;
    } else {
        $existing = maybe_unserialize($existing_raw);
    }

    if (!is_array($existing)) {
        $existing = [];
    }

    $existing = array_map('strval', $existing);

    foreach ($service_ids as $sid) {
        $existing[] = (string)$sid;
    }

    $existing = array_values(array_unique($existing));

    // Let WP handle serialization.
    update_post_meta($room_type_id, 'mphb_services', $existing);

    $log[] = 'Services assigned (mphb_services): ' . implode(', ', $existing);
}

function hostfully_mphb_import_services(int $room_type_id, array $property, array &$log): void
{
    // Hostfully doesn't explicitly call these "services" in the property payload.
    // We treat common fee fields as services so the WP side mirrors Hostfully's pricing model.
    $pricing = $property['pricing'] ?? [];
    if (!is_array($pricing)) $pricing = [];

    $service_ids = [];

    if (!empty($pricing['cleaningFee']) && (float)$pricing['cleaningFee'] > 0) {
        $service_ids[] = hostfully_mphb_upsert_service('cleaningFee', 'Cleaning Fee', (float)$pricing['cleaningFee'], $log);
    }

    if (!empty($pricing['securityDeposit']) && (float)$pricing['securityDeposit'] > 0) {
        $service_ids[] = hostfully_mphb_upsert_service('securityDeposit', 'Security Deposit', (float)$pricing['securityDeposit'], $log);
    }

    // Extra guest fee is often per-extra-guest-per-night in Hostfully.
    // MotoPress supports extra adult/child pricing inside Rates; we may migrate it there later.
    if (!empty($pricing['extraGuestFee']) && (float)$pricing['extraGuestFee'] > 0) {
        $service_ids[] = hostfully_mphb_upsert_service('extraGuestFee', 'Extra Guest Fee', (float)$pricing['extraGuestFee'], $log);
    }

    $service_ids = array_values(array_filter(array_map('intval', $service_ids)));
    if (empty($service_ids)) {
        $log[] = 'Services: none found in Hostfully pricing.';
        return;
    }

    hostfully_mphb_assign_services_to_room_type($room_type_id, $service_ids, $log);
}
function hostfully_mphb_import_property(string $property_uid, array &$log): int
{
    $cfg = hostfully_mphb_settings();

    $log[] = '---';
    $log[] = 'Importing property UID: ' . $property_uid;
    hostfully_mphb_log_debug($log, 'Import debug: started at ' . date('c'));

    $existing_post_id = hostfully_mphb_find_existing_post_id($property_uid);
    $log[] = 'Existing post ID: ' . ($existing_post_id ?: 'none');

    // 1) Fetch property detail
    $detail_url = rtrim($cfg['base_url'], '/') . '/properties/' . urlencode($property_uid) . '?agencyUid=' . urlencode($cfg['agency_uid']);
    $response = hostfully_mphb_api_get($detail_url);

    if (is_wp_error($response)) {
        $log[] = 'Detail request failed: ' . $response->get_error_message();
        return 0;
    }

    $status = wp_remote_retrieve_response_code($response);
    $body   = wp_remote_retrieve_body($response);
    $data   = json_decode($body, true);
    $property = $data['property'] ?? null;

    hostfully_mphb_log_debug($log, 'Detail HTTP: ' . (int)$status . ' (bytes: ' . strlen((string)$body) . ').');

    if ($status !== 200 || !is_array($data) || !$property || empty($property['uid'])) {
        $log[] = 'Detail invalid. HTTP: ' . $status;
        $log[] = 'Raw detail body: ' . $body;
        return 0;
    }

    // 2) Create or update room type
    $post_args = [
        'post_type'   => 'mphb_room_type',
        'post_status' => 'publish',
        'post_title'  => $property['name'] ?? 'Imported Property',
        'post_content' => !empty($property['webLink'])
            ? 'Imported from Hostfully. Source: ' . esc_url_raw($property['webLink'])
            : '',
    ];
    if ($existing_post_id) $post_args['ID'] = $existing_post_id;

    $post_id = wp_insert_post($post_args);

    if (is_wp_error($post_id) || !$post_id) {
        $log[] = 'Failed to create/update post.';
        return 0;
    }

    $log[] = ($existing_post_id ? 'Updated' : 'Created') . ' accommodation type OK: ' . (int)$post_id;

    update_post_meta($post_id, '_hostfully_property_uid', $property['uid']);

    // 3) Core meta
    $max_guests  = $property['availability']['maxGuests'] ?? null;
    $base_guests = $property['availability']['baseGuests'] ?? null;
    $adults      = (int)($max_guests ?? $base_guests ?? 2);
    $children    = 0;

    $daily_rate  = $property['pricing']['dailyRate'] ?? 0;

    update_post_meta($post_id, 'mphb_adults', $adults);
    update_post_meta($post_id, 'mphb_children', $children);
    update_post_meta($post_id, 'mphb_price', (string)(int)round((float)$daily_rate));

    $min_stay = $property['availability']['minimumStay'] ?? null;
    $max_stay = $property['availability']['maximumStay'] ?? null;

    if ($min_stay !== null) update_post_meta($post_id, 'mphb_min_stay', (int)$min_stay);
    if ($max_stay !== null) update_post_meta($post_id, 'mphb_max_stay', (int)$max_stay);

    $log[] = 'Adults: ' . $adults . ' | Price: ' . $daily_rate . ' | Min: ' . ($min_stay ?? '') . ' | Max: ' . ($max_stay ?? '');

    // 4) Description (best-effort)
    $description = '';
    if (!empty($property['description'])) $description = $property['description'];
    elseif (!empty($property['publicDescription'])) $description = $property['publicDescription'];
    elseif (!empty($property['summary'])) $description = $property['summary'];

    if (!$description) {
        $parts = [];
        if (!empty($property['listingType']))  $parts[] = $property['listingType'];
        if (!empty($property['propertyType'])) $parts[] = $property['propertyType'];

        $addr = $property['address']['address'] ?? '';
        $city = $property['address']['city'] ?? '';

        $description = trim(implode(' • ', $parts));
        if ($addr || $city) {
            $description .= "\n\nLocation: " . trim($addr . ' ' . $city);
        }
    }

    if ($description) {
        wp_update_post([
            'ID'           => $post_id,
            'post_content' => wp_kses_post($description),
        ]);
    }

    // 5) Amenities
    hostfully_mphb_import_amenities((int)$post_id, $property, $log);

    // 5b) Categories + Tags
    hostfully_mphb_import_categories_tags((int)$post_id, $property, $log);

    // 5c) Attributes (Room Attributes / mphb_ra_* taxonomies)
    hostfully_mphb_import_attributes((int)$post_id, $property, $log);

    // 5d) Services (fees)
    hostfully_mphb_import_services((int)$post_id, $property, $log);

    // 6) Featured image
    $picture_url = $property['pictureLink'] ?? '';
    if ($picture_url) {
        $log[] = 'Featured image URL: ' . $picture_url;

        $attach_id = hostfully_mphb_sideload_image(
            $picture_url,
            $post_id,
            'hostfully-featured-' . ($property['uid'] ?? uniqid()) . '.jpg',
            $log
        );

        if (!is_wp_error($attach_id) && $attach_id) {
            set_post_thumbnail($post_id, (int)$attach_id);
            $log[] = 'Featured image imported attachment ID: ' . (int)$attach_id;
        }
    } else {
        $log[] = 'Featured image URL: (none provided by Hostfully)';
    }

    // 7) Gallery import
    $photos_url = rtrim($cfg['base_url'], '/') . '/photos?propertyUid=' . urlencode($property['uid']) . '&agencyUid=' . urlencode($cfg['agency_uid']);
    $log[] = 'Photos URL: ' . $photos_url;

    $photo_map = get_post_meta($post_id, '_hostfully_photo_map', true);
    if (!is_array($photo_map)) $photo_map = [];

    $photos_response = hostfully_mphb_api_get($photos_url);

    $gallery_ids = [];

    if (is_wp_error($photos_response)) {
        $log[] = 'Photos request failed: ' . $photos_response->get_error_message();
    } else {
        $photos_status = wp_remote_retrieve_response_code($photos_response);
        $photos_body   = wp_remote_retrieve_body($photos_response);
        $photos_data   = json_decode($photos_body, true);

        $photos = $photos_data['photos'] ?? [];
        $log[] = 'Photos HTTP: ' . $photos_status . ' | Count: ' . (is_array($photos) ? count($photos) : 0);
        hostfully_mphb_log_debug($log, 'Photos response bytes: ' . strlen((string)$photos_body) . '.');

        if (is_array($photos) && !empty($photos)) {
            usort($photos, function ($a, $b) {
                return (int)($a['displayOrder'] ?? 0) <=> (int)($b['displayOrder'] ?? 0);
            });

            $count = 0;
            $max = (int)$cfg['max_photos'];

            foreach ($photos as $photo) {
                if ($count >= $max) break;

                $photo_uid = $photo['uid'] ?? '';
                if ($photo_uid && isset($photo_map[$photo_uid])) {
                    $existing_attach_id = (int)$photo_map[$photo_uid];
                    $gallery_ids[] = $existing_attach_id;
                    $log[] = "Gallery skip (already imported): {$photo_uid} => attachment {$existing_attach_id}";
                    $count++;
                    continue;
                }

                $img = $photo['largeScaleImageUrl']
                    ?? $photo['mediumScaleImageUrl']
                    ?? $photo['originalImageUrl']
                    ?? '';

                if (!$img) {
                    $log[] = 'Gallery skip: no URL';
                    continue;
                }

                $log[] = 'Downloading gallery photo ' . ($count + 1) . ': ' . $img;

                $attach_id = hostfully_mphb_sideload_image(
                    $img,
                    $post_id,
                    'hostfully-' . ($photo_uid ?: uniqid()) . '.jpg',
                    $log
                );

                if (is_wp_error($attach_id) || !$attach_id) {
                    continue;
                }

                $attach_id = (int)$attach_id;
                $gallery_ids[] = $attach_id;

                if ($photo_uid) $photo_map[$photo_uid] = $attach_id;

                $log[] = 'Imported gallery attachment ID: ' . $attach_id;
                $count++;
            }
            if ($count >= $max) {
                hostfully_mphb_log_debug($log, 'Gallery truncated to max_photos=' . $max . '.');
            }
        }
    }

    update_post_meta($post_id, '_hostfully_photo_map', $photo_map);

    if (!empty($gallery_ids)) {
        $gallery_ids = array_values(array_unique(array_map('intval', $gallery_ids)));
        update_post_meta($post_id, 'mphb_gallery', implode(',', $gallery_ids));
        $log[] = 'Saved mphb_gallery: ' . implode(',', $gallery_ids);
    } else {
        $log[] = 'No gallery images imported.';
    }

    // 8) Featured fallback
    if (!has_post_thumbnail($post_id) && !empty($gallery_ids)) {
        set_post_thumbnail($post_id, (int)$gallery_ids[0]);
        $log[] = 'Featured image fallback set to first gallery image attachment ID: ' . (int)$gallery_ids[0];
    }

    // 9) Rate + at least one unit (bookable)
    $rate_id = hostfully_mphb_upsert_rate((int)$post_id, $property, (float)$daily_rate, (int)$adults, (int)$children, $log);
    if ($rate_id) {
        $log[] = 'Rate linked OK: ' . $rate_id;
    } else {
        $log[] = 'Rate not created (check logs).';
    }

    $room_id = hostfully_mphb_upsert_room_unit((int)$post_id, $property, $log);
    if ($room_id) {
        $log[] = 'Accommodation unit linked OK: ' . $room_id;
    } else {
        $log[] = 'Accommodation unit not created (check logs).';
    }

    hostfully_mphb_log_debug($log, 'Import debug: completed at ' . date('c'));
    return (int)$post_id;
}

/**
 * =========
 * ADMIN UI
 * =========
 */
add_action('admin_menu', function () {
    add_menu_page(
        'Hostfully Import',
        'Hostfully Import',
        'manage_options',
        'hostfully-import',
        'hostfully_mphb_render_admin'
    );
});

function hostfully_mphb_render_admin()
{
    if (!current_user_can('manage_options')) return;

    $cfg = hostfully_mphb_settings();

    // Clear last error
    if (isset($_POST['hostfully_clear_last_error'])) {
        check_admin_referer('hostfully_mphb_clear_last_error');
        hostfully_mphb_clear_last_error();
        echo '<div class="notice notice-success"><p>Last error cleared.</p></div>';
    }

    // Save settings
    if (isset($_POST['hostfully_save_settings'])) {
        check_admin_referer('hostfully_mphb_settings');
        hostfully_mphb_update_settings($_POST['hostfully'] ?? []);
        $cfg = hostfully_mphb_settings();

        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    // Manual sync is handled via AJAX (Catalog Sync section below).

    $properties    = hostfully_mphb_get_properties();
    $imported_uids = hostfully_mphb_get_imported_uids();
    $last_error    = hostfully_mphb_get_last_error();
?>
    <div class="wrap">
        <h1>Hostfully → MotoPress Import</h1>

        <?php if (!empty($last_error)): ?>
            <div class="notice notice-error">
                <p><strong>Last error:</strong> <?= esc_html($last_error); ?></p>
                <form method="post" style="margin-top:6px;">
                    <?php wp_nonce_field('hostfully_mphb_clear_last_error'); ?>
                    <button class="button" name="hostfully_clear_last_error" value="1">Clear last error</button>
                </form>
            </div>
        <?php endif; ?>

        <h2>Quick Start</h2>
        <ol>
            <li>Enter your Hostfully API credentials and save settings.</li>
            <li>Sync the amenities catalog once (recommended).</li>
            <li>Run a single import to confirm everything looks correct.</li>
            <li>Run bulk import to bring in all remaining properties.</li>
        </ol>
        <p class="description">Tip: If you need to optimize large images, do it after import using an optimizer plugin or a separate batch job.</p>

        <hr>

        <h2>Step 1: API Settings</h2>
        <form method="post">
            <?php wp_nonce_field('hostfully_mphb_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="hostfully_api_key">API Key</label></th>
                    <td><input id="hostfully_api_key" type="text" name="hostfully[api_key]" value="<?= esc_attr($cfg['api_key']); ?>" style="width:420px;"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hostfully_agency_uid">Agency UID</label></th>
                    <td><input id="hostfully_agency_uid" type="text" name="hostfully[agency_uid]" value="<?= esc_attr($cfg['agency_uid']); ?>" style="width:420px;"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hostfully_max_photos">Max photos per property</label></th>
                    <td><input id="hostfully_max_photos" type="number" min="0" name="hostfully[max_photos]" value="<?= esc_attr($cfg['max_photos']); ?>" style="width:120px;"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="hostfully_bulk_limit">Bulk import limit</label></th>
                    <td><input id="hostfully_bulk_limit" type="number" min="1" name="hostfully[bulk_limit]" value="<?= esc_attr($cfg['bulk_limit']); ?>" style="width:120px;"></td>
                </tr>
                <tr>
                    <th scope="row">API enrichment</th>
                    <td>
                        <label style="display:block; margin:4px 0 6px;">
                            <input type="checkbox" name="hostfully[allow_enrich_api]" value="1" <?= !empty($cfg['allow_enrich_api']) ? 'checked' : ''; ?>>
                            Allow extra Hostfully API calls to enrich missing data (cached). Disable this if you’re worried about rate limits.
                        </label>
                        <label style="display:block; margin:4px 0 0;">
                            Amenities cache hours:
                            <input type="number" min="1" max="168" name="hostfully[amenities_cache_hours]" value="<?= esc_attr($cfg['amenities_cache_hours'] ?? 24); ?>" style="width:120px;">
                        </label>
                        <label style="display:block; margin:8px 0 0;">
                            <input type="checkbox" name="hostfully[verbose_log]" value="1" <?= !empty($cfg['verbose_log']) ? 'checked' : ''; ?>>
                            Enable verbose logging (adds detailed steps to the import log).
                        </label>
                        <p class="description" style="margin-top:8px;">Amenities cache hours controls how long we reuse Hostfully data before refreshing.</p>
                    </td>
                </tr>

            </table>

            <p>
                <button class="button button-primary" name="hostfully_save_settings" value="1">Save Settings</button>
            </p>
        </form>

        <hr>

        <h2>Step 2: Catalog Sync (Recommended)</h2>
        <p>Sync global lists (like amenities) once to reduce per-property API calls and keep mappings stable.</p>

        <p>
            <button id="hostfully-sync-amenities" class="button">Sync Amenities Catalog</button>
        </p>

        <hr>

        <h2>Step 3: Import One (Test)</h2>
        <form method="post" id="hostfully-import-one-form">
            <?php wp_nonce_field('hostfully_mphb_import_one'); ?>

            <p>
                <label>Select Hostfully Property</label><br>
                <label style="display:block; margin:6px 0 8px;">
                    <input type="checkbox" name="update_existing" value="1"> Allow updating an already-imported property
                </label>
                <select name="property_uid" style="width:420px;">
                    <option value="">-- Select a property --</option>

                    <?php foreach ($properties as $property): ?>
                        <?php
                        $uid  = $property['uid'] ?? '';
                        $name = $property['name'] ?? 'Unnamed property';
                        $is_imported = in_array($uid, $imported_uids, true);
                        if (!$uid) continue;
                        if ($is_imported && empty($_POST['update_existing'])) {
                            // show but disabled unless update mode is enabled
                        }
                        ?>
                        <option value="<?= esc_attr($uid); ?>" <?= $is_imported ? 'data-imported="1"' : ''; ?>><?= esc_html($name . ($is_imported ? ' (imported)' : '')); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p>
                <button class="button button-primary" name="hostfully_import_one" value="1">Import Selected</button>
                <span id="hostfully-import-one-status" style="margin-left:10px; color:#666;"></span>
                <span id="hostfully-import-one-spinner" class="spinner" style="float:none; vertical-align:middle; margin-left:6px;"></span>
            </p>
        </form>

        <hr>

        <h2>Step 4: Bulk Import</h2>
        <p>This will import properties that are not yet imported (up to your bulk limit), one at a time via AJAX so the page doesn’t time out.</p>

        <p>
            <label style="margin-right:12px;">
                <input type="checkbox" id="hostfully-bulk-update-existing" value="1"> Update existing imports too
            </label>
            <button id="hostfully-bulk-start" class="button button-primary">Start Bulk Import</button>
            <button id="hostfully-bulk-stop" class="button" disabled>Stop</button>
        </p>

        <div id="hostfully-progress" style="display:none; background:#fff; border:1px solid #ccc; padding:10px; max-width:900px;">
            <strong>Status:</strong> <span id="hostfully-status">Idle</span>
            <span id="hostfully-spinner" class="spinner" style="float:none; margin-left:8px; vertical-align:middle;"></span>
            <span id="hostfully-counter" style="margin-left:8px; color:#555;"></span>
            <pre id="hostfully-log" style="white-space:pre-wrap; margin-top:10px;"></pre>
            <div id="hostfully-summary" style="display:none; margin-top:10px; padding:8px; background:#f6f7f7; border:1px solid #ccd0d4;"></div>
        </div>
        <p id="hostfully-js-indicator" style="margin-top:8px; color:#666; font-size:12px;">JS status: not loaded</p>
    </div>
<?php
}

/**
 * ===========
 * SINGLE IMPORT
 * ===========
 */
add_action('admin_init', function () {
    if (!isset($_POST['hostfully_import_one'])) return;
    if (!current_user_can('manage_options')) return;
    if (!check_admin_referer('hostfully_mphb_import_one')) return;

    $property_uid = sanitize_text_field($_POST['property_uid'] ?? '');
    if (!$property_uid) wp_die('No property selected.');

    $update_existing = !empty($_POST['update_existing']);
    if (!$update_existing) {
        $existing = hostfully_mphb_find_existing_post_id($property_uid);
        if ($existing) wp_die('That property is already imported. Tick “Allow updating…” if you want to re-import/update it.');
    }

    $existing_id = hostfully_mphb_find_existing_post_id($property_uid);
    $started_at = time();

    $log = [];
    $post_id = hostfully_mphb_import_property($property_uid, $log);

    $duration = time() - $started_at;
    $created = ($post_id && !$existing_id) ? 1 : 0;
    $updated = ($post_id && $existing_id) ? 1 : 0;
    $errors  = $post_id ? 0 : 1;
    $summary = 'Summary: total 1, done 1, created ' . $created . ', updated ' . $updated . ', errors ' . $errors . ', duration ' . $duration . 's';

    wp_die(
        '<h2>✅ Import complete</h2>' .
            '<p><strong>' . esc_html($summary) . '</strong></p>' .
            '<p><strong>Room Type ID:</strong> ' . (int)$post_id . '</p>' .
            '<h3>Progress log</h3>' .
            '<pre style="white-space:pre-wrap; background:#fff; border:1px solid #ccc; padding:10px;">' . esc_html(implode("\n", $log)) . '</pre>'
    );
});

/**
 * ===========
 * BULK IMPORT (AJAX)
 * ===========
 */
add_action('wp_ajax_hostfully_mphb_bulk_start', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'No permission.'], 403);
    check_ajax_referer('hostfully_mphb_ajax', 'nonce');

    $cfg = hostfully_mphb_settings();
    $properties = hostfully_mphb_get_properties();
    $imported   = hostfully_mphb_get_imported_uids();

    $update_existing = !empty($_POST['update_existing']);

    $missing = [];

    foreach ($properties as $p) {
        $uid = $p['uid'] ?? '';
        if (!$uid) continue;
        if (!$update_existing && in_array($uid, $imported, true)) continue;
        $missing[] = $uid;
        if (count($missing) >= (int)$cfg['bulk_limit']) break;
    }

    update_option(HOSTFULLY_MPHB_OPT_QUEUE, $missing, false);

    $progress = [
        'total'      => count($missing),
        'done'       => 0,
        'last'       => null,
        'errors'     => 0,
        'created'    => 0,
        'updated'    => 0,
        'started_at' => time(),
    ];
    update_option(HOSTFULLY_MPHB_OPT_PROGRESS, $progress, false);

    wp_send_json_success([
        'total' => $progress['total'],
        'queue' => $missing,
        'update_existing' => $update_existing,
        'last_error' => hostfully_mphb_get_last_error(),
    ]);
});

add_action('wp_ajax_hostfully_mphb_bulk_tick', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'No permission.'], 403);
    check_ajax_referer('hostfully_mphb_ajax', 'nonce');

    $queue = get_option(HOSTFULLY_MPHB_OPT_QUEUE, []);
    if (!is_array($queue)) $queue = [];

    $progress = get_option(HOSTFULLY_MPHB_OPT_PROGRESS, []);
    if (!is_array($progress)) $progress = ['total' => 0, 'done' => 0, 'errors' => 0];

    if (empty($queue)) {
        wp_send_json_success([
            'done'     => true,
            'progress' => $progress,
            'message'  => 'Queue finished.',
        ]);
    }

    $uid = array_shift($queue);
    update_option(HOSTFULLY_MPHB_OPT_QUEUE, $queue, false);

    $existing_id = hostfully_mphb_find_existing_post_id($uid);

    $log = [];
    $post_id = 0;
    try {
        $post_id = hostfully_mphb_import_property($uid, $log);
    } catch (Throwable $e) {
        $msg = 'Import exception: ' . $e->getMessage() . ' ('. get_class($e) . ') in ' . $e->getFile() . ':' . $e->getLine();
        $log[] = $msg;
        hostfully_mphb_set_last_error($msg);
        error_log('[Hostfully Importer] Exception during bulk tick: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }

    $progress['done'] = (int)($progress['done'] ?? 0) + 1;
    $progress['last'] = $uid;

    if (!$post_id) {
        $progress['errors'] = (int)($progress['errors'] ?? 0) + 1;
    } else {
        if ($existing_id) {
            $progress['updated'] = (int)($progress['updated'] ?? 0) + 1;
        } else {
            $progress['created'] = (int)($progress['created'] ?? 0) + 1;
        }
    }

    update_option(HOSTFULLY_MPHB_OPT_PROGRESS, $progress, false);

    wp_send_json_success([
        'done'      => empty($queue),
        'post_id'   => (int)$post_id,
        'uid'       => $uid,
        'log'       => $log,
        'progress'  => $progress,
        'remaining' => count($queue),
        'last_error' => hostfully_mphb_get_last_error(),
    ]);
});

add_action('wp_ajax_hostfully_mphb_import_one', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'No permission.'], 403);
    check_ajax_referer('hostfully_mphb_ajax', 'nonce');

    $property_uid = sanitize_text_field($_POST['property_uid'] ?? '');
    if (!$property_uid) {
        wp_send_json_error(['message' => 'No property selected.'], 400);
    }

    $update_existing = !empty($_POST['update_existing']);
    if (!$update_existing) {
        $existing = hostfully_mphb_find_existing_post_id($property_uid);
        if ($existing) {
            wp_send_json_error(['message' => 'That property is already imported. Tick “Allow updating…” if you want to re-import/update it.'], 400);
        }
    }

    $existing_id = hostfully_mphb_find_existing_post_id($property_uid);
    $started_at = time();

    $log = [];
    $post_id = 0;
    try {
        $post_id = hostfully_mphb_import_property($property_uid, $log);
    } catch (Throwable $e) {
        $msg = 'Import exception: ' . $e->getMessage() . ' ('. get_class($e) . ') in ' . $e->getFile() . ':' . $e->getLine();
        $log[] = $msg;
        hostfully_mphb_set_last_error($msg);
        error_log('[Hostfully Importer] Exception during single import: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }

    $duration = time() - $started_at;
    $created = ($post_id && !$existing_id) ? 1 : 0;
    $updated = ($post_id && $existing_id) ? 1 : 0;
    $errors  = $post_id ? 0 : 1;

    $progress = [
        'total'      => 1,
        'done'       => 1,
        'errors'     => $errors,
        'created'    => $created,
        'updated'    => $updated,
        'started_at' => $started_at,
    ];

    wp_send_json_success([
        'uid'       => $property_uid,
        'post_id'   => (int)$post_id,
        'log'       => $log,
        'progress'  => $progress,
        'duration'  => $duration,
        'last_error' => hostfully_mphb_get_last_error(),
    ]);
});

add_action('wp_ajax_hostfully_mphb_get_last_error', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'No permission.'], 403);
    check_ajax_referer('hostfully_mphb_ajax', 'nonce');

    wp_send_json_success([
        'last_error' => hostfully_mphb_get_last_error(),
    ]);
});


/**
 * ==================
 * CATALOG SYNC (AJAX)
 * ==================
 */
add_action('wp_ajax_hostfully_mphb_sync_amenities', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'No permission.'], 403);
    check_ajax_referer('hostfully_mphb_ajax', 'nonce');

    $log = [];
    $res = hostfully_mphb_sync_amenities_catalog_safe($log);

    wp_send_json_success([
        'result' => $res,
        'log'    => $log,
    ]);
});

/**
 * ===========
 * ADMIN JS
 * ===========
 */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_hostfully-import') return;

    $script_path = plugin_dir_path(__FILE__) . 'assets/admin.js';
    $script_ver = file_exists($script_path) ? (string) filemtime($script_path) : '0.5';

    wp_enqueue_script(
        'hostfully-mphb-admin',
        plugin_dir_url(__FILE__) . 'assets/admin.js',
        [],
        $script_ver,
        true
    );

    wp_localize_script('hostfully-mphb-admin', 'HOSTFULLY_MPHB', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('hostfully_mphb_ajax'),
    ]);
});
