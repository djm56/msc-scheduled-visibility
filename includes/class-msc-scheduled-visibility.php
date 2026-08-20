<?php
/**
 * Main bootstrap class for MSC Scheduled Visibility.
 *
 * @package MSC_Scheduled_Visibility
 */

namespace MSC_Scheduled_Visibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton container: owns the option store and wires up every feature class.
 */
class Plugin {

	/**
	 * Option storage key.
	 */
	const OPTION_KEY = 'mscsv_options';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Module instance.
	 *
	 * @var Module|null
	 */
	private $module = null;

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Quick/bulk edit instance.
	 *
	 * @var Quick_Edit|null
	 */
	private $quick_edit = null;

	/**
	 * WooCommerce integration instance.
	 *
	 * @var WooCommerce|null
	 */
	private $woocommerce = null;

	/**
	 * Preview instance.
	 *
	 * @var Preview|null
	 */
	private $preview = null;

	/**
	 * Audit log instance.
	 *
	 * @var Audit_Log|null
	 */
	private $audit_log = null;

	/**
	 * REST controller instance.
	 *
	 * @var REST|null
	 */
	private $rest = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Activate plugin.
	 *
	 * @return void
	 */
	public static function activate() {
		$options = get_option( self::OPTION_KEY );

		if ( ! is_array( $options ) ) {
			update_option( self::OPTION_KEY, self::default_options() );
		} else {
			// Backfill keys added by later versions without touching existing choices.
			update_option( self::OPTION_KEY, wp_parse_args( $options, self::default_options() ) );
		}

		update_option( 'mscsv_version', MSCSV_PLUGIN_VERSION );

		self::ensure_cron();
	}

	/**
	 * Make sure the recurrence exists and the check event is scheduled.
	 *
	 * Called on activation and on every load, so an event lost to a failed update
	 * or a cron table reset repairs itself.
	 *
	 * @return void
	 */
	public static function ensure_cron() {
		/*
		 * Declared here as well as in Module::register_cron_schedule(): on the
		 * activation request WordPress includes the plugin file and fires the
		 * activation hook after `plugins_loaded` has already passed, so Module has not
		 * been constructed and its filter is not registered. Without this,
		 * wp_schedule_event() would be handed a recurrence WordPress does not know.
		 * Keep the interval in step with the Module copy.
		 */
		add_filter(
			'cron_schedules',
			static function ( $schedules ) {
				$schedules[ Module::CRON_RECURRENCE ] = array(
					'interval' => 900,
					'display'  => 'Every 15 Minutes',
				);
				return $schedules;
			}
		);

		/*
		 * Releases up to 1.2.0 scheduled against a `mscsv_five_minutes` recurrence that
		 * no longer exists. WordPress cannot work out the next run for a recurrence it
		 * does not know, so such an event would fire once more and then stop repeating.
		 * Clear it and reschedule on the current recurrence.
		 */
		$existing = wp_get_scheduled_event( 'mscsv_cron_check' );

		if ( $existing && Module::CRON_RECURRENCE !== $existing->schedule ) {
			wp_clear_scheduled_hook( 'mscsv_cron_check' );
			$existing = false;
		}

		if ( ! $existing ) {
			wp_schedule_event( time(), Module::CRON_RECURRENCE, 'mscsv_cron_check' );
		}
	}

	/**
	 * Timestamp of the next scheduled visibility check.
	 *
	 * @return int|false
	 */
	public function next_cron_run() {
		return wp_next_scheduled( 'mscsv_cron_check' );
	}

	/**
	 * Deactivate plugin.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'mscsv_cron_check' );
	}

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	private function __construct() {
		$this->audit_log = new Audit_Log( $this );
		$this->module    = new Module( $this );
		$this->settings  = new Settings( $this, $this->module, $this->audit_log );

		$this->quick_edit  = new Quick_Edit( $this, $this->module );
		$this->woocommerce = new WooCommerce( $this, $this->module );
		$this->preview     = new Preview( $this, $this->module );
		$this->rest        = new REST( $this, $this->module );
	}

	/**
	 * Module accessor.
	 *
	 * @return Module|null
	 */
	public function module() {
		return $this->module;
	}

	/**
	 * Audit log accessor.
	 *
	 * @return Audit_Log|null
	 */
	public function audit_log() {
		return $this->audit_log;
	}

	/**
	 * WooCommerce integration accessor.
	 *
	 * @return WooCommerce|null
	 */
	public function woocommerce() {
		return $this->woocommerce;
	}

	/**
	 * Default options.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_options() {
		return array(
			'module_enabled'           => 1,
			'post_types'               => array( 'post', 'page' ),
			'block_scheduling_enabled' => 1,
			'recurring_enabled'        => 1,
			'roles_enabled'            => 1,
			'woocommerce_enabled'      => 1,
			'preview_enabled'          => 1,
			'audit_log_enabled'        => 1,
			'audit_log_limit'          => 200,
			'rest_enabled'             => 1,
		);
	}

	/**
	 * Option getter.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Value to return when the key is absent.
	 * @return mixed
	 */
	public function get_option( $key, $fallback = null ) {
		$options = wp_parse_args( get_option( self::OPTION_KEY, array() ), self::default_options() );
		return array_key_exists( $key, $options ) ? $options[ $key ] : $fallback;
	}

	/**
	 * Save merged options.
	 *
	 * @param array<string,mixed> $new_options New values.
	 * @return bool
	 */
	public function update_options( $new_options ) {
		$current = wp_parse_args( get_option( self::OPTION_KEY, array() ), self::default_options() );
		$merged  = array_merge( $current, $new_options );
		return (bool) update_option( self::OPTION_KEY, $merged );
	}
}
