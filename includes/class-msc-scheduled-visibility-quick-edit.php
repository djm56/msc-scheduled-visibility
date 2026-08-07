<?php
/**
 * Quick Edit and Bulk Edit support for MSC Scheduled Visibility.
 *
 * @package MSC_Scheduled_Visibility
 */

namespace MSC_Scheduled_Visibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds scheduling fields to the post list table's Quick Edit and Bulk Edit panels.
 */
class Quick_Edit {

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

		add_action( 'quick_edit_custom_box', array( $this, 'render_quick_edit_fields' ), 10, 2 );
		add_action( 'bulk_edit_custom_box', array( $this, 'render_bulk_edit_fields' ), 10, 2 );

		// Stash each row's current schedule so the Quick Edit JS can prefill.
		add_action( 'mscsv_visibility_column', array( $this, 'render_inline_data' ) );

		add_action( 'save_post', array( $this, 'save_quick_edit' ), 12, 2 );
		add_action( 'save_post', array( $this, 'save_bulk_edit' ), 12, 2 );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Whether the given column/post type combination should render our fields.
	 *
	 * @param string $column_name Column key.
	 * @param string $post_type   Post type.
	 * @return bool
	 */
	private function is_target( $column_name, $post_type ) {
		return 'mscsv_status' === $column_name
			&& in_array( $post_type, $this->module->get_post_types(), true );
	}

	/**
	 * Output the current schedule for a row as JSON for the Quick Edit script.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function render_inline_data( $post_id ) {
		$data = array(
			'enabled'    => (int) get_post_meta( $post_id, '_mscsv_enabled', true ),
			'start_date' => (string) get_post_meta( $post_id, '_mscsv_start_date', true ),
			'start_time' => (string) get_post_meta( $post_id, '_mscsv_start_time', true ),
			'end_date'   => (string) get_post_meta( $post_id, '_mscsv_end_date', true ),
			'end_time'   => (string) get_post_meta( $post_id, '_mscsv_end_time', true ),
		);

		printf(
			'<script type="application/json" class="mscsv-inline-data" id="mscsv-inline-%d">%s</script>',
			(int) $post_id,
			wp_json_encode( $data )
		);
	}

	/**
	 * Render the Quick Edit fields.
	 *
	 * @param string $column_name Column key.
	 * @param string $post_type   Post type.
	 * @return void
	 */
	public function render_quick_edit_fields( $column_name, $post_type ) {
		if ( ! $this->is_target( $column_name, $post_type ) ) {
			return;
		}

		wp_nonce_field( 'mscsv_quick_edit', 'mscsv_quick_edit_nonce' );
		?>
		<fieldset class="inline-edit-col-right mscsv-quick-edit">
			<div class="inline-edit-col">
				<span class="title"><?php esc_html_e( 'Scheduled Visibility', 'msc-scheduled-visibility' ); ?></span>

				<label class="alignleft mscsv-quick-edit__toggle">
					<input type="checkbox" name="mscsv_enabled" value="1" />
					<span class="checkbox-title"><?php esc_html_e( 'Enable scheduled visibility', 'msc-scheduled-visibility' ); ?></span>
				</label>

				<div class="mscsv-quick-edit__fields">
					<label class="alignleft">
						<span class="title"><?php esc_html_e( 'From', 'msc-scheduled-visibility' ); ?></span>
						<input type="date" name="mscsv_start_date" value="" />
						<input type="time" name="mscsv_start_time" value="" />
					</label>
					<label class="alignleft">
						<span class="title"><?php esc_html_e( 'Until', 'msc-scheduled-visibility' ); ?></span>
						<input type="date" name="mscsv_end_date" value="" />
						<input type="time" name="mscsv_end_time" value="" />
					</label>
				</div>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Render the Bulk Edit fields.
	 *
	 * @param string $column_name Column key.
	 * @param string $post_type   Post type.
	 * @return void
	 */
	public function render_bulk_edit_fields( $column_name, $post_type ) {
		if ( ! $this->is_target( $column_name, $post_type ) ) {
			return;
		}

		wp_nonce_field( 'mscsv_bulk_edit', 'mscsv_bulk_edit_nonce' );
		?>
		<fieldset class="inline-edit-col-right mscsv-bulk-edit">
			<div class="inline-edit-col">
				<span class="title"><?php esc_html_e( 'Scheduled Visibility', 'msc-scheduled-visibility' ); ?></span>

				<label class="alignleft">
					<span class="title"><?php esc_html_e( 'Scheduling', 'msc-scheduled-visibility' ); ?></span>
					<select name="mscsv_bulk_enabled">
						<option value=""><?php esc_html_e( '— No change —', 'msc-scheduled-visibility' ); ?></option>
						<option value="1"><?php esc_html_e( 'Enable', 'msc-scheduled-visibility' ); ?></option>
						<option value="0"><?php esc_html_e( 'Disable (clear schedule)', 'msc-scheduled-visibility' ); ?></option>
					</select>
				</label>

				<div class="mscsv-bulk-edit__fields">
					<label class="alignleft">
						<span class="title"><?php esc_html_e( 'From', 'msc-scheduled-visibility' ); ?></span>
						<input type="date" name="mscsv_start_date" value="" />
						<input type="time" name="mscsv_start_time" value="" />
					</label>
					<label class="alignleft">
						<span class="title"><?php esc_html_e( 'Until', 'msc-scheduled-visibility' ); ?></span>
						<input type="date" name="mscsv_end_date" value="" />
						<input type="time" name="mscsv_end_time" value="" />
					</label>
					<p class="description">
						<?php esc_html_e( 'Dates left blank are not changed. Choose "Enable" to apply these dates to every selected post.', 'msc-scheduled-visibility' ); ?>
					</p>
				</div>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Persist Quick Edit input.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function save_quick_edit( $post_id, $post ) {
		// Core prints the inline-edit and bulk-edit template rows inside the same
		// posts-filter form, so a bulk submission carries our Quick Edit fields too —
		// all of them blank. Without this guard that would wipe every selected post's
		// schedule. Quick Edit only ever arrives as the inline-save AJAX action.
		if ( ! wp_doing_ajax() ) {
			return;
		}

		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		if ( 'inline-save' !== $action ) {
			return;
		}

		if ( ! isset( $_POST['mscsv_quick_edit_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mscsv_quick_edit_nonce'] ) ), 'mscsv_quick_edit' )
		) {
			return;
		}

		if ( ! $this->can_edit( $post_id, $post ) ) {
			return;
		}

		$this->module->set_base_schedule(
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
	 * Persist Bulk Edit input.
	 *
	 * Bulk edit posts every field for every selected post, so blank values must be
	 * treated as "leave alone" rather than "clear".
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function save_bulk_edit( $post_id, $post ) {
		// Only a genuine bulk submission sets `bulk_edit`; this keeps the handler out
		// of ordinary post saves that happen to carry the form's hidden fields.
		if ( ! isset( $_REQUEST['bulk_edit'] ) ) {
			return;
		}

		if ( ! isset( $_REQUEST['mscsv_bulk_edit_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['mscsv_bulk_edit_nonce'] ) ), 'mscsv_bulk_edit' )
		) {
			return;
		}

		if ( ! $this->can_edit( $post_id, $post ) ) {
			return;
		}

		$toggle = isset( $_REQUEST['mscsv_bulk_enabled'] )
			? sanitize_text_field( wp_unslash( $_REQUEST['mscsv_bulk_enabled'] ) )
			: '';

		if ( '0' === $toggle ) {
			$this->module->set_base_schedule( $post_id, array( 'enabled' => false ) );
			return;
		}

		$fields = array( 'start_date', 'start_time', 'end_date', 'end_time' );
		$input  = array();

		foreach ( $fields as $field ) {
			$key = 'mscsv_' . $field;

			$input[ $field ] = isset( $_REQUEST[ $key ] )
				? sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) )
				: '';
		}

		$has_dates = (bool) array_filter( $input );

		// Nothing selected and nothing typed — leave this post untouched.
		if ( '' === $toggle && ! $has_dates ) {
			return;
		}

		$enabled = '1' === $toggle
			? true
			: (bool) get_post_meta( $post_id, '_mscsv_enabled', true );

		if ( ! $enabled ) {
			return;
		}

		// Blank inputs keep whatever the post already had.
		foreach ( $fields as $field ) {
			if ( '' === $input[ $field ] ) {
				$input[ $field ] = (string) get_post_meta( $post_id, '_mscsv_' . $field, true );
			}
		}

		$input['enabled'] = true;

		$this->module->set_base_schedule( $post_id, $input );
	}

	/**
	 * Whether the current request may write scheduling meta for this post.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return bool
	 */
	private function can_edit( $post_id, $post ) {
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

		return in_array( $post_type, $this->module->get_post_types(), true );
	}

	/**
	 * Enqueue the inline-edit script on post list screens.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'edit.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, $this->module->get_post_types(), true ) ) {
			return;
		}

		wp_enqueue_script(
			'mscsv-quick-edit',
			MSCSV_PLUGIN_URL . 'assets/js/quick-edit.js',
			array( 'inline-edit-post' ),
			MSCSV_PLUGIN_VERSION,
			true
		);

		wp_enqueue_style(
			'mscsv-admin',
			MSCSV_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			MSCSV_PLUGIN_VERSION
		);
	}
}
