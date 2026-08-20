<?php
/**
 * Scheduled visibility preview for administrators.
 *
 * @package MSC_Scheduled_Visibility
 */

namespace MSC_Scheduled_Visibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets an administrator view the front end as it will appear at another moment in
 * time, or to a visitor holding a particular role.
 *
 * Preview state lives entirely in signed query arguments — nothing is written to the
 * database, and the simulation is scoped to the single request that carries them.
 */
class Preview {

	/**
	 * Query argument carrying the simulated timestamp.
	 */
	const ARG_TIME = 'mscsv_preview_time';

	/**
	 * Query argument carrying the simulated role.
	 */
	const ARG_ROLE = 'mscsv_preview_role';

	/**
	 * Nonce action for preview links.
	 */
	const NONCE_ACTION = 'mscsv_preview';

	/**
	 * Main plugin instance.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Module instance.
	 *
	 * @var Module
	 */
	private $module;

	/**
	 * Simulated timestamp for this request, or null when not previewing.
	 *
	 * @var int|null
	 */
	private $preview_time = null;

	/**
	 * Simulated role slug, 'guest' for logged out, or null when not previewing.
	 *
	 * @var string|null
	 */
	private $preview_role = null;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin instance.
	 * @param Module $module Module instance.
	 */
	public function __construct( $plugin, $module ) {
		$this->plugin = $plugin;
		$this->module = $module;

		if ( ! $this->plugin->get_option( 'module_enabled', 1 ) ) {
			return;
		}

		if ( ! $this->plugin->get_option( 'preview_enabled', 1 ) ) {
			return;
		}

		add_action( 'mscsv_metabox_after_fields', array( $this, 'render_metabox_controls' ) );

		// Must run before the main query, since that is where the visibility filters
		// attach — `template_redirect` would be too late for archive previews.
		add_action( 'init', array( $this, 'maybe_start_preview' ), 99 );
	}

	/**
	 * Detect and validate preview arguments on a front-end request.
	 *
	 * @return void
	 */
	public function maybe_start_preview() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified immediately below.
		if ( ! isset( $_GET[ self::ARG_TIME ] ) && ! isset( $_GET[ self::ARG_ROLE ] ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		$nonce = isset( $_GET['_mscsvnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_mscsvnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( isset( $_GET[ self::ARG_TIME ] ) ) {
			$raw       = sanitize_text_field( wp_unslash( $_GET[ self::ARG_TIME ] ) );
			$timestamp = $this->parse_datetime( $raw );

			if ( $timestamp ) {
				$this->preview_time = $timestamp;
				add_filter( 'mscsv_current_time', array( $this, 'filter_current_time' ) );
			}
		}

		if ( isset( $_GET[ self::ARG_ROLE ] ) ) {
			$role = sanitize_key( wp_unslash( $_GET[ self::ARG_ROLE ] ) );

			if ( 'guest' === $role || array_key_exists( $role, wp_roles()->roles ) ) {
				$this->preview_role = $role;
				add_filter( 'mscsv_is_visible', array( $this, 'filter_role_visibility' ), 30, 3 );
			}
		}

		if ( null === $this->preview_time && null === $this->preview_role ) {
			return;
		}

		// The editor bypass would defeat the whole point of the preview.
		add_filter( 'mscsv_user_can_bypass', '__return_false' );
		$this->module->flush_visibility_cache();

		add_action( 'wp_footer', array( $this, 'render_preview_bar' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_preview_styles' ) );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
	}

	/**
	 * Substitute the simulated timestamp.
	 *
	 * @param int $timestamp Real timestamp.
	 * @return int
	 */
	public function filter_current_time( $timestamp ) {
		return null === $this->preview_time ? $timestamp : $this->preview_time;
	}

	/**
	 * Re-evaluate role rules against the simulated role.
	 *
	 * Runs at priority 30, after the module's own role check at 20, so it replaces
	 * that verdict rather than competing with it.
	 *
	 * @param bool   $is_visible Current visibility.
	 * @param int    $post_id    Post ID.
	 * @param string $status     Stored status.
	 * @return bool
	 */
	public function filter_role_visibility( $is_visible, $post_id, $status ) {
		if ( null === $this->preview_role ) {
			return $is_visible;
		}

		// A post hidden by its dates stays hidden regardless of who is looking.
		if ( 'visible' !== $status && (int) get_post_meta( $post_id, '_mscsv_enabled', true ) ) {
			return false;
		}

		if ( ! (int) get_post_meta( $post_id, '_mscsv_roles_enabled', true ) ) {
			return $is_visible;
		}

		$allowed = get_post_meta( $post_id, '_mscsv_roles', true );
		if ( ! is_array( $allowed ) || empty( $allowed ) ) {
			return $is_visible;
		}

		if ( 'guest' === $this->preview_role ) {
			return (bool) get_post_meta( $post_id, '_mscsv_roles_logged_out', true );
		}

		return in_array( $this->preview_role, $allowed, true );
	}

	/**
	 * Whether the current request is a preview.
	 *
	 * @return bool
	 */
	public function is_previewing() {
		return null !== $this->preview_time || null !== $this->preview_role;
	}

	/**
	 * Build a signed preview URL.
	 *
	 * @param string      $url      Base URL.
	 * @param string|null $datetime Local datetime string (Y-m-d\TH:i), or null.
	 * @param string|null $role     Role slug or 'guest', or null.
	 * @return string
	 */
	public function build_url( $url, $datetime = null, $role = null ) {
		$args = array();

		if ( $datetime ) {
			$args[ self::ARG_TIME ] = $datetime;
		}

		if ( $role ) {
			$args[ self::ARG_ROLE ] = $role;
		}

		if ( empty( $args ) ) {
			return $url;
		}

		$args['_mscsvnonce'] = wp_create_nonce( self::NONCE_ACTION );

		return add_query_arg( $args, $url );
	}

	/**
	 * Render the preview controls inside the post metabox.
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	public function render_metabox_controls( $post ) {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		$permalink = get_permalink( $post );
		if ( ! $permalink || 'publish' !== $post->post_status ) {
			return;
		}

		$roles = wp_roles()->roles;
		?>
		<div class="mscsv-extra-section mscsv-preview-section">
			<hr />
			<p><strong><?php esc_html_e( 'Preview schedule', 'msc-scheduled-visibility' ); ?></strong></p>
			<p class="description mscsv-preview-section__hint">
				<?php esc_html_e( 'Open the front end as it will look at a chosen moment, or to a chosen role.', 'msc-scheduled-visibility' ); ?>
			</p>

			<p>
				<label for="mscsv_preview_datetime"><?php esc_html_e( 'At:', 'msc-scheduled-visibility' ); ?></label><br />
				<input type="datetime-local" id="mscsv_preview_datetime" value="" />
			</p>

			<p>
				<label for="mscsv_preview_role_select"><?php esc_html_e( 'As:', 'msc-scheduled-visibility' ); ?></label><br />
				<select id="mscsv_preview_role_select">
					<option value=""><?php esc_html_e( 'Current user', 'msc-scheduled-visibility' ); ?></option>
					<option value="guest"><?php esc_html_e( 'Logged-out visitor', 'msc-scheduled-visibility' ); ?></option>
					<?php foreach ( $roles as $slug => $role ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>">
							<?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<p>
				<a href="#"
					class="button mscsv-preview-button"
					target="_blank"
					rel="noopener noreferrer"
					data-permalink="<?php echo esc_url( $permalink ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>">
					<?php esc_html_e( 'Open preview', 'msc-scheduled-visibility' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Enqueue the front-end preview bar stylesheet.
	 *
	 * @return void
	 */
	public function enqueue_preview_styles() {
		wp_enqueue_style(
			'mscsv-preview',
			MSCSV_PLUGIN_URL . 'assets/css/preview.css',
			array(),
			MSCSV_PLUGIN_VERSION
		);
	}

	/**
	 * Render the front-end preview notice bar.
	 *
	 * @return void
	 */
	public function render_preview_bar() {
		$parts = array();

		if ( null !== $this->preview_time ) {
			$parts[] = sprintf(
				/* translators: %s: localised date and time. */
				esc_html__( 'Time: %s', 'msc-scheduled-visibility' ),
				'<strong>' . esc_html( $this->module->format_timestamp( $this->preview_time ) ) . '</strong>'
			);
		}

		if ( null !== $this->preview_role ) {
			$roles = wp_roles()->roles;

			$label = 'guest' === $this->preview_role
				? __( 'Logged-out visitor', 'msc-scheduled-visibility' )
				: translate_user_role( $roles[ $this->preview_role ]['name'] );

			$parts[] = sprintf(
				/* translators: %s: user role name. */
				esc_html__( 'Viewing as: %s', 'msc-scheduled-visibility' ),
				'<strong>' . esc_html( $label ) . '</strong>'
			);
		}

		if ( empty( $parts ) ) {
			return;
		}

		$exit_url = remove_query_arg( array( self::ARG_TIME, self::ARG_ROLE, '_mscsvnonce' ) );
		?>
		<div class="mscsv-preview-bar">
			<?php echo esc_html__( 'Scheduled visibility preview', 'msc-scheduled-visibility' ); ?>
			&mdash;
			<?php echo wp_kses_post( implode( ' &nbsp;·&nbsp; ', $parts ) ); ?>
			<a href="<?php echo esc_url( $exit_url ); ?>"><?php esc_html_e( 'Exit preview', 'msc-scheduled-visibility' ); ?></a>
		</div>
		<?php
	}

	/**
	 * Parse a `datetime-local` value into a timestamp in the site timezone.
	 *
	 * @param string $value Raw input (Y-m-d\TH:i or Y-m-d H:i).
	 * @return int|false
	 */
	private function parse_datetime( $value ) {
		$value = str_replace( 'T', ' ', trim( $value ) );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $value ) ) {
			return false;
		}

		$dto = date_create_immutable( $value, wp_timezone() );

		return $dto ? $dto->getTimestamp() : false;
	}
}
