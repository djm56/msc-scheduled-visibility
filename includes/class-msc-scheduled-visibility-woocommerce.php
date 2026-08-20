<?php
/**
 * WooCommerce integration for MSC Scheduled Visibility.
 *
 * @package MSC_Scheduled_Visibility
 */

namespace MSC_Scheduled_Visibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies scheduled visibility to WooCommerce products.
 *
 * WooCommerce builds its shop and archive listings through its own query class and
 * gates add-to-cart behind `woocommerce_is_purchasable`, so the generic post filters
 * are not enough on their own.
 */
class WooCommerce {

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

		if ( ! $this->is_active() ) {
			return;
		}

		/*
		 * Shop, archive, shortcode and block product lists are all WP_Query-backed, so
		 * the module's `the_posts` pass already drops scheduled-out products from them.
		 * The catalog-visibility and purchasability filters below cover the paths that
		 * do not go through a post query at all.
		 */

		// A hidden product must not be buyable even if a direct link is followed.
		add_filter( 'woocommerce_is_purchasable', array( $this, 'filter_is_purchasable' ), 10, 2 );
		add_filter( 'woocommerce_is_visible', array( $this, 'filter_is_visible' ), 10, 2 );

		// Block checkout for anything that slipped into the cart before it expired.
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_items' ) );

		// Explain the absence rather than rendering a blank product page.
		add_filter( 'mscsv_hidden_content_message', array( $this, 'hidden_product_message' ), 10, 2 );
	}

	/**
	 * Whether WooCommerce is loaded and the integration is switched on.
	 *
	 * @return bool
	 */
	public function is_active() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		if ( ! $this->plugin->get_option( 'module_enabled', 1 ) ) {
			return false;
		}

		if ( ! $this->plugin->get_option( 'woocommerce_enabled', 1 ) ) {
			return false;
		}

		return in_array( 'product', $this->module->get_post_types(), true );
	}

	/**
	 * Prevent purchase of a product that is outside its visibility window.
	 *
	 * @param bool        $purchasable Current purchasability.
	 * @param \WC_Product $product     Product object.
	 * @return bool
	 */
	public function filter_is_purchasable( $purchasable, $product ) {
		if ( ! $purchasable || ! is_object( $product ) || $this->module->can_bypass() ) {
			return $purchasable;
		}

		return $this->module->is_post_visible( $product->get_id() );
	}

	/**
	 * Hide a scheduled-out product from catalog visibility checks.
	 *
	 * @param bool $visible    Current visibility.
	 * @param int  $product_id Product ID.
	 * @return bool
	 */
	public function filter_is_visible( $visible, $product_id ) {
		if ( ! $visible || ! $this->module->is_visitor_facing_request() || $this->module->can_bypass() ) {
			return $visible;
		}

		return $this->module->is_post_visible( $product_id );
	}

	/**
	 * Reject checkout when a cart item is no longer visible.
	 *
	 * @return void
	 */
	public function validate_cart_items() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;

			if ( ! $product_id || $this->module->is_post_visible( $product_id ) ) {
				continue;
			}

			wc_add_notice(
				sprintf(
					/* translators: %s: product name. */
					esc_html__( '"%s" is no longer available and has been removed from your cart.', 'msc-scheduled-visibility' ),
					esc_html( get_the_title( $product_id ) )
				),
				'error'
			);

			WC()->cart->remove_cart_item( $cart_item['key'] );
		}
	}

	/**
	 * Default replacement copy for a hidden product page.
	 *
	 * @param string $message Current message.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function hidden_product_message( $message, $post_id = 0 ) {
		if ( '' !== $message || 'product' !== get_post_type( $post_id ) ) {
			return $message;
		}

		return '<p class="mscsv-unavailable">'
			. esc_html__( 'This product is not currently available.', 'msc-scheduled-visibility' )
			. '</p>';
	}
}
