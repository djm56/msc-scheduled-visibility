<?php
/**
 * Visibility history log for MSC Scheduled Visibility.
 *
 * @package MSC_Scheduled_Visibility
 */

namespace MSC_Scheduled_Visibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records every visibility transition so site owners can see what changed and when.
 *
 * Entries live in a single capped option rather than a custom table — the log is
 * bounded, read only from the settings screen, and a table would mean migration
 * code for what amounts to a rolling buffer.
 */
class Audit_Log {

	/**
	 * Option key holding the log entries.
	 */
	const OPTION_KEY = 'mscsv_audit_log';

	/**
	 * Hard ceiling on retained entries.
	 */
	const MAX_ENTRIES = 500;

	/**
	 * Main plugin instance.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;

		add_action( 'admin_post_mscsv_clear_log', array( $this, 'handle_clear' ) );

		if ( ! $this->is_enabled() ) {
			return;
		}

		add_action( 'mscsv_status_changed', array( $this, 'record_transition' ), 10, 3 );
	}

	/**
	 * Whether logging is switched on.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) $this->plugin->get_option( 'audit_log_enabled', 1 );
	}

	/**
	 * How many entries to retain, honouring the configured limit.
	 *
	 * @return int
	 */
	public function get_limit() {
		$limit = (int) $this->plugin->get_option( 'audit_log_limit', 200 );

		return max( 10, min( self::MAX_ENTRIES, $limit ) );
	}

	/**
	 * Record a status transition.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $new_status New status.
	 * @param string $old_status Previous status.
	 * @return void
	 */
	public function record_transition( $post_id, $new_status, $old_status ) {
		// A first-time save has no prior status; that is not a transition worth logging.
		if ( '' === (string) $old_status ) {
			return;
		}

		$this->add(
			array(
				'post_id'    => (int) $post_id,
				'title'      => get_the_title( $post_id ),
				'from'       => (string) $old_status,
				'to'         => (string) $new_status,
				'time'       => time(),
				'actor'      => $this->describe_actor(),
				'actor_type' => $this->actor_type(),
			)
		);
	}

	/**
	 * Append an entry, trimming the log to its limit.
	 *
	 * @param array<string,mixed> $entry Entry data.
	 * @return void
	 */
	public function add( $entry ) {
		$log = $this->get_entries();

		// Newest first, so trimming from the tail drops the oldest.
		array_unshift( $log, $entry );

		$log = array_slice( $log, 0, $this->get_limit() );

		update_option( self::OPTION_KEY, $log, false );
	}

	/**
	 * Read all entries, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_entries() {
		$log = get_option( self::OPTION_KEY, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Entries for a single post, newest first.
	 *
	 * @param int $post_id Post ID.
	 * @param int $limit   Maximum entries to return.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_entries_for_post( $post_id, $limit = 10 ) {
		$post_id = (int) $post_id;

		$matches = array_filter(
			$this->get_entries(),
			static function ( $entry ) use ( $post_id ) {
				return isset( $entry['post_id'] ) && (int) $entry['post_id'] === $post_id;
			}
		);

		return array_slice( array_values( $matches ), 0, $limit );
	}

	/**
	 * Delete every entry.
	 *
	 * @return void
	 */
	public function clear() {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Handle the "Clear history" form submission.
	 *
	 * @return void
	 */
	public function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'msc-scheduled-visibility' ) );
		}

		check_admin_referer( 'mscsv_clear_log' );

		$this->clear();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'mscsv-settings',
					'tab'     => 'history',
					'updated' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Short description of whoever triggered a transition.
	 *
	 * @return string
	 */
	private function describe_actor() {
		$user = wp_get_current_user();

		if ( ! wp_doing_cron() && $user && $user->exists() ) {
			return $user->display_name;
		}

		return '';
	}

	/**
	 * Stable token for whoever triggered a transition.
	 *
	 * Kept separate from the display name so nothing translated is written to the
	 * database. A label stored at write time would be frozen in the locale of the
	 * request that happened to make it — the site locale for cron, the editor's own
	 * locale for a manual save — leaving the History tab a mix of languages that
	 * ignores the locale of whoever is reading it.
	 *
	 * @return string One of `cron`, `user` or `system`.
	 */
	private function actor_type() {
		if ( wp_doing_cron() ) {
			return 'cron';
		}

		$user = wp_get_current_user();

		if ( $user && $user->exists() ) {
			return 'user';
		}

		return 'system';
	}

	/**
	 * Human-readable actor for a log entry, translated at read time.
	 *
	 * @param array<string,mixed> $entry Log entry.
	 * @return string
	 */
	public function actor_label( $entry ) {
		$type = isset( $entry['actor_type'] ) ? (string) $entry['actor_type'] : '';
		$name = isset( $entry['actor'] ) ? (string) $entry['actor'] : '';

		if ( 'cron' === $type ) {
			return __( 'Scheduled task', 'msc-scheduled-visibility' );
		}

		if ( 'system' === $type ) {
			return __( 'System', 'msc-scheduled-visibility' );
		}

		if ( '' !== $name ) {
			return $name;
		}

		// Entries written before actor_type existed stored a display name, or a label
		// already translated into whatever locale wrote it. Pass those through as-is.
		return '' === $type ? $name : __( 'System', 'msc-scheduled-visibility' );
	}
}
