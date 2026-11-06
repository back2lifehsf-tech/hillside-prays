<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('hillside-prays/v1', '/public', [
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function () {
            $out = array_map(function ($r) {
                return [
                    'id'    => (int) $r->id,
                    'name'  => hp_display_name($r),
                    'text'  => $r->text,
                    'count' => (int) $r->prayed_count,
                    'ago'   => hp_ago($r->created_at),
                ];
            }, hp_public_requests());
            $res = rest_ensure_response($out);
            $res->header('Cache-Control', 'no-store');
            return $res;
        },
    ]);

    register_rest_route('hillside-prays/v1', '/pray/(?P<id>\d+)', [
        'methods' => 'POST', 'permission_callback' => '__return_true',
        'callback' => function ($req) {
            if (!hp_rate_ok('pray', 120)) return new WP_Error('slow_down', 'Too many, try later', ['status' => 429]);
            $r = hp_get_request((int) $req['id']);
            if (!$r || $r->hidden || $r->status === 'ARCHIVED' || $r->kind === 'URGENT') return new WP_Error('nf', 'Not found', ['status' => 404]);
            if (!$r->approved && !in_array($r->visibility, ['PRIVATE'], true)) return new WP_Error('nf', 'Not found', ['status' => 404]);
            $public = in_array($r->visibility, ['CHURCH_NAME', 'CHURCH_ANON'], true) && hp_church_on();
            $team = in_array($r->visibility, ['TEAM', 'CHURCH_NAME', 'CHURCH_ANON'], true) && current_user_can('hp_team');
            if (!$public && !$team) return new WP_Error('nf', 'Not found', ['status' => 404]);
            return ['ok' => true, 'count' => hp_add_prayer($r->id)];
        },
    ]);

    register_rest_route('hillside-prays/v1', '/report/(?P<id>\d+)', [
        'methods' => 'POST', 'permission_callback' => '__return_true',
        'callback' => function ($req) {
            if (!hp_rate_ok('report', 20)) return new WP_Error('slow_down', 'Too many, try later', ['status' => 429]);
            $r = hp_get_request((int) $req['id']);
            if (!$r || !in_array($r->visibility, ['CHURCH_NAME', 'CHURCH_ANON', 'TEAM'], true)) return ['ok' => true];
            if (!$r->reported) {
                hp_update_request($r->id, ['reported' => 1, 'hidden' => 1]);
                hp_notify_report($r);
            }
            return ['ok' => true];
        },
    ]);
});
