<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    $count = hp_admin_count('new') + hp_admin_count('urgent') + hp_admin_count('approve');
    $bubble = $count ? ' <span class="awaiting-mod">' . (int) $count . '</span>' : '';
    add_menu_page('Hillside Prays', 'Hillside Prays' . $bubble, 'hp_manage', 'hillside-prays', 'hp_admin_page', 'dashicons-heart', 26);
    add_submenu_page('hillside-prays', 'Requests', 'Requests', 'hp_manage', 'hillside-prays', 'hp_admin_page');
    add_submenu_page('hillside-prays', 'Care summary', 'Summary', 'hp_manage', 'hillside-prays-summary', 'hp_summary_page');
    add_submenu_page('hillside-prays', 'Hillside Prays settings', 'Settings', 'manage_options', 'hillside-prays-settings', 'hp_settings_page');
});

add_action('admin_post_hp_admin', function () {
    if (!current_user_can('hp_manage')) wp_die('Not allowed');
    check_admin_referer('hp_admin');
    $id = (int) ($_POST['id'] ?? 0);
    $do = sanitize_key($_POST['do'] ?? '');
    $map = [
        'followed' => ['status' => 'FOLLOWED_UP'],
        'archive'  => ['status' => 'ARCHIVED'],
        'restore'  => ['status' => 'NEW'],
        'hide'     => ['hidden' => 1],
        'show'     => ['hidden' => 0, 'reported' => 0],
        'approve'  => ['approved' => 1],
        'unapprove'=> ['approved' => 0],
    ];
    if ($id && isset($map[$do])) hp_update_request($id, $map[$do]);

    // A request that came by phone, Connect Card or email: staff types it into the same log.
    if ($do === 'add') {
        $kind = strtoupper(sanitize_key($_POST['kind'] ?? 'PRAYER'));
        if (!in_array($kind, HP_KINDS, true)) $kind = 'PRAYER';
        $vis = strtoupper(sanitize_key($_POST['visibility'] ?? 'PRIVATE'));
        if (!in_array($vis, ['PRIVATE', 'TEAM'], true)) $vis = 'PRIVATE';
        $source = sanitize_key($_POST['source'] ?? 'other');
        if (!in_array($source, ['card', 'email', 'phone', 'person', 'other'], true)) $source = 'other';
        $text = mb_substr(trim(sanitize_textarea_field(wp_unslash($_POST['text'] ?? ''))), 0, 1500);
        if ($text) {
            hp_insert_request([
                'kind' => $kind, 'visibility' => $vis, 'text' => $text,
                'first_name' => mb_substr(trim(sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''))), 0, 40) ?: null,
                'contact' => mb_substr(trim(sanitize_text_field(wp_unslash($_POST['contact'] ?? ''))), 0, 120) ?: null,
                'notify_on_pray' => 0, 'contact_me' => !empty($_POST['contact_me']),
                'source' => $source,
            ]);
        }
    }

    if ($id && $do === 'care') {
        $r = hp_get_request($id);
        $ministry = sanitize_text_field(wp_unslash($_POST['ministry'] ?? ''));
        if ($ministry && !in_array($ministry, hp_ministries(), true)) $ministry = '';
        $owner = (int) ($_POST['owner_id'] ?? 0);
        if ($owner && !array_key_exists($owner, hp_owner_choices())) $owner = 0;
        $follow = trim((string) ($_POST['follow_up'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $follow)) $follow = '';
        $fields = [
            'ministry'   => $ministry ?: null,
            'owner_id'   => $owner ?: null,
            'follow_up'  => $follow ?: null,
            'care_notes' => mb_substr(trim(sanitize_textarea_field(wp_unslash($_POST['care_notes'] ?? ''))), 0, 2000) ?: null,
        ];
        // The Prayer lead can reword the request before the team sees it.
        $new_text = mb_substr(trim(sanitize_textarea_field(wp_unslash($_POST['request_text'] ?? ''))), 0, 1500);
        if ($new_text !== '') $fields['text'] = $new_text;
        // Assigning it moves a new request to In progress.
        if ($r && $r->status === 'NEW' && ($ministry || $owner)) $fields['status'] = 'ASSIGNED';
        hp_update_request($id, $fields);
    }

    if ($do === 'digest' && current_user_can('manage_options')) {
        $n = hp_run_digest();
        wp_safe_redirect(add_query_arg(['page' => 'hillside-prays-settings', 'sent' => $n], admin_url('admin.php')));
        exit;
    }
    if ($do === 'teamdigest' && current_user_can('manage_options')) {
        $n = hp_run_team_digest(true);
        wp_safe_redirect(add_query_arg(['page' => 'hillside-prays-settings', 'tsent' => $n], admin_url('admin.php')));
        exit;
    }
    $f = sanitize_key($_POST['f'] ?? 'new');
    wp_safe_redirect(add_query_arg(['page' => 'hillside-prays', 'f' => $f], admin_url('admin.php')));
    exit;
});

function hp_admin_button($id, $do, $label, $f, $primary = false) {
    $out = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
    $out .= wp_nonce_field('hp_admin', '_wpnonce', true, false);
    $out .= '<input type="hidden" name="action" value="hp_admin"><input type="hidden" name="id" value="' . (int) $id . '">';
    $out .= '<input type="hidden" name="do" value="' . esc_attr($do) . '"><input type="hidden" name="f" value="' . esc_attr($f) . '">';
    $out .= '<button class="button' . ($primary ? ' button-primary' : '') . '">' . esc_html($label) . '</button></form> ';
    return $out;
}

function hp_admin_page() {
    if (!current_user_can('hp_manage')) return;
    $filters = hp_admin_filters();
    $f = isset($_GET['f']) && isset($filters[$_GET['f']]) ? sanitize_key($_GET['f']) : 'new';
    $rows = hp_admin_requests($f);
    $tz = new DateTimeZone('America/Moncton');
    $today = wp_date('Y-m-d', time(), $tz);
    $owners = hp_owner_choices();
    $ministries = hp_ministries();
    ?>
    <div class="wrap hp-admin">
        <style>
            .hp-admin .hp-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;margin:0 0 12px;max-width:820px}
            .hp-admin .hp-card.urgent{border-left:4px solid #b3261e}
            .hp-admin .hp-meta{display:flex;flex-wrap:wrap;gap:6px;align-items:center;color:#50575e;font-size:13px}
            .hp-admin .hp-meta b{color:#1d2327;font-size:14px}
            .hp-admin .tag{display:inline-block;padding:1px 8px;border-radius:4px;background:#eef3fa;color:#23467a;font-size:12px;font-weight:600}
            .hp-admin .tag.red{background:#fdecea;color:#b3261e}.hp-admin .tag.gray{background:#f0f0f1;color:#3c434a}
            .hp-admin .tag.green{background:#e5f4e4;color:#1e5c2a}.hp-admin .tag.amber{background:#fdf3e0;color:#8a5a00}
            .hp-admin .hp-text{font-size:15px;line-height:1.55;margin:8px 0;white-space:pre-wrap}
            .hp-admin .hp-btns{margin-top:8px}
            .hp-admin details.hp-assign{margin-top:10px;border-top:1px solid #f0f0f1;padding-top:8px}
            .hp-admin details.hp-assign summary{cursor:pointer;color:#2271b1;font-weight:600}
            .hp-admin .hp-assign-grid{display:flex;flex-wrap:wrap;gap:10px;margin:10px 0;align-items:flex-end}
            .hp-admin .hp-assign-grid label{display:block;font-size:12px;color:#50575e;margin-bottom:2px}
            .hp-admin .hp-assign textarea{width:100%;max-width:640px}
            .hp-admin .hp-add{background:#fff;border:1px dashed #8c8f94;border-radius:8px;padding:12px 16px;margin:0 0 16px;max-width:820px}
            .hp-admin .hp-add summary{cursor:pointer;font-weight:600;color:#2271b1}
            .hp-admin .hp-add .hp-assign-grid{display:flex;flex-wrap:wrap;gap:10px;margin:10px 0;align-items:flex-end}
            .hp-admin .hp-add label{display:block;font-size:12px;color:#50575e;margin-bottom:2px}
            .hp-admin .hp-add textarea,.hp-admin .hp-add input[type=text]{width:100%;max-width:640px}
        </style>
        <h1 class="wp-heading-inline">Hillside Prays</h1>
        <?php $page = (int) get_option('hp_page_id'); if ($page): ?>
            <a class="page-title-action" href="<?php echo esc_url(get_permalink($page)); ?>" target="_blank">View page</a>
            <?php if (get_post_status($page) !== 'publish'): ?>
                <div class="notice notice-warning inline"><p>The <b>Pray</b> page is still a draft. <a href="<?php echo esc_url(get_edit_post_link($page)); ?>">Publish it</a> when you're ready.</p></div>
            <?php endif; ?>
        <?php endif; ?>
        <p>Every request, including private and urgent ones. The prayer team sees theirs at <a href="<?php echo esc_url(hp_page_url('team')); ?>" target="_blank">the team page</a>.</p>
        <details class="hp-add">
            <summary>+ Add a request (a call, a Connect Card, an email, in person)</summary>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('hp_admin'); ?>
                <input type="hidden" name="action" value="hp_admin"><input type="hidden" name="do" value="add">
                <input type="hidden" name="f" value="<?php echo esc_attr($f); ?>">
                <div class="hp-assign-grid">
                    <div><label>How it came in</label>
                        <select name="source">
                            <option value="phone">By phone</option><option value="card">Connect Card</option>
                            <option value="email">By email</option><option value="person">In person</option><option value="other">Other</option>
                        </select></div>
                    <div><label>What they need</label>
                        <select name="kind">
                            <option value="PRAYER">Prayer</option><option value="PASTOR">Talk with a pastor</option>
                            <option value="HELP">Practical help</option><option value="URGENT">Something urgent</option>
                        </select></div>
                    <div><label>Who can see it</label>
                        <select name="visibility"><option value="PRIVATE">Pastors and Care only</option><option value="TEAM">Prayer team (after approval)</option></select></div>
                    <div><label>First name</label><input type="text" name="first_name" maxlength="40" style="width:130px"></div>
                    <div><label>Email or phone</label><input type="text" name="contact" maxlength="120" style="width:170px"></div>
                </div>
                <label>The request</label>
                <textarea name="text" rows="2" maxlength="1500" required></textarea>
                <p><label><input type="checkbox" name="contact_me" checked> They'd like someone to reach out</label></p>
                <p><button class="button button-primary">Add to the log</button></p>
            </form>
        </details>
        <ul class="subsubsub">
            <?php $i = 0; foreach ($filters as $k => $v): ?>
                <li><?php echo $i++ ? ' | ' : ''; ?><a href="<?php echo esc_url(add_query_arg(['page' => 'hillside-prays', 'f' => $k], admin_url('admin.php'))); ?>" class="<?php echo $k === $f ? 'current' : ''; ?>"><?php echo esc_html($v[0]); ?>
                    <?php if (in_array($k, ['new', 'approve', 'assigned', 'mine', 'urgent', 'reported'], true)): ?><span class="count">(<?php echo hp_admin_count($k); ?>)</span><?php endif; ?></a></li>
            <?php endforeach; ?>
        </ul>
        <div style="clear:both;padding-top:12px">
            <?php if (!$rows): ?><p>Nothing here.</p><?php endif; ?>
            <?php foreach ($rows as $r):
                $when = (new DateTime($r->created_at, new DateTimeZone('UTC')))->setTimezone($tz)->format('M j, Y g:i a');
                $overdue = $r->follow_up && $r->follow_up < $today && !in_array($r->status, ['FOLLOWED_UP', 'ARCHIVED'], true); ?>
                <div class="hp-card<?php echo $r->kind === 'URGENT' ? ' urgent' : ''; ?>">
                    <div class="hp-meta">
                        <b><?php echo esc_html($r->first_name ?: 'No name'); ?></b>
                        <span>· <?php echo esc_html(hp_ago($r->created_at)); ?></span>
                        <span class="tag<?php echo $r->kind === 'URGENT' ? ' red' : ''; ?>"><?php echo esc_html(hp_kind_label($r->kind)); ?></span>
                        <span class="tag gray"><?php echo esc_html(hp_vis_label($r->visibility)); ?></span>
                        <?php if ($r->ministry): ?><span class="tag green"><?php echo esc_html($r->ministry); ?></span><?php endif; ?>
                        <?php if ($r->owner_id): ?><span class="tag"><?php echo esc_html(hp_owner_name($r->owner_id)); ?></span><?php endif; ?>
                        <?php if ($r->follow_up): ?><span class="tag<?php echo $overdue ? ' red' : ' amber'; ?>">Follow up <?php echo esc_html(wp_date('M j', strtotime($r->follow_up), $tz)); ?><?php echo $overdue ? ' — overdue' : ''; ?></span><?php endif; ?>
                        <?php if ($r->due_at && in_array($r->status, ['NEW', 'ASSIGNED'], true)):
                            $due_ts = strtotime($r->due_at . ' UTC'); $late = $due_ts < time(); ?>
                            <span class="tag<?php echo $late ? ' red' : ' amber'; ?>"><?php echo $late ? 'Respond — overdue' : 'Respond by ' . esc_html(wp_date('D g:i a', $due_ts, $tz)); ?></span>
                        <?php endif; ?>
                        <?php if (in_array($r->visibility, ['TEAM', 'CHURCH_NAME', 'CHURCH_ANON'], true) && !$r->approved): ?><span class="tag amber">Waiting for approval</span><?php endif; ?>
                        <?php if (($r->source ?? 'web') !== 'web'): ?><span class="tag gray"><?php echo esc_html(['card' => 'Connect Card', 'email' => 'By email', 'phone' => 'By phone', 'person' => 'In person', 'other' => 'Added by staff'][$r->source] ?? $r->source); ?></span><?php endif; ?>
                        <?php if ($r->contact_me): ?><span class="tag">Wants contact</span><?php endif; ?>
                        <?php if ($r->reported): ?><span class="tag red">Reported</span><?php endif; ?>
                        <?php if ($r->hidden): ?><span class="tag red">Hidden</span><?php endif; ?>
                        <?php if (in_array($r->status, ['ASSIGNED', 'FOLLOWED_UP'], true)): ?><span class="tag gray"><?php echo esc_html(hp_status_label($r->status)); ?></span><?php endif; ?>
                    </div>
                    <div class="hp-text"><?php echo esc_html($r->text); ?></div>
                    <?php if ($r->care_notes): ?><div class="hp-meta" style="white-space:pre-wrap"><span><b>Care notes:</b> <?php echo esc_html($r->care_notes); ?></span></div><?php endif; ?>
                    <div class="hp-meta">
                        <span>Contact: <?php echo esc_html($r->contact ?: 'none'); ?></span>
                        <span>· <?php echo (int) $r->prayed_count; ?> prayed</span>
                        <span>· <?php echo esc_html($when); ?></span>
                    </div>
                    <div class="hp-btns">
                        <?php
                        if (in_array($r->visibility, ['TEAM', 'CHURCH_NAME', 'CHURCH_ANON'], true)) {
                            echo $r->approved
                                ? hp_admin_button($r->id, 'unapprove', 'Take off the team page', $f)
                                : hp_admin_button($r->id, 'approve', 'Approve for the team', $f, true);
                        }
                        if (in_array($r->status, ['NEW', 'ASSIGNED'], true)) echo hp_admin_button($r->id, 'followed', 'Mark followed up', $f, $r->approved || $r->visibility === 'PRIVATE');
                        echo $r->hidden ? hp_admin_button($r->id, 'show', 'Show again', $f) : hp_admin_button($r->id, 'hide', 'Hide', $f);
                        echo $r->status === 'ARCHIVED' ? hp_admin_button($r->id, 'restore', 'Restore', $f) : hp_admin_button($r->id, 'archive', 'Archive', $f);
                        ?>
                    </div>
                    <details class="hp-assign" <?php echo ($f === 'new' && !$r->ministry && $r->kind !== 'PRAYER') ? 'open' : ''; ?>>
                        <summary><?php echo $r->ministry || $r->owner_id ? 'Assignment & log' : 'Assign to a ministry'; ?></summary>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('hp_admin'); ?>
                            <input type="hidden" name="action" value="hp_admin"><input type="hidden" name="do" value="care">
                            <input type="hidden" name="id" value="<?php echo (int) $r->id; ?>"><input type="hidden" name="f" value="<?php echo esc_attr($f); ?>">
                            <div class="hp-assign-grid">
                                <div><label>Ministry</label>
                                    <select name="ministry"><option value="">—</option>
                                    <?php foreach ($ministries as $m): ?><option <?php selected($r->ministry, $m); ?>><?php echo esc_html($m); ?></option><?php endforeach; ?>
                                    </select></div>
                                <div><label>Owner</label>
                                    <select name="owner_id"><option value="0">—</option>
                                    <?php foreach ($owners as $oid => $oname): ?><option value="<?php echo (int) $oid; ?>" <?php selected((int) $r->owner_id, $oid); ?>><?php echo esc_html($oname); ?></option><?php endforeach; ?>
                                    </select></div>
                                <div><label>Follow up on</label>
                                    <input type="date" name="follow_up" value="<?php echo esc_attr($r->follow_up ?: ''); ?>"></div>
                            </div>
                            <label style="display:block;font-size:12px;color:#50575e;margin-bottom:2px">Request text (you can reword it before the team sees it)</label>
                            <textarea name="request_text" rows="3" maxlength="1500" class="hp-assign-text"><?php echo esc_textarea($r->text); ?></textarea>
                            <label style="display:block;font-size:12px;color:#50575e;margin:8px 0 2px">Care notes (only Care sees these)</label>
                            <textarea name="care_notes" rows="2" maxlength="2000"><?php echo esc_textarea($r->care_notes ?: ''); ?></textarea>
                            <p><button class="button">Save</button></p>
                        </form>
                    </details>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php }

function hp_summary_page() {
    if (!current_user_can('hp_manage')) return;
    $d = hp_summary_data();
    ?>
    <div class="wrap">
        <h1>Care summary</h1>
        <p>Requests through the Pray page, for the monthly report. Open = new or in progress; done = followed up or archived.</p>
        <h2>By month</h2>
        <table class="widefat striped" style="max-width:820px">
            <thead><tr><th>Month</th><th>Requests</th><th>Prayer</th><th>Pastor</th><th>Practical help</th><th>Urgent</th><th>Done</th><th>On time</th><th>"I prayed" taps</th></tr></thead>
            <tbody>
            <?php foreach ($d['months'] as $m): ?>
                <tr><td><b><?php echo esc_html($m['label']); ?></b></td>
                    <td><?php echo (int) $m['total']; ?></td>
                    <td><?php echo (int) $m['PRAYER']; ?></td>
                    <td><?php echo (int) $m['PASTOR']; ?></td>
                    <td><?php echo (int) $m['HELP']; ?></td>
                    <td><?php echo (int) $m['URGENT']; ?></td>
                    <td><?php echo (int) $m['done']; ?></td>
                    <td><?php echo (int) $m['on_time']; ?></td>
                    <td><?php echo (int) $m['prayers']; ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <h2>By ministry</h2>
        <?php if (!$d['ministries']): ?><p>Nothing assigned to a ministry yet. Assign requests from the Requests page.</p>
        <?php else: ?>
        <table class="widefat striped" style="max-width:520px">
            <thead><tr><th>Ministry</th><th>Open</th><th>Done</th></tr></thead>
            <tbody>
            <?php foreach ($d['ministries'] as $name => $m): ?>
                <tr><td><b><?php echo esc_html($name); ?></b></td><td><?php echo (int) $m['open']; ?></td><td><?php echo (int) $m['done']; ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
<?php }

add_action('admin_init', function () {
    register_setting('hp_settings', 'hp_settings', [
        'type' => 'array',
        'sanitize_callback' => function ($in) {
            $emails = array_filter(array_map('sanitize_email', array_map('trim', explode(',', (string) ($in['care_email'] ?? '')))));
            $valid_days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
            $days = array_values(array_intersect($valid_days, array_map('sanitize_key', (array) ($in['team_digest_days'] ?? []))));
            $ministries = array_filter(array_map(function ($m) { return trim(sanitize_text_field($m)); }, explode(',', (string) ($in['ministries'] ?? ''))));
            return [
                'care_email'       => implode(', ', $emails),
                'feed_days'        => max(1, min(365, (int) ($in['feed_days'] ?? 30))),
                'church_public'    => empty($in['church_public']) ? 0 : 1,
                'ministries'       => implode(', ', $ministries) ?: hp_default_settings()['ministries'],
                'team_digest_days' => implode(',', $days),
                'sla_urgent'       => max(1, min(72, (int) ($in['sla_urgent'] ?? 2))),
                'sla_soon'         => max(1, min(168, (int) ($in['sla_soon'] ?? 24))),
                'sla_help'         => max(1, min(168, (int) ($in['sla_help'] ?? 48))),
            ];
        },
    ]);
});

function hp_settings_page() {
    if (!current_user_can('manage_options')) return;
    $s = hp_settings();
    $next = wp_next_scheduled('hp_daily_digest');
    $digest_days = array_filter(array_map('trim', explode(',', strtolower((string) $s['team_digest_days']))));
    $team_n = count(get_users(['role' => 'hp_prayer_team', 'fields' => 'ID']));
    ?>
    <div class="wrap">
        <h1>Hillside Prays settings</h1>
        <?php if (isset($_GET['sent'])): ?><div class="notice notice-success"><p>Daily summary sent to <?php echo (int) $_GET['sent']; ?> people.</p></div><?php endif; ?>
        <?php if (isset($_GET['tsent'])): ?><div class="notice notice-success"><p><?php echo (int) $_GET['tsent'] === -1 ? 'Nobody has the Prayer Team role yet, so no team note was sent.' : 'Team note sent (' . (int) $_GET['tsent'] . ' new requests).'; ?></p></div><?php endif; ?>
        <form method="post" action="options.php">
            <?php settings_fields('hp_settings'); ?>
            <table class="form-table" role="presentation">
                <tr><th scope="row"><label for="hp-care">Care email</label></th>
                    <td><input id="hp-care" name="hp_settings[care_email]" class="regular-text" value="<?php echo esc_attr($s['care_email']); ?>">
                    <p class="description">Gets urgent, pastor, practical help, "contact me" and reported alerts. Separate several with commas.</p></td></tr>
                <tr><th scope="row"><label for="hp-days">Show requests for</label></th>
                    <td><input id="hp-days" type="number" min="1" max="365" name="hp_settings[feed_days]" value="<?php echo (int) $s['feed_days']; ?>" style="width:80px"> days
                    <p class="description">On the team page, and in Pray for others when it's on.</p></td></tr>
                <tr><th scope="row">Pray for others (whole church)</th>
                    <td><label><input type="checkbox" name="hp_settings[church_public]" value="1" <?php checked(!empty($s['church_public'])); ?>>
                        Let people share a request with the whole church (first name or no name)</label>
                    <p class="description">Off during the pilot: the form only offers "Pastors and Care" or "Prayer team", and the Pray for others page says it's coming. Turn it on when leadership says yes — nothing else changes.</p></td></tr>
                <tr><th scope="row"><label for="hp-min">Care ministries</label></th>
                    <td><input id="hp-min" name="hp_settings[ministries]" class="large-text" value="<?php echo esc_attr($s['ministries']); ?>">
                    <p class="description">The list you assign requests to, separated by commas.</p></td></tr>
                <tr><th scope="row">Respond within</th>
                    <td>
                        Urgent: <input type="number" min="1" max="72" name="hp_settings[sla_urgent]" value="<?php echo (int) $s['sla_urgent']; ?>" style="width:60px"> hours ·
                        Prayer / pastor: <input type="number" min="1" max="168" name="hp_settings[sla_soon]" value="<?php echo (int) $s['sla_soon']; ?>" style="width:60px"> hours ·
                        Practical help: <input type="number" min="1" max="168" name="hp_settings[sla_help]" value="<?php echo (int) $s['sla_help']; ?>" style="width:60px"> hours
                    <p class="description">Every request gets a "respond by" time from these. Overdue ones show in red and sort to the top. Responding means a human made contact — then "Mark followed up".</p></td></tr>
                <tr><th scope="row">Team note days</th>
                    <td><?php foreach (['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'] as $k => $l): ?>
                        <label style="margin-right:10px"><input type="checkbox" name="hp_settings[team_digest_days][]" value="<?php echo $k; ?>" <?php checked(in_array($k, $digest_days, true)); ?>> <?php echo $l; ?></label>
                    <?php endforeach; ?>
                    <p class="description">On these days, everyone with the Prayer Team role (<?php echo (int) $team_n; ?> right now) gets one email: how many new requests there are and a link to the team page — never the requests themselves. Sent only when something is new.</p></td></tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <h2>Daily summary email</h2>
        <p>Once a day, each person who asked gets one email: "3 people prayed for you today".
            Next run: <b><?php echo $next ? esc_html(wp_date('M j, g:i a', $next, new DateTimeZone('America/Moncton'))) : 'not scheduled — deactivate and activate the plugin'; ?></b>.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px">
            <?php wp_nonce_field('hp_admin'); ?>
            <input type="hidden" name="action" value="hp_admin"><input type="hidden" name="do" value="digest">
            <button class="button">Send today's summary now</button>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
            <?php wp_nonce_field('hp_admin'); ?>
            <input type="hidden" name="action" value="hp_admin"><input type="hidden" name="do" value="teamdigest">
            <button class="button">Send the team note now</button>
        </form>
        <h2>Who can see what</h2>
        <ul style="list-style:disc;padding-left:20px">
            <li><b>Hillside Care</b> role (and Administrators, Editors): every request, under Hillside Prays in this menu. They can be assigned as owners.</li>
            <li><b>Prayer Team</b> role: log in and they land on the team page. They see "prayer team" and whole-church requests, never private or urgent ones.</li>
            <li>Add people under <a href="<?php echo esc_url(admin_url('user-new.php')); ?>">Users → Add New</a> and pick the role.</li>
        </ul>
    </div>
<?php }
