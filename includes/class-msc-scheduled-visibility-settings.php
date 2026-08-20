<?php
/**
 * Admin settings class for MSC Scheduled Visibility.
 *
 * @package MSC_Scheduled_Visibility
 */

namespace MSC_Scheduled_Visibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and saves the tabbed settings screen under Settings > MSC Scheduled Visibility.
 */
class Settings {

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
	 * Audit log instance.
	 *
	 * @var Audit_Log
	 */
	private $audit_log;

	/**
	 * Constructor.
	 *
	 * @param Plugin    $plugin    Plugin instance.
	 * @param Module    $module    Module instance.
	 * @param Audit_Log $audit_log Audit log instance.
	 */
	public function __construct( $plugin, $module, $audit_log ) {
		$this->plugin    = $plugin;
		$this->module    = $module;
		$this->audit_log = $audit_log;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_mscsv_save_settings', array( $this, 'handle_save' ) );
	}

	/**
	 * Register admin page under Settings.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_options_page(
			esc_html__( 'MSC Scheduled Visibility', 'msc-scheduled-visibility' ),
			esc_html__( 'MSC Scheduled Visibility', 'msc-scheduled-visibility' ),
			'manage_options',
			'mscsv-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Get registered settings tabs.
	 *
	 * @return array<string,string>
	 */
	public function get_tabs() {
		$tabs = array(
			'general'          => __( 'General', 'msc-scheduled-visibility' ),
			'block-scheduling' => __( 'Block Scheduling', 'msc-scheduled-visibility' ),
			'recurring'        => __( 'Recurring', 'msc-scheduled-visibility' ),
			'role-based'       => __( 'Role-Based', 'msc-scheduled-visibility' ),
		);

		if ( class_exists( 'WooCommerce' ) ) {
			$tabs['woocommerce'] = __( 'WooCommerce', 'msc-scheduled-visibility' );
		}

		$tabs['advanced'] = __( 'Advanced', 'msc-scheduled-visibility' );
		$tabs['history']  = __( 'History', 'msc-scheduled-visibility' );
		$tabs['support']  = __( 'Support', 'msc-scheduled-visibility' );

		return $tabs;
	}

	/**
	 * Handle settings save.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'msc-scheduled-visibility' ) );
		}

		check_admin_referer( 'mscsv_save_settings' );

		$tab = isset( $_POST['mscsv_tab'] ) ? sanitize_key( wp_unslash( $_POST['mscsv_tab'] ) ) : 'general';

		switch ( $tab ) {
			case 'general':
				$module_enabled = isset( $_POST['module_enabled'] ) ? 1 : 0;

				$post_types = array();
				if ( ! empty( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ) {
					$registered = get_post_types( array( 'public' => true ), 'names' );
					$submitted  = array_map( 'sanitize_key', wp_unslash( $_POST['post_types'] ) );
					foreach ( $submitted as $pt ) {
						if ( isset( $registered[ $pt ] ) ) {
							$post_types[] = $pt;
						}
					}
				}

				$this->plugin->update_options(
					array(
						'module_enabled' => $module_enabled,
						'post_types'     => array_values( array_unique( $post_types ) ),
					)
				);
				break;

			case 'block-scheduling':
				$this->plugin->update_options(
					array(
						'block_scheduling_enabled' => isset( $_POST['block_scheduling_enabled'] ) ? 1 : 0,
					)
				);
				break;

			case 'recurring':
				$this->plugin->update_options(
					array(
						'recurring_enabled' => isset( $_POST['recurring_enabled'] ) ? 1 : 0,
					)
				);
				break;

			case 'role-based':
				$this->plugin->update_options(
					array(
						'roles_enabled' => isset( $_POST['roles_enabled'] ) ? 1 : 0,
					)
				);
				break;

			case 'woocommerce':
				$this->plugin->update_options(
					array(
						'woocommerce_enabled' => isset( $_POST['woocommerce_enabled'] ) ? 1 : 0,
					)
				);
				break;

			case 'advanced':
				$limit = isset( $_POST['audit_log_limit'] ) ? (int) $_POST['audit_log_limit'] : 200;
				$limit = max( 10, min( Audit_Log::MAX_ENTRIES, $limit ) );

				$this->plugin->update_options(
					array(
						'preview_enabled'   => isset( $_POST['preview_enabled'] ) ? 1 : 0,
						'rest_enabled'      => isset( $_POST['rest_enabled'] ) ? 1 : 0,
						'audit_log_enabled' => isset( $_POST['audit_log_enabled'] ) ? 1 : 0,
						'audit_log_limit'   => $limit,
					)
				);
				break;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'mscsv-settings',
					'tab'     => $tab,
					'updated' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Render settings page with tab navigation.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs = $this->get_tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab is a safe UI routing parameter.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';

		if ( ! array_key_exists( $active_tab, $tabs ) ) {
			$active_tab = 'general';
		}
		?>
		<div class="wrap msc-admin-wrap">
			<h1><?php esc_html_e( 'MSC Scheduled Visibility', 'msc-scheduled-visibility' ); ?></h1>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success notice flag. ?>
			<?php if ( isset( $_GET['updated'] ) && '1' === $_GET['updated'] ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'msc-scheduled-visibility' ); ?></p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper mscsv-nav-tabs">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=mscsv-settings&tab=' . $slug ) ); ?>"
						class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="msc-admin-card" style="margin-top:1.5em;">
				<?php
				switch ( $active_tab ) {
					case 'general':
						$this->render_tab_general();
						break;
					case 'block-scheduling':
						$this->render_tab_block_scheduling();
						break;
					case 'recurring':
						$this->render_tab_recurring();
						break;
					case 'role-based':
						$this->render_tab_role_based();
						break;
					case 'woocommerce':
						$this->render_tab_woocommerce();
						break;
					case 'advanced':
						$this->render_tab_advanced();
						break;
					case 'history':
						$this->render_tab_history();
						break;
					case 'support':
						$this->render_tab_support();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the General settings tab.
	 *
	 * @return void
	 */
	private function render_tab_general() {
		$options = array(
			'module_enabled' => (int) $this->plugin->get_option( 'module_enabled', 1 ),
			'post_types'     => (array) $this->plugin->get_option( 'post_types', array( 'post', 'page' ) ),
		);

		$available_post_types = get_post_types( array( 'public' => true ), 'objects' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mscsv_save_settings" />
			<input type="hidden" name="mscsv_tab" value="general" />
			<?php wp_nonce_field( 'mscsv_save_settings' ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable scheduled visibility', 'msc-scheduled-visibility' ); ?></th>
						<td>
							<label for="module_enabled">
								<input id="module_enabled" type="checkbox" name="module_enabled" value="1" <?php checked( 1, $options['module_enabled'] ); ?> />
								<?php esc_html_e( 'Allow posts to be shown or hidden on a schedule.', 'msc-scheduled-visibility' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Post types', 'msc-scheduled-visibility' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $available_post_types as $pt_obj ) : ?>
									<label style="display:block;margin-bottom:4px;">
										<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt_obj->name ); ?>" <?php checked( in_array( $pt_obj->name, $options['post_types'], true ) ); ?> />
										<?php echo esc_html( $pt_obj->labels->singular_name ); ?>
										<span style="color:#888;font-size:12px;">(<?php echo esc_html( $pt_obj->name ); ?>)</span>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Select which post types can use scheduled visibility.', 'msc-scheduled-visibility' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Save Settings', 'msc-scheduled-visibility' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Render the Block Scheduling settings tab.
	 *
	 * @return void
	 */
	private function render_tab_block_scheduling() {
		$enabled = (int) $this->plugin->get_option( 'block_scheduling_enabled', 1 );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mscsv_save_settings" />
			<input type="hidden" name="mscsv_tab" value="block-scheduling" />
			<?php wp_nonce_field( 'mscsv_save_settings' ); ?>

			<h2><?php esc_html_e( 'Block-Level Scheduling', 'msc-scheduled-visibility' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Show or hide individual blocks within a post on a schedule. When enabled, a "Block Scheduling" sidebar panel will appear in the block editor.', 'msc-scheduled-visibility' ); ?></p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable block scheduling', 'msc-scheduled-visibility' ); ?></th>
						<td>
							<label for="block_scheduling_enabled">
								<input id="block_scheduling_enabled" type="checkbox" name="block_scheduling_enabled" value="1" <?php checked( 1, $enabled ); ?> />
								<?php esc_html_e( 'Allow per-block visibility scheduling in the block editor.', 'msc-scheduled-visibility' ); ?>
							</label>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Save Settings', 'msc-scheduled-visibility' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Render the Recurring settings tab.
	 *
	 * @return void
	 */
	private function render_tab_recurring() {
		$enabled = (int) $this->plugin->get_option( 'recurring_enabled', 1 );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mscsv_save_settings" />
			<input type="hidden" name="mscsv_tab" value="recurring" />
			<?php wp_nonce_field( 'mscsv_save_settings' ); ?>

			<h2><?php esc_html_e( 'Recurring Schedules', 'msc-scheduled-visibility' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Allow posts to be shown or hidden on a repeating schedule — daily, weekly, or monthly. When enabled, recurring options will appear in the post metabox.', 'msc-scheduled-visibility' ); ?></p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable recurring schedules', 'msc-scheduled-visibility' ); ?></th>
						<td>
							<label for="recurring_enabled">
								<input id="recurring_enabled" type="checkbox" name="recurring_enabled" value="1" <?php checked( 1, $enabled ); ?> />
								<?php esc_html_e( 'Allow daily, weekly, or monthly visibility windows.', 'msc-scheduled-visibility' ); ?>
							</label>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Save Settings', 'msc-scheduled-visibility' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Render the Role-Based settings tab.
	 *
	 * @return void
	 */
	private function render_tab_role_based() {
		$enabled = (int) $this->plugin->get_option( 'roles_enabled', 1 );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mscsv_save_settings" />
			<input type="hidden" name="mscsv_tab" value="role-based" />
			<?php wp_nonce_field( 'mscsv_save_settings' ); ?>

			<h2><?php esc_html_e( 'Role-Based Visibility', 'msc-scheduled-visibility' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Restrict content visibility by user role. When enabled, role selection options will appear in the post metabox.', 'msc-scheduled-visibility' ); ?></p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable role-based visibility', 'msc-scheduled-visibility' ); ?></th>
						<td>
							<label for="roles_enabled">
								<input id="roles_enabled" type="checkbox" name="roles_enabled" value="1" <?php checked( 1, $enabled ); ?> />
								<?php esc_html_e( 'Allow restricting content visibility by user role.', 'msc-scheduled-visibility' ); ?>
							</label>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Save Settings', 'msc-scheduled-visibility' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Render the WooCommerce settings tab.
	 *
	 * @return void
	 */
	private function render_tab_woocommerce() {
		$enabled       = (int) $this->plugin->get_option( 'woocommerce_enabled', 1 );
		$product_ready = in_array( 'product', $this->module->get_post_types(), true );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mscsv_save_settings" />
			<input type="hidden" name="mscsv_tab" value="woocommerce" />
			<?php wp_nonce_field( 'mscsv_save_settings' ); ?>

			<h2><?php esc_html_e( 'WooCommerce Products', 'msc-scheduled-visibility' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Apply scheduled visibility to products. Hidden products are removed from the shop and product archives, cannot be purchased, and are dropped from the cart at checkout.', 'msc-scheduled-visibility' ); ?>
			</p>

			<?php if ( ! $product_ready ) : ?>
				<div class="notice notice-warning inline" style="margin:1em 0;">
					<p>
						<?php
						printf(
							/* translators: %s: link to the General tab. */
							esc_html__( 'Add the Product post type on the %s tab before product scheduling can take effect.', 'msc-scheduled-visibility' ),
							'<a href="' . esc_url( admin_url( 'options-general.php?page=mscsv-settings&tab=general' ) ) . '">' . esc_html__( 'General', 'msc-scheduled-visibility' ) . '</a>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable product scheduling', 'msc-scheduled-visibility' ); ?></th>
						<td>
							<label for="woocommerce_enabled">
								<input id="woocommerce_enabled" type="checkbox" name="woocommerce_enabled" value="1" <?php checked( 1, $enabled ); ?> />
								<?php esc_html_e( 'Hide scheduled-out products from the shop and block their purchase.', 'msc-scheduled-visibility' ); ?>
							</label>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Save Settings', 'msc-scheduled-visibility' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Render the Advanced settings tab.
	 *
	 * @return void
	 */
	private function render_tab_advanced() {
		$preview_enabled = (int) $this->plugin->get_option( 'preview_enabled', 1 );
		$rest_enabled    = (int) $this->plugin->get_option( 'rest_enabled', 1 );
		$log_enabled     = (int) $this->plugin->get_option( 'audit_log_enabled', 1 );
		$log_limit       = (int) $this->plugin->get_option( 'audit_log_limit', 200 );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mscsv_save_settings" />
			<input type="hidden" name="mscsv_tab" value="advanced" />
			<?php wp_nonce_field( 'mscsv_save_settings' ); ?>

			<h2><?php esc_html_e( 'Advanced', 'msc-scheduled-visibility' ); ?></h2>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Schedule preview', 'msc-scheduled-visibility' ); ?></th>
						<td>
							<label for="preview_enabled">
								<input id="preview_enabled" type="checkbox" name="preview_enabled" value="1" <?php checked( 1, $preview_enabled ); ?> />
								<?php esc_html_e( 'Let editors view the front end at a chosen time or as a chosen role.', 'msc-scheduled-visibility' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Adds a "Preview schedule" control to the post metabox. Preview links are signed and only work for users who can edit others\' posts.', 'msc-scheduled-visibility' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Visibility history', 'msc-scheduled-visibility' ); ?></th>
						<td>
							<label for="audit_log_enabled">
								<input id="audit_log_enabled" type="checkbox" name="audit_log_enabled" value="1" <?php checked( 1, $log_enabled ); ?> />
								<?php esc_html_e( 'Record every time a post is shown or hidden.', 'msc-scheduled-visibility' ); ?>
							</label>
							<p style="margin-top:8px;">
								<label for="audit_log_limit"><?php esc_html_e( 'Entries to keep:', 'msc-scheduled-visibility' ); ?></label>
								<input id="audit_log_limit" type="number" name="audit_log_limit" value="<?php echo esc_attr( $log_limit ); ?>" min="10" max="<?php echo esc_attr( Audit_Log::MAX_ENTRIES ); ?>" step="10" style="width:90px;" />
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'REST API', 'msc-scheduled-visibility' ); ?></th>
						<td>
							<label for="rest_enabled">
								<input id="rest_enabled" type="checkbox" name="rest_enabled" value="1" <?php checked( 1, $rest_enabled ); ?> />
								<?php esc_html_e( 'Expose scheduling endpoints under /wp-json/mscsv/v1/.', 'msc-scheduled-visibility' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Endpoints require an authenticated user with edit rights on the post concerned.', 'msc-scheduled-visibility' ); ?>
							</p>
							<?php if ( $rest_enabled ) : ?>
								<ul class="mscsv-endpoint-list">
									<li><code>GET    /wp-json/mscsv/v1/schedule/&lt;id&gt;</code></li>
									<li><code>POST   /wp-json/mscsv/v1/schedule/&lt;id&gt;</code></li>
									<li><code>DELETE /wp-json/mscsv/v1/schedule/&lt;id&gt;</code></li>
									<li><code>GET    /wp-json/mscsv/v1/scheduled</code></li>
								</ul>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Save Settings', 'msc-scheduled-visibility' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Render the visibility History tab.
	 *
	 * @return void
	 */
	private function render_tab_history() {
		$entries = $this->audit_log->get_entries();
		?>
		<h2><?php esc_html_e( 'Visibility History', 'msc-scheduled-visibility' ); ?></h2>

		<?php if ( ! $this->audit_log->is_enabled() ) : ?>
			<div class="notice notice-info inline" style="margin:1em 0;">
				<p>
					<?php
					printf(
						/* translators: %s: link to the Advanced tab. */
						esc_html__( 'History recording is switched off. Enable it on the %s tab.', 'msc-scheduled-visibility' ),
						'<a href="' . esc_url( admin_url( 'options-general.php?page=mscsv-settings&tab=advanced' ) ) . '">' . esc_html__( 'Advanced', 'msc-scheduled-visibility' ) . '</a>'
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( empty( $entries ) ) : ?>
			<p><?php esc_html_e( 'No visibility changes recorded yet.', 'msc-scheduled-visibility' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<p class="description">
			<?php esc_html_e( 'Most recent changes first. Only transitions are recorded — a post that stays in the same state produces no entry.', 'msc-scheduled-visibility' ); ?>
		</p>

		<table class="widefat striped mscsv-history-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'msc-scheduled-visibility' ); ?></th>
					<th><?php esc_html_e( 'Post', 'msc-scheduled-visibility' ); ?></th>
					<th><?php esc_html_e( 'Change', 'msc-scheduled-visibility' ); ?></th>
					<th><?php esc_html_e( 'Triggered by', 'msc-scheduled-visibility' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<?php
					$edit_link = isset( $entry['post_id'] ) ? get_edit_post_link( $entry['post_id'] ) : '';
					$title     = isset( $entry['title'] ) && '' !== $entry['title']
						? $entry['title']
						: __( '(no title)', 'msc-scheduled-visibility' );
					?>
					<tr>
						<td><?php echo esc_html( $this->module->format_timestamp( isset( $entry['time'] ) ? (int) $entry['time'] : 0 ) ); ?></td>
						<td>
							<?php if ( $edit_link ) : ?>
								<a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $title ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $title ); ?>
							<?php endif; ?>
						</td>
						<td>
							<span class="mscsv-badge mscsv-badge--<?php echo esc_attr( isset( $entry['from'] ) ? $entry['from'] : '' ); ?>">
								<?php echo esc_html( $this->module->status_label( isset( $entry['from'] ) ? $entry['from'] : '' ) ); ?>
							</span>
							&rarr;
							<span class="mscsv-badge mscsv-badge--<?php echo esc_attr( isset( $entry['to'] ) ? $entry['to'] : '' ); ?>">
								<?php echo esc_html( $this->module->status_label( isset( $entry['to'] ) ? $entry['to'] : '' ) ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $this->audit_log->actor_label( $entry ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em;">
			<input type="hidden" name="action" value="mscsv_clear_log" />
			<?php wp_nonce_field( 'mscsv_clear_log' ); ?>
			<?php submit_button( __( 'Clear history', 'msc-scheduled-visibility' ), 'delete', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Render the Support tab.
	 *
	 * @return void
	 */
	private function render_tab_support() {
		?>
		<div style="max-width:800px;">

			<h2><?php esc_html_e( 'How to Use Scheduled Visibility', 'msc-scheduled-visibility' ); ?></h2>
			<p><?php esc_html_e( 'Scheduled Visibility allows you to automatically show or hide posts between specific dates and times without changing their publish status.', 'msc-scheduled-visibility' ); ?></p>

			<h3><?php esc_html_e( 'Basic Scheduling', 'msc-scheduled-visibility' ); ?></h3>
			<p><?php esc_html_e( 'When editing a post or page, look for the "Scheduled Visibility" metabox in the sidebar. Enable scheduling and set the "Visible From" and "Visible Until" dates and times.', 'msc-scheduled-visibility' ); ?></p>

			<h3><?php esc_html_e( 'Block-Level Scheduling', 'msc-scheduled-visibility' ); ?></h3>
			<p><?php esc_html_e( 'In the block editor, open the "Block Scheduling" sidebar panel to set visibility schedules for individual blocks within a post.', 'msc-scheduled-visibility' ); ?></p>

			<h3><?php esc_html_e( 'Recurring Schedules', 'msc-scheduled-visibility' ); ?></h3>
			<p><?php esc_html_e( 'Enable recurring visibility in the metabox to make content appear on a daily, weekly, or monthly pattern within a specified time window.', 'msc-scheduled-visibility' ); ?></p>

			<h3><?php esc_html_e( 'Role-Based Visibility', 'msc-scheduled-visibility' ); ?></h3>
			<p><?php esc_html_e( 'Restrict content to specific user roles. Select which roles can see the content, and optionally allow logged-out visitors to see it.', 'msc-scheduled-visibility' ); ?></p>

			<h3><?php esc_html_e( 'Quick Edit and Bulk Edit', 'msc-scheduled-visibility' ); ?></h3>
			<p><?php esc_html_e( 'Schedules can be set without opening a post. Use Quick Edit on a single row, or select several posts and use Bulk Edit to apply the same window to all of them.', 'msc-scheduled-visibility' ); ?></p>

			<h3><?php esc_html_e( 'Previewing a schedule', 'msc-scheduled-visibility' ); ?></h3>
			<p><?php esc_html_e( 'The "Preview schedule" control in the post metabox opens the front end as it will appear at a moment you choose, or to a role you choose. Nothing is saved — the simulation lasts for that one page view.', 'msc-scheduled-visibility' ); ?></p>

			<h3><?php esc_html_e( 'Frequently Asked Questions', 'msc-scheduled-visibility' ); ?></h3>

			<h4><?php esc_html_e( 'The metabox is not showing on my posts.', 'msc-scheduled-visibility' ); ?></h4>
			<ol>
				<li><?php esc_html_e( 'Check that "Enable scheduled visibility" is ticked on the General tab.', 'msc-scheduled-visibility' ); ?></li>
				<li><?php esc_html_e( 'Check that the post type is selected in the Post types list.', 'msc-scheduled-visibility' ); ?></li>
				<li><?php esc_html_e( 'The metabox appears in the sidebar when editing a post.', 'msc-scheduled-visibility' ); ?></li>
			</ol>

			<h4><?php esc_html_e( 'When does the visibility change occur?', 'msc-scheduled-visibility' ); ?></h4>
			<p><?php esc_html_e( 'Visibility changes are processed by WordPress scheduled events (cron) every 15 minutes, which is what keeps the status shown in admin listings current. Visitors never see stale content in the meantime — the schedule is also checked live on every page view.', 'msc-scheduled-visibility' ); ?></p>

			<h4><?php esc_html_e( 'Why can I still see a hidden post?', 'msc-scheduled-visibility' ); ?></h4>
			<p><?php esc_html_e( 'Users who can edit other people\'s posts bypass scheduling so they can proof upcoming content. Use the preview control, or view the site logged out, to see what visitors get.', 'msc-scheduled-visibility' ); ?></p>

			<hr style="margin:2em 0;" />

			<h2><?php esc_html_e( 'Diagnostics', 'msc-scheduled-visibility' ); ?></h2>
			<?php $this->render_diagnostics(); ?>

			<hr style="margin:2em 0;" />

			<h2><?php esc_html_e( 'Need Help?', 'msc-scheduled-visibility' ); ?></h2>
			<p><?php esc_html_e( 'If you have questions, encounter bugs, or need setup assistance, we\'re here to help.', 'msc-scheduled-visibility' ); ?></p>
			<p>
				<a class="button" href="https://anomalous.co.za" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Get Support', 'msc-scheduled-visibility' ); ?>
				</a>
			</p>

		</div>
		<?php
	}

	/**
	 * Render the diagnostics table on the Support tab.
	 *
	 * @return void
	 */
	private function render_diagnostics() {
		$next_cron  = $this->plugin->next_cron_run();
		$post_types = $this->module->get_post_types();
		$counts     = $this->count_by_status();

		$rows = array(
			__( 'Plugin version', 'msc-scheduled-visibility' )  => MSCSV_PLUGIN_VERSION,
			__( 'WordPress version', 'msc-scheduled-visibility' ) => get_bloginfo( 'version' ),
			__( 'PHP version', 'msc-scheduled-visibility' )     => PHP_VERSION,
			__( 'Site timezone', 'msc-scheduled-visibility' )   => wp_timezone_string(),
			__( 'Site time now', 'msc-scheduled-visibility' )   => $this->module->format_timestamp( $this->module->now() ),
			__( 'Enabled post types', 'msc-scheduled-visibility' ) => $post_types ? implode( ', ', $post_types ) : __( 'None', 'msc-scheduled-visibility' ),
		);

		if ( $next_cron ) {
			$rows[ __( 'Next scheduled check', 'msc-scheduled-visibility' ) ] = sprintf(
				/* translators: 1: formatted date and time, 2: human-readable interval. */
				__( '%1$s (in %2$s)', 'msc-scheduled-visibility' ),
				$this->module->format_timestamp( $next_cron ),
				human_time_diff( time(), $next_cron )
			);
		} else {
			$rows[ __( 'Next scheduled check', 'msc-scheduled-visibility' ) ] = __( 'Not scheduled — deactivate and reactivate the plugin.', 'msc-scheduled-visibility' );
		}

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$rows[ __( 'WP-Cron', 'msc-scheduled-visibility' ) ] = __( 'Disabled by DISABLE_WP_CRON — make sure a system cron calls wp-cron.php.', 'msc-scheduled-visibility' );
		}

		$rows[ __( 'Scheduled posts', 'msc-scheduled-visibility' ) ] = sprintf(
			/* translators: 1: visible count, 2: upcoming count, 3: hidden count, 4: expired count. */
			__( '%1$d visible, %2$d upcoming, %3$d hidden, %4$d expired', 'msc-scheduled-visibility' ),
			$counts['visible'],
			$counts['scheduled'],
			$counts['hidden'],
			$counts['expired']
		);
		?>
		<table class="widefat striped mscsv-diagnostics">
			<tbody>
				<?php foreach ( $rows as $label => $value ) : ?>
					<tr>
						<td style="width:220px;"><strong><?php echo esc_html( $label ); ?></strong></td>
						<td><?php echo esc_html( $value ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Count scheduled posts grouped by their stored status.
	 *
	 * @return array<string,int>
	 */
	private function count_by_status() {
		$counts     = array(
			'visible'   => 0,
			'scheduled' => 0,
			'hidden'    => 0,
			'expired'   => 0,
		);
		$post_types = $this->module->get_post_types();

		if ( empty( $post_types ) ) {
			return $counts;
		}

		/*
		 * Read straight off the term counts. Core keeps these current through
		 * _update_post_term_count(), which counts published posts of the taxonomy's
		 * own object types — the same set the old per-status WP_Query measured, but
		 * without running four queries to get it.
		 */
		foreach ( array_keys( $counts ) as $status ) {
			$term = get_term_by( 'slug', $status, Module::STATUS_TAXONOMY );

			if ( $term instanceof \WP_Term ) {
				$counts[ $status ] = (int) $term->count;
			}
		}

		return $counts;
	}
}
