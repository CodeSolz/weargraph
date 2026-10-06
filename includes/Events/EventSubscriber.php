<?php
/**
 * Turns fitting-room, generation and WooCommerce hooks into §46 events.
 *
 * @package WearGraph
 */

namespace WearGraph\Events;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Repositories\EventsRepository;
use WearGraph\Database\Repositories\GenerationsRepository;
use WearGraph\Generation\Kinds\TryOnKind;
use WearGraph\Storefront\SessionManager;

/**
 * Everything is recorded on the server except `variant_changed` (the beacon). Commerce
 * events are matched by the fitting-room session cookie; the order keeps the session
 * id in its meta (HPOS-safe CRUD) so the purchase can be matched later, whoever
 * changes the order status. The Dashboard shows checkouts, orders and sales after try-on.
 */
final class EventSubscriber {

	public const ORDER_SESSION_META  = '_weargraph_session';
	public const ORDER_RECORDED_META = '_weargraph_purchase_recorded';

	private const CHECKOUT_DEDUPE = 30 * MINUTE_IN_SECONDS;

	/**
	 * Recorder.
	 *
	 * @var EventRecorder
	 */
	private $recorder;

	/**
	 * Events.
	 *
	 * @var EventsRepository
	 */
	private $events;

	/**
	 * Generations.
	 *
	 * @var GenerationsRepository
	 */
	private $generations;

	/**
	 * Sessions.
	 *
	 * @var SessionManager
	 */
	private $sessions;

	/**
	 * Constructor.
	 *
	 * @param EventRecorder         $recorder    Recorder.
	 * @param EventsRepository      $events      Events.
	 * @param GenerationsRepository $generations Generations.
	 * @param SessionManager        $sessions    Sessions.
	 */
	public function __construct( EventRecorder $recorder, EventsRepository $events, GenerationsRepository $generations, SessionManager $sessions ) {
		$this->recorder    = $recorder;
		$this->events      = $events;
		$this->generations = $generations;
		$this->sessions    = $sessions;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'weargraph_storefront_opened', array( $this, 'opened' ), 10, 2 );
		add_action( 'weargraph_storefront_photo_saved', array( $this, 'photo_saved' ) );
		add_action( 'weargraph_storefront_photo_rejected', array( $this, 'photo_rejected' ), 10, 2 );
		add_action( 'weargraph_storefront_tryon_requested', array( $this, 'tryon_requested' ), 10, 5 );
		add_action( 'weargraph_generation_ready', array( $this, 'generation_ready' ) );
		add_action( 'weargraph_generation_failed', array( $this, 'generation_failed' ), 10, 2 );
		add_action( 'weargraph_storefront_client_event', array( $this, 'client_event' ), 10, 4 );
		add_action( 'weargraph_session_erased', array( $this->events, 'forget_session' ) );

		add_action( 'woocommerce_add_to_cart', array( $this, 'added_to_cart' ), 10, 4 );
		add_action( 'template_redirect', array( $this, 'checkout_viewed' ) );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'link_order' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'link_order' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'order_status_changed' ), 10, 4 );
	}

	/**
	 * Fitting room opened.
	 *
	 * @param int    $product_id Product.
	 * @param string $session_id Session public id.
	 */
	public function opened( $product_id, $session_id ): void {
		$this->recorder->record(
			'fitting_room_opened',
			array(
				'session_id' => $session_id,
				'product_id' => (int) $product_id,
			)
		);
	}

	/**
	 * Photo passed the server checks and was stored.
	 *
	 * @param string $session_id Session public id.
	 */
	public function photo_saved( $session_id ): void {
		$this->recorder->record( 'photo_uploaded', array( 'session_id' => $session_id ) );
		$this->recorder->record( 'photo_validated', array( 'session_id' => $session_id ) );
	}

	/**
	 * Photo arrived but was refused.
	 *
	 * @param string $reason     SanitizeException code.
	 * @param string $session_id Session public id.
	 */
	public function photo_rejected( $reason, $session_id ): void {
		$this->recorder->record(
			'photo_uploaded',
			array(
				'session_id' => $session_id,
				'props'      => array( 'rejected' => (string) $reason ),
			)
		);
	}

	/**
	 * Try-on requested (a cached result also counts as completed straight away).
	 *
	 * @param array{id:string,status:string,cached:bool} $result       Result.
	 * @param int                                        $product_id   Product.
	 * @param int                                        $variation_id Variation.
	 * @param bool                                       $regenerate   Regeneration.
	 * @param string                                     $session_id   Session public id.
	 */
	public function tryon_requested( $result, $product_id, $variation_id, $regenerate = false, $session_id = '' ): void {
		$row  = $this->generations->find_public( (string) ( $result['id'] ?? '' ) );
		$base = array(
			'session_id'    => $session_id,
			'product_id'    => (int) $product_id,
			'variant_id'    => (int) $variation_id,
			'generation_id' => $row ? (int) $row['id'] : 0,
		);

		$this->recorder->record( $regenerate ? 'tryon_regenerated' : 'tryon_requested', $base + array( 'props' => array( 'cached' => ! empty( $result['cached'] ) ) ) );

		if ( ! empty( $result['cached'] ) && 'ready' === ( $result['status'] ?? '' ) ) {
			$this->recorder->record( 'tryon_completed', $base + array( 'props' => array( 'cached' => true ) ) );
		}
	}

	/**
	 * Event reported by the browser beacon (already limited to EventSchema::CLIENT_NAMES).
	 *
	 * @param string $name         Event name.
	 * @param string $session_id   Session public id.
	 * @param int    $product_id   Product.
	 * @param int    $variation_id Variation.
	 */
	public function client_event( $name, $session_id, $product_id, $variation_id ): void {
		$this->recorder->record(
			(string) $name,
			array(
				'session_id' => $session_id,
				'product_id' => (int) $product_id,
				'variant_id' => (int) $variation_id,
			)
		);
	}

	/**
	 * Storefront try-on finished.
	 *
	 * @param int $id Generation id.
	 */
	public function generation_ready( $id ): void {
		$row = $this->storefront_tryon( (int) $id );
		if ( $row ) {
			$this->recorder->record( 'tryon_completed', $this->from_generation( $row, array( 'latency_ms' => (int) $row['latency_ms'] ) ) );
		}
	}

	/**
	 * Storefront try-on failed.
	 *
	 * @param int    $id   Generation id.
	 * @param string $code Error code.
	 */
	public function generation_failed( $id, $code = '' ): void {
		$row = $this->storefront_tryon( (int) $id );
		if ( $row ) {
			$this->recorder->record( 'tryon_failed', $this->from_generation( $row, array( 'code' => (string) $code ) ) );
		}
	}

	/**
	 * Added to cart (any way: fitting room or the page's own button) after trying the product on.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param int    $product_id    Product.
	 * @param int    $quantity      Quantity.
	 * @param int    $variation_id  Variation.
	 */
	public function added_to_cart( $cart_item_key, $product_id, $quantity, $variation_id = 0 ): void {
		$session = $this->sessions->current();
		if ( ! $session ) {
			return;
		}
		$tried = $this->events->tried_products( (string) $session['public_id'] );
		if ( ! isset( $tried[ (int) $product_id ] ) ) {
			return;
		}

		$this->recorder->record(
			'add_to_cart_after_tryon',
			array(
				'session_id' => (string) $session['public_id'],
				'product_id' => (int) $product_id,
				'variant_id' => (int) $variation_id,
				'props'      => array(
					'qty'          => (int) $quantity,
					'same_variant' => in_array( (int) $variation_id, $tried[ (int) $product_id ], true ),
				),
			)
		);
	}

	/**
	 * Checkout page viewed by a shopper who used the fitting room (once per 30 minutes).
	 */
	public function checkout_viewed(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || is_wc_endpoint_url( 'order-pay' ) ) {
			return;
		}
		$session = $this->sessions->current();
		if ( ! $session ) {
			return;
		}
		$sid   = (string) $session['public_id'];
		$tried = $this->events->tried_products( $sid );
		if ( ! $tried || get_transient( 'weargraph_ck_' . $sid ) ) {
			return;
		}
		set_transient( 'weargraph_ck_' . $sid, 1, self::CHECKOUT_DEDUPE );

		$in_cart = 0;
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$in_cart += isset( $tried[ (int) ( $item['product_id'] ?? 0 ) ] ) ? 1 : 0;
			}
		}

		$this->recorder->record(
			'checkout_started_after_tryon',
			array(
				'session_id' => $sid,
				'props'      => array( 'tried_in_cart' => $in_cart ),
			)
		);
	}

	/**
	 * Remember the fitting-room session on a new order (classic and block checkout).
	 *
	 * @param \WC_Order $order Order.
	 */
	public function link_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$session = $this->sessions->current();
		if ( ! $session || ! $this->events->tried_products( (string) $session['public_id'] ) ) {
			return;
		}

		$order->update_meta_data( self::ORDER_SESSION_META, (string) $session['public_id'] );
		$order->save();
	}

	/**
	 * Order paid: one purchase event per line item that was tried on.
	 *
	 * @param int            $order_id Order id.
	 * @param string         $from     Old status.
	 * @param string         $to       New status.
	 * @param \WC_Order|null $order    Order.
	 */
	public function order_status_changed( $order_id, $from, $to, $order = null ): void {
		if ( ! in_array( (string) $to, wc_get_is_paid_statuses(), true ) ) {
			return;
		}
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$sid = (string) $order->get_meta( self::ORDER_SESSION_META );
		if ( '' === $sid || $order->get_meta( self::ORDER_RECORDED_META ) ) {
			return;
		}

		$tried = $this->events->tried_products( $sid );
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || ! isset( $tried[ $item->get_product_id() ] ) ) {
				continue;
			}
			$this->recorder->record(
				'purchase_after_tryon',
				array(
					'session_id' => $sid,
					'product_id' => $item->get_product_id(),
					'variant_id' => $item->get_variation_id(),
					'order_id'   => $order->get_id(),
					'props'      => array(
						'qty'          => (int) $item->get_quantity(),
						'line_total'   => (float) $item->get_total(),
						'currency'     => $order->get_currency(),
						'same_variant' => in_array( (int) $item->get_variation_id(), $tried[ $item->get_product_id() ], true ),
					),
				)
			);
		}

		$order->update_meta_data( self::ORDER_RECORDED_META, time() );
		$order->save();
	}

	/**
	 * A storefront try-on generation row, or null.
	 *
	 * @param int $id Generation id.
	 * @return array<string,mixed>|null
	 */
	private function storefront_tryon( int $id ): ?array {
		$row = $this->generations->find( $id );

		return $row && 'storefront' === $row['context'] && TryOnKind::ID === $row['kind'] ? $row : null;
	}

	/**
	 * Base properties from a generation row.
	 *
	 * @param array<string,mixed> $row   Row.
	 * @param array<string,mixed> $props Extra properties.
	 * @return array<string,mixed>
	 */
	private function from_generation( array $row, array $props ): array {
		return array(
			'session_id'    => (string) $row['session_id'],
			'product_id'    => (int) $row['product_id'],
			'variant_id'    => (int) $row['variant_id'],
			'generation_id' => (int) $row['id'],
			'props'         => $props,
		);
	}
}
