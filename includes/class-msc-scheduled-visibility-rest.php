<?php
/**
 * REST API endpoints for MSC Scheduled Visibility.
 *
 * @package MSC_Scheduled_Visibility
 */

namespace MSC_Scheduled_Visibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes read/write access to a post's schedule under `mscsv/v1`.
 */
class REST {

	/**
	 * REST namespace.
	 */
	const NAMESPACE_V1 = 'mscsv/v1';

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

		if ( ! $this->plugin->get_option( 'rest_enabled', 1 ) ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/schedule/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_schedule' ),
					'permission_callback' => array( $this, 'can_read_post' ),
					'args'                => $this->id_arg(),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_schedule' ),
					'permission_callback' => array( $this, 'can_edit_post' ),
					'args'                => $this->update_args(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_schedule' ),
					'permission_callback' => array( $this, 'can_edit_post' ),
					'args'                => $this->id_arg(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/scheduled',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_scheduled' ),
				'permission_callback' => array( $this, 'can_list' ),
				'args'                => array(
					'status'    => array(
						'type'    => 'string',
						'enum'    => array( 'any', 'visible', 'scheduled', 'hidden', 'expired' ),
						'default' => 'any',
					),
					'post_type' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'per_page'  => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'page'      => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
				),
			)
		);
	}

	// =========================================================================
	// Permissions
	// =========================================================================

	/**
	 * Whether the request may read the target post's schedule.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_read_post( $request ) {
		return $this->check_post_access( (int) $request['id'], 'read_post' );
	}

	/**
	 * Whether the request may modify the target post's schedule.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_edit_post( $request ) {
		return $this->check_post_access( (int) $request['id'], 'edit_post' );
	}

	/**
	 * Whether the request may list scheduled posts.
	 *
	 * @return bool|\WP_Error
	 */
	public function can_list() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error(
				'mscsv_forbidden',
				__( 'You are not allowed to list scheduled content.', 'msc-scheduled-visibility' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Shared post lookup and capability check.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $capability Capability to test.
	 * @return bool|\WP_Error
	 */
	private function check_post_access( $post_id, $capability ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'mscsv_not_found',
				__( 'Post not found.', 'msc-scheduled-visibility' ),
				array( 'status' => 404 )
			);
		}

		if ( ! in_array( $post->post_type, $this->module->get_post_types(), true ) ) {
			return new \WP_Error(
				'mscsv_unsupported_post_type',
				__( 'Scheduling is not enabled for this post type.', 'msc-scheduled-visibility' ),
				array( 'status' => 400 )
			);
		}

		if ( ! current_user_can( $capability, $post_id ) ) {
			return new \WP_Error(
				'mscsv_forbidden',
				__( 'You are not allowed to access this schedule.', 'msc-scheduled-visibility' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	// =========================================================================
	// Handlers
	// =========================================================================

	/**
	 * Return a post's full schedule.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_schedule( $request ) {
		return rest_ensure_response( $this->prepare_schedule( (int) $request['id'] ) );
	}

	/**
	 * Update a post's schedule.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_schedule( $request ) {
		$post_id = (int) $request['id'];

		if ( null !== $request->get_param( 'enabled' ) ) {
			$this->module->set_base_schedule(
				$post_id,
				array(
					'enabled'    => (bool) $request->get_param( 'enabled' ),
					'start_date' => (string) $request->get_param( 'start_date' ),
					'start_time' => (string) $request->get_param( 'start_time' ),
					'end_date'   => (string) $request->get_param( 'end_date' ),
					'end_time'   => (string) $request->get_param( 'end_time' ),
				)
			);
		}

		$recurring = $request->get_param( 'recurring' );
		if ( is_array( $recurring ) && $this->plugin->get_option( 'recurring_enabled', 1 ) ) {
			$this->module->set_recurring_schedule( $post_id, $recurring );
		}

		$roles = $request->get_param( 'roles' );
		if ( is_array( $roles ) && $this->plugin->get_option( 'roles_enabled', 1 ) ) {
			$this->module->set_role_rules( $post_id, $roles );
		}

		$this->module->refresh_status( $post_id );

		return rest_ensure_response( $this->prepare_schedule( $post_id ) );
	}

	/**
	 * Clear a post's schedule entirely.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_schedule( $request ) {
		$post_id = (int) $request['id'];

		$this->module->set_base_schedule( $post_id, array( 'enabled' => false ) );
		$this->module->set_recurring_schedule( $post_id, array( 'enabled' => false ) );
		$this->module->set_role_rules( $post_id, array( 'enabled' => false ) );

		return rest_ensure_response(
			array(
				'deleted'  => true,
				'schedule' => $this->prepare_schedule( $post_id ),
			)
		);
	}

	/**
	 * List posts that have scheduling enabled.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_scheduled( $request ) {
		$post_types = $this->module->get_post_types();

		$requested_type = $request->get_param( 'post_type' );
		if ( $requested_type ) {
			if ( ! in_array( $requested_type, $post_types, true ) ) {
				return rest_ensure_response(
					array(
						'items' => array(),
						'total' => 0,
						'pages' => 0,
					)
				);
			}
			$post_types = array( $requested_type );
		}

		$meta_query = array(
			array(
				'key'   => '_mscsv_enabled',
				'value' => '1',
			),
		);

		$status = $request->get_param( 'status' );
		if ( $status && 'any' !== $status ) {
			$meta_query[] = array(
				'key'   => '_mscsv_status',
				'value' => $status,
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'any',
				'posts_per_page' => (int) $request->get_param( 'per_page' ),
				'paged'          => (int) $request->get_param( 'page' ),
				'meta_query'     => $meta_query,
				'fields'         => 'ids',
			)
		);

		$items = array_map( array( $this, 'prepare_schedule' ), $query->posts );

		$response = rest_ensure_response(
			array(
				'items' => $items,
				'total' => (int) $query->found_posts,
				'pages' => (int) $query->max_num_pages,
			)
		);

		$response->header( 'X-WP-Total', (int) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (int) $query->max_num_pages );

		return $response;
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Build the response body for a single post's schedule.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public function prepare_schedule( $post_id ) {
		$post_id = (int) $post_id;

		$days = get_post_meta( $post_id, '_mscsv_recurring_days', true );
		$days = is_array( $days ) ? array_map( 'intval', $days ) : array();

		$roles = get_post_meta( $post_id, '_mscsv_roles', true );
		$roles = is_array( $roles ) ? array_values( $roles ) : array();

		return array(
			'id'         => $post_id,
			'title'      => get_the_title( $post_id ),
			'post_type'  => get_post_type( $post_id ),
			'link'       => get_permalink( $post_id ),
			'enabled'    => (bool) get_post_meta( $post_id, '_mscsv_enabled', true ),
			'status'     => $this->module->compute_post_status( $post_id ),
			'start_date' => (string) get_post_meta( $post_id, '_mscsv_start_date', true ),
			'start_time' => (string) get_post_meta( $post_id, '_mscsv_start_time', true ),
			'end_date'   => (string) get_post_meta( $post_id, '_mscsv_end_date', true ),
			'end_time'   => (string) get_post_meta( $post_id, '_mscsv_end_time', true ),
			'recurring'  => array(
				'enabled'      => (bool) get_post_meta( $post_id, '_mscsv_recurring_enabled', true ),
				'pattern'      => (string) get_post_meta( $post_id, '_mscsv_recurring_pattern', true ),
				'days'         => $days,
				'day_of_month' => (int) get_post_meta( $post_id, '_mscsv_recurring_day_of_month', true ),
				'start_time'   => (string) get_post_meta( $post_id, '_mscsv_recurring_start_time', true ),
				'end_time'     => (string) get_post_meta( $post_id, '_mscsv_recurring_end_time', true ),
				'valid_from'   => (string) get_post_meta( $post_id, '_mscsv_recurring_valid_from', true ),
				'valid_until'  => (string) get_post_meta( $post_id, '_mscsv_recurring_valid_until', true ),
			),
			'roles'      => array(
				'enabled'    => (bool) get_post_meta( $post_id, '_mscsv_roles_enabled', true ),
				'roles'      => $roles,
				'logged_out' => (bool) get_post_meta( $post_id, '_mscsv_roles_logged_out', true ),
			),
		);
	}

	/**
	 * Shared `id` argument definition.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function id_arg() {
		return array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);
	}

	/**
	 * Argument schema for the update route.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function update_args() {
		$date = array(
			'type'              => 'string',
			'default'           => '',
			'validate_callback' => array( $this, 'validate_date' ),
		);

		$time = array(
			'type'              => 'string',
			'default'           => '',
			'validate_callback' => array( $this, 'validate_time' ),
		);

		return array_merge(
			$this->id_arg(),
			array(
				'enabled'    => array( 'type' => 'boolean' ),
				'start_date' => $date,
				'start_time' => $time,
				'end_date'   => $date,
				'end_time'   => $time,
				'recurring'  => array( 'type' => 'object' ),
				'roles'      => array( 'type' => 'object' ),
			)
		);
	}

	/**
	 * Validate a YYYY-MM-DD date, allowing empty.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool|\WP_Error
	 */
	public function validate_date( $value ) {
		if ( '' === $value || null === $value ) {
			return true;
		}

		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return new \WP_Error(
				'mscsv_invalid_date',
				__( 'Dates must use the YYYY-MM-DD format.', 'msc-scheduled-visibility' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Validate an HH:MM time, allowing empty.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool|\WP_Error
	 */
	public function validate_time( $value ) {
		if ( '' === $value || null === $value ) {
			return true;
		}

		if ( ! is_string( $value ) || ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ) {
			return new \WP_Error(
				'mscsv_invalid_time',
				__( 'Times must use the 24-hour HH:MM format.', 'msc-scheduled-visibility' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}
}
