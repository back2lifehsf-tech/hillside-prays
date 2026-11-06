<?php
if (!defined('ABSPATH')) exit;

add_shortcode('hillside_prays', 'hp_shortcode');
add_action('admin_post_hp_submit', 'hp_handle_submit');
add_action('admin_post_nopriv_hp_submit', 'hp_handle_submit');

/** Prayer team members land on their page after logging in. */
add_filter('login_redirect', function ($to, $requested, $user) {
    if ($user instanceof WP_User && in_array('hp_prayer_team', (array) $user->roles, true) && !$user->has_cap('hp_manage')) {
        return hp_page_url('team');
    }
    return $to;
}, 10, 3);

function hp_enqueue() {
    wp_enqueue_style('hillside-prays', HP_URL . 'assets/hp.css', [], HP_VERSION);
    wp_enqueue_script('hillside-prays', HP_URL . 'assets/hp.js', [], HP_VERSION, true);
    wp_localize_script('hillside-prays', 'HP', [
        'api'   => esc_url_raw(rest_url('hillside-prays/v1/')),
        'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
        'home'  => hp_page_url(),
        'ask'   => hp_page_url('ask'),
    ]);
}

function hp_link($view = '', $args = []) {
    return esc_url(hp_page_url($view, $args));
}

function hp_back($title) {
    return '<a class="hp-back" href="' . hp_link() . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg><span>' . esc_html($title) . '</span></a>';
}

function hp_crisis($extra = '') {
    return '<p class="hp-note">In danger right now? Call <b>911</b>. Thinking about suicide? Call or text <b>9-8-8</b>.' . ($extra ? ' ' . esc_html($extra) : '') . '</p>';
}

function hp_shortcode() {
    // Remember which page holds the app, so links and emails point here.
    if (is_singular() && (int) get_option('hp_page_id') !== get_the_ID()) update_option('hp_page_id', get_the_ID());
    hp_enqueue();
    $view = isset($_GET['hp']) ? sanitize_key($_GET['hp']) : '';
    ob_start();
    echo '<div class="hp-app">';
    switch ($view) {
        case 'ask':    hp_view_ask(); break;
        case 'thanks': hp_view_thanks(); break;
        case 'others': hp_view_others(); break;
        case 'team':   hp_view_team(); break;
        default:       hp_view_home();
    }
    echo '</div>';
    return ob_get_clean();
}

function hp_view_home() { ?>
    <section class="hp-hero">
        <div class="hp-kicker">Pray</div>
        <h2>We'd love to pray with you.</h2>
    </section>
    <div class="hp-pad hp-stack">
        <p class="hp-lead"><?php echo hp_church_on() ? "Share what's on your heart, or take a minute to pray for someone in our church family." : "Share what's on your heart. Our pastors, Care team and prayer team would love to pray with you."; ?></p>
        <a class="hp-btn hp-primary" href="<?php echo hp_link('ask'); ?>">Ask for prayer</a>
        <?php if (hp_church_on()): ?><a class="hp-btn hp-secondary" href="<?php echo hp_link('others'); ?>">Pray for others</a><?php endif; ?>
    </div>
    <div class="hp-info">
        <b>Want to talk with a pastor?</b><br>
        Choose it on the form, or call the church office at <a href="tel:+15068570832">506.857.0832</a>.
    </div>
    <div class="hp-pad"><?php echo hp_crisis(); ?></div>
<?php }

function hp_view_ask() {
    $errors = [
        'kind' => 'Please choose what you need.',
        'vis' => 'Please choose who can see this.',
        'text' => 'Please tell us what we can pray for.',
        'long' => 'Please keep it under 1500 characters.',
        'contact' => 'Please leave an email or phone so we can reach you.',
        'slow' => 'You have sent a few requests already. Please try again in an hour, or call 506.857.0832.',
    ];
    $err = isset($_GET['hp_err']) ? sanitize_key($_GET['hp_err']) : '';
    $kinds = ['PRAYER' => 'Prayer', 'PASTOR' => 'Talk with a pastor', 'HELP' => 'Practical help', 'URGENT' => 'Something urgent'];
    $vis = [
        'PRIVATE' => ['Pastors and Care team only', 'Private. Not shared with anyone else.'],
        'TEAM' => ['The prayer team', 'A team who pray through the week and keep it confidential.'],
    ];
    if (hp_church_on()) {
        $vis['CHURCH_NAME'] = ['The whole church, first name only', 'Shown in Pray for others.'];
        $vis['CHURCH_ANON'] = ['The whole church, no name', 'Shown in Pray for others, without your name.'];
    }
    ?>
    <div class="hp-bar"><?php echo hp_back('Ask for prayer'); ?></div>
    <form class="hp-pad hp-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-hp-ask>
        <input type="hidden" name="action" value="hp_submit">
        <fieldset class="hp-group">
            <legend class="hp-q">1. What do you need?</legend>
            <div class="hp-chips">
                <?php $i = 0; foreach ($kinds as $k => $l): ?>
                    <label class="hp-chip<?php echo $k === 'URGENT' ? ' hp-urgent' : ''; ?>">
                        <input type="radio" name="kind" value="<?php echo esc_attr($k); ?>" <?php checked($i++ === 0); ?>>
                        <span><?php echo esc_html($l); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="hp-alert" data-hp-urgent-note hidden>This goes straight to a pastor and is never shared. If someone is in danger right now, call 911.</div>
            <div class="hp-group" data-hp-help hidden style="margin:10px 0 0">
                <label class="hp-lbl" for="hp-help-kind">What kind of help? (optional — it gets your request to the right person faster)</label>
                <select id="hp-help-kind" name="help_kind" class="hp-input">
                    <option value="">I'm not sure</option>
                    <option value="groceries">Groceries or help with bills</option>
                    <option value="meals">Meals (new baby, illness, a hard season)</option>
                    <option value="hospital">A visit in the hospital</option>
                    <option value="visit">A visit at home</option>
                </select>
            </div>
        </fieldset>

        <div class="hp-group">
            <label class="hp-q" for="hp-text" data-hp-text-label>2. What can we pray for?</label>
            <textarea id="hp-text" name="text" rows="5" required maxlength="1500" class="hp-input" placeholder="Share as much or as little as you like."></textarea>
            <p class="hp-note">Please don't share private details about other people without their permission.</p>
        </div>

        <fieldset class="hp-group">
            <legend class="hp-q">3. Who can see this?</legend>
            <?php $i = 0; foreach ($vis as $v => $l): ?>
                <label class="hp-radio">
                    <input type="radio" name="visibility" value="<?php echo esc_attr($v); ?>" <?php checked($i++ === 0); ?>>
                    <span><b><?php echo esc_html($l[0]); ?></b><small><?php echo esc_html($l[1]); ?></small></span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <div class="hp-group">
            <div class="hp-q">4. About you <span data-hp-optional>(optional)</span></div>
            <label class="hp-lbl" for="hp-first">First name</label>
            <input id="hp-first" name="first_name" class="hp-input" autocomplete="given-name" maxlength="40">
            <label class="hp-lbl" for="hp-contact">Email or phone</label>
            <input id="hp-contact" name="contact" class="hp-input" autocomplete="email" maxlength="120">
            <label class="hp-check"><input type="checkbox" name="notify_on_pray" checked> <span>Email me when people pray for me</span></label>
            <label class="hp-check"><input type="checkbox" name="contact_me" data-hp-contact-me> <span>Someone from Care may contact me</span></label>
        </div>

        <div class="hp-hpot" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>

        <?php if ($err && isset($errors[$err])): ?><div class="hp-alert" role="alert"><?php echo esc_html($errors[$err]); ?></div><?php endif; ?>

        <button type="submit" class="hp-btn hp-primary">Send my request</button>
        <?php echo hp_crisis('Urgent requests go straight to a pastor and are never shared.'); ?>
    </form>
<?php }

function hp_handle_submit() {
    $back = function ($code) { wp_safe_redirect(hp_page_url('ask', ['hp_err' => $code])); exit; };

    if (!empty($_POST['website'])) { wp_safe_redirect(hp_page_url('thanks')); exit; }

    $kind = strtoupper(sanitize_key($_POST['kind'] ?? ''));
    $visibility = strtoupper(sanitize_key($_POST['visibility'] ?? ''));
    $text = trim(sanitize_textarea_field(wp_unslash($_POST['text'] ?? '')));
    $first = mb_substr(trim(sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''))), 0, 40) ?: null;
    $contact = mb_substr(trim(sanitize_text_field(wp_unslash($_POST['contact'] ?? ''))), 0, 120) ?: null;
    $notify = !empty($_POST['notify_on_pray']);
    $contact_me = !empty($_POST['contact_me']) || ($kind !== 'PRAYER');

    if (!in_array($kind, HP_KINDS, true)) $back('kind');
    if ($kind === 'URGENT') $visibility = 'PRIVATE';
    $allowed_vis = hp_church_on() ? HP_VIS : ['PRIVATE', 'TEAM'];
    if (!in_array($visibility, $allowed_vis, true)) $back('vis');
    if (mb_strlen($text) < 3) $back('text');
    if (mb_strlen($text) > 1500) $back('long');
    if ($contact_me && !$contact) $back('contact');
    if (!hp_rate_ok('submit', 6)) $back('slow');

    $d = ['kind' => $kind, 'visibility' => $visibility, 'text' => $text, 'first_name' => $first,
          'contact' => $contact, 'notify_on_pray' => $notify, 'contact_me' => $contact_me,
          'help_kind' => sanitize_key($_POST['help_kind'] ?? ''), 'source' => 'web'];
    $id = hp_insert_request($d);
    hp_notify_new_request($id, $d);

    // Prefixed names: WordPress itself uses ?name= and ?p= in URLs.
    $q = [];
    if ($first) $q['hp_name'] = $first;
    if ($notify && is_email((string) $contact)) $q['hp_e'] = 1;
    if ($contact_me) $q['hp_c'] = 1;
    if ($kind === 'URGENT') $q['hp_u'] = 1;
    if ($visibility === 'PRIVATE') $q['hp_p'] = 1;
    wp_safe_redirect(hp_page_url('thanks', $q));
    exit;
}

function hp_view_thanks() {
    $name = isset($_GET['hp_name']) ? sanitize_text_field(wp_unslash($_GET['hp_name'])) : '';
    $u = !empty($_GET['hp_u']); $p = !empty($_GET['hp_p']); $e = !empty($_GET['hp_e']); $c = !empty($_GET['hp_c']);
    ?>
    <div class="hp-pad hp-stack hp-center" style="padding-top:48px">
        <div class="hp-circle"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>
        <h2 class="hp-big">Thank you<?php echo $name ? ', ' . esc_html($name) : ''; ?>.</h2>
        <p class="hp-sub"><?php echo $u ? 'A pastor has your request.' : ($p ? 'Our pastors and Care team will pray for you.' : 'Your church family will pray for you.'); ?></p>
        <p class="hp-note hp-note-lg">
            <?php if ($e && !$p) echo "We'll email you once a day when people pray for you. "; ?>
            <?php if ($c) echo 'Someone from Care will reach out within 2 days.'; ?>
            <?php if ($u) echo ' If anyone is in danger right now, call 911.'; ?>
        </p>
    </div>
    <div class="hp-pad hp-stack">
        <a class="hp-btn hp-primary" href="<?php echo hp_link('others'); ?>">Pray for someone else</a>
        <a class="hp-btn hp-secondary" href="<?php echo hp_link(); ?>">Done</a>
    </div>
<?php }

function hp_view_others() {
    if (!hp_church_on()) { ?>
        <div class="hp-pad hp-stack">
            <h2 class="hp-big">Pray for others</h2>
            <p class="hp-lead">This part is coming soon. For now, our prayer team prays through every request during the week.</p>
            <a class="hp-btn hp-primary" href="<?php echo hp_link('ask'); ?>">Ask for prayer</a>
        </div>
    <?php return; } ?>
    <div class="hp-soft">
        <div class="hp-bar"><?php echo hp_back('Pray for others'); ?><span class="hp-note hp-strong" data-hp-pos></span></div>
        <div class="hp-progress"><div data-hp-progress></div></div>
        <div data-hp-deck><p class="hp-pad hp-note">Loading…</p></div>
    </div>
<?php }

function hp_view_team() {
    if (!is_user_logged_in()) { ?>
        <div class="hp-pad hp-stack">
            <h2 class="hp-big">Prayer team</h2>
            <p class="hp-lead">Please log in to see the prayer team requests.</p>
            <a class="hp-btn hp-primary" href="<?php echo esc_url(wp_login_url(hp_page_url('team'))); ?>">Log in</a>
        </div>
    <?php return; }
    if (!current_user_can('hp_team')) { ?>
        <div class="hp-pad"><p class="hp-lead">This page is for the prayer team. Ask the church office if you should have access.</p></div>
    <?php return; }
    $rows = hp_team_requests();
    ?>
    <div class="hp-soft">
        <div class="hp-pad">
            <h2 class="hp-big">Prayer team</h2>
            <p class="hp-note">Requests from the last <?php echo (int) hp_settings()['feed_days']; ?> days. "Prayer team" ones are only for this team. Please keep them confidential.</p>
        </div>
        <div class="hp-list">
            <?php if (!$rows): ?><p class="hp-empty">No requests right now.</p><?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <div class="hp-item">
                    <div class="hp-meta">
                        <b><?php echo esc_html($r->visibility === 'TEAM' ? ($r->first_name ?: 'No name') : hp_display_name($r)); ?></b>
                        <span>· <?php echo esc_html(hp_ago($r->created_at)); ?></span>
                        <span class="hp-tag<?php echo $r->visibility === 'TEAM' ? '' : ' hp-gray'; ?>"><?php echo esc_html(hp_vis_label($r->visibility)); ?></span>
                    </div>
                    <p><?php echo esc_html($r->text); ?></p>
                    <div class="hp-row">
                        <span class="hp-note" data-hp-count><?php echo (int) $r->prayed_count; ?> prayed</span>
                        <button type="button" class="hp-sm hp-sm-blue" data-hp-team-pray="<?php echo (int) $r->id; ?>">I prayed</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php }
