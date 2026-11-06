<?php
/**
 * Plugin Name:       Hillside Prays
 * Description:       Prayer requests for Hillside: ask for prayer, pray for others, and a private view for pastors, Care and the prayer team. Add the [hillside_prays] shortcode to a page.
 * Version:           0.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Hillside Care Ministry
 * License:           GPL-2.0-or-later
 * Text Domain:       hillside-prays
 */

if (!defined('ABSPATH')) exit;

define('HP_VERSION', '0.3.0');
define('HP_FILE', __FILE__);
define('HP_DIR', plugin_dir_path(__FILE__));
define('HP_URL', plugin_dir_url(__FILE__));

require_once HP_DIR . 'includes/data.php';
require_once HP_DIR . 'includes/install.php';
require_once HP_DIR . 'includes/email.php';
require_once HP_DIR . 'includes/rest.php';
require_once HP_DIR . 'includes/front.php';
require_once HP_DIR . 'includes/admin.php';

register_activation_hook(__FILE__, 'hp_activate');
register_deactivation_hook(__FILE__, 'hp_deactivate');
add_action('plugins_loaded', 'hp_maybe_upgrade');
