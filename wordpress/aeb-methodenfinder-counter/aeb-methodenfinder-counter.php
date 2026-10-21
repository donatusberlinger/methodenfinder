<?php
/**
 * Plugin Name: aeB Methodenfinder – einfache Zugriffszählung
 * Description: Speichert ausschliesslich stündliche Aufrufsummen. Keine Cookies, IP-Adressen, Besucherkennungen oder Formulareingaben.
 * Version: 1.0.1
 * Author: aeB Schweiz
 */
defined('ABSPATH') || exit;

final class AEB_Methodenfinder_Counter {
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'aeb_mf_counts';
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            hour_utc datetime NOT NULL,
            views bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (hour_utc)
        ) $charset;");
        add_option('aeb_mf_counter_started', gmdate('Y-m-d H:i:s'), '', false);
    }

    public static function register() {
        register_rest_route('aeb-methodenfinder/v1', '/count', array(
            'methods' => 'POST',
            'permission_callback' => array(__CLASS__, 'allow'),
            'callback' => array(__CLASS__, 'count_view'),
        ));
    }

    public static function allow($request) {
        $origin = $request->get_header('origin');
        $expected = 'https://' . wp_parse_url(home_url(), PHP_URL_HOST);
        if ($origin !== $expected || strlen($request->get_body()) > 0 || !empty($request->get_query_params())) {
            return new WP_Error('aeb_counter_request', 'Ungueltige Zaehlanfrage.', array('status' => 403));
        }
        return true;
    }

    public static function count_view($request) {
        // User-Agent is checked only in memory; it is never saved by this plugin.
        $ua = $request->get_header('user_agent');
        if (!preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|monitor|headless/i', $ua)) {
            global $wpdb;
            $result = $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . self::table() . ' (hour_utc, views) VALUES (%s, 1) ON DUPLICATE KEY UPDATE views = views + 1',
                gmdate('Y-m-d H:00:00')
            ));
            if ($result === false) {
                return new WP_Error('aeb_counter_unavailable', 'Zaehlung derzeit nicht verfuegbar.', array('status' => 503));
            }
        }
        return new WP_REST_Response(null, 204, array('Cache-Control' => 'no-store, private'));
    }

    public static function menu() {
        add_management_page('aeB Methodenfinder – Aufrufe', 'Methodenfinder-Aufrufe', 'manage_options', 'aeb-methodenfinder-counts', array(__CLASS__, 'report'));
    }

    public static function report() {
        if (!current_user_can('manage_options')) { return; }
        global $wpdb;
        $zone = new DateTimeZone('Europe/Zurich');
        $rows = $wpdb->get_results('SELECT hour_utc, views FROM ' . self::table() . ' ORDER BY hour_utc DESC', ARRAY_A);
        $total = 0; $days = array(); $hours = array();
        // Exclude the single authorised installation test from 21 October 2026,
        // 18:48 CEST. Keep the raw aggregate intact so the correction is reversible.
        $test_excluded = 0;
        $started_raw = get_option('aeb_mf_counter_started', '');
        foreach ($rows as $row) {
            if (strpos($started_raw, '2026-10-21 16:48:') === 0 && $row['hour_utc'] === '2026-10-21 16:00:00') {
                $test_excluded = min(1, (int) $row['views']);
                $row['views'] = (int) $row['views'] - $test_excluded;
            }
            $local = new DateTimeImmutable($row['hour_utc'], new DateTimeZone('UTC'));
            $local = $local->setTimezone($zone);
            $day = $local->format('Y-m-d');
            $total += (int) $row['views'];
            $days[$day] = (isset($days[$day]) ? $days[$day] : 0) + (int) $row['views'];
            $hours[] = array($local->format('d.m.Y H:00 T'), (int) $row['views']);
        }
        $today = (new DateTimeImmutable('now', $zone))->format('Y-m-d');
        $started = new DateTimeImmutable(get_option('aeb_mf_counter_started', gmdate('Y-m-d H:i:s')), new DateTimeZone('UTC'));
        echo '<div class="wrap"><h1>aeB-Methodenfinder: Seitenaufrufe</h1>';
        echo '<p>Datensparsame Zählung seit <strong>' . esc_html($started->setTimezone($zone)->format('d.m.Y H:i T')) . '</strong>. Kostenlos auf dem aeB-Server.</p>';
        echo '<h2>Heute: ' . esc_html(number_format_i18n(isset($days[$today]) ? $days[$today] : 0)) . ' · Insgesamt: ' . esc_html(number_format_i18n($total)) . '</h2>';
        echo '<p>Gezählt wird jeder neu geladene, sichtbare Methodenfinder mit aktivem JavaScript. Mehrfachaufrufe zählen mehrfach; dies ist keine Zahl unterschiedlicher Personen. Bekannte Bots werden soweit erkennbar ausgeschlossen. </p>';
        echo '<p>Gespeichert werden nur Stunde und Aufrufsumme. Keine IP-Adressen, Cookies, Besucherkennungen, Herkunftsseiten oder Lernaufträge. Technische Serverprotokolle des Hosters bestehen davon unabhängig. Google Analytics ist separat und weiterhin zustimmungsabhängig.</p>';
        if ($test_excluded) {
            echo '<p>Der technische Installationstest vom 21.10.2026 (1 Aufruf) ist aus allen angezeigten Summen ausgeschlossen.</p>';
        }
        echo '<h2>Letzte 30 Tage · Schweizer Zeit</h2><table class="widefat striped" style="max-width:700px"><thead><tr><th>Datum</th><th>Seitenaufrufe</th></tr></thead><tbody>';
        $date = new DateTimeImmutable('today', $zone);
        for ($i = 0; $i < 30; $i++) {
            $key = $date->format('Y-m-d');
            echo '<tr><td>' . esc_html($date->format('d.m.Y')) . '</td><td>' . esc_html(number_format_i18n(isset($days[$key]) ? $days[$key] : 0)) . '</td></tr>';
            $date = $date->modify('-1 day');
        }
        echo '</tbody></table><h2>Letzte 48 erfasste Stunden</h2><table class="widefat striped" style="max-width:700px"><thead><tr><th>Stunde · Schweizer Zeit</th><th>Seitenaufrufe</th></tr></thead><tbody>';
        foreach (array_slice($hours, 0, 48) as $hour) {
            echo '<tr><td>' . esc_html($hour[0]) . '</td><td>' . esc_html(number_format_i18n($hour[1])) . '</td></tr>';
        }
        if (!$hours) { echo '<tr><td colspan="2">Noch keine Seitenaufrufe erfasst.</td></tr>'; }
        echo '</tbody></table></div>';
    }
}
register_activation_hook(__FILE__, array('AEB_Methodenfinder_Counter', 'activate'));
add_action('rest_api_init', array('AEB_Methodenfinder_Counter', 'register'));
add_action('admin_menu', array('AEB_Methodenfinder_Counter', 'menu'));
