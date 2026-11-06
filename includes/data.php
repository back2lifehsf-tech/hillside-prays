<?php
if (!defined('ABSPATH')) exit;

const HP_KINDS    = ['PRAYER', 'PASTOR', 'HELP', 'URGENT'];
const HP_VIS      = ['PRIVATE', 'TEAM', 'CHURCH_NAME', 'CHURCH_ANON'];
const HP_STATUSES = ['NEW', 'ASSIGNED', 'FOLLOWED_UP', 'ARCHIVED'];

function hp_kind_label($k) {
    return ['PRAYER' => 'Prayer', 'PASTOR' => 'Talk with a pastor', 'HELP' => 'Practical help', 'URGENT' => 'Something urgent'][$k] ?? $k;
}
function hp_vis_label($v) {
    return ['PRIVATE' => 'Pastors and Care only', 'TEAM' => 'Prayer team', 'CHURCH_NAME' => 'Whole church, first name', 'CHURCH_ANON' => 'Whole church, no name'][$v] ?? $v;
}
function hp_status_label($s) {
    return ['NEW' => 'New', 'ASSIGNED' => 'In progress', 'FOLLOWED_UP' => 'Followed up', 'ARCHIVED' => 'Archived'][$s] ?? $s;
}
function hp_display_name($r) {
    if ($r->visibility === 'CHURCH_ANON' || !trim((string) $r->first_name)) return 'Someone from Hillside';
    return $r->first_name;
}
function hp_ago($datetime_utc) {
    $s = time() - strtotime($datetime_utc . ' UTC');
    if ($s < 3600) return 'just now';
    if ($s < DAY_IN_SECONDS) return floor($s / 3600) . ' h ago';
    $d = floor($s / DAY_IN_SECONDS);
    return $d == 1 ? 'yesterday' : "$d days ago";
}

/** Is the whole-church tier (Pray for others) switched on? Off by default for the pilot. */
function hp_church_on() {
    return !empty(hp_settings()['church_public']);
}

/** The Care ministries a request can be assigned to, from settings. */
function hp_ministries() {
    $list = array_filter(array_map('trim', explode(',', (string) hp_settings()['ministries'])));
    return array_values(array_unique($list));
}

/** Only a prayer request gets a visibility choice; everything else is always private. */
function hp_effective_visibility($kind, $visibility) {
    return $kind === 'PRAYER' ? $visibility : 'PRIVATE';
}

/** Respond-by deadline in hours, from settings: urgent 2, prayer/pastor 24, practical help 48. */
function hp_due_hours($kind) {
    $s = hp_settings();
    if ($kind === 'URGENT') return max(1, (int) $s['sla_urgent']);
    if ($kind === 'HELP') return max(1, (int) $s['sla_help']);
    return max(1, (int) $s['sla_soon']);
}

/** The ministry a request probably belongs to. A human confirms; this only suggests. */
function hp_suggest_ministry($kind, $help_kind = '') {
    $map = ['groceries' => 'Benevolence', 'meals' => 'Meals of Love', 'hospital' => 'Hospital Visitation', 'visit' => 'Home Visitation'];
    if ($kind === 'HELP') return $map[$help_kind] ?? null;
    if ($kind === 'PRAYER') return 'Prayer';
    return 'Pastor'; // PASTOR and URGENT
}

function hp_insert_request($d) {
    global $wpdb;
    $now = time();
    $ministry = $d['ministry'] ?? hp_suggest_ministry($d['kind'], $d['help_kind'] ?? '');
    if ($ministry && !in_array($ministry, hp_ministries(), true)) $ministry = null;
    $wpdb->insert(hp_tables()['requests'], [
        'created_at'     => gmdate('Y-m-d H:i:s', $now),
        'kind'           => $d['kind'],
        'text'           => $d['text'],
        'visibility'     => $d['visibility'],
        'first_name'     => $d['first_name'],
        'contact'        => $d['contact'],
        'notify_on_pray' => $d['notify_on_pray'] ? 1 : 0,
        'contact_me'     => $d['contact_me'] ? 1 : 0,
        'ministry'       => $ministry,
        'source'         => $d['source'] ?? 'web',
        'due_at'         => gmdate('Y-m-d H:i:s', $now + hp_due_hours($d['kind']) * HOUR_IN_SECONDS),
    ]);
    return (int) $wpdb->insert_id;
}

function hp_get_request($id) {
    global $wpdb;
    $t = hp_tables()['requests'];
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $id));
}

function hp_update_request($id, $fields) {
    global $wpdb;
    $allowed = array_intersect_key($fields, array_flip(['hidden', 'reported', 'status', 'ministry', 'owner_id', 'follow_up', 'care_notes', 'approved', 'text', 'followed_at']));
    if (!$allowed) return false;
    if (isset($allowed['status']) && $allowed['status'] === 'FOLLOWED_UP' && !isset($allowed['followed_at'])) {
        $allowed['followed_at'] = current_time('mysql', true);
    }
    return $wpdb->update(hp_tables()['requests'], $allowed, ['id' => (int) $id]);
}

function hp_since_sql() {
    $days = max(1, (int) hp_settings()['feed_days']);
    return gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
}

/** Requests shown to the whole church. Empty while the church tier is off. */
function hp_public_requests($limit = 60) {
    global $wpdb;
    if (!hp_church_on()) return [];
    $t = hp_tables()['requests'];
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $t WHERE visibility IN ('CHURCH_NAME','CHURCH_ANON') AND approved = 1 AND hidden = 0 AND status <> 'ARCHIVED'
         AND kind <> 'URGENT' AND created_at >= %s ORDER BY created_at DESC LIMIT %d",
        hp_since_sql(), $limit
    ));
}

/** Requests the prayer team sees: team-only plus public. */
function hp_team_requests() {
    global $wpdb;
    $t = hp_tables()['requests'];
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $t WHERE visibility IN ('TEAM','CHURCH_NAME','CHURCH_ANON') AND approved = 1 AND hidden = 0 AND status <> 'ARCHIVED'
         AND kind <> 'URGENT' AND created_at >= %s ORDER BY created_at DESC LIMIT 200",
        hp_since_sql()
    ));
}

/** How many team-visible requests arrived after a given UTC datetime. */
function hp_team_new_count($since_utc) {
    global $wpdb;
    $t = hp_tables()['requests'];
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $t WHERE visibility IN ('TEAM','CHURCH_NAME','CHURCH_ANON') AND approved = 1 AND hidden = 0
         AND status <> 'ARCHIVED' AND kind <> 'URGENT' AND created_at >= %s",
        $since_utc
    ));
}

function hp_admin_filters() {
    $me = get_current_user_id();
    return [
        'new'      => ['To follow up', "status = 'NEW' AND (kind <> 'PRAYER' OR contact_me = 1)"],
        'approve'  => ['To approve', "visibility IN ('TEAM','CHURCH_NAME','CHURCH_ANON') AND approved = 0 AND hidden = 0 AND status <> 'ARCHIVED'"],
        'assigned' => ['In progress', "status = 'ASSIGNED'"],
        'mine'     => ['Mine', sprintf("owner_id = %d AND status <> 'ARCHIVED'", $me)],
        'urgent'   => ['Urgent', "kind = 'URGENT' AND status <> 'ARCHIVED'"],
        'reported' => ['Reported', "reported = 1"],
        'all'      => ['All', "status <> 'ARCHIVED'"],
        'archived' => ['Archived', "status = 'ARCHIVED'"],
    ];
}
function hp_admin_requests($filter) {
    global $wpdb;
    $t = hp_tables()['requests'];
    $f = hp_admin_filters()[$filter][1];
    // Work lists sort by what's due first; archive-style lists stay newest-first.
    $order = in_array($filter, ['new', 'approve', 'assigned', 'mine', 'urgent'], true)
        ? 'CASE WHEN due_at IS NULL THEN 1 ELSE 0 END, due_at ASC, created_at ASC'
        : 'created_at DESC';
    return $wpdb->get_results("SELECT * FROM $t WHERE $f ORDER BY $order LIMIT 300");
}
function hp_admin_count($filter) {
    global $wpdb;
    $t = hp_tables()['requests'];
    $f = hp_admin_filters()[$filter][1];
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE $f");
}

/** Everyone who can be an owner: Care role, editors, admins. */
function hp_owner_choices() {
    $users = get_users(['capability' => 'hp_manage', 'orderby' => 'display_name', 'fields' => ['ID', 'display_name']]);
    $out = [];
    foreach ($users as $u) $out[(int) $u->ID] = $u->display_name;
    return $out;
}
function hp_owner_name($id) {
    if (!$id) return '';
    $u = get_userdata((int) $id);
    return $u ? $u->display_name : '';
}

/**
 * Data for the Summary page, aggregated in PHP so it works on any database.
 * Returns ['months' => [...last 6, newest first...], 'ministries' => [...], 'prayers_by_month' => [...]].
 */
function hp_summary_data() {
    global $wpdb;
    $t = hp_tables();
    $tz = new DateTimeZone('America/Moncton');
    $since = gmdate('Y-m-d H:i:s', time() - 190 * DAY_IN_SECONDS);

    $months = [];
    $cursor = new DateTime('first day of this month', $tz);
    for ($i = 0; $i < 6; $i++) {
        $months[$cursor->format('Y-m')] = [
            'label' => $cursor->format('M Y'), 'total' => 0,
            'PRAYER' => 0, 'PASTOR' => 0, 'HELP' => 0, 'URGENT' => 0,
            'done' => 0, 'on_time' => 0, 'prayers' => 0,
        ];
        $cursor->modify('-1 month');
    }

    $reqs = $wpdb->get_results($wpdb->prepare(
        "SELECT created_at, kind, status, ministry, due_at, followed_at FROM {$t['requests']} WHERE created_at >= %s", $since));
    foreach ($reqs as $r) {
        $m = (new DateTime($r->created_at, new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m');
        if (!isset($months[$m])) continue;
        $months[$m]['total']++;
        if (isset($months[$m][$r->kind])) $months[$m][$r->kind]++;
        if (in_array($r->status, ['FOLLOWED_UP', 'ARCHIVED'], true)) $months[$m]['done']++;
        if ($r->followed_at && $r->due_at && $r->followed_at <= $r->due_at) $months[$m]['on_time']++;
    }

    $prayers = $wpdb->get_results($wpdb->prepare(
        "SELECT created_at FROM {$t['prayers']} WHERE created_at >= %s", $since));
    foreach ($prayers as $p) {
        $m = (new DateTime($p->created_at, new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m');
        if (isset($months[$m])) $months[$m]['prayers']++;
    }

    $ministries = [];
    $rows = $wpdb->get_results("SELECT ministry, status FROM {$t['requests']} WHERE ministry IS NOT NULL AND ministry <> ''");
    foreach ($rows as $r) {
        if (!isset($ministries[$r->ministry])) $ministries[$r->ministry] = ['open' => 0, 'done' => 0];
        if (in_array($r->status, ['FOLLOWED_UP', 'ARCHIVED'], true)) $ministries[$r->ministry]['done']++;
        else $ministries[$r->ministry]['open']++;
    }
    ksort($ministries);

    return ['months' => $months, 'ministries' => $ministries];
}

function hp_add_prayer($id) {
    global $wpdb;
    $t = hp_tables();
    $wpdb->insert($t['prayers'], ['created_at' => current_time('mysql', true), 'request_id' => (int) $id]);
    $wpdb->query($wpdb->prepare("UPDATE {$t['requests']} SET prayed_count = prayed_count + 1 WHERE id = %d", $id));
    return (int) $wpdb->get_var($wpdb->prepare("SELECT prayed_count FROM {$t['requests']} WHERE id = %d", $id));
}

/** Simple per-visitor limit so nobody can flood the form or the counter. */
function hp_rate_ok($bucket, $max, $window = HOUR_IN_SECONDS) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
    $key = 'hp_rl_' . $bucket . '_' . md5($ip . wp_salt('nonce'));
    $n = (int) get_transient($key);
    if ($n >= $max) return false;
    set_transient($key, $n + 1, $window);
    return true;
}
