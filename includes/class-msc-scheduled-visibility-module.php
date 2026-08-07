<?php
/**
 * Module class for MSC Scheduled Visibility.
 *
 * Handles all scheduling features: basic post scheduling, block-level scheduling,
 * recurring schedules, and role-based visibility.
 *
 * @package MSC_Scheduled_Visibility
 */

namespace MSC_Scheduled_Visibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The scheduling engine: decides visibility, renders the admin UI, and keeps the
 * stored status current.
 */
class Module {

	/**
	 * Number of posts processed per cron batch.
	 */
	const CRON_BATCH_SIZE = 200;

	/**
	 * Main plugin instance.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Per-request visibility cache, keyed by "post_id:user_id".
	 *
	 * @var array<string,bool>
	 */
	private $visibility_cache = array();

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;

		// Cron interval must be registered even when the module is switched off so
		// that an already-scheduled event keeps a valid recurrence.
		add_filter( 'cron_schedules', array( $this, 'register_cron_schedule' ) );

		if ( ! $this->is_enabled() ) {
			return;
		}

		// Admin hooks.
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		add_action( 'save_post', array( $this, 'save_metabox' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save_extended_meta' ), 11, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );

		// Admin columns for each enabled post type.
		foreach ( $this->get_post_types() as $pt ) {
			add_filter( "manage_{$pt}_posts_columns", array( $this, 'add_visibility_column' ) );
			add_action( "manage_{$pt}_posts_custom_column", array( $this, 'render_visibility_column' ), 10, 2 );
		}

		// Cron callback.
		add_action( 'mscsv_cron_check', array( $this, 'cron_check_visibility' ) );

		// Frontend visibility filtering.
		if ( ! is_admin() ) {
			add_action( 'pre_get_posts', array( $this, 'filter_frontend_query' ) );
			add_filter( 'posts_results', array( $this, 'filter_posts_results' ), 10, 2 );
			// Second pass: the meta_query above can only test the cron-written status,
			// so recurring windows and per-user role rules are applied here.
			add_filter( 'the_posts', array( $this, 'filter_the_posts' ), 10, 2 );
		}

		// Content safety net.
		add_filter( 'the_content', array( $this, 'filter_content' ), 20 );

		// Recurring visibility check.
		if ( $this->plugin->get_option( 'recurring_enabled', 1 ) ) {
			add_filter( 'mscsv_is_visible', array( $this, 'check_recurring_visibility' ), 10, 3 );
		}

		// Role-based visibility check.
		if ( $this->plugin->get_option( 'roles_enabled', 1 ) ) {
			add_filter( 'mscsv_is_visible', array( $this, 'check_role_visibility' ), 20, 3 );
		}

		// Block-level content filtering. Core attaches do_blocks() to the_content at
		// priority 9, so this must run before that to still see block delimiters.
		if ( ! is_admin() && $this->plugin->get_option( 'block_scheduling_enabled', 1 ) ) {
			add_filter( 'the_content', array( $this, 'filter_block_visibility' ), 8 );
		}

		// Metabox extensions for recurring and roles.
		add_action( 'mscsv_metabox_after_fields', array( $this, 'render_metabox_extended_fields' ) );

		// Block editor: register meta and enqueue sidebar script.
		if ( $this->plugin->get_option( 'block_scheduling_enabled', 1 ) ) {
			add_action( 'init', array( $this, 'register_block_schedule_meta' ) );
			add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
		}
	}

	/**
	 * Post types that scheduling applies to.
	 *
	 * @return array<int,string>
	 */
	public function get_post_types() {
		$post_types = (array) $this->plugin->get_option( 'post_types', array( 'post', 'page' ) );

		return array_values( array_filter( array_map( 'strval', $post_types ) ) );
	}

	// =========================================================================
	// Admin: Metabox
	// =========================================================================

	/**
	 * Register the metabox on enabled post types.
	 *
	 * @return void
	 */
	public function register_metabox() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$post_types = $this->get_post_types();

		foreach ( $post_types as $pt ) {
			add_meta_box(
				'mscsv_schedule',
				__( 'Scheduled Visibility', 'msc-scheduled-visibility' ),
				array( $this, 'render_metabox' ),
				$pt,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render metabox contents.
	 *
	 * @param \WP_Post $post Current post object.
	 * @return void
	 */
	public function render_metabox( $post ) {
		wp_nonce_field( 'mscsv_metabox_nonce', 'mscsv_nonce' );

		$enabled    = (int) get_post_meta( $post->ID, '_mscsv_enabled', true );
		$start_date = get_post_meta( $post->ID, '_mscsv_start_date', true );
		$start_time = get_post_meta( $post->ID, '_mscsv_start_time', true );
		$end_date   = get_post_meta( $post->ID, '_mscsv_end_date', true );
		$end_time   = get_post_meta( $post->ID, '_mscsv_end_time', true );
		$status     = get_post_meta( $post->ID, '_mscsv_status', true );
		?>
		<div class="mscsv-metabox">
			<p>
				<label>
					<input type="checkbox" id="mscsv_enabled" name="mscsv_enabled" value="1" <?php checked( 1, $enabled ); ?> />
					<?php esc_html_e( 'Enable scheduled visibility', 'msc-scheduled-visibility' ); ?>
				</label>
			</p>

			<div class="mscsv-fields" <?php echo $enabled ? '' : 'style="display:none;"'; ?>>
				<p>
					<label for="mscsv_start_date"><strong><?php esc_html_e( 'Visible From', 'msc-scheduled-visibility' ); ?></strong></label><br />
					<input type="date" id="mscsv_start_date" name="mscsv_start_date" value="<?php echo esc_attr( $start_date ); ?>" />
					<input type="time" id="mscsv_start_time" name="mscsv_start_time" value="<?php echo esc_attr( $start_time ); ?>" />
				</p>
				<p>
					<label for="mscsv_end_date"><strong><?php esc_html_e( 'Visible Until', 'msc-scheduled-visibility' ); ?></strong></label><br />
					<input type="date" id="mscsv_end_date" name="mscsv_end_date" value="<?php echo esc_attr( $end_date ); ?>" />
					<input type="time" id="mscsv_end_time" name="mscsv_end_time" value="<?php echo esc_attr( $end_time ); ?>" />
				</p>
				<?php if ( $status ) : ?>
					<p class="mscsv-current-status">
						<?php esc_html_e( 'Status:', 'msc-scheduled-visibility' ); ?>
						<span class="mscsv-badge mscsv-badge--<?php echo esc_attr( $status ); ?>">
							<?php echo esc_html( $this->status_label( $status ) ); ?>
						</span>
					</p>
				<?php endif; ?>
			</div>

			<?php do_action( 'mscsv_metabox_after_fields', $post ); ?>
		</div>
		<?php
	}

	/**
	 * Render recurring and role-based fields in the metabox.
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	public function render_metabox_extended_fields( $post ) {
		if ( $this->plugin->get_option( 'recurring_enabled', 1 ) ) {
			$this->render_recurring_fields( $post );
		}

		if ( $this->plugin->get_option( 'roles_enabled', 1 ) ) {
			$this->render_role_fields( $post );
		}
	}

	/**
	 * Render recurring schedule fields.
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	private function render_recurring_fields( $post ) {
		$enabled      = (int) get_post_meta( $post->ID, '_mscsv_recurring_enabled', true );
		$pattern      = get_post_meta( $post->ID, '_mscsv_recurring_pattern', true );
		$days         = get_post_meta( $post->ID, '_mscsv_recurring_days', true );
		$day_of_month = get_post_meta( $post->ID, '_mscsv_recurring_day_of_month', true );
		$start_time   = get_post_meta( $post->ID, '_mscsv_recurring_start_time', true );
		$end_time     = get_post_meta( $post->ID, '_mscsv_recurring_end_time', true );
		$valid_from   = get_post_meta( $post->ID, '_mscsv_recurring_valid_from', true );
		$valid_until  = get_post_meta( $post->ID, '_mscsv_recurring_valid_until', true );

		if ( ! is_array( $days ) ) {
			$days = array();
		}

		$day_labels = array(
			0 => __( 'Sun', 'msc-scheduled-visibility' ),
			1 => __( 'Mon', 'msc-scheduled-visibility' ),
			2 => __( 'Tue', 'msc-scheduled-visibility' ),
			3 => __( 'Wed', 'msc-scheduled-visibility' ),
			4 => __( 'Thu', 'msc-scheduled-visibility' ),
			5 => __( 'Fri', 'msc-scheduled-visibility' ),
			6 => __( 'Sat', 'msc-scheduled-visibility' ),
		);
		?>
		<div class="mscsv-extra-section mscsv-recurring-section">
			<hr />
			<p>
				<label>
					<input type="checkbox" id="mscsv_recurring_enabled" name="mscsv_recurring_enabled" value="1" <?php checked( 1, $enabled ); ?> />
					<strong><?php esc_html_e( 'Enable recurring schedule', 'msc-scheduled-visibility' ); ?></strong>
				</label>
			</p>

			<div class="mscsv-recurring-fields" <?php echo $enabled ? '' : 'style="display:none;"'; ?>>
				<p>
					<label for="mscsv_recurring_pattern"><?php esc_html_e( 'Pattern:', 'msc-scheduled-visibility' ); ?></label>
					<select id="mscsv_recurring_pattern" name="mscsv_recurring_pattern">
						<option value="daily" <?php selected( 'daily', $pattern ); ?>><?php esc_html_e( 'Daily', 'msc-scheduled-visibility' ); ?></option>
						<option value="weekly" <?php selected( 'weekly', $pattern ); ?>><?php esc_html_e( 'Weekly', 'msc-scheduled-visibility' ); ?></option>
						<option value="monthly" <?php selected( 'monthly', $pattern ); ?>><?php esc_html_e( 'Monthly', 'msc-scheduled-visibility' ); ?></option>
					</select>
				</p>

				<p class="mscsv-recurring-days" <?php echo 'weekly' === $pattern ? '' : 'style="display:none;"'; ?>>
					<label><?php esc_html_e( 'Days:', 'msc-scheduled-visibility' ); ?></label><br />
					<?php foreach ( $day_labels as $num => $label ) : ?>
						<label style="display:inline-block;margin-right:8px;">
							<input type="checkbox" name="mscsv_recurring_days[]" value="<?php echo esc_attr( $num ); ?>" <?php checked( in_array( $num, array_map( 'intval', $days ), true ) ); ?> />
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</p>

				<p class="mscsv-recurring-dom" <?php echo 'monthly' === $pattern ? '' : 'style="display:none;"'; ?>>
					<label for="mscsv_recurring_day_of_month"><?php esc_html_e( 'Day of month:', 'msc-scheduled-visibility' ); ?></label>
					<input type="number" id="mscsv_recurring_day_of_month" name="mscsv_recurring_day_of_month" value="<?php echo esc_attr( $day_of_month ); ?>" min="1" max="31" style="width:60px;" />
				</p>

				<p>
					<label><?php esc_html_e( 'Time window:', 'msc-scheduled-visibility' ); ?></label><br />
					<input type="time" name="mscsv_recurring_start_time" value="<?php echo esc_attr( $start_time ); ?>" />
					<?php esc_html_e( 'to', 'msc-scheduled-visibility' ); ?>
					<input type="time" name="mscsv_recurring_end_time" value="<?php echo esc_attr( $end_time ); ?>" />
				</p>

				<p>
					<label><?php esc_html_e( 'Valid from:', 'msc-scheduled-visibility' ); ?></label>
					<input type="date" name="mscsv_recurring_valid_from" value="<?php echo esc_attr( $valid_from ); ?>" />
					<label><?php esc_html_e( 'until:', 'msc-scheduled-visibility' ); ?></label>
					<input type="date" name="mscsv_recurring_valid_until" value="<?php echo esc_attr( $valid_until ); ?>" />
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render role-based visibility fields.
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	private function render_role_fields( $post ) {
		$enabled          = (int) get_post_meta( $post->ID, '_mscsv_roles_enabled', true );
		$allowed_roles    = get_post_meta( $post->ID, '_mscsv_roles', true );
		$allow_logged_out = (int) get_post_meta( $post->ID, '_mscsv_roles_logged_out', true );

		if ( ! is_array( $allowed_roles ) ) {
			$allowed_roles = array();
		}

		$all_roles = wp_roles()->roles;
		?>
		<div class="mscsv-extra-section mscsv-roles-section">
			<hr />
			<p>
				<label>
					<input type="checkbox" id="mscsv_roles_enabled" name="mscsv_roles_enabled" value="1" <?php checked( 1, $enabled ); ?> />
					<strong><?php esc_html_e( 'Enable role-based visibility', 'msc-scheduled-visibility' ); ?></strong>
				</label>
			</p>

			<div class="mscsv-roles-fields" <?php echo $enabled ? '' : 'style="display:none;"'; ?>>
				<p>
					<label><?php esc_html_e( 'Visible to:', 'msc-scheduled-visibility' ); ?></label><br />
					<?php foreach ( $all_roles as $slug => $role_data ) : ?>
						<label style="display:block;margin:2px 0;">
							<input type="checkbox" name="mscsv_roles[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $allowed_roles, true ) ); ?> />
							<?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?>
						</label>
					<?php endforeach; ?>
				</p>
				<p>
					<label>
						<input type="checkbox" name="mscsv_roles_logged_out" value="1" <?php checked( 1, $allow_logged_out ); ?> />
						<?php esc_html_e( 'Also visible to logged-out visitors', 'msc-scheduled-visibility' ); ?>
					</label>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Save metabox data.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function save_metabox( $post_id, $post ) {
		if ( ! $this->can_save_meta( $post_id, $post ) ) {
			return;
		}

		// Verify nonce.
		if ( ! isset( $_POST['mscsv_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mscsv_nonce'] ) ), 'mscsv_metabox_nonce' )
		) {
			return;
		}

		$this->set_base_schedule(
			$post_id,
			array(
				'enabled'    => isset( $_POST['mscsv_enabled'] ),
				'start_date' => isset( $_POST['mscsv_start_date'] ) ? wp_unslash( $_POST['mscsv_start_date'] ) : '',
				'start_time' => isset( $_POST['mscsv_start_time'] ) ? wp_unslash( $_POST['mscsv_start_time'] ) : '',
				'end_date'   => isset( $_POST['mscsv_end_date'] ) ? wp_unslash( $_POST['mscsv_end_date'] ) : '',
				'end_time'   => isset( $_POST['mscsv_end_time'] ) ? wp_unslash( $_POST['mscsv_end_time'] ) : '',
			)
		);
	}

	/**
	 * Save recurring and role-based meta fields on post save.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function save_extended_meta( $post_id, $post ) {
		if ( ! $this->can_save_meta( $post_id, $post ) ) {
			return;
		}

		if ( ! isset( $_POST['mscsv_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mscsv_nonce'] ) ), 'mscsv_metabox_nonce' )
		) {
			return;
		}

		if ( $this->plugin->get_option( 'recurring_enabled', 1 ) ) {
			$this->set_recurring_schedule(
				$post_id,
				array(
					'enabled'      => isset( $_POST['mscsv_recurring_enabled'] ),
					'pattern'      => isset( $_POST['mscsv_recurring_pattern'] ) ? wp_unslash( $_POST['mscsv_recurring_pattern'] ) : 'daily',
					'days'         => isset( $_POST['mscsv_recurring_days'] ) ? (array) wp_unslash( $_POST['mscsv_recurring_days'] ) : array(),
					'day_of_month' => isset( $_POST['mscsv_recurring_day_of_month'] ) ? wp_unslash( $_POST['mscsv_recurring_day_of_month'] ) : 0,
					'start_time'   => isset( $_POST['mscsv_recurring_start_time'] ) ? wp_unslash( $_POST['mscsv_recurring_start_time'] ) : '',
					'end_time'     => isset( $_POST['mscsv_recurring_end_time'] ) ? wp_unslash( $_POST['mscsv_recurring_end_time'] ) : '',
					'valid_from'   => isset( $_POST['mscsv_recurring_valid_from'] ) ? wp_unslash( $_POST['mscsv_recurring_valid_from'] ) : '',
					'valid_until'  => isset( $_POST['mscsv_recurring_valid_until'] ) ? wp_unslash( $_POST['mscsv_recurring_valid_until'] ) : '',
				)
			);
		}

		if ( $this->plugin->get_option( 'roles_enabled', 1 ) ) {
			$this->set_role_rules(
				$post_id,
				array(
					'enabled'    => isset( $_POST['mscsv_roles_enabled'] ),
					'roles'      => isset( $_POST['mscsv_roles'] ) ? (array) wp_unslash( $_POST['mscsv_roles'] ) : array(),
					'logged_out' => isset( $_POST['mscsv_roles_logged_out'] ),
				)
			);
		}

		// Recurring changes can flip the stored status, so recompute once both
		// halves of the schedule have landed.
		$this->refresh_status( $post_id );
	}

	/**
	 * Shared guard for every save path: autosave, revision, capability, post type.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post object.
	 * @return bool
	 */
	private function can_save_meta( $post_id, $post = null ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return false;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		$post_type = $post instanceof \WP_Post ? $post->post_type : get_post_type( $post_id );

		return in_array( $post_type, $this->get_post_types(), true );
	}

	// =========================================================================
	// Schedule Writers
	//
	// Shared by the metabox, quick edit, bulk edit and the REST API so every
	// entry point sanitises and stores schedules identically.
	// =========================================================================

	/**
	 * Write the base date-window schedule for a post.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $data    Raw schedule input.
	 * @return void
	 */
	public function set_base_schedule( $post_id, $data ) {
		$enabled = ! empty( $data['enabled'] ) ? 1 : 0;
		update_post_meta( $post_id, '_mscsv_enabled', $enabled );

		if ( ! $enabled ) {
			foreach ( array( 'start_date', 'start_time', 'end_date', 'end_time', 'status' ) as $key ) {
				delete_post_meta( $post_id, '_mscsv_' . $key );
			}
			$this->flush_visibility_cache();
			return;
		}

		update_post_meta( $post_id, '_mscsv_start_date', $this->sanitize_date( isset( $data['start_date'] ) ? $data['start_date'] : '' ) );
		update_post_meta( $post_id, '_mscsv_start_time', $this->sanitize_time( isset( $data['start_time'] ) ? $data['start_time'] : '' ) );
		update_post_meta( $post_id, '_mscsv_end_date', $this->sanitize_date( isset( $data['end_date'] ) ? $data['end_date'] : '' ) );
		update_post_meta( $post_id, '_mscsv_end_time', $this->sanitize_time( isset( $data['end_time'] ) ? $data['end_time'] : '' ) );

		$this->refresh_status( $post_id );
	}

	/**
	 * Write the recurring schedule for a post.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $data    Raw recurrence input.
	 * @return void
	 */
	public function set_recurring_schedule( $post_id, $data ) {
		$enabled = ! empty( $data['enabled'] ) ? 1 : 0;
		update_post_meta( $post_id, '_mscsv_recurring_enabled', $enabled );

		if ( ! $enabled ) {
			foreach ( array( 'pattern', 'days', 'day_of_month', 'start_time', 'end_time', 'valid_from', 'valid_until' ) as $key ) {
				delete_post_meta( $post_id, '_mscsv_recurring_' . $key );
			}
			$this->flush_visibility_cache();
			return;
		}

		$pattern = isset( $data['pattern'] ) ? sanitize_key( $data['pattern'] ) : 'daily';
		if ( ! in_array( $pattern, array( 'daily', 'weekly', 'monthly' ), true ) ) {
			$pattern = 'daily';
		}
		update_post_meta( $post_id, '_mscsv_recurring_pattern', $pattern );

		$days = array();
		if ( ! empty( $data['days'] ) && is_array( $data['days'] ) ) {
			foreach ( $data['days'] as $day ) {
				$day = (int) $day;
				if ( $day >= 0 && $day <= 6 ) {
					$days[] = $day;
				}
			}
		}
		update_post_meta( $post_id, '_mscsv_recurring_days', array_values( array_unique( $days ) ) );

		$day_of_month = isset( $data['day_of_month'] ) ? (int) $data['day_of_month'] : 0;
		$day_of_month = min( 31, max( 1, $day_of_month ) );
		update_post_meta( $post_id, '_mscsv_recurring_day_of_month', $day_of_month );

		update_post_meta( $post_id, '_mscsv_recurring_start_time', $this->sanitize_time( isset( $data['start_time'] ) ? $data['start_time'] : '' ) );
		update_post_meta( $post_id, '_mscsv_recurring_end_time', $this->sanitize_time( isset( $data['end_time'] ) ? $data['end_time'] : '' ) );
		update_post_meta( $post_id, '_mscsv_recurring_valid_from', $this->sanitize_date( isset( $data['valid_from'] ) ? $data['valid_from'] : '' ) );
		update_post_meta( $post_id, '_mscsv_recurring_valid_until', $this->sanitize_date( isset( $data['valid_until'] ) ? $data['valid_until'] : '' ) );

		$this->flush_visibility_cache();
	}

	/**
	 * Write the role-based visibility rules for a post.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $data    Raw role input.
	 * @return void
	 */
	public function set_role_rules( $post_id, $data ) {
		$enabled = ! empty( $data['enabled'] ) ? 1 : 0;
		update_post_meta( $post_id, '_mscsv_roles_enabled', $enabled );

		if ( ! $enabled ) {
			delete_post_meta( $post_id, '_mscsv_roles' );
			delete_post_meta( $post_id, '_mscsv_roles_logged_out' );
			$this->flush_visibility_cache();
			return;
		}

		$roles      = array();
		$registered = array_keys( wp_roles()->roles );

		if ( ! empty( $data['roles'] ) && is_array( $data['roles'] ) ) {
			foreach ( $data['roles'] as $role ) {
				$role = sanitize_key( $role );
				if ( in_array( $role, $registered, true ) ) {
					$roles[] = $role;
				}
			}
		}

		update_post_meta( $post_id, '_mscsv_roles', array_values( array_unique( $roles ) ) );
		update_post_meta( $post_id, '_mscsv_roles_logged_out', ! empty( $data['logged_out'] ) ? 1 : 0 );

		$this->flush_visibility_cache();
	}

	/**
	 * Recompute and store a post's status, firing transition hooks on change.
	 *
	 * @param int $post_id Post ID.
	 * @return string The status now stored.
	 */
	public function refresh_status( $post_id ) {
		$this->flush_visibility_cache();

		if ( ! (int) get_post_meta( $post_id, '_mscsv_enabled', true ) ) {
			delete_post_meta( $post_id, '_mscsv_status' );
			return 'visible';
		}

		$old_status = (string) get_post_meta( $post_id, '_mscsv_status', true );
		$new_status = $this->compute_post_status( $post_id );

		if ( $old_status === $new_status ) {
			return $new_status;
		}

		update_post_meta( $post_id, '_mscsv_status', $new_status );

		/**
		 * Fires whenever a post's stored visibility status changes.
		 *
		 * @param int    $post_id    Post ID.
		 * @param string $new_status New status.
		 * @param string $old_status Previous status.
		 */
		do_action( 'mscsv_status_changed', $post_id, $new_status, $old_status );

		if ( 'visible' === $new_status ) {
			do_action( 'mscsv_after_show_post', $post_id, $new_status, $old_status );
		} else {
			do_action( 'mscsv_before_hide_post', $post_id, $new_status, $old_status );
		}

		return $new_status;
	}

	// =========================================================================
	// Admin: Assets
	// =========================================================================

	/**
	 * Enqueue admin assets on post edit screens.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'mscsv-admin',
			MSCSV_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			MSCSV_PLUGIN_VERSION
		);

		wp_enqueue_script(
			'mscsv-admin',
			MSCSV_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			MSCSV_PLUGIN_VERSION,
			true
		);

		wp_localize_script(
			'mscsv-admin',
			'mscsvAdmin',
			array(
				'chooseOne' => __( 'Choose a time or a role to preview.', 'msc-scheduled-visibility' ),
			)
		);
	}

	// =========================================================================
	// Block Editor: Meta Registration & Script
	// =========================================================================

	/**
	 * Register the block_schedules meta for the REST API.
	 *
	 * @return void
	 */
	public function register_block_schedule_meta() {
		$post_types = $this->get_post_types();

		foreach ( $post_types as $pt ) {
			register_post_meta(
				$pt,
				'_mscsv_block_schedules',
				array(
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => 'string',
					'auth_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}

	/**
	 * Enqueue the block editor sidebar script.
	 *
	 * @return void
	 */
	public function enqueue_block_editor_assets() {
		$asset_file = MSCSV_PLUGIN_DIR . 'build/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			'mscsv-block-scheduling',
			MSCSV_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
	}

	// =========================================================================
	// Admin: Post List Column
	// =========================================================================

	/**
	 * Add visibility column to post list table.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public function add_visibility_column( $columns ) {
		$new_columns = array();
		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'date' === $key ) {
				$new_columns['mscsv_status'] = __( 'Visibility', 'msc-scheduled-visibility' );
			}
		}
		// If 'date' column was not found, append at end.
		if ( ! isset( $new_columns['mscsv_status'] ) ) {
			$new_columns['mscsv_status'] = __( 'Visibility', 'msc-scheduled-visibility' );
		}
		return $new_columns;
	}

	/**
	 * Render visibility column content.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_visibility_column( $column, $post_id ) {
		if ( 'mscsv_status' !== $column ) {
			return;
		}

		/**
		 * Fires inside the visibility column, before its own output.
		 *
		 * Quick Edit uses this to stash the post's current schedule in the row.
		 *
		 * @param int $post_id Post ID.
		 */
		do_action( 'mscsv_visibility_column', $post_id );

		$enabled = (int) get_post_meta( $post_id, '_mscsv_enabled', true );
		$badges  = array();

		if ( $enabled ) {
			$status = (string) get_post_meta( $post_id, '_mscsv_status', true );
			if ( '' === $status ) {
				$status = $this->compute_post_status( $post_id );
			}

			$badges[] = sprintf(
				'<span class="mscsv-badge mscsv-badge--%s">%s</span>',
				esc_attr( $status ),
				esc_html( $this->status_label( $status ) )
			);
		}

		if ( (int) get_post_meta( $post_id, '_mscsv_recurring_enabled', true ) ) {
			$badges[] = '<span class="mscsv-badge mscsv-badge--recurring">' . esc_html__( 'Recurring', 'msc-scheduled-visibility' ) . '</span>';
		}

		if ( (int) get_post_meta( $post_id, '_mscsv_roles_enabled', true ) ) {
			$badges[] = '<span class="mscsv-badge mscsv-badge--roles">' . esc_html__( 'Roles', 'msc-scheduled-visibility' ) . '</span>';
		}

		if ( empty( $badges ) ) {
			echo '&mdash;';
			return;
		}

		echo '<span class="mscsv-badges">' . wp_kses_post( implode( ' ', $badges ) ) . '</span>';

		if ( $enabled ) {
			$next = $this->get_next_transition_time( $post_id, get_post_meta( $post_id, '_mscsv_status', true ) );
			if ( $next ) {
				printf( '<br><small>%s</small>', esc_html( $next ) );
			}
		}
	}

	/**
	 * Human-readable, translatable label for a status key.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public function status_label( $status ) {
		$labels = array(
			'visible'   => __( 'Visible', 'msc-scheduled-visibility' ),
			'scheduled' => __( 'Scheduled', 'msc-scheduled-visibility' ),
			'hidden'    => __( 'Hidden', 'msc-scheduled-visibility' ),
			'expired'   => __( 'Expired', 'msc-scheduled-visibility' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( (string) $status );
	}

	// =========================================================================
	// Admin: Dashboard Widget
	// =========================================================================

	/**
	 * Register the dashboard widget.
	 *
	 * @return void
	 */
	public function register_dashboard_widget() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		wp_add_dashboard_widget(
			'mscsv_dashboard',
			__( 'Scheduled Visibility', 'msc-scheduled-visibility' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * Render the dashboard widget.
	 *
	 * @return void
	 */
	public function render_dashboard_widget() {
		$post_types = $this->get_post_types();

		if ( empty( $post_types ) ) {
			echo '<p>' . esc_html__( 'No post types are enabled for scheduling.', 'msc-scheduled-visibility' ) . '</p>';
			return;
		}

		$posts = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				// Soonest start date first, so the widget leads with what changes next.
				'orderby'        => array(
					'start_date_clause' => 'ASC',
					'title'             => 'ASC',
				),
				'meta_query'     => array(
					array(
						'key'   => '_mscsv_enabled',
						'value' => '1',
					),
					'start_date_clause' => array(
						'key'     => '_mscsv_start_date',
						'compare' => 'EXISTS',
						'type'    => 'DATE',
					),
				),
			)
		);

		// Fall back to any scheduled post when none carry a start date.
		if ( empty( $posts ) ) {
			$posts = get_posts(
				array(
					'post_type'      => $post_types,
					'post_status'    => 'publish',
					'posts_per_page' => 10,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'meta_query'     => array(
						array(
							'key'   => '_mscsv_enabled',
							'value' => '1',
						),
					),
				)
			);
		}

		if ( empty( $posts ) ) {
			echo '<p>' . esc_html__( 'No posts with scheduled visibility.', 'msc-scheduled-visibility' ) . '</p>';
			return;
		}

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Post', 'msc-scheduled-visibility' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'msc-scheduled-visibility' ) . '</th>';
		echo '<th>' . esc_html__( 'Next Change', 'msc-scheduled-visibility' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $posts as $p ) {
			$status = get_post_meta( $p->ID, '_mscsv_status', true );
			$next   = $this->get_next_transition_time( $p->ID, $status );
			$edit   = get_edit_post_link( $p->ID );

			echo '<tr>';
			printf( '<td><a href="%s">%s</a></td>', esc_url( $edit ), esc_html( $p->post_title ) );
			printf(
				'<td><span class="mscsv-badge mscsv-badge--%s">%s</span></td>',
				esc_attr( $status ),
				esc_html( $this->status_label( $status ) )
			);
			printf( '<td>%s</td>', $next ? esc_html( $next ) : '&mdash;' );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	// =========================================================================
	// Frontend: Visibility Filtering
	// =========================================================================

	/**
	 * Filter frontend queries to exclude hidden posts.
	 *
	 * @param \WP_Query $query Query object.
	 * @return void
	 */
	public function filter_frontend_query( $query ) {
		if ( is_admin() ) {
			return;
		}

		// Allow admins/editors to see everything on frontend.
		if ( $this->can_bypass() ) {
			return;
		}

		if ( ! $query->is_main_query() && ! $this->is_query_for_enabled_type( $query ) ) {
			return;
		}

		$post_types = $this->get_post_types();
		$query_type = $query->get( 'post_type' );

		// Only filter if the query involves enabled post types.
		if ( ! empty( $query_type ) ) {
			$query_types = (array) $query_type;
			if ( empty( array_intersect( $query_types, $post_types ) ) ) {
				return;
			}
		}

		// Exclude posts that have scheduling enabled and are currently NOT visible.
		$meta_query = $query->get( 'meta_query' );
		if ( ! is_array( $meta_query ) ) {
			$meta_query = array();
		}

		$meta_query[] = array(
			'relation' => 'OR',
			// Posts without scheduling enabled — always show.
			array(
				'key'     => '_mscsv_enabled',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'   => '_mscsv_enabled',
				'value' => '0',
			),
			// Posts with scheduling enabled and status is visible.
			array(
				'relation' => 'AND',
				array(
					'key'   => '_mscsv_enabled',
					'value' => '1',
				),
				array(
					'key'   => '_mscsv_status',
					'value' => 'visible',
				),
			),
		);

		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Filter individual post results for singular queries.
	 *
	 * @param array<\WP_Post> $posts Array of post objects.
	 * @param \WP_Query       $query Query object.
	 * @return array<\WP_Post>
	 */
	public function filter_posts_results( $posts, $query ) {
		if ( is_admin() || $this->can_bypass() || ! $query->is_singular ) {
			return $posts;
		}

		return $this->reject_hidden_posts( $posts );
	}

	/**
	 * Trim hidden posts from list queries.
	 *
	 * The `pre_get_posts` meta_query can only test the status written by cron. Recurring
	 * windows resolve on a finer granularity than the 5-minute cron, and role rules are
	 * per-visitor and cannot be stored at all, so both are settled here.
	 *
	 * @param array<\WP_Post> $posts Post objects.
	 * @param \WP_Query       $query Query object.
	 * @return array<\WP_Post>
	 */
	public function filter_the_posts( $posts, $query ) {
		if ( is_admin() || empty( $posts ) || $this->can_bypass() ) {
			return $posts;
		}

		// Singular queries were already settled in posts_results so the 404 stands.
		if ( $query->is_singular ) {
			return $posts;
		}

		return $this->reject_hidden_posts( $posts );
	}

	/**
	 * Remove posts the current visitor may not see.
	 *
	 * @param array<\WP_Post> $posts Post objects.
	 * @return array<\WP_Post>
	 */
	private function reject_hidden_posts( $posts ) {
		$post_types = $this->get_post_types();

		foreach ( $posts as $key => $post ) {
			if ( ! isset( $post->ID ) || ! in_array( $post->post_type, $post_types, true ) ) {
				continue;
			}

			if ( ! $this->is_post_visible( $post->ID ) ) {
				unset( $posts[ $key ] );
			}
		}

		return array_values( $posts );
	}

	/**
	 * Content filter — safety net for hidden posts.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function filter_content( $content ) {
		if ( is_admin() || $this->can_bypass() ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id || ! in_array( get_post_type( $post_id ), $this->get_post_types(), true ) ) {
			return $content;
		}

		if ( $this->is_post_visible( $post_id ) ) {
			return $content;
		}

		/**
		 * Replacement markup for a post whose schedule has hidden it.
		 *
		 * Empty by default — a hidden post is normally unreachable, so this only
		 * fires when a theme or plugin renders the content directly.
		 *
		 * @param string $message Replacement content.
		 * @param int    $post_id Post ID.
		 */
		return apply_filters( 'mscsv_hidden_content_message', '', $post_id );
	}

	// =========================================================================
	// Block-Level Scheduling
	// =========================================================================

	/**
	 * Filter individual blocks based on their schedules.
	 *
	 * Runs on `the_content` at priority 8 — ahead of core's do_blocks() at 9 — so
	 * the content still contains block delimiters. Out-of-window blocks are dropped
	 * and the remainder re-serialised, letting core render as usual.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function filter_block_visibility( $content ) {
		if ( $this->can_bypass() || ! has_blocks( $content ) ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id || ! in_array( get_post_type( $post_id ), $this->get_post_types(), true ) ) {
			return $content;
		}

		$schedules = $this->get_block_schedules( $post_id );
		if ( empty( $schedules ) ) {
			return $content;
		}

		$blocks  = parse_blocks( $content );
		$kept    = array();
		$index   = 0;
		$removed = false;

		foreach ( $blocks as $block ) {
			// parse_blocks() emits nameless nodes for the whitespace between blocks.
			// The editor's getBlocks() does not, so they must not consume an index.
			if ( empty( $block['blockName'] ) ) {
				$kept[] = $block;
				continue;
			}

			$key = 'block_' . $index;
			++$index;

			if ( ! $this->is_block_schedule_visible( isset( $schedules[ $key ] ) ? $schedules[ $key ] : null ) ) {
				$removed = true;
				continue;
			}

			$kept[] = $block;
		}

		if ( ! $removed ) {
			return $content;
		}

		return serialize_blocks( $kept );
	}

	/**
	 * Read and normalise the block schedule map for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,array<string,mixed>>
	 */
	public function get_block_schedules( $post_id ) {
		$schedules = get_post_meta( $post_id, '_mscsv_block_schedules', true );

		if ( is_string( $schedules ) && '' !== $schedules ) {
			$schedules = json_decode( $schedules, true );
		}

		return is_array( $schedules ) ? $schedules : array();
	}

	/**
	 * Whether a single block schedule entry currently resolves to visible.
	 *
	 * @param array<string,mixed>|null $schedule Schedule entry, or null when unscheduled.
	 * @return bool
	 */
	private function is_block_schedule_visible( $schedule ) {
		if ( ! is_array( $schedule ) || empty( $schedule['enabled'] ) ) {
			return true;
		}

		$status = $this->compute_status(
			isset( $schedule['start_date'] ) ? $schedule['start_date'] : '',
			isset( $schedule['start_time'] ) ? $schedule['start_time'] : '',
			isset( $schedule['end_date'] ) ? $schedule['end_date'] : '',
			isset( $schedule['end_time'] ) ? $schedule['end_time'] : ''
		);

		return 'visible' === $status;
	}

	// =========================================================================
	// Recurring Schedules
	// =========================================================================

	/**
	 * Check recurring visibility for a post.
	 *
	 * @param bool   $is_visible Current visibility.
	 * @param int    $post_id    Post ID.
	 * @param string $status     Current status from base schedule. Part of the
	 *                           `mscsv_is_visible` signature; unused here because the
	 *                           base verdict already arrives via $is_visible.
	 * @return bool
	 */
	public function check_recurring_visibility( $is_visible, $post_id, $status ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Filter signature.
		// Recurring narrows an already-visible post; it never resurrects a hidden one.
		if ( ! $is_visible ) {
			return false;
		}

		$recurring_enabled = (int) get_post_meta( $post_id, '_mscsv_recurring_enabled', true );
		if ( ! $recurring_enabled ) {
			return $is_visible;
		}

		$pattern     = get_post_meta( $post_id, '_mscsv_recurring_pattern', true );
		$start_time  = get_post_meta( $post_id, '_mscsv_recurring_start_time', true );
		$end_time    = get_post_meta( $post_id, '_mscsv_recurring_end_time', true );
		$valid_from  = get_post_meta( $post_id, '_mscsv_recurring_valid_from', true );
		$valid_until = get_post_meta( $post_id, '_mscsv_recurring_valid_until', true );

		$now = $this->now();

		// Check overall date boundaries.
		if ( ! empty( $valid_from ) ) {
			$from_ts = $this->build_timestamp( $valid_from, '', '00:00' );
			if ( $from_ts && $now < $from_ts ) {
				return false;
			}
		}

		if ( ! empty( $valid_until ) ) {
			$until_ts = $this->build_timestamp( $valid_until, '', '23:59' );
			if ( $until_ts && $now > $until_ts ) {
				return false;
			}
		}

		// Evaluate the pattern against site-local wall-clock time.
		$now_dt = date_create_immutable( '@' . $now )->setTimezone( wp_timezone() );

		$day_of_week  = (int) $now_dt->format( 'w' );
		$day_of_month = (int) $now_dt->format( 'j' );
		$current_time = $now_dt->format( 'H:i' );

		switch ( $pattern ) {
			case 'weekly':
				$days = get_post_meta( $post_id, '_mscsv_recurring_days', true );
				$days = is_array( $days ) ? array_map( 'intval', $days ) : array();
				if ( ! empty( $days ) && ! in_array( $day_of_week, $days, true ) ) {
					return false;
				}
				break;

			case 'monthly':
				$target_day = (int) get_post_meta( $post_id, '_mscsv_recurring_day_of_month', true );
				if ( $target_day ) {
					// Clamp to the last day so day 31 still fires in a 30-day month.
					$days_in_month = (int) $now_dt->format( 't' );
					$effective_day = min( $target_day, $days_in_month );
					if ( $day_of_month !== $effective_day ) {
						return false;
					}
				}
				break;

			case 'daily':
				break;

			default:
				// Unrecognised pattern — leave the decision untouched.
				return $is_visible;
		}

		return $this->is_in_time_window( $current_time, $start_time, $end_time );
	}

	// =========================================================================
	// Role-Based Visibility
	// =========================================================================

	/**
	 * Check role-based visibility for a post.
	 *
	 * @param bool   $is_visible Current visibility.
	 * @param int    $post_id    Post ID.
	 * @param string $status     Current status. Part of the `mscsv_is_visible` signature;
	 *                           unused here because role rules are independent of dates.
	 * @return bool
	 */
	public function check_role_visibility( $is_visible, $post_id, $status ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Filter signature.
		if ( ! $is_visible ) {
			return false;
		}

		$roles_enabled = (int) get_post_meta( $post_id, '_mscsv_roles_enabled', true );
		if ( ! $roles_enabled ) {
			return $is_visible;
		}

		// Admin/editor bypass.
		if ( $this->can_bypass() ) {
			return true;
		}

		$allowed_roles = get_post_meta( $post_id, '_mscsv_roles', true );
		if ( ! is_array( $allowed_roles ) || empty( $allowed_roles ) ) {
			return $is_visible;
		}

		$allow_logged_out = (int) get_post_meta( $post_id, '_mscsv_roles_logged_out', true );

		if ( ! is_user_logged_in() ) {
			return (bool) $allow_logged_out;
		}

		$user       = wp_get_current_user();
		$user_roles = (array) $user->roles;

		return ! empty( array_intersect( $user_roles, $allowed_roles ) );
	}

	// =========================================================================
	// Cron
	// =========================================================================

	/**
	 * Register custom cron schedule interval.
	 *
	 * @param array<string,array> $schedules Existing schedules.
	 * @return array<string,array>
	 */
	public function register_cron_schedule( $schedules ) {
		$schedules['mscsv_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every 5 Minutes', 'msc-scheduled-visibility' ),
		);
		return $schedules;
	}

	/**
	 * Cron callback: recompute status for all scheduled posts.
	 *
	 * @return void
	 */
	public function cron_check_visibility() {
		$post_types = $this->get_post_types();

		if ( empty( $post_types ) ) {
			return;
		}

		$paged = 1;

		// Batched so a site with thousands of scheduled posts does not load them
		// all into memory in a single query.
		do {
			$batch = get_posts(
				array(
					'post_type'              => $post_types,
					'post_status'            => 'publish',
					'posts_per_page'         => self::CRON_BATCH_SIZE,
					'paged'                  => $paged,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_term_cache' => false,
					'meta_query'             => array(
						array(
							'key'   => '_mscsv_enabled',
							'value' => '1',
						),
					),
				)
			);

			foreach ( $batch as $post_id ) {
				$this->refresh_status( $post_id );
			}

			$fetched = count( $batch );
			++$paged;
		} while ( self::CRON_BATCH_SIZE === $fetched );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Whether the module is enabled.
	 *
	 * @return bool
	 */
	private function is_enabled() {
		return (bool) $this->plugin->get_option( 'module_enabled', 1 );
	}

	/**
	 * Whether the current user sees scheduled content regardless of its schedule.
	 *
	 * Editors and above bypass scheduling so they can proof upcoming content.
	 * The preview feature switches this off to render the visitor's view.
	 *
	 * @return bool
	 */
	public function can_bypass() {
		return (bool) apply_filters( 'mscsv_user_can_bypass', current_user_can( 'edit_others_posts' ) );
	}

	/**
	 * Reference "now" as a true Unix timestamp.
	 *
	 * Admins previewing a schedule can simulate a different moment in time; the
	 * `mscsv_current_time` filter is the single source of truth for every
	 * date comparison in the plugin so previews stay consistent.
	 *
	 * @return int
	 */
	public function now() {
		return (int) apply_filters( 'mscsv_current_time', time() );
	}

	/**
	 * Compute the stored status for a post, including its recurring window.
	 *
	 * Role rules are deliberately excluded — they vary per visitor and cannot be
	 * baked into a single stored value.
	 *
	 * @param int $post_id Post ID.
	 * @return string One of: scheduled, visible, hidden, expired.
	 */
	public function compute_post_status( $post_id ) {
		$status = 'visible';

		if ( (int) get_post_meta( $post_id, '_mscsv_enabled', true ) ) {
			$status = $this->compute_status(
				get_post_meta( $post_id, '_mscsv_start_date', true ),
				get_post_meta( $post_id, '_mscsv_start_time', true ),
				get_post_meta( $post_id, '_mscsv_end_date', true ),
				get_post_meta( $post_id, '_mscsv_end_time', true )
			);
		}

		// A post inside its overall window can still be outside today's recurring slot.
		if ( 'visible' === $status
			&& $this->plugin->get_option( 'recurring_enabled', 1 )
			&& (int) get_post_meta( $post_id, '_mscsv_recurring_enabled', true )
			&& ! $this->check_recurring_visibility( true, $post_id, $status )
		) {
			$status = 'hidden';
		}

		return $status;
	}

	/**
	 * Check if a specific post is currently visible to the current visitor.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function is_post_visible( $post_id ) {
		$post_id   = (int) $post_id;
		$cache_key = $post_id . ':' . get_current_user_id();

		if ( isset( $this->visibility_cache[ $cache_key ] ) ) {
			return $this->visibility_cache[ $cache_key ];
		}

		$enabled = (int) get_post_meta( $post_id, '_mscsv_enabled', true );

		// A post with no date window still starts out visible, but recurring and
		// role rules are independent features and must always get a say.
		$status = 'visible';
		if ( $enabled ) {
			$status = $this->compute_status(
				get_post_meta( $post_id, '_mscsv_start_date', true ),
				get_post_meta( $post_id, '_mscsv_start_time', true ),
				get_post_meta( $post_id, '_mscsv_end_date', true ),
				get_post_meta( $post_id, '_mscsv_end_time', true )
			);
		}

		$visible = (bool) apply_filters( 'mscsv_is_visible', 'visible' === $status, $post_id, $status );

		$this->visibility_cache[ $cache_key ] = $visible;

		return $visible;
	}

	/**
	 * Drop every cached visibility decision.
	 *
	 * @return void
	 */
	public function flush_visibility_cache() {
		$this->visibility_cache = array();
	}

	/**
	 * Compute visibility status based on dates and current site time.
	 *
	 * @param string $start_date Start date (YYYY-MM-DD).
	 * @param string $start_time Start time (HH:MM).
	 * @param string $end_date   End date (YYYY-MM-DD).
	 * @param string $end_time   End time (HH:MM).
	 * @return string One of: scheduled, visible, expired.
	 */
	public function compute_status( $start_date, $start_time, $end_date, $end_time ) {
		$now = $this->now();

		$start_timestamp = $this->build_timestamp( $start_date, $start_time, '00:00' );
		// An end date with no time means "through the end of that day".
		$end_timestamp = $this->build_timestamp( $end_date, $end_time, '23:59' );

		// No start and no end = always visible.
		if ( ! $start_timestamp && ! $end_timestamp ) {
			return 'visible';
		}

		// Has start date, no end date.
		if ( $start_timestamp && ! $end_timestamp ) {
			return $now >= $start_timestamp ? 'visible' : 'scheduled';
		}

		// No start date, has end date.
		if ( ! $start_timestamp && $end_timestamp ) {
			return $now <= $end_timestamp ? 'visible' : 'expired';
		}

		// Both start and end exist.
		if ( $now < $start_timestamp ) {
			return 'scheduled';
		}
		if ( $now >= $start_timestamp && $now <= $end_timestamp ) {
			return 'visible';
		}

		return 'expired';
	}

	/**
	 * Build a Unix timestamp from date and time strings using site timezone.
	 *
	 * @param string $date     Date string (YYYY-MM-DD).
	 * @param string $time     Time string (HH:MM).
	 * @param string $fallback Time to assume when $time is empty.
	 * @return int|false Timestamp or false if invalid/empty.
	 */
	public function build_timestamp( $date, $time, $fallback = '00:00' ) {
		if ( empty( $date ) ) {
			return false;
		}

		$time     = ! empty( $time ) ? $time : $fallback;
		$datetime = $date . ' ' . $time . ':00';

		$dto = date_create_immutable( $datetime, wp_timezone() );

		if ( ! $dto ) {
			return false;
		}

		return $dto->getTimestamp();
	}

	/**
	 * Check if current time is within a start/end time window.
	 *
	 * @param string $current Current time (HH:MM).
	 * @param string $start   Start time (HH:MM).
	 * @param string $end     End time (HH:MM).
	 * @return bool
	 */
	private function is_in_time_window( $current, $start, $end ) {
		if ( empty( $start ) && empty( $end ) ) {
			return true;
		}

		if ( empty( $start ) ) {
			$start = '00:00';
		}
		if ( empty( $end ) ) {
			$end = '23:59';
		}

		// A window whose end is before its start wraps past midnight (e.g. 22:00–02:00).
		if ( $start > $end ) {
			return ( $current >= $start || $current <= $end );
		}

		return ( $current >= $start && $current <= $end );
	}

	/**
	 * Get the next transition time for display.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  Current status.
	 * @return string|false Human-readable time string or false.
	 */
	public function get_next_transition_time( $post_id, $status ) {
		if ( 'scheduled' === $status ) {
			$timestamp = $this->build_timestamp(
				get_post_meta( $post_id, '_mscsv_start_date', true ),
				get_post_meta( $post_id, '_mscsv_start_time', true ),
				'00:00'
			);

			if ( $timestamp ) {
				/* translators: %s: localised date and time. */
				return sprintf( __( 'Shows: %s', 'msc-scheduled-visibility' ), $this->format_timestamp( $timestamp ) );
			}
		}

		if ( 'visible' === $status ) {
			$timestamp = $this->build_timestamp(
				get_post_meta( $post_id, '_mscsv_end_date', true ),
				get_post_meta( $post_id, '_mscsv_end_time', true ),
				'23:59'
			);

			if ( $timestamp ) {
				/* translators: %s: localised date and time. */
				return sprintf( __( 'Hides: %s', 'msc-scheduled-visibility' ), $this->format_timestamp( $timestamp ) );
			}
		}

		return false;
	}

	/**
	 * Format a timestamp using the site's configured date and time formats.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	public function format_timestamp( $timestamp ) {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Check if a query is for an enabled post type.
	 *
	 * @param \WP_Query $query Query object.
	 * @return bool
	 */
	private function is_query_for_enabled_type( $query ) {
		$post_types  = $this->get_post_types();
		$query_types = (array) $query->get( 'post_type' );

		if ( empty( $query_types ) || in_array( '', $query_types, true ) || in_array( 'any', $query_types, true ) ) {
			return true;
		}

		return ! empty( array_intersect( $query_types, $post_types ) );
	}

	/**
	 * Sanitize a date string to YYYY-MM-DD format.
	 *
	 * @param string $value Raw date input.
	 * @return string Sanitized date or empty string.
	 */
	private function sanitize_date( $value ) {
		$value = sanitize_text_field( $value );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value;
		}
		return '';
	}

	/**
	 * Sanitize a time string to HH:MM format.
	 *
	 * @param string $value Raw time input.
	 * @return string Sanitized time or empty string.
	 */
	private function sanitize_time( $value ) {
		$value = sanitize_text_field( $value );
		if ( preg_match( '/^\d{2}:\d{2}$/', $value ) ) {
			return $value;
		}
		return '';
	}
}
