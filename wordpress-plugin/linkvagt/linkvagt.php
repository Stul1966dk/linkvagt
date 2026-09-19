<?php
/**
 * Plugin Name: LinkVagt
 * Description: Centralt kontrolpanel til scanning og sikker rettelse af links på eksterne websites.
 * Version: 0.6.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: SU Media
 * Text Domain: linkvagt
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('LINKVAGT_VERSION', '0.6.0');
define('LINKVAGT_FILE', __FILE__);
define('LINKVAGT_DIR', plugin_dir_path(__FILE__));
define('LINKVAGT_URL', plugin_dir_url(__FILE__));

require_once LINKVAGT_DIR . 'includes/class-schema.php';
require_once LINKVAGT_DIR . 'includes/class-access.php';
require_once LINKVAGT_DIR . 'includes/class-auth.php';
require_once LINKVAGT_DIR . 'includes/class-crypto.php';
require_once LINKVAGT_DIR . 'includes/class-backup.php';
require_once LINKVAGT_DIR . 'includes/class-link-rules.php';
require_once LINKVAGT_DIR . 'includes/class-wordpress-service.php';
require_once LINKVAGT_DIR . 'includes/class-scanner.php';
require_once LINKVAGT_DIR . 'includes/class-scheduler.php';
require_once LINKVAGT_DIR . 'includes/class-reporter.php';
require_once LINKVAGT_DIR . 'includes/class-rest.php';
require_once LINKVAGT_DIR . 'includes/class-plugin.php';

register_activation_hook(__FILE__, [\LinkVagt\Schema::class, 'activate']);

add_action('plugins_loaded', static function (): void {
    \LinkVagt\Plugin::instance()->boot();
});
