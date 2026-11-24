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
	 * Name of the custom cron recurrence.
	 *
	 * Renamed from `mscsv_five_minutes`; ensure_cron() migrates events still pointing
	 * at the old name.
	 */
	const CRON_RECURRENCE = 'mscsv_fifteen_minutes';

	/**
	 * Private taxonomy mirroring `_mscsv_status`.
	 *
	 * The status meta stays the source of truth, but listing queries filter on this
	 * taxonomy instead: term relationships are indexed, where a postmeta lookup has
	 * to join and scan `wp_postmeta` on every shop page, archive and cron sweep.
	 */
	const STATUS_TAXONOMY = 'mscsv_status';

	/**
	 * Every status a scheduled post can hold.
	 */
	const STATUSES = array( 'visible', 'scheduled', 'hidden', 'expired' );

	/**
	 * How many scheduled posts the dashboard widget examines when picking the
	 * soonest transitions. Bounded so the widget cost does not grow with the
	 * number of scheduled posts.
	 */
	const DASHBOARD_SCAN_LIMIT = 100;

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
	 * Per-request cache of scheduled-out post IDs. Null until first resolved.
	 *
	 * @var array<int,int>|null
	 */
	/**
	 * True while the plugin is writing scheduling meta itself, so the safety-net
	 * meta hook stands aside and lets the write path own the term.
	 *
	 * @var bool
	 */
	private $writing_own_meta = false;

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

		// The status taxonomy is registered whatever the module state: terms written
		// while it was on must stay readable so they can be cleaned up or re-read
		// after it is switched back on. Priority 0 puts it in place before any
		// `init`-time query can run against it.
		add_action( 'init', array( $this, 'register_status_taxonomy' ), 0 );

		if ( ! $this->is_enabled() ) {
			return;
		}

		// The cron sweep finds scheduled posts by term, so a schedule written straight
		// to post meta — an import, WP-CLI, another plugin — would otherwise never be
		// swept. Mirror the term whenever the meta itself moves.
		add_action( 'added_post_meta', array( $this, 'sync_term_on_meta_write' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'sync_term_on_meta_write' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'sync_term_on_meta_write' ), 10, 3 );

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

		/*
		 * Frontend visibility filtering, done entirely on results rather than by
		 * narrowing the query. Excluding IDs in SQL would keep result counts exact,
		 * but recurring windows and per-visitor role rules cannot be expressed as a
		 * query clause anyway, so a results pass was always going to run — this way
		 * there is one filtering path instead of two that have to agree.
		 */
		if ( ! is_admin() || wp_doing_ajax() ) {
			add_filter( 'posts_results', array( $this, 'filter_posts_results' ), 10, 2 );
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
		if ( ( ! is_admin() || wp_doing_ajax() ) && $this->plugin->get_option( 'block_scheduling_enabled', 1 ) ) {
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
	// Status taxonomy
	// =========================================================================

	/**
	 * Register the private status taxonomy.
	 *
	 * @return void
	 */
	public function register_status_taxonomy() {
		$object_types = $this->get_post_types();

		// Products are filtered through the same exclusion even when `product` is not
		// a selected post type, so the taxonomy has to cover them too.
		if ( ! in_array( 'product', $object_types, true ) ) {
			$object_types[] = 'product';
		}

		register_taxonomy(
			self::STATUS_TAXONOMY,
			$object_types,
			array(
				// Not translated: the taxonomy has no UI, so this label is never shown.
				'label'              => 'Scheduled Visibility Status',
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => false,
				'show_admin_column'  => false,
				'show_tagcloud'      => false,
				'hierarchical'       => false,
				'query_var'          => false,
				'rewrite'            => false,
			)
		);
	}

	/**
	 * Resolve status slugs to existing term IDs.
	 *
	 * Slugs with no term yet are dropped rather than created: a term that does not
	 * exist has no posts, so leaving it out of a clause is always correct.
	 *
	 * @param array<int,string> $statuses Status slugs.
	 * @return array<int,int> Term IDs.
	 */
	public function status_term_ids( $statuses ) {
		if ( ! taxonomy_exists( self::STATUS_TAXONOMY ) ) {
			return array();
		}

		$term_ids = array();

		foreach ( (array) $statuses as $status ) {
			$term = get_term_by( 'slug', $status, self::STATUS_TAXONOMY );

			if ( $term instanceof \WP_Term ) {
				$term_ids[] = (int) $term->term_id;
			}
		}

		return $term_ids;
	}

	/**
	 * Post IDs carrying any of the given statuses.
	 *
	 * Read straight out of the term relationships rather than through a tax_query on
	 * the consuming query: one indexed lookup here, reused across every caller in
	 * the request, instead of a taxonomy subquery bolted onto each of them.
	 *
	 * @param array<int,string> $statuses Status slugs.
	 * @return array<int,int> Post IDs.
	 */
	private function post_ids_with_statuses( $statuses ) {
		$term_ids = $this->status_term_ids( $statuses );

		if ( empty( $term_ids ) ) {
			return array();
		}

		$post_ids = get_objects_in_term( $term_ids, self::STATUS_TAXONOMY );

		if ( is_wp_error( $post_ids ) ) {
			return array();
		}

		return array_values( array_unique( array_map( 'intval', $post_ids ) ) );
	}

	/**
	 * Post IDs that have scheduling enabled.
	 *
	 * @param array<int,string>|null $statuses Statuses, or null for all of them.
	 * @return array<int,int> Post IDs.
	 */
	public function scheduled_post_ids( $statuses = null ) {
		return $this->post_ids_with_statuses( null === $statuses ? self::STATUSES : $statuses );
	}

	/**
	 * Keep the status term in step with direct writes to the scheduling meta.
	 *
	 * Only the term is touched — no status transition hooks fire and nothing is
	 * logged, because this runs on writes the plugin did not make and cannot
	 * attribute. The next cron sweep, which can now see the post, does the real
	 * refresh and reports the transition properly.
	 *
	 * @param int|array<int,int> $meta_id  Meta ID(s), unused.
	 * @param int                $post_id  Post ID.
	 * @param string             $meta_key Meta key that changed.
	 * @return void
	 */
	public function sync_term_on_meta_write( $meta_id, $post_id, $meta_key ) {
		unset( $meta_id );

		if ( $this->writing_own_meta ) {
			return;
		}

		if ( '_mscsv_enabled' !== $meta_key && '_mscsv_status' !== $meta_key ) {
			return;
		}

		if ( ! (int) get_post_meta( $post_id, '_mscsv_enabled', true ) ) {
			$this->sync_status_term( $post_id, '' );
			return;
		}

		$status = (string) get_post_meta( $post_id, '_mscsv_status', true );

		// Enabled but never evaluated: compute a status so the post becomes visible to
		// the sweep. compute_post_status() only reads, so this stays side-effect free.
		if ( '' === $status ) {
			$status = $this->compute_post_status( $post_id );
		}

		$this->sync_status_term( $post_id, $status );
	}

	/**
	 * Mirror a post's status onto the taxonomy.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  Status slug, or an empty string to clear.
	 * @return void
	 */
	private function sync_status_term( $post_id, $status ) {
		if ( ! taxonomy_exists( self::STATUS_TAXONOMY ) ) {
			return;
		}

		if ( '' === $status || ! in_array( $status, self::STATUSES, true ) ) {
			wp_set_object_terms( $post_id, array(), self::STATUS_TAXONOMY );
			return;
		}

		wp_set_object_terms( $post_id, array( $status ), self::STATUS_TAXONOMY );
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
							<input type="checkbox" name="mscsv_recurring_days[]" value="<?php echo esc_attr( (string) (int) $num ); ?>" <?php checked( in_array( $num, array_map( 'intval', $days ), true ) ); ?> />
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
				'start_date' => isset( $_POST['mscsv_start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['mscsv_start_date'] ) ) : '',
				'start_time' => isset( $_POST['mscsv_start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['mscsv_start_time'] ) ) : '',
				'end_date'   => isset( $_POST['mscsv_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['mscsv_end_date'] ) ) : '',
				'end_time'   => isset( $_POST['mscsv_end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['mscsv_end_time'] ) ) : '',
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
					'pattern'      => isset( $_POST['mscsv_recurring_pattern'] ) ? sanitize_key( wp_unslash( $_POST['mscsv_recurring_pattern'] ) ) : 'daily',
					'days'         => isset( $_POST['mscsv_recurring_days'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['mscsv_recurring_days'] ) ) : array(),
					'day_of_month' => isset( $_POST['mscsv_recurring_day_of_month'] ) ? absint( wp_unslash( $_POST['mscsv_recurring_day_of_month'] ) ) : 0,
					'start_time'   => isset( $_POST['mscsv_recurring_start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['mscsv_recurring_start_time'] ) ) : '',
					'end_time'     => isset( $_POST['mscsv_recurring_end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['mscsv_recurring_end_time'] ) ) : '',
					'valid_from'   => isset( $_POST['mscsv_recurring_valid_from'] ) ? sanitize_text_field( wp_unslash( $_POST['mscsv_recurring_valid_from'] ) ) : '',
					'valid_until'  => isset( $_POST['mscsv_recurring_valid_until'] ) ? sanitize_text_field( wp_unslash( $_POST['mscsv_recurring_valid_until'] ) ) : '',
				)
			);
		}

		if ( $this->plugin->get_option( 'roles_enabled', 1 ) ) {
			$this->set_role_rules(
				$post_id,
				array(
					'enabled'    => isset( $_POST['mscsv_roles_enabled'] ),
					'roles'      => isset( $_POST['mscsv_roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['mscsv_roles'] ) ) : array(),
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

		// This method owns the term for the writes below, so the meta safety net must
		// not step in and set one from half-written dates.
		$this->writing_own_meta = true;

		try {
			update_post_meta( $post_id, '_mscsv_enabled', $enabled );

			if ( ! $enabled ) {
				foreach ( array( 'start_date', 'start_time', 'end_date', 'end_time', 'status' ) as $key ) {
					delete_post_meta( $post_id, '_mscsv_' . $key );
				}
				$this->sync_status_term( $post_id, '' );
				$this->flush_visibility_cache();
				return;
			}

			update_post_meta( $post_id, '_mscsv_start_date', $this->sanitize_date( isset( $data['start_date'] ) ? $data['start_date'] : '' ) );
			update_post_meta( $post_id, '_mscsv_start_time', $this->sanitize_time( isset( $data['start_time'] ) ? $data['start_time'] : '' ) );
			update_post_meta( $post_id, '_mscsv_end_date', $this->sanitize_date( isset( $data['end_date'] ) ? $data['end_date'] : '' ) );
			update_post_meta( $post_id, '_mscsv_end_time', $this->sanitize_time( isset( $data['end_time'] ) ? $data['end_time'] : '' ) );
		} finally {
			$this->writing_own_meta = false;
		}

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

		// This method is the authority on the status term, so the meta safety net
		// stays out of the way of its own writes.
		$this->writing_own_meta = true;

		try {
			return $this->refresh_status_unguarded( $post_id );
		} finally {
			$this->writing_own_meta = false;
		}
	}

	/**
	 * Body of refresh_status(), run with the meta safety net suppressed.
	 *
	 * @param int $post_id Post ID.
	 * @return string The status now stored.
	 */
	private function refresh_status_unguarded( $post_id ) {
		if ( ! (int) get_post_meta( $post_id, '_mscsv_enabled', true ) ) {
			delete_post_meta( $post_id, '_mscsv_status' );
			$this->sync_status_term( $post_id, '' );
			return 'visible';
		}

		$old_status = (string) get_post_meta( $post_id, '_mscsv_status', true );
		$new_status = $this->compute_post_status( $post_id );

		// Mirrored on every pass rather than only on change, so a term lost to a
		// half-finished write or an interrupted backfill repairs itself on the next
		// cron sweep instead of leaving the post invisible to listing queries.
		$this->sync_status_term( $post_id, $new_status );

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
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'string',
					// Authorize against the post being written, not a blanket
					// site-wide capability. Core's map_meta_cap() and the REST posts
					// controller both gate this too, so this is defence in depth
					// rather than the only check — but a per-object write deserves a
					// per-object test.
					'auth_callback'     => function ( $allowed, $meta_key, $object_id ) {
						return current_user_can( 'edit_post', $object_id );
					},
					// Registered as a REST-writable string that is later json_decode'd,
					// so constrain it to something that actually decodes to a structure.
					'sanitize_callback' => array( $this, 'sanitize_block_schedules_meta' ),
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
		if ( ! file_exists( MSCSV_PLUGIN_DIR . 'build/index.asset.php' ) ) {
			return;
		}

		$asset = include MSCSV_PLUGIN_DIR . 'build/index.asset.php';

		wp_enqueue_script(
			'mscsv-block-scheduling',
			MSCSV_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// The sidebar's ~15 __() calls are extracted into the POT but would render
		// untranslated in every locale without this: JS translations are loaded from
		// per-script JSON, not the PHP text domain.
		wp_set_script_translations(
			'mscsv-block-scheduling',
			'msc-scheduled-visibility',
			MSCSV_PLUGIN_DIR . 'languages'
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

		$scheduled = $this->scheduled_post_ids();

		if ( empty( $scheduled ) ) {
			echo '<p>' . esc_html__( 'No posts with scheduled visibility.', 'msc-scheduled-visibility' ) . '</p>';
			return;
		}

		$candidate_query = new \WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => self::DASHBOARD_SCAN_LIMIT,
				'post__in'               => $scheduled,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'orderby'                => 'ID',
				'order'                  => 'DESC',
			)
		);

		$candidates = $candidate_query->posts;

		if ( empty( $candidates ) ) {
			echo '<p>' . esc_html__( 'No posts with scheduled visibility.', 'msc-scheduled-visibility' ) . '</p>';
			return;
		}

		/*
		 * Ordered on the real next-transition time rather than the raw start date:
		 * which end of the window matters depends on the post's current status, so
		 * the comparison cannot be expressed as a single SQL sort without joining
		 * postmeta. Note the ordering is over the scanned slice only — on a site with
		 * more than DASHBOARD_SCAN_LIMIT scheduled posts the widget reports on the
		 * most recent of them, not on every one.
		 */
		$rows = array();

		foreach ( $candidates as $candidate_id ) {
			$status = (string) get_post_meta( $candidate_id, '_mscsv_status', true );

			$rows[] = array(
				'id'     => (int) $candidate_id,
				'status' => $status,
				'next'   => $this->get_next_transition_timestamp( $candidate_id, $status ),
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				// Soonest change first; posts with nothing pending sort to the end.
				if ( $a['next'] && $b['next'] ) {
					return $a['next'] <=> $b['next'];
				}

				if ( $a['next'] ) {
					return -1;
				}

				if ( $b['next'] ) {
					return 1;
				}

				return 0;
			}
		);

		$rows = array_slice( $rows, 0, 10 );

		echo '<table class="widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Post', 'msc-scheduled-visibility' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'msc-scheduled-visibility' ) . '</th>';
		echo '<th>' . esc_html__( 'Next Change', 'msc-scheduled-visibility' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$next = $this->get_next_transition_time( $row['id'], $row['status'] );
			$edit = get_edit_post_link( $row['id'] );

			echo '<tr>';
			printf( '<td><a href="%s">%s</a></td>', esc_url( $edit ), esc_html( get_the_title( $row['id'] ) ) );
			printf(
				'<td><span class="mscsv-badge mscsv-badge--%s">%s</span></td>',
				esc_attr( $row['status'] ),
				esc_html( $this->status_label( $row['status'] ) )
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
	 * Whether visitor-facing visibility rules apply to the current request.
	 *
	 * `is_admin()` alone is the wrong test. It reports true for admin-ajax.php even
	 * when the caller is logged out, and themes routinely serve front-end search,
	 * filtering and infinite scroll through that endpoint — so treating those requests
	 * as admin traffic would hand scheduled-out content straight to visitors.
	 *
	 * Admin AJAX is therefore filtered too, except for callers who can edit posts:
	 * their own admin screens (Quick Edit's inline-save, for one) run through AJAX and
	 * must keep seeing everything. Anyone below that level has no admin AJAX UI to
	 * break, and is exactly who the schedule is meant to hide content from.
	 *
	 * @return bool
	 */
	public function is_visitor_facing_request() {
		if ( ! is_admin() ) {
			return true;
		}

		return wp_doing_ajax() && ! current_user_can( 'edit_posts' );
	}

	/**
	 * Filter individual post results for singular queries.
	 *
	 * @param array<int|string|\WP_Post> $posts Array of post objects or bare IDs.
	 * @param \WP_Query                  $query Query object.
	 * @return array<int|string|\WP_Post>
	 */
	public function filter_posts_results( $posts, $query ) {
		if ( ! $this->is_visitor_facing_request() || $this->can_bypass() || ! $query->is_singular ) {
			return $posts;
		}

		return $this->reject_hidden_posts( $posts );
	}

	/**
	 * Trim hidden posts from list queries.
	 *
	 * The `pre_get_posts` exclusion can only test the status written by cron. Recurring
	 * windows resolve on a finer granularity than the cron sweep, and role rules are
	 * per-visitor and cannot be stored at all, so both are settled here.
	 *
	 * Because this pass rechecks every post live, the query-level exclusion is only an
	 * optimisation — visibility stays correct even before the status backfill has run.
	 *
	 * @param array<int|string|\WP_Post> $posts Post objects or bare IDs.
	 * @param \WP_Query                  $query Query object.
	 * @return array<int|string|\WP_Post>
	 */
	public function filter_the_posts( $posts, $query ) {
		if ( ! $this->is_visitor_facing_request() || empty( $posts ) || $this->can_bypass() ) {
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
	 * @param array<int|string|\WP_Post> $posts Post objects or bare IDs.
	 * @return array<int|string|\WP_Post>
	 */
	private function reject_hidden_posts( $posts ) {
		$post_types = $this->get_post_types();

		foreach ( $posts as $key => $post ) {
			/*
			 * Both result shapes have to be handled. A query asking for
			 * `fields => 'ids'` hands back bare IDs — WooCommerce's shortcode and
			 * block product lists do exactly that — and skipping those would let a
			 * scheduled-out product through, since nothing narrows the query itself.
			 */
			if ( $post instanceof \WP_Post ) {
				$post_id   = (int) $post->ID;
				$post_type = $post->post_type;
			} elseif ( is_numeric( $post ) ) {
				$post_id   = (int) $post;
				$post_type = get_post_type( $post_id );
			} else {
				continue;
			}

			if ( ! in_array( $post_type, $post_types, true ) ) {
				continue;
			}

			if ( ! $this->is_post_visible( $post_id ) ) {
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
		if ( ! $this->is_visitor_facing_request() || $this->can_bypass() ) {
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
		$message = apply_filters( 'mscsv_hidden_content_message', '', $post_id );

		// The return value of a `the_content` callback is echoed by core, so anything a
		// filter hands back has to be safe as post markup before it leaves here.
		return wp_kses_post( (string) $message );
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
		/*
		 * 900 is written out rather than held in a constant so the interval stays
		 * statically checkable. Plugin::ensure_cron() declares the same recurrence for
		 * the activation request, where this class is not yet constructed — the two
		 * definitions have to stay in step.
		 *
		 * The sweep only refreshes the *stored* status, which drives admin listings and
		 * the status counts. Visitor-facing visibility is resolved live on every
		 * request, so the interval costs admin freshness, not front-end correctness.
		 */
		$schedules[ self::CRON_RECURRENCE ] = array(
			'interval' => 900,

			/*
			 * Not translated, for two reasons. Plugin::ensure_cron() runs at
			 * plugins_loaded and reaches wp_schedule_event() whenever the event needs
			 * self-healing, which fires `cron_schedules` before `init` — translating
			 * here would trip the "_load_textdomain_just_in_time was called
			 * incorrectly" notice on that path. The label is also only ever surfaced by
			 * cron-inspection tools, and the ensure_cron() copy is a plain string too.
			 */
			'display'  => 'Every 15 Minutes',
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

		// Sites upgrading from a version that had no status taxonomy carry schedules
		// with no term yet, and the sweep below can only find posts by term. Seed
		// them first, one batch per run.
		if ( $this->backfill_status_terms( $post_types ) ) {
			return;
		}

		// The term relationships already are the list of scheduled posts, so the
		// sweep needs no post query at all — one indexed read, then recompute each.
		foreach ( $this->scheduled_post_ids() as $post_id ) {
			$this->refresh_status( $post_id );
		}
	}

	/**
	 * Seed status terms for schedules written before the taxonomy existed.
	 *
	 * Walks every post of an enabled type once, in cron-sized batches, calling
	 * refresh_status() — which writes the term for a scheduled post and clears it
	 * for an unscheduled one. Deliberately unfiltered: selecting only the scheduled
	 * posts would need the postmeta lookup this change exists to remove, and the
	 * walk runs once per site.
	 *
	 * @param array<int,string> $post_types Enabled post types.
	 * @return bool True while the backfill is still running.
	 */
	private function backfill_status_terms( $post_types ) {
		if ( get_option( 'mscsv_status_terms_backfilled' ) ) {
			return false;
		}

		$paged = max( 1, (int) get_option( 'mscsv_status_terms_backfill_page', 1 ) );

		$batch_query = new \WP_Query(
			array(
				'post_type'              => $post_types,
				// Any status, not just publish: the REST list reports on drafts too,
				// so their terms have to be seeded as well.
				'post_status'            => 'any',
				'posts_per_page'         => self::CRON_BATCH_SIZE,
				'paged'                  => $paged,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
			)
		);

		$batch = $batch_query->posts;

		foreach ( $batch as $post_id ) {
			$this->refresh_status( $post_id );
		}

		if ( count( $batch ) < self::CRON_BATCH_SIZE ) {
			update_option( 'mscsv_status_terms_backfilled', 1 );
			delete_option( 'mscsv_status_terms_backfill_page' );
			return false;
		}

		update_option( 'mscsv_status_terms_backfill_page', $paged + 1 );

		return true;
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
		$timestamp = $this->get_next_transition_timestamp( $post_id, $status );

		if ( ! $timestamp ) {
			return false;
		}

		if ( 'scheduled' === $status ) {
			/* translators: %s: localised date and time. */
			return sprintf( __( 'Shows: %s', 'msc-scheduled-visibility' ), $this->format_timestamp( $timestamp ) );
		}

		/* translators: %s: localised date and time. */
		return sprintf( __( 'Hides: %s', 'msc-scheduled-visibility' ), $this->format_timestamp( $timestamp ) );
	}

	/**
	 * Timestamp of a post's next visibility change.
	 *
	 * Which end of the window matters depends on the current status: a scheduled
	 * post is waiting to show, a visible one is waiting to hide.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  Current status.
	 * @return int|false Unix timestamp, or false when nothing is pending.
	 */
	public function get_next_transition_timestamp( $post_id, $status ) {
		if ( 'scheduled' === $status ) {
			return $this->build_timestamp(
				get_post_meta( $post_id, '_mscsv_start_date', true ),
				get_post_meta( $post_id, '_mscsv_start_time', true ),
				'00:00'
			);
		}

		if ( 'visible' === $status ) {
			return $this->build_timestamp(
				get_post_meta( $post_id, '_mscsv_end_date', true ),
				get_post_meta( $post_id, '_mscsv_end_time', true ),
				'23:59'
			);
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
	 * Sanitize the block-schedules meta blob.
	 *
	 * Rebuilds the structure key by key rather than trusting the submitted JSON, so a
	 * REST client cannot store arbitrary shapes or values in a field the front end
	 * later decodes and acts on.
	 *
	 * @param mixed $value Raw meta value.
	 * @return string JSON-encoded schedule map, or an empty string.
	 */
	public function sanitize_block_schedules_meta( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		$decoded = json_decode( $value, true );

		if ( ! is_array( $decoded ) ) {
			return '';
		}

		$clean = array();

		foreach ( $decoded as $key => $schedule ) {
			if ( ! is_array( $schedule ) ) {
				continue;
			}

			$clean[ sanitize_key( $key ) ] = array(
				'enabled'    => ! empty( $schedule['enabled'] ),
				'start_date' => $this->sanitize_date( $this->stringify( $schedule, 'start_date' ) ),
				'start_time' => $this->sanitize_time( $this->stringify( $schedule, 'start_time' ) ),
				'end_date'   => $this->sanitize_date( $this->stringify( $schedule, 'end_date' ) ),
				'end_time'   => $this->sanitize_time( $this->stringify( $schedule, 'end_time' ) ),
			);
		}

		return (string) wp_json_encode( $clean );
	}

	/**
	 * Read a key from an array as a string, tolerating any submitted type.
	 *
	 * @param array<string,mixed> $source Source array.
	 * @param string              $key    Key to read.
	 * @return string
	 */
	private function stringify( $source, $key ) {
		if ( ! isset( $source[ $key ] ) || ! is_scalar( $source[ $key ] ) ) {
			return '';
		}

		return (string) $source[ $key ];
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
