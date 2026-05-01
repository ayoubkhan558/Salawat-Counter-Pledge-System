<?php
/**
 * Main plugin class.
 *
 * @package SalawatCounter
 */

if (!defined('ABSPATH')) {
	exit;
}

final class Salawat_Counter_Plugin
{
	const NONCE_ACTION = 'salawat_counter_submit';
	const NONCE_NAME = 'salawat_nonce';
	const CACHE_GROUP = 'salawat_counter';
	const CACHE_TTL = 60;
	const REST_NAMESPACE = 'salawat/v1';
	const OPTION_FIELD_MAP = 'salawat_counter_field_map';
	const OPTION_DB_VERSION = 'salawat_counter_db_version';
	const OPTION_LAST_BRICKS_DEBUG = 'salawat_counter_last_bricks_debug';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Runtime table name.
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Create database table.
	 *
	 * @return void
	 */
	public static function activate()
	{
		global $wpdb;

		$table_name = $wpdb->prefix . 'salawat_pledges';
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			salawat_amount bigint(20) unsigned NOT NULL DEFAULT 0,
			message text NULL,
			is_anonymous tinyint(1) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY salawat_amount (salawat_amount),
			KEY email (email)
		) {$charset_collate};";

		dbDelta($sql);
		update_option(self::OPTION_DB_VERSION, SALAWAT_COUNTER_VERSION, false);
	}

	/**
	 * Constructor.
	 */
	private function __construct()
	{
		global $wpdb;

		$this->table_name = $wpdb->prefix . 'salawat_pledges';

		$this->maybe_create_table();

		add_shortcode('salawat_form', array($this, 'shortcode_form'));
		add_shortcode('salawat_nonce', array($this, 'shortcode_nonce'));
		add_shortcode('salawat_total', array($this, 'shortcode_total'));
		add_shortcode('salawat_today', array($this, 'shortcode_today'));
		add_shortcode('salawat_week', array($this, 'shortcode_week'));
		add_shortcode('salawat_month', array($this, 'shortcode_month'));
		add_shortcode('salawat_leaderboard', array($this, 'shortcode_leaderboard'));
		add_shortcode('salawat_latest_pledges', array($this, 'shortcode_latest_pledges'));

		add_action('init', array($this, 'handle_shortcode_submission'));
		add_action('init', array($this, 'register_bricks_elements'), 11);
		add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
		add_action('wp_ajax_salawat_get_stats', array($this, 'ajax_get_stats'));
		add_action('wp_ajax_nopriv_salawat_get_stats', array($this, 'ajax_get_stats'));
		add_action('rest_api_init', array($this, 'register_rest_routes'));

		add_action('admin_menu', array($this, 'register_admin_menu'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_init', array($this, 'handle_delete_pledge'));
		add_action('admin_init', array($this, 'handle_csv_export'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

		add_action('bricks/form/custom_action', array($this, 'handle_bricks_submission'), 10, 1);
		add_filter('bricks/dynamic_tags_list', array($this, 'register_bricks_dynamic_tags'));
		add_filter('bricks/dynamic_data/render_tag', array($this, 'render_bricks_dynamic_tag'), 20, 3);
		add_filter('bricks/dynamic_data/render_content', array($this, 'render_bricks_dynamic_content'), 20, 3);
		add_filter('bricks/frontend/render_data', array($this, 'render_bricks_dynamic_content'), 20, 3);
	}

	/**
	 * Create the table if activation did not run.
	 *
	 * @return void
	 */
	private function maybe_create_table()
	{
		$db_version = get_option(self::OPTION_DB_VERSION);

		if (SALAWAT_COUNTER_VERSION === $db_version && $this->table_exists()) {
			return;
		}

		self::activate();
		update_option(self::OPTION_DB_VERSION, SALAWAT_COUNTER_VERSION, false);
	}

	/**
	 * Add frontend script for live counters.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets()
	{
		$this->register_frontend_style();

		wp_register_script(
			'salawat-counter-frontend',
			SALAWAT_COUNTER_URL . 'assets/frontend.js',
			array(),
			SALAWAT_COUNTER_VERSION,
			true
		);

		wp_localize_script(
			'salawat-counter-frontend',
			'SalawatCounter',
			array(
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'action' => 'salawat_get_stats',
			)
		);
	}

	/**
	 * Register frontend stylesheet.
	 *
	 * @return void
	 */
	private function register_frontend_style()
	{
		if (wp_style_is('salawat-counter-frontend', 'registered')) {
			return;
		}

		wp_register_style(
			'salawat-counter-frontend',
			SALAWAT_COUNTER_URL . 'assets/frontend.css',
			array(),
			SALAWAT_COUNTER_VERSION
		);
	}

	/**
	 * Register custom Bricks elements.
	 *
	 * @return void
	 */
	public function register_bricks_elements()
	{
		if (!class_exists('\Bricks\Elements')) {
			return;
		}

		$element_file = SALAWAT_COUNTER_DIR . 'includes/elements/class-salawat-latest-pledges-element.php';

		if (file_exists($element_file)) {
			\Bricks\Elements::register_element($element_file);
		}
	}

	/**
	 * Add admin chart script.
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function enqueue_admin_assets($hook)
	{
		if (!in_array($hook, array('toplevel_page_salawat-stats', 'salawat-stats_page_salawat-settings'), true)) {
			return;
		}

		wp_enqueue_style(
			'salawat-counter-admin',
			SALAWAT_COUNTER_URL . 'assets/admin.css',
			array(),
			SALAWAT_COUNTER_VERSION
		);

		wp_enqueue_script(
			'salawat-counter-admin',
			SALAWAT_COUNTER_URL . 'assets/admin.js',
			array(),
			SALAWAT_COUNTER_VERSION,
			true
		);
	}

	/**
	 * Render fallback form shortcode.
	 *
	 * @return string
	 */
	public function shortcode_form()
	{
		$message = '';

		if (isset($_GET['salawat_submitted'])) {
			$message = '<p class="salawat-counter-notice salawat-counter-success">' . esc_html__('Thank you. Your Salawat pledge has been recorded.', 'salawat-counter') . '</p>';
		}

		if (isset($_GET['salawat_error'])) {
			$error_text = 'database' === sanitize_key(wp_unslash($_GET['salawat_error']))
				? __('The pledge could not be saved. Please contact the site administrator.', 'salawat-counter')
				: __('Please enter a valid Salawat amount.', 'salawat-counter');
			$message = '<p class="salawat-counter-notice salawat-counter-error">' . esc_html($error_text) . '</p>';
		}

		ob_start();
		?>
<?php echo wp_kses_post($message); ?>
<form method="post" class="salawat-counter-form">
    <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
    <input type="hidden" name="salawat_counter_form" value="1">

    <p>
        <label for="salawat-name"><?php esc_html_e('Full Name', 'salawat-counter'); ?></label>
        <input id="salawat-name" type="text" name="salawat_name" autocomplete="name">
    </p>

    <p>
        <label for="salawat-email"><?php esc_html_e('Email', 'salawat-counter'); ?></label>
        <input id="salawat-email" type="email" name="salawat_email" autocomplete="email">
    </p>

    <p>
        <label for="salawat-amount"><?php esc_html_e('Salawat Amount', 'salawat-counter'); ?></label>
        <input id="salawat-amount" class="salawat-counter-amount" type="number" name="salawat_amount" min="1" step="1"
            inputmode="numeric" required>
    </p>

    <p>
        <label for="salawat-message"><?php esc_html_e('Message', 'salawat-counter'); ?></label>
        <textarea id="salawat-message" name="salawat_message" rows="4"></textarea>
    </p>

    <p>
        <label>
            <input type="checkbox" name="salawat_anonymous" value="1">
            <?php esc_html_e('Submit anonymously', 'salawat-counter'); ?>
        </label>
    </p>

    <p>
        <button type="submit"><?php esc_html_e('Submit Pledge', 'salawat-counter'); ?></button>
    </p>
</form>
<style>
.salawat-counter-form p {
    margin: 0 0 14px;
}

.salawat-counter-form label {
    display: block;
    margin-bottom: 6px;
}

.salawat-counter-form input[type="text"],
.salawat-counter-form input[type="email"],
.salawat-counter-form input[type="number"],
.salawat-counter-form textarea {
    box-sizing: border-box;
    display: block;
    width: 100%;
    max-width: 520px;
}

.salawat-counter-form input[type="checkbox"] {
    margin-right: 6px;
}
</style>
<?php

				return ob_get_clean();
	}

	/**
	 * Render a nonce value for Bricks hidden fields.
	 *
	 * @return string
	 */
	public function shortcode_nonce()
	{
		return esc_html(wp_create_nonce(self::NONCE_ACTION));
	}

	/**
	 * Handle shortcode form post.
	 *
	 * @return void
	 */
	public function handle_shortcode_submission()
	{
		if (empty($_POST['salawat_counter_form'])) {
			return;
		}

		if (
			empty($_POST[self::NONCE_NAME])
			|| !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)
		) {
			wp_die(esc_html__('Security check failed.', 'salawat-counter'), 403);
		}

		$data = $this->sanitize_submission($_POST);

		if (is_wp_error($data)) {
			wp_safe_redirect(add_query_arg('salawat_error', '1', wp_get_referer() ? wp_get_referer() : home_url('/')));
			exit;
		}

		$inserted = $this->insert_submission($data);

		if (!$inserted) {
			wp_safe_redirect(add_query_arg('salawat_error', 'database', wp_get_referer() ? wp_get_referer() : home_url('/')));
			exit;
		}

		wp_safe_redirect(add_query_arg('salawat_submitted', '1', remove_query_arg(array('salawat_error', 'salawat_submitted'), wp_get_referer() ? wp_get_referer() : home_url('/'))));
		exit;
	}

	/**
	 * Handle Bricks Builder custom form action.
	 *
	 * Expected Bricks field IDs:
	 * salawat_name, salawat_email, salawat_amount, salawat_message, salawat_anonymous, salawat_nonce.
	 *
	 * @param object $form Bricks form object.
	 * @return void
	 */
	public function handle_bricks_submission($form)
	{
		if (!is_object($form) || !method_exists($form, 'get_fields')) {
			return;
		}

		$fields = (array) $form->get_fields();
		$data = $this->normalize_bricks_fields($fields);
		$this->save_bricks_debug($data);

		if (!empty($data[self::NONCE_NAME]) && !wp_verify_nonce($data[self::NONCE_NAME], self::NONCE_ACTION)) {
			$this->set_bricks_result($form, 'danger', __('Security check failed.', 'salawat-counter'));
			return;
		}

		$sanitized = $this->sanitize_submission($data);

		if (is_wp_error($sanitized)) {
			$this->set_bricks_result($form, 'danger', $sanitized->get_error_message());
			return;
		}

		$inserted = $this->insert_submission($sanitized);

		if (!$inserted) {
			$this->set_bricks_result($form, 'danger', __('The pledge could not be saved. Please check the Salawat plugin database table.', 'salawat-counter'));
			return;
		}

		$this->set_bricks_result($form, 'success', __('Thank you. Your Salawat pledge has been recorded.', 'salawat-counter'));
	}

	/**
	 * Save non-sensitive Bricks mapping diagnostics for admins.
	 *
	 * @param array $data Normalized data.
	 * @return void
	 */
	private function save_bricks_debug(array $data)
	{
		update_option(
			self::OPTION_LAST_BRICKS_DEBUG,
			array(
				'time' => current_time('mysql'),
				'keys' => array_values(array_keys($data)),
				'has_amount' => !empty($data['salawat_amount']),
			),
			false
		);
	}

	/**
	 * Normalize Bricks fields into plugin field names.
	 *
	 * @param array $fields Bricks fields.
	 * @return array
	 */
	private function normalize_bricks_fields(array $fields)
	{
		$data = array();

		foreach ($fields as $key => $value) {
			$field_keys = array($key);

			if (is_array($value)) {
				if (isset($value['id'])) {
					$field_keys[] = $value['id'];
				}

				if (isset($value['name'])) {
					$field_keys[] = $value['name'];
				}

				if (isset($value['label'])) {
					$field_keys[] = $value['label'];
				}

				if (array_key_exists('value', $value)) {
					$value = $value['value'];
				}
			}

			$field_value = $this->normalize_field_value($value);

			foreach ($field_keys as $field_key) {
				foreach ($this->get_field_key_variants($field_key) as $normalized_key) {
					$data[$normalized_key] = $field_value;
				}
			}
		}

		$data = $this->apply_saved_field_map($data);

		$aliases = array(
			'name' => 'salawat_name',
			'full_name' => 'salawat_name',
			'email' => 'salawat_email',
			'amount' => 'salawat_amount',
			'message' => 'salawat_message',
			'anonymous' => 'salawat_anonymous',
			'is_anonymous' => 'salawat_anonymous',
		);

		foreach ($aliases as $from => $to) {
			if (isset($data[$from]) && !isset($data[$to])) {
				$data[$to] = $data[$from];
			}
		}

		return $data;
	}

	/**
	 * Normalize any incoming field key.
	 *
	 * @param string|int $key Field key.
	 * @return string
	 */
	private function normalize_field_key($key)
	{
		$key = trim((string) $key);
		$key = preg_replace('/^#+/', '', $key);
		$key = preg_replace('/^form-field-/', '', $key);
		$key = preg_replace('/^form_field_/', '', $key);

		return sanitize_key(str_replace('-', '_', $key));
	}

	/**
	 * Get normalized variants for a Bricks field key.
	 *
	 * @param string|int $key Field key.
	 * @return array
	 */
	private function get_field_key_variants($key)
	{
		$raw = trim((string) $key);

		if ('' === $raw) {
			return array();
		}

		$raw = trim($raw, " \t\n\r\0\x0B#.");

		$variants = array(
			$raw,
			str_replace('-', '_', $raw),
			str_replace('_', '-', $raw),
		);

		foreach (array('form-field-', 'form_field_', 'field-', 'field_') as $prefix) {
			if (0 === strpos($raw, $prefix)) {
				$variants[] = substr($raw, strlen($prefix));
			} else {
				$variants[] = $prefix . $raw;
			}
		}

		$normalized = array();

		foreach ($variants as $variant) {
			$key = $this->normalize_field_key($variant);

			if ('' !== $key) {
				$normalized[] = $key;
			}
		}

		return array_values(array_unique($normalized));
	}

	/**
	 * Normalize scalar or list field values.
	 *
	 * @param mixed $value Field value.
	 * @return mixed
	 */
	private function normalize_field_value($value)
	{
		if (is_array($value)) {
			$value = array_map(array($this, 'normalize_field_value'), $value);
			return implode(', ', array_filter(array_map('strval', $value), 'strlen'));
		}

		return is_string($value) ? wp_unslash($value) : $value;
	}

	/**
	 * Map saved Bricks field IDs to plugin field names.
	 *
	 * @param array $data Normalized Bricks data.
	 * @return array
	 */
	private function apply_saved_field_map(array $data)
	{
		$field_map = $this->get_field_map();

		foreach ($field_map as $plugin_field => $bricks_field) {
			$bricks_fields = preg_split('/[\r\n,]+/', (string) $bricks_field);

			foreach ($bricks_fields as $bricks_field) {
				foreach ($this->get_field_key_variants($bricks_field) as $mapped_key) {
					if (!array_key_exists($mapped_key, $data)) {
						continue;
					}

					if (!array_key_exists($plugin_field, $data) || '' === (string) $data[$plugin_field]) {
						$data[$plugin_field] = $data[$mapped_key];
					}
				}
			}
		}

		return $data;
	}

	/**
	 * Get saved Bricks field map.
	 *
	 * @return array
	 */
	private function get_field_map()
	{
		$defaults = array(
			'salawat_name' => 'salawat_name',
			'salawat_email' => 'salawat_email',
			'salawat_amount' => 'salawat_amount',
			'salawat_message' => 'salawat_message',
			'salawat_anonymous' => 'salawat_anonymous',
			'salawat_nonce' => 'salawat_nonce',
		);

		$saved = get_option(self::OPTION_FIELD_MAP, array());

		return wp_parse_args(is_array($saved) ? $saved : array(), $defaults);
	}

	/**
	 * Set Bricks form result when API is available.
	 *
	 * @param object $form Bricks form.
	 * @param string $type Result type.
	 * @param string $message Message.
	 * @return void
	 */
	private function set_bricks_result($form, $type, $message)
	{
		if (method_exists($form, 'set_result')) {
			$form->set_result(
				array(
					'action' => 'custom',
					'type' => $type,
					'message' => $message,
				)
			);
		}
	}

	/**
	 * Sanitize and validate a submission.
	 *
	 * @param array $raw Raw submission data.
	 * @return array|WP_Error
	 */
	private function sanitize_submission(array $raw)
	{
		$amount = isset($raw['salawat_amount']) ? $this->sanitize_amount($raw['salawat_amount']) : 0;

		if ($amount < 1) {
			return new WP_Error('invalid_amount', __('Please enter a valid Salawat amount.', 'salawat-counter'));
		}

		$email = isset($raw['salawat_email']) ? sanitize_email(wp_unslash($raw['salawat_email'])) : '';

		if ('' !== $email && !is_email($email)) {
			return new WP_Error('invalid_email', __('Please enter a valid email address.', 'salawat-counter'));
		}

		return array(
			'name' => isset($raw['salawat_name']) ? sanitize_text_field(wp_unslash($raw['salawat_name'])) : '',
			'email' => $email,
			'salawat_amount' => $amount,
			'message' => isset($raw['salawat_message']) ? sanitize_textarea_field(wp_unslash($raw['salawat_message'])) : '',
			'is_anonymous' => !empty($raw['salawat_anonymous']) ? 1 : 0,
		);
	}

	/**
	 * Sanitize a pledge amount.
	 *
	 * @param mixed $amount Raw amount.
	 * @return int
	 */
	private function sanitize_amount($amount)
	{
		if (is_array($amount)) {
			$amount = reset($amount);
		}

		$amount = is_string($amount) ? wp_unslash($amount) : $amount;
		$amount = preg_replace('/[^\d]/', '', (string) $amount);

		return '' === $amount ? 0 : absint($amount);
	}

	/**
	 * Insert submission.
	 *
	 * @param array $data Sanitized data.
	 * @return int|false
	 */
	private function insert_submission(array $data)
	{
		global $wpdb;

		$inserted = $wpdb->insert(
			$this->table_name,
			array(
				'name' => $data['name'],
				'email' => $data['email'],
				'salawat_amount' => $data['salawat_amount'],
				'message' => $data['message'],
				'is_anonymous' => $data['is_anonymous'],
				'created_at' => current_time('mysql'),
			),
			array('%s', '%s', '%d', '%s', '%d', '%s')
		);

		if (false !== $inserted) {
			$this->clear_stats_cache();
			return (int) $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Get totals.
	 *
	 * @param string $start_date Optional start date Y-m-d.
	 * @param string $end_date Optional end date Y-m-d.
	 * @return array
	 */
	public function get_stats($start_date = '', $end_date = '')
	{
		if (!$this->table_exists()) {
			return array(
				'total' => 0,
				'today' => 0,
				'week' => 0,
				'month' => 0,
				'participants' => 0,
			);
		}

		$cache_key = 'salawat_counter_stats_' . md5($start_date . '|' . $end_date);
		$cached = current_user_can('manage_options') ? false : get_transient($cache_key);

		if (false !== $cached) {
			return $cached;
		}

		global $wpdb;

		$timezone = $this->get_wp_timezone();
		$now = new DateTimeImmutable('now', $timezone);
		$today_start = $now->setTime(0, 0, 0);
		$week_starts = (int) get_option('start_of_week', 1);
		$current_day = (int) $now->format('w');
		$days_since = ($current_day - $week_starts + 7) % 7;
		$week_start = $today_start->modify('-' . $days_since . ' days');
		$month_start = $now->modify('first day of this month')->setTime(0, 0, 0);
		$next_month = $month_start->modify('+1 month');
		$today_start = $today_start->format('Y-m-d H:i:s');
		$tomorrow = $now->setTime(0, 0, 0)->modify('+1 day')->format('Y-m-d H:i:s');
		$week_start = $week_start->format('Y-m-d H:i:s');
		$month_start = $month_start->format('Y-m-d H:i:s');
		$next_month = $next_month->format('Y-m-d H:i:s');

		$stats = array(
			'total' => $wpdb->get_var("SELECT COALESCE(SUM(salawat_amount), 0) FROM {$this->table_name}"),
			'today' => $this->sum_between($today_start, $tomorrow),
			'week' => $this->sum_between($week_start, ''),
			'month' => $this->sum_between($month_start, $next_month),
			'participants' => $wpdb->get_var("SELECT COUNT(DISTINCT CASE WHEN email != '' THEN email ELSE CAST(id AS CHAR) END) FROM {$this->table_name}"),
		);

		if ($start_date || $end_date) {
			$stats['filtered_total'] = $this->sum_for_range($start_date, $end_date);
			$stats['filtered_participants'] = $this->count_for_range($start_date, $end_date);
		}

		set_transient($cache_key, $stats, self::CACHE_TTL);

		return $stats;
	}

	/**
	 * Get WordPress timezone with compatibility fallback.
	 *
	 * @return DateTimeZone
	 */
	private function get_wp_timezone()
	{
		if (function_exists('wp_timezone')) {
			return wp_timezone();
		}

		$timezone_string = get_option('timezone_string');

		if ($timezone_string) {
			return new DateTimeZone($timezone_string);
		}

		$offset = (float) get_option('gmt_offset', 0);
		$hours = (int) $offset;
		$minutes = ($offset - $hours) * 60;
		$sign = $offset < 0 ? '-' : '+';

		$timezone_name = timezone_name_from_abbr('', (int) ($offset * HOUR_IN_SECONDS), 0);

		if (false === $timezone_name) {
			$timezone_name = 'UTC';
		}

		return new DateTimeZone($timezone_name);
	}

	/**
	 * Sum between two datetimes.
	 *
	 * @param string $start Inclusive start datetime.
	 * @param string $end Exclusive end datetime.
	 * @return int
	 */
	private function sum_between($start, $end = '')
	{
		global $wpdb;

		if ($end) {
			return $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(salawat_amount), 0) FROM {$this->table_name} WHERE created_at >= %s AND created_at < %s",
					$start,
					$end
				)
			);
		}

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(salawat_amount), 0) FROM {$this->table_name} WHERE created_at >= %s",
				$start
			)
		);
	}

	/**
	 * Sum for date range.
	 *
	 * @param string $start_date Y-m-d.
	 * @param string $end_date Y-m-d.
	 * @return int
	 */
	private function sum_for_range($start_date, $end_date)
	{
		global $wpdb;

		$where = array();
		$params = array();

		if ($start_date) {
			$where[] = 'created_at >= %s';
			$params[] = $start_date . ' 00:00:00';
		}

		if ($end_date) {
			$where[] = 'created_at <= %s';
			$params[] = $end_date . ' 23:59:59';
		}

		$sql = "SELECT COALESCE(SUM(salawat_amount), 0) FROM {$this->table_name}";

		if ($where) {
			$sql .= ' WHERE ' . implode(' AND ', $where);
			$sql = $wpdb->prepare($sql, $params);
		}

		return $wpdb->get_var($sql);
	}

	/**
	 * Count for date range.
	 *
	 * @param string $start_date Y-m-d.
	 * @param string $end_date Y-m-d.
	 * @return int
	 */
	private function count_for_range($start_date, $end_date)
	{
		global $wpdb;

		$where = array();
		$params = array();

		if ($start_date) {
			$where[] = 'created_at >= %s';
			$params[] = $start_date . ' 00:00:00';
		}

		if ($end_date) {
			$where[] = 'created_at <= %s';
			$params[] = $end_date . ' 23:59:59';
		}

		$sql = "SELECT COUNT(*) FROM {$this->table_name}";

		if ($where) {
			$sql .= ' WHERE ' . implode(' AND ', $where);
			$sql = $wpdb->prepare($sql, $params);
		}

		return $wpdb->get_var($sql);
	}

	/**
	 * Clear transient cache.
	 *
	 * @return void
	 */
	private function clear_stats_cache()
	{
		global $wpdb;

		// Clear the most common global cache key
		delete_transient('salawat_counter_stats_' . md5('|'));

		// Also try to clear all by pattern in options table for environments without object cache
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like('_transient_salawat_counter_stats_') . '%',
				$wpdb->esc_like('_transient_timeout_salawat_counter_stats_') . '%'
			)
		);
	}

	/**
	 * Format number for display.
	 *
	 * @param int $value Number.
	 * @return string
	 */
	private function format_number($value)
	{
		return number_format_i18n($value);
	}

	/**
	 * Shortcode total.
	 *
	 * @return string
	 */
	public function shortcode_total()
	{
		wp_enqueue_script('salawat-counter-frontend');
		return '<span class="salawat-live-counter" data-salawat-stat="total">' . esc_html($this->format_number($this->get_stats()['total'])) . '</span>';
	}

	/**
	 * Shortcode today.
	 *
	 * @return string
	 */
	public function shortcode_today()
	{
		wp_enqueue_script('salawat-counter-frontend');
		return '<span class="salawat-live-counter" data-salawat-stat="today">' . esc_html($this->format_number($this->get_stats()['today'])) . '</span>';
	}

	/**
	 * Shortcode week.
	 *
	 * @return string
	 */
	public function shortcode_week()
	{
		wp_enqueue_script('salawat-counter-frontend');
		return '<span class="salawat-live-counter" data-salawat-stat="week">' . esc_html($this->format_number($this->get_stats()['week'])) . '</span>';
	}

	/**
	 * Shortcode month.
	 *
	 * @return string
	 */
	public function shortcode_month()
	{
		wp_enqueue_script('salawat-counter-frontend');
		return '<span class="salawat-live-counter" data-salawat-stat="month">' . esc_html($this->format_number($this->get_stats()['month'])) . '</span>';
	}

	/**
	 * Leaderboard shortcode.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function shortcode_leaderboard($atts)
	{
		global $wpdb;

		$atts = shortcode_atts(array('limit' => 10), $atts, 'salawat_leaderboard');
		$limit = max(1, min(50, absint($atts['limit'])));

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT name, is_anonymous, SUM(salawat_amount) AS total_amount
				FROM {$this->table_name}
				GROUP BY CASE WHEN is_anonymous = 1 THEN CONCAT('anonymous-', id) ELSE LOWER(email) END, name, is_anonymous
				ORDER BY total_amount DESC
				LIMIT %d",
				$limit
			)
		);

		if (empty($rows)) {
			return '';
		}

		ob_start();
		?>
<ol class="salawat-leaderboard">
    <?php foreach ($rows as $row): ?>
    <li>
        <span
            class="salawat-leaderboard-name"><?php echo esc_html($row->is_anonymous ? __('Anonymous', 'salawat-counter') : ($row->name ? $row->name : __('Participant', 'salawat-counter'))); ?></span>
        <span
            class="salawat-leaderboard-amount"><?php echo esc_html($this->format_number($row->total_amount)); ?></span>
    </li>
    <?php endforeach; ?>
</ol>
<?php

				return ob_get_clean();
	}

	/**
	 * Render latest pledges shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_latest_pledges($atts)
	{
		$atts = shortcode_atts(
			array(
				'limit' => 5,
				'title' => __('Latest Pledges', 'salawat-counter'),
				'show_title' => 'yes',
				'show_date' => 'yes',
				'show_message' => 'yes',
				'show_amount_label' => 'yes',
				'order' => 'desc',
				'date_format' => 'jS F Y',
				'amount_label' => __('Amount Donated', 'salawat-counter'),
				'anonymous_label' => __('Anonymous', 'salawat-counter'),
				'empty_text' => __('No pledges have been submitted yet.', 'salawat-counter'),
			),
			$atts,
			'salawat_latest_pledges'
		);

		return $this->render_latest_pledges($atts);
	}

	/**
	 * Render latest pledges list for shortcode and Bricks.
	 *
	 * @param array $args Render args.
	 * @return string
	 */
	public function render_latest_pledges(array $args = array())
	{
		$this->register_frontend_style();
		wp_enqueue_style('salawat-counter-frontend');

		$args = wp_parse_args(
			$args,
			array(
				'limit' => 5,
				'title' => __('Latest Pledges', 'salawat-counter'),
				'show_title' => true,
				'show_date' => true,
				'show_message' => true,
				'show_amount_label' => true,
				'order' => 'desc',
				'date_format' => 'jS F Y',
				'amount_label' => __('Amount Donated', 'salawat-counter'),
				'anonymous_label' => __('Anonymous', 'salawat-counter'),
				'empty_text' => __('No pledges have been submitted yet.', 'salawat-counter'),
				'columns' => 1,
				'layout' => 'cards',
				'extra_class' => '',
			)
		);

		$args['limit'] = max(1, min(50, absint($args['limit'])));
		$args['columns'] = max(1, min(4, absint($args['columns'])));
		$args['order'] = 'asc' === strtolower((string) $args['order']) ? 'ASC' : 'DESC';
		$args['show_title'] = $this->truthy($args['show_title']);
		$args['show_date'] = $this->truthy($args['show_date']);
		$args['show_message'] = $this->truthy($args['show_message']);
		$args['show_amount_label'] = $this->truthy($args['show_amount_label']);

		$rows = $this->get_latest_pledges($args['limit'], $args['order']);

		$classes = array(
			'salawat-latest-pledges',
			'salawat-latest-layout-' . sanitize_html_class($args['layout']),
		);

		if ($args['extra_class']) {
			$classes[] = sanitize_html_class($args['extra_class']);
		}

		ob_start();
		?>
<?php $this->print_latest_pledges_inline_style(); ?>
<section class="<?php echo esc_attr(implode(' ', $classes)); ?>"
    style="<?php echo esc_attr('--salawat-latest-columns:' . (int) $args['columns']); ?>">
    <?php if ($args['show_title'] && '' !== trim((string) $args['title'])): ?>
    <h2 class="salawat-latest-title"><?php echo esc_html($args['title']); ?></h2>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
    <p class="salawat-latest-empty"><?php echo esc_html($args['empty_text']); ?></p>
    <?php else: ?>
    <div class="salawat-latest-list">
        <?php foreach ($rows as $row): ?>
        <?php
										$name = $row->is_anonymous ? $args['anonymous_label'] : ($row->name ? $row->name : __('Participant', 'salawat-counter'));
										$message = trim((string) $row->message);
										$date = mysql2date($args['date_format'], $row->created_at);
										$amount = $this->format_number($row->salawat_amount);
										?>
        <article class="salawat-latest-card">
            <header class="salawat-latest-card-header">
                <h3 class="salawat-latest-name"><?php echo esc_html($name); ?></h3>
                <?php if ($args['show_date']): ?>
                <time class="salawat-latest-date"
                    datetime="<?php echo esc_attr(mysql2date('c', $row->created_at)); ?>"><?php echo esc_html($date); ?></time>
                <?php endif; ?>
            </header>

            <?php if ($args['show_message'] && '' !== $message): ?>
            <div class="salawat-latest-message"><?php echo esc_html($message); ?></div>
            <?php endif; ?>

            <footer class="salawat-latest-footer">
                <?php if ($args['show_amount_label']): ?>
                <span class="salawat-latest-amount-label"><?php echo esc_html($args['amount_label']); ?></span>
                <?php endif; ?>
                <strong
                    class="salawat-latest-amount"><?php echo esc_html(sprintf(_n('%s Salawat', '%s Salawat', (int) $row->salawat_amount, 'salawat-counter'), $amount)); ?></strong>
            </footer>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<?php

				return ob_get_clean();
	}

	/**
	 * Print base latest pledge styles once for late Bricks builder renders.
	 *
	 * @return void
	 */
	private function print_latest_pledges_inline_style()
	{
		static $printed = false;

		if ($printed) {
			return;
		}

		$printed = true;
		$css = '';
		$file = SALAWAT_COUNTER_DIR . 'assets/frontend.css';

		if (file_exists($file) && is_readable($file)) {
			$css = file_get_contents($file);
		}

		if ('' === $css) {
			return;
		}

		echo '<style id="salawat-latest-pledges-inline-css">' . wp_strip_all_tags($css) . '</style>';
	}

	/**
	 * Fetch latest pledges.
	 *
	 * @param int    $limit Number of rows.
	 * @param string $order ASC or DESC.
	 * @return array
	 */
	private function get_latest_pledges($limit, $order)
	{
		global $wpdb;

		if (!$this->table_exists()) {
			return array();
		}

		$order = 'ASC' === $order ? 'ASC' : 'DESC';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, salawat_amount, message, is_anonymous, created_at
				FROM {$this->table_name}
				ORDER BY created_at {$order}, id {$order}
				LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Normalize truthy setting values.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function truthy($value)
	{
		if (is_bool($value)) {
			return $value;
		}

		return in_array(strtolower((string) $value), array('1', 'yes', 'true', 'on'), true);
	}

	/**
	 * AJAX stats response.
	 *
	 * @return void
	 */
	public function ajax_get_stats()
	{
		wp_send_json_success($this->get_public_stats());
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_rest_routes()
	{
		register_rest_route(
			self::REST_NAMESPACE,
			'/stats',
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array($this, 'rest_get_stats'),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST stats callback.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_get_stats()
	{
		return rest_ensure_response($this->get_public_stats());
	}

	/**
	 * Public stats payload.
	 *
	 * @return array
	 */
	private function get_public_stats()
	{
		$stats = $this->get_stats();

		return array(
			'total' => $stats['total'],
			'today' => $stats['today'],
			'week' => $stats['week'],
			'month' => $stats['month'],
			'participants' => $stats['participants'],
			'formatted' => array(
				'total' => $this->format_number($stats['total']),
				'today' => $this->format_number($stats['today']),
				'week' => $this->format_number($stats['week']),
				'month' => $this->format_number($stats['month']),
				'participants' => $this->format_number($stats['participants']),
			),
		);
	}

	/**
	 * Register admin page.
	 *
	 * @return void
	 */
	public function register_admin_menu()
	{
		add_menu_page(
			__('Salawat Stats', 'salawat-counter'),
			__('Salawat Stats', 'salawat-counter'),
			'manage_options',
			'salawat-stats',
			array($this, 'render_admin_page'),
			'dashicons-chart-bar',
			56
		);

		add_submenu_page(
			'salawat-stats',
			__('Salawat Stats', 'salawat-counter'),
			__('Stats', 'salawat-counter'),
			'manage_options',
			'salawat-stats',
			array($this, 'render_admin_page')
		);

		add_submenu_page(
			'salawat-stats',
			__('Salawat Settings', 'salawat-counter'),
			__('Settings', 'salawat-counter'),
			'manage_options',
			'salawat-settings',
			array($this, 'render_settings_page')
		);
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings()
	{
		register_setting(
			'salawat_counter_settings',
			self::OPTION_FIELD_MAP,
			array(
				'type' => 'array',
				'sanitize_callback' => array($this, 'sanitize_field_map'),
				'default' => $this->get_field_map(),
			)
		);
	}

	/**
	 * Sanitize saved field map.
	 *
	 * @param array $value Raw value.
	 * @return array
	 */
	public function sanitize_field_map($value)
	{
		$defaults = $this->get_field_map();
		$clean = array();
		$value = is_array($value) ? $value : array();

		foreach ($defaults as $key => $default) {
			$raw = isset($value[$key]) ? wp_unslash($value[$key]) : $default;
			$clean[$key] = sanitize_textarea_field($raw);
		}

		return $clean;
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page()
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		$field_map = $this->get_field_map();
		$table_ready = $this->table_exists();
		$debug = get_option(self::OPTION_LAST_BRICKS_DEBUG, array());
		$fields = array(
			'salawat_name' => __('Full Name field ID', 'salawat-counter'),
			'salawat_email' => __('Email field ID', 'salawat-counter'),
			'salawat_amount' => __('Salawat Amount field ID', 'salawat-counter'),
			'salawat_message' => __('Message field ID', 'salawat-counter'),
			'salawat_anonymous' => __('Anonymous checkbox field ID', 'salawat-counter'),
			'salawat_nonce' => __('Nonce hidden field ID', 'salawat-counter'),
		);
		?>
<div class="wrap">
    <h1><?php esc_html_e('Salawat Settings', 'salawat-counter'); ?></h1>

    <div class="notice <?php echo $table_ready ? 'notice-success' : 'notice-error'; ?>">
        <p>
            <?php
							echo esc_html(
								$table_ready
								? __('Database table is ready.', 'salawat-counter')
								: __('Database table was not found. Deactivate and reactivate the plugin, or reload this page to let the plugin try creating it again.', 'salawat-counter')
							);
							?>
        </p>
    </div>

    <form method="post" action="options.php">
        <?php settings_fields('salawat_counter_settings'); ?>

        <h2><?php esc_html_e('Bricks Form Field Mapping', 'salawat-counter'); ?></h2>
        <p><?php esc_html_e('Paste the generated Bricks field ID for each pledge field. Keep the defaults if your Bricks fields already use these names.', 'salawat-counter'); ?>
        </p>

        <table class="form-table" role="presentation">
            <tbody>
                <?php foreach ($fields as $key => $label): ?>
                <tr>
                    <th scope="row">
                        <label
                            for="<?php echo esc_attr('salawat-field-map-' . $key); ?>"><?php echo esc_html($label); ?></label>
                    </th>
                    <td>
                        <input id="<?php echo esc_attr('salawat-field-map-' . $key); ?>" class="regular-text"
                            type="text" name="<?php echo esc_attr(self::OPTION_FIELD_MAP . '[' . $key . ']'); ?>"
                            value="<?php echo esc_attr(isset($field_map[$key]) ? $field_map[$key] : $key); ?>">
                        <p class="description">
                            <?php
													printf(
														/* translators: %s: plugin field key */
														esc_html__('Plugin field: %s', 'salawat-counter'),
														'<code>' . esc_html($key) . '</code>'
													);
													?>
                        </p>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php submit_button(); ?>
    </form>

    <h2><?php esc_html_e('Shortcode Fallback', 'salawat-counter'); ?></h2>
    <p><?php esc_html_e('Use this shortcode on any page if you do not want to use Bricks for the pledge form:', 'salawat-counter'); ?>
    </p>
    <p><code>[salawat_form]</code></p>

    <h2><?php esc_html_e('Last Bricks Submission Diagnostics', 'salawat-counter'); ?></h2>
    <?php if (empty($debug['time'])): ?>
    <p><?php esc_html_e('No Bricks submission has reached the plugin yet.', 'salawat-counter'); ?></p>
    <?php else: ?>
    <p>
        <?php
								printf(
									/* translators: 1: date, 2: yes/no */
									esc_html__('Last seen: %1$s. Amount mapped: %2$s.', 'salawat-counter'),
									esc_html($debug['time']),
									esc_html(!empty($debug['has_amount']) ? __('Yes', 'salawat-counter') : __('No', 'salawat-counter'))
								);
								?>
    </p>
    <?php if (!empty($debug['keys']) && is_array($debug['keys'])): ?>
    <p><?php esc_html_e('Available normalized keys from the last Bricks submission:', 'salawat-counter'); ?></p>
    <textarea class="large-text code" rows="6"
        readonly><?php echo esc_textarea(implode("\n", $debug['keys'])); ?></textarea>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php
	}

	/**
	 * Check if custom table exists.
	 *
	 * @return bool
	 */
	private function table_exists()
	{
		global $wpdb;

		return $this->table_name === $wpdb->get_var(
			$wpdb->prepare('SHOW TABLES LIKE %s', $this->table_name)
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render_admin_page()
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		$start_date = isset($_GET['start_date']) ? $this->sanitize_date(wp_unslash($_GET['start_date'])) : '';
		$end_date = isset($_GET['end_date']) ? $this->sanitize_date(wp_unslash($_GET['end_date'])) : '';
		$stats = $this->get_stats($start_date, $end_date);
		$chart_data = $this->get_daily_chart_data($start_date, $end_date);
		?>
<div class="wrap salawat-admin-wrap">
    <h1><?php esc_html_e('Salawat Stats', 'salawat-counter'); ?></h1>

    <?php if (!empty($_GET['deleted'])): ?>
    <div class="notice notice-success is-dismissible">
        <p><?php esc_html_e('Pledge deleted.', 'salawat-counter'); ?></p>
    </div>
    <?php endif; ?>

    <form method="get" class="salawat-admin-filters">
        <input type="hidden" name="page" value="salawat-stats">
        <label>
            <?php esc_html_e('Start date', 'salawat-counter'); ?>
            <input type="date" name="start_date" value="<?php echo esc_attr($start_date); ?>">
        </label>
        <label>
            <?php esc_html_e('End date', 'salawat-counter'); ?>
            <input type="date" name="end_date" value="<?php echo esc_attr($end_date); ?>">
        </label>
        <button class="button button-primary" type="submit"><?php esc_html_e('Filter', 'salawat-counter'); ?></button>
        <a class="button"
            href="<?php echo esc_url(remove_query_arg(array('start_date', 'end_date'))); ?>"><?php esc_html_e('Reset', 'salawat-counter'); ?></a>
        <a class="button"
            href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('salawat_export' => 'csv', 'start_date' => $start_date, 'end_date' => $end_date)), 'salawat_export_csv')); ?>"><?php esc_html_e('Export CSV', 'salawat-counter'); ?></a>
    </form>

    <div class="salawat-admin-cards">
        <div class="salawat-admin-card">
            <h2><?php esc_html_e('Total Pledged', 'salawat-counter'); ?></h2>
            <strong><?php echo esc_html($this->format_number($stats['total'])); ?></strong>
        </div>
        <div class="salawat-admin-card">
            <h2><?php esc_html_e('Today', 'salawat-counter'); ?></h2>
            <strong><?php echo esc_html($this->format_number($stats['today'])); ?></strong>
        </div>
        <div class="salawat-admin-card">
            <h2><?php esc_html_e('This Week', 'salawat-counter'); ?></h2>
            <strong><?php echo esc_html($this->format_number($stats['week'])); ?></strong>
        </div>
        <div class="salawat-admin-card">
            <h2><?php esc_html_e('This Month', 'salawat-counter'); ?></h2>
            <strong><?php echo esc_html($this->format_number($stats['month'])); ?></strong>
        </div>
        <div class="salawat-admin-card">
            <h2><?php esc_html_e('Participants', 'salawat-counter'); ?></h2>
            <strong><?php echo esc_html($this->format_number($stats['participants'])); ?></strong>
        </div>
        <?php if ($start_date || $end_date): ?>
        <div class="salawat-admin-card">
            <h2><?php esc_html_e('Filtered Total', 'salawat-counter'); ?></h2>
            <strong><?php echo esc_html($this->format_number($stats['filtered_total'])); ?></strong>
        </div>
        <?php endif; ?>
    </div>

    <h2><?php esc_html_e('Daily Pledges', 'salawat-counter'); ?></h2>
    <canvas id="salawat-admin-chart" height="110"
        data-chart="<?php echo esc_attr(wp_json_encode($chart_data)); ?>"></canvas>

    <h2><?php esc_html_e('Recent Pledges', 'salawat-counter'); ?></h2>
    <?php $this->render_recent_table($start_date, $end_date); ?>
</div>
<style>
.salawat-admin-wrap {
    /* reuse similar scale */
    --fs-sm: clamp(14px, calc(0.196vw + 13.176px), 16px);
    --fs-lg: clamp(22px, calc(0.6vw + 20px), 28px);

    --space-sm: clamp(12px, 1.5vw, 18px);
    --space-md: clamp(16px, 2vw, 24px);

    --border: #dcdcde;
    --text-muted: #50575e;
    --bg: #fff;
    --salawat-admin-fs-sm: var(--fs-sm);
    --salawat-admin-fs-lg: var(--fs-lg);
    --salawat-admin-space-sm: var(--space-sm);
    --salawat-admin-space-md: var(--space-md);
    --salawat-admin-border: var(--border);
    --salawat-admin-muted: var(--text-muted);
    --salawat-admin-bg: var(--bg);
}

/* FILTERS */
.salawat-admin-filters {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: var(--space-sm);
    margin: var(--space-sm) 0;
}

.salawat-admin-filters label {
    display: grid;
    gap: 4px;
    font-size: var(--fs-sm);
}

/* GRID */
.salawat-admin-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: var(--space-sm);
    margin: var(--space-sm) 0;
}

/* CARD */
.salawat-admin-card {
    background: var(--bg);
    border: 1px solid var(--border);
    padding: var(--space-md);
    border-radius: 4px;
}

/* CARD TEXT */
.salawat-admin-card h2 {
    font-size: var(--fs-sm);
    margin: 0 0 8px;
    color: var(--text-muted);
    font-weight: 600;
}

.salawat-admin-card strong {
    font-size: var(--fs-lg);
    font-weight: 800;
}

/* CHART */
#salawat-admin-chart {
    background: var(--bg);
    border: 1px solid var(--border);
    max-width: 100%;
}

.salawat-admin-table .column-actions {
    width: 110px;
}

.salawat-delete-link {
    color: #b32d2e;
}
</style>
<?php
	}

	/**
	 * Render recent admin table.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return void
	 */
	private function render_recent_table($start_date, $end_date)
	{
		global $wpdb;

		if (!$this->table_exists()) {
			echo '<p>' . esc_html__('The Salawat pledges table does not exist yet.', 'salawat-counter') . '</p>';
			return;
		}

		$where = array();
		$params = array();

		if ($start_date) {
			$where[] = 'created_at >= %s';
			$params[] = $start_date . ' 00:00:00';
		}

		if ($end_date) {
			$where[] = 'created_at <= %s';
			$params[] = $end_date . ' 23:59:59';
		}

		$sql = "SELECT * FROM {$this->table_name}";

		if ($where) {
			$sql .= ' WHERE ' . implode(' AND ', $where);
		}

		$sql .= ' ORDER BY created_at DESC LIMIT 50';

		if ($params) {
			$sql = $wpdb->prepare($sql, $params);
		}

		$rows = $wpdb->get_results($sql);
		?>
<table class="widefat striped salawat-admin-table">
    <thead>
        <tr>
            <th><?php esc_html_e('ID', 'salawat-counter'); ?></th>
            <th><?php esc_html_e('Date', 'salawat-counter'); ?></th>
            <th><?php esc_html_e('Name', 'salawat-counter'); ?></th>
            <th><?php esc_html_e('Email', 'salawat-counter'); ?></th>
            <th><?php esc_html_e('Amount', 'salawat-counter'); ?></th>
            <th><?php esc_html_e('Anonymous', 'salawat-counter'); ?></th>
            <th><?php esc_html_e('Message', 'salawat-counter'); ?></th>
            <th class="column-actions"><?php esc_html_e('Actions', 'salawat-counter'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($rows)): ?>
        <tr>
            <td colspan="8"><?php esc_html_e('No pledges found.', 'salawat-counter'); ?></td>
        </tr>
        <?php else: ?>
        <?php foreach ($rows as $row): ?>
        <tr>
            <td><?php echo esc_html((int) $row->id); ?></td>
            <td><?php echo esc_html($row->created_at); ?></td>
            <td><?php echo esc_html($row->name); ?></td>
            <td><?php echo esc_html($row->email); ?></td>
            <td><?php echo esc_html($this->format_number($row->salawat_amount)); ?></td>
            <td><?php echo esc_html($row->is_anonymous ? __('Yes', 'salawat-counter') : __('No', 'salawat-counter')); ?>
            </td>
            <td><?php echo esc_html($row->message); ?></td>
            <td>
                <a class="salawat-delete-link"
                    href="<?php echo esc_url($this->get_delete_pledge_url((int) $row->id, $start_date, $end_date)); ?>"
                    onclick="return confirm('<?php echo esc_js(__('Delete this Salawat pledge? This cannot be undone.', 'salawat-counter')); ?>');">
                    <?php esc_html_e('Delete', 'salawat-counter'); ?>
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
<?php
	}

	/**
	 * Build a nonce-protected delete URL.
	 *
	 * @param int    $pledge_id Pledge ID.
	 * @param string $start_date Current filter start date.
	 * @param string $end_date Current filter end date.
	 * @return string
	 */
	private function get_delete_pledge_url($pledge_id, $start_date = '', $end_date = '')
	{
		$url = add_query_arg(
			array(
				'page' => 'salawat-stats',
				'salawat_action' => 'delete_pledge',
				'pledge_id' => absint($pledge_id),
				'start_date' => $start_date,
				'end_date' => $end_date,
			),
			admin_url('admin.php')
		);

		return wp_nonce_url($url, 'salawat_delete_pledge_' . absint($pledge_id));
	}

	/**
	 * Handle deleting a pledge from admin.
	 *
	 * @return void
	 */
	public function handle_delete_pledge()
	{
		if (empty($_GET['salawat_action']) || 'delete_pledge' !== sanitize_key(wp_unslash($_GET['salawat_action']))) {
			return;
		}

		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to delete pledges.', 'salawat-counter'), 403);
		}

		$pledge_id = isset($_GET['pledge_id']) ? absint($_GET['pledge_id']) : 0;

		if (!$pledge_id) {
			wp_die(esc_html__('Invalid pledge ID.', 'salawat-counter'), 400);
		}

		check_admin_referer('salawat_delete_pledge_' . $pledge_id);

		global $wpdb;

		$wpdb->delete($this->table_name, array('id' => $pledge_id), array('%d'));
		$this->clear_stats_cache();

		$redirect_url = add_query_arg(
			array(
				'page' => 'salawat-stats',
				'deleted' => '1',
				'start_date' => isset($_GET['start_date']) ? $this->sanitize_date(wp_unslash($_GET['start_date'])) : '',
				'end_date' => isset($_GET['end_date']) ? $this->sanitize_date(wp_unslash($_GET['end_date'])) : '',
			),
			admin_url('admin.php')
		);

		wp_safe_redirect($redirect_url);
		exit;
	}

	/**
	 * Handle CSV export.
	 *
	 * @return void
	 */
	public function handle_csv_export()
	{
		if (empty($_GET['salawat_export']) || 'csv' !== $_GET['salawat_export']) {
			return;
		}

		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to export pledges.', 'salawat-counter'), 403);
		}

		check_admin_referer('salawat_export_csv');

		global $wpdb;

		$start_date = isset($_GET['start_date']) ? $this->sanitize_date(wp_unslash($_GET['start_date'])) : '';
		$end_date = isset($_GET['end_date']) ? $this->sanitize_date(wp_unslash($_GET['end_date'])) : '';
		$where = array();
		$params = array();

		if ($start_date) {
			$where[] = 'created_at >= %s';
			$params[] = $start_date . ' 00:00:00';
		}

		if ($end_date) {
			$where[] = 'created_at <= %s';
			$params[] = $end_date . ' 23:59:59';
		}

		$sql = "SELECT id, name, email, salawat_amount, message, is_anonymous, created_at FROM {$this->table_name}";

		if ($where) {
			$sql .= ' WHERE ' . implode(' AND ', $where);
		}

		$sql .= ' ORDER BY created_at DESC';

		if ($params) {
			$sql = $wpdb->prepare($sql, $params);
		}

		$rows = $wpdb->get_results($sql, ARRAY_A);

		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=salawat-pledges-' . gmdate('Y-m-d') . '.csv');

		$output = fopen('php://output', 'w');
		fputcsv($output, array('ID', 'Name', 'Email', 'Salawat Amount', 'Message', 'Anonymous', 'Created At'));

		foreach ($rows as $row) {
			fputcsv($output, $row);
		}

		fclose($output);
		exit;
	}

	/**
	 * Get chart data grouped by day.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return array
	 */
	private function get_daily_chart_data($start_date, $end_date)
	{
		global $wpdb;

		if (!$this->table_exists()) {
			return array();
		}

		$where = array();
		$params = array();

		if ($start_date) {
			$where[] = 'created_at >= %s';
			$params[] = $start_date . ' 00:00:00';
		}

		if ($end_date) {
			$where[] = 'created_at <= %s';
			$params[] = $end_date . ' 23:59:59';
		}

		$sql = "SELECT DATE(created_at) AS pledge_day, COALESCE(SUM(salawat_amount), 0) AS total_amount
			FROM {$this->table_name}";

		if ($where) {
			$sql .= ' WHERE ' . implode(' AND ', $where);
		}

		$sql .= ' GROUP BY DATE(created_at) ORDER BY pledge_day ASC LIMIT 90';

		if ($params) {
			$sql = $wpdb->prepare($sql, $params);
		}

		return $wpdb->get_results($sql, ARRAY_A);
	}

	/**
	 * Sanitize date string.
	 *
	 * @param string $date Date.
	 * @return string
	 */
	private function sanitize_date($date)
	{
		$date = sanitize_text_field($date);
		return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
	}

	/**
	 * Register Bricks dynamic tags.
	 *
	 * @param array $tags Existing tags.
	 * @return array
	 */
	public function register_bricks_dynamic_tags($tags = array())
	{
		if (!is_array($tags)) {
			$tags = array();
		}

		$group = __('Salawat Counter', 'salawat-counter');

		$tags[] = array('name' => '{salawat_total}', 'label' => __('Salawat Total', 'salawat-counter'), 'group' => $group);
		$tags[] = array('name' => '{salawat_today}', 'label' => __('Salawat Today', 'salawat-counter'), 'group' => $group);
		$tags[] = array('name' => '{salawat_week}', 'label' => __('Salawat This Week', 'salawat-counter'), 'group' => $group);
		$tags[] = array('name' => '{salawat_month}', 'label' => __('Salawat This Month', 'salawat-counter'), 'group' => $group);
		$tags[] = array('name' => '{salawat_participants}', 'label' => __('Salawat Participants', 'salawat-counter'), 'group' => $group);

		return $tags;
	}

	/**
	 * Render single Bricks tag.
	 *
	 * @param mixed  $value Existing value.
	 * @param string $tag Tag.
	 * @param mixed  $post Post.
	 * @return mixed
	 */
	public function render_bricks_dynamic_tag($value = '', $tag = '', $post = null)
	{
		// In Bricks 1.9+, $tag is the base tag name (e.g. salawat_total)
		// and $value is the full tag (e.g. {salawat_total:raw})
		// We use the full tag to detect filters.
		$tag_to_process = (is_string($value) && strpos($value, '{') !== false) ? $value : $tag;
		
		$rendered = $this->get_dynamic_tag_value($tag_to_process);
		return null === $rendered ? $value : $rendered;
	}

	/**
	 * Render Bricks content containing tags.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function render_bricks_dynamic_content($content = '')
	{
		if (!is_string($content) || '' === $content) {
			return $content;
		}

		// Use regex to match tags only when they are not followed by a colon (which would indicate a filter)
		// This prevents str_replace from corrupting tags like {salawat_total:number}
		$tags = array('salawat_total', 'salawat_today', 'salawat_week', 'salawat_month', 'salawat_participants');

		foreach ($tags as $tag_name) {
			$pattern = '/\{' . preg_quote($tag_name, '/') . '\}/';
			if (preg_match($pattern, $content)) {
				$content = preg_replace($pattern, $this->get_dynamic_tag_value($tag_name), $content);
			}
		}

		return $content;
	}

	/**
	 * Get dynamic tag value.
	 *
	 * @param string $tag Tag.
	 * @return string|null
	 */
	private function get_dynamic_tag_value($tag)
	{
		if (!is_scalar($tag)) {
			return null;
		}

		$tag = trim((string) $tag, '{}');
		// Handle Bricks filters by stripping anything after a colon
		$tag_parts = explode(':', $tag);
		$base_tag  = $tag_parts[0];
		$filter    = isset($tag_parts[1]) ? $tag_parts[1] : '';

		$stats = $this->get_stats();
		$value = null;

		switch ($base_tag) {
			case 'salawat_total':
				$value = $stats['total'];
				break;
			case 'salawat_today':
				$value = $stats['today'];
				break;
			case 'salawat_week':
				$value = $stats['week'];
				break;
			case 'salawat_month':
				$value = $stats['month'];
				break;
			case 'salawat_participants':
				$value = $stats['participants'];
				break;
		}

		if (null === $value) {
			return null;
		}

		// If user requested raw value or numeric value, return unformatted
		if (in_array($filter, array('raw', 'value', 'plain'), true)) {
			return $value;
		}

		return $this->format_number($value);

		return null;
	}
}
