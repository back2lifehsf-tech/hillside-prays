<?php
if (!defined('ABSPATH')) exit;

function hp_care_emails() {
    $list = array_map('trim', explode(',', (string) hp_settings()['care_email']));
    return array_values(array_filter($list, 'is_email'));
}

function hp_mail($to, $subject, $lines) {
    if (!$to) return;
    $body = is_array($lines) ? implode("\n", $lines) : $lines;
    wp_mail($to, $subject, $body);
}

function hp_notify_new_request($id, $d) {
    if ($d['kind'] === 'PRAYER' && !$d['contact_me']) return;
    $prefix = $d['kind'] === 'URGENT' ? 'URGENT: ' : '';
    hp_mail(hp_care_emails(), $prefix . hp_kind_label($d['kind']) . ' — ' . ($d['first_name'] ?: 'No name'), [
        hp_kind_label($d['kind']) . ' from ' . ($d['first_name'] ?: '(no name)'),
        'Contact: ' . ($d['contact'] ?: '(none)'),
        'Wants contact from Care: ' . ($d['contact_me'] ? 'Yes' : 'No'),
        'Who can see it: ' . hp_vis_label($d['visibility']),
        '',
        $d['text'],
        '',
        'Open: ' . admin_url('admin.php?page=hillside-prays'),
    ]);
}

function hp_notify_report($r) {
    hp_mail(hp_care_emails(), 'A prayer request was reported', [
        'This request was reported and is now hidden until someone from Care reviews it:',
        '',
        '"' . $r->text . '"',
        '— ' . hp_display_name($r),
        '',
        'Review: ' . admin_url('admin.php?page=hillside-prays&f=reported'),
    ]);
}

/**
 * Prayer team note: "N new requests — take a few minutes to pray", with a link.
 * No request content in the email, so nothing confidential can be forwarded.
 */
function hp_run_team_digest($force = false) {
    $last = (int) get_option('hp_team_digest_last', 0);
    $since = gmdate('Y-m-d H:i:s', $last ?: time() - 7 * DAY_IN_SECONDS);
    $new = hp_team_new_count($since);
    if (!$new && !$force) return 0;

    $team = get_users(['role' => 'hp_prayer_team', 'fields' => ['user_email']]);
    $emails = array_values(array_filter(array_map(function ($u) { return is_email($u->user_email) ? $u->user_email : null; }, $team)));
    if (!$emails) return -1; // nobody on the team yet

    $active = count(hp_team_requests());
    $subject = $new === 1 ? '1 new prayer request for the team' : "$new new prayer requests for the team";
    if (!$new) $subject = 'Prayer requests are waiting for the team';
    $to = hp_care_emails();
    $headers = ['Bcc: ' . implode(',', $emails)];
    wp_mail($to ? $to[0] : get_option('admin_email'), $subject, implode("\n", [
        'Hi prayer team,',
        '',
        ($new ? ($new === 1 ? 'One new request has' : "$new new requests have") . ' come in since the last note. ' : '')
            . "There " . ($active === 1 ? 'is 1 request' : "are $active requests") . ' to pray for right now.',
        '',
        'Take a few minutes to pray: ' . hp_page_url('team'),
        '',
        'Please keep every request confidential.',
        '',
        'Hillside Prays',
    ]), $headers);
    update_option('hp_team_digest_last', time());
    return $new;
}

/** On digest days (settings), the daily cron also sends the team note. */
add_action('hp_daily_digest', 'hp_maybe_team_digest', 20);
function hp_maybe_team_digest() {
    $days = array_filter(array_map('trim', explode(',', strtolower((string) hp_settings()['team_digest_days']))));
    $today = strtolower(wp_date('D', time(), new DateTimeZone('America/Moncton')));
    if (in_array($today, $days, true)) hp_run_team_digest();
}

/** Once a day: one email per person, "N people prayed for you today". */
add_action('hp_daily_digest', 'hp_run_digest');
function hp_run_digest() {
    global $wpdb;
    $t = hp_tables();
    $rows = $wpdb->get_results(
        "SELECT r.id, r.first_name, r.contact, r.text, r.prayed_count, COUNT(p.id) AS n
           FROM {$t['prayers']} p JOIN {$t['requests']} r ON r.id = p.request_id
          WHERE p.notified = 0 AND r.notify_on_pray = 1
          GROUP BY r.id, r.first_name, r.contact, r.text, r.prayed_count"
    );
    $sent = 0;
    foreach ($rows as $r) {
        if (!is_email(trim((string) $r->contact))) continue;
        $n = (int) $r->n;
        $total = (int) $r->prayed_count;
        $short = mb_strlen($r->text) > 120 ? mb_substr($r->text, 0, 117) . '…' : $r->text;
        hp_mail(trim($r->contact), $n === 1 ? 'Someone prayed for you today' : "$n people prayed for you today", [
            'Hi ' . ($r->first_name ?: 'friend') . ',',
            '',
            ($n === 1 ? 'Someone' : "$n people") . ' from your Hillside family prayed for you today.',
            $total . ($total === 1 ? ' person has' : ' people have') . ' prayed in all for:',
            '"' . $short . '"',
            '',
            "If you'd like to talk with someone, call the church office at 506.857.0832.",
            '',
            'Hillside Prays',
        ]);
        $sent++;
    }
    $wpdb->query("UPDATE {$t['prayers']} SET notified = 1 WHERE notified = 0");
    return $sent;
}
