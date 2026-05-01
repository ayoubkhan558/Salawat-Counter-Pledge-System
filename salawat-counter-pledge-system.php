<?php
/**
 * Plugin Name: Salawat Counter & Pledge System
 * Description: Collects Salawat pledges, stores them in a custom table, and exposes totals through shortcodes, REST, AJAX, Bricks dynamic tags, and an admin stats dashboard.
 * Version: 1.0.0
 * Author: Custom Development
 * Text Domain: salawat-counter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SALAWAT_COUNTER_VERSION', '1.0.0' );
define( 'SALAWAT_COUNTER_FILE', __FILE__ );
define( 'SALAWAT_COUNTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'SALAWAT_COUNTER_URL', plugin_dir_url( __FILE__ ) );

require_once SALAWAT_COUNTER_DIR . 'includes/class-salawat-counter-plugin.php';

register_activation_hook( __FILE__, array( 'Salawat_Counter_Plugin', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		Salawat_Counter_Plugin::instance();
	}
);
