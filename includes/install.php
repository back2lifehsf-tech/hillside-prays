<?php
if (!defined('ABSPATH')) exit;

function hp_tables() {
    global $wpdb;
    return [
        'requests' => $wpdb->prefix . 'hp_requests',
        'prayers'  => $wpdb->prefix . 'hp_prayers',
    ];
}

function hp_create_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = hp_tables();
    $c = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$t['requests']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  kind varchar(10) NOT NULL,
  text text NOT NULL,
  visibility varchar(12) NOT NULL,
  first_name varchar(60) DEFAULT NULL,
  contact varchar(140) DEFAULT NULL,
  notify_on_pray tinyint(1) NOT NULL DEFAULT 0,
  contact_me tinyint(1) NOT NULL DEFAULT 0,
  prayed_count int(11) NOT NULL DEFAULT 0,
  hidden tinyint(1) NOT NULL DEFAULT 0,
  reported tinyint(1) NOT NULL DEFAULT 0,
  status varchar(12) NOT NULL DEFAULT 'NEW',
  ministry varchar(60) DEFAULT NULL,
  owner_id bigint(20) unsigned DEFAULT NULL,
  follow_up date DEFAULT NULL,
  care_notes text,
  approved tinyint(1) NOT NULL DEFAULT 0,
  source varchar(20) NOT NULL DEFAULT 'web',
  due_at datetime DEFAULT NULL,
  followed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY feed (visibility,hidden,status,created_at)
) $c;");
    dbDelta("CREATE TABLE {$t['prayers']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  request_id bigint(20) unsigned NOT NULL,
  notified tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY request_id (request_id),
  KEY notified (notified)
) $c;");
}

function hp_add_roles() {
    // Prayer team: can log in and see /pray?hp=team. Nothing else.
    add_role('hp_prayer_team', 'Prayer Team', ['read' => true, 'hp_team' => true]);
    // Pastors / Care: see every request in wp-admin. Nothing else on the site.
    add_role('hp_care', 'Hillside Care', ['read' => true, 'hp_team' => true, 'hp_manage' => true]);
    foreach (['administrator', 'editor'] as $r) {
        $role = get_role($r);
        if ($role) { $role->add_cap('hp_manage'); $role->add_cap('hp_team'); }
    }
}

function hp_create_page() {
    // A draft page with the shortcode. Publish it when you're ready.
    if (get_option('hp_page_id') && get_post(get_option('hp_page_id'))) return;
    $existing = get_page_by_path('pray');
    if ($existing && has_shortcode($existing->post_content, 'hillside_prays')) {
        update_option('hp_page_id', $existing->ID);
        return;
    }
    $id = wp_insert_post([
        'post_title'   => 'Pray',
        'post_name'    => $existing ? 'hillside-prays' : 'pray',
        'post_content' => '<!-- wp:shortcode -->[hillside_prays]<!-- /wp:shortcode -->',
        'post_status'  => 'draft',
        'post_type'    => 'page',
    ]);
    if ($id && !is_wp_error($id)) update_option('hp_page_id', $id);
}

function hp_activate() {
    hp_create_tables();
    hp_add_roles();
    hp_create_page();
    add_option('hp_settings', hp_default_settings());
    if (!wp_next_scheduled('hp_daily_digest')) {
        // 23:00 UTC is about 7-8 pm in Moncton.
        $next = strtotime('today 23:00 UTC');
        if ($next < time()) $next += DAY_IN_SECONDS;
        wp_schedule_event($next, 'daily', 'hp_daily_digest');
    }
    update_option('hp_db_version', HP_VERSION);
}

function hp_deactivate() {
    wp_clear_scheduled_hook('hp_daily_digest');
}

function hp_maybe_upgrade() {
    $from = get_option('hp_db_version');
    if ($from === HP_VERSION) return;
    hp_create_tables();
    hp_add_roles();
    // Before 0.3.0 there was no approval queue: keep what the team already saw visible.
    if ($from && version_compare($from, '0.3.0', '<')) {
        global $wpdb;
        $t = hp_tables()['requests'];
        $wpdb->query("UPDATE $t SET approved = 1 WHERE visibility <> 'PRIVATE'");
    }
    update_option('hp_db_version', HP_VERSION);
}

function hp_default_settings() {
    return [
        'care_email'       => get_option('admin_email'),
        'feed_days'        => 30,
        'church_public'    => 0, // Pray for others for the whole church: off until the pilot says yes.
        'ministries'       => 'Pastor, Prayer, Hospital Visitation, Home Visitation, Meals of Love, Benevolence, DivorceCare, Celebrate Recovery, Christmas Sponsorship, Baskets of Love, Funerals',
        'team_digest_days' => 'mon,thu',
        'sla_urgent'       => 2,  // hours to first contact
        'sla_soon'         => 24, // prayer, talk with a pastor
        'sla_help'         => 48, // practical help
    ];
}

function hp_settings() {
    $s = get_option('hp_settings', []);
    return wp_parse_args(is_array($s) ? $s : [], hp_default_settings());
}

function hp_page_url($view = '', $args = []) {
    $id = (int) get_option('hp_page_id');
    $base = $id ? get_permalink($id) : home_url('/pray/');
    if ($view) $args = array_merge(['hp' => $view], $args);
    return $args ? add_query_arg($args, $base) : $base;
}
