<?php
/**
 * SigNoz observability service integration.
 *
 * Sends integration events (app.created, request.sent, app.transaction, app.error)
 * to the Flutterwave SigNoz service for developer analytics and TTFS/TTGL tracking.
 * All requests are fire-and-forget so they never block the payment flow.
 *
 * @class          Flutterwave_Signoz_Logger
 * @version        1.0.0
 * @package    Flutterwave/WooCommerce
 * @subpackage Flutterwave/WooCommerce/util
 */

declare(strict_types=1);

namespace Flutterwave\WooCommerce\Util;

defined( 'ABSPATH' ) || exit;

/**
 * SigNoz Logger — sends observability events to the Flutterwave analytics service.
 *
 * @since 3.1.0
 */
final class Flutterwave_Signoz_Logger {

	const BASE_URL = 'https://signozservice-prod.f4b-flutterwave.com';
	const API_KEY  = '';
	const LIBRARY  = 'WooCommerce';

	/**
	 * Singleton instance.
	 *
	 * @var Flutterwave_Signoz_Logger|null
	 */
	private static ?self $instance = null;

	/**
	 * Derived app identifier (app_<public_key>).
	 *
	 * @var string
	 */
	private string $app_id = '';

	/**
	 * Merchant environment: "production" or "sandbox".
	 *
	 * @var string
	 */
	private string $environment = 'sandbox';

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * Get or create the singleton.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get the current Flutterwave WooCommerce settings.
	 *
	 * @return array
	 */
	public function get_settings(): array {
		return get_option( 'woocommerce_rave_settings', array() );
	}

	/**
	 * Configure the logger for a specific merchant public key and environment.
	 * Must be called before any track_* methods.
	 *
	 * @param string $public_key  Merchant Flutterwave public key.
	 * @return string
	 */
	public function init( string $public_key ): string {
		$merchant_id = $this->get_merchant_id( $public_key );
		if ( $merchant_id ) {
			$merchant_id = str_replace( ' ', '_', $merchant_id );
		}
		$this->app_id                  = $merchant_id ?? $public_key;
		$this->environment             = $this->get_current_environment();
		$new_options                   = $this->get_settings();
		$new_options['merchant_id']    = $this->app_id;
		$new_options['app_registered'] = false;
		update_option( 'woocommerce_rave_settings', $new_options );
		return $merchant_id;
	}

	/**
	 * Get the merchant's Flutterwave account name using the public key.
	 *
	 * @param string $public_key Merchant Flutterwave public key.
	 * @return string|null Account name or null on failure.
	 */
	public function get_merchant_id( string $public_key ): ?string {
		$settings = $this->get_settings();

		if ( isset( $settings['merchant_id'] ) && $settings['merchant_id'] ) {
			return $settings['merchant_id'];
		}
		// Extract the merchant ID from the public key (assuming format "FLWPUBK-<merchant_id>-...").
		// Make a request to https://api.ravepay.co/flwv3-pug/getpaidx/api/mercinfo?PBFPubKey=$public_key.
		$url      = 'https://api.ravepay.co/flwv3-pug/getpaidx/api/mercinfo?PBFPubKey=' . $public_key;
		$response = wp_remote_get( $url );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$body = json_decode( $body, true );

		return $body['mn'] ?? null;
	}

	/**
	 * Fire the `app.created` event.
	 * Should be called once when the merchant first configures their keys.
	 *
	 * @param string $public_key Merchant Flutterwave public key.
	 * @param string $merchant_info Merchants actual name.
	 * @return void
	 */
	public function track_app_created( string $public_key, string $merchant_info ): void {
		if ( empty( $public_key ) && empty( $merchant_info ) ) {
			return;
		}
		$this->app_id = $merchant_info;
		$this->send(
			'app.created',
			array(
				'app_id'          => $this->app_id,
				'client_id'       => null,
				'public_key'      => $public_key,
				'library'         => self::LIBRARY,
				'library_version' => $this->library_version(),
			)
		);
	}

	/**
	 * Mark App as Registered.
	 *
	 * @return void
	 */
	public function mark_app_registered() {
		$settings                       = $this->get_settings();
		$new_settings                   = $settings;
		$new_settings['app_registered'] = true;
		update_option( 'woocommerce_rave_settings', $new_settings );
	}

	/**
	 * Get an Application Identifier.
	 */
	public function get_app_id(): string {
		$settings = $this->get_settings();
		return $settings['merchant_id'];
	}

	/**
	 * Get the current environment.
	 *
	 * @return string
	 */
	public function get_current_environment(): string {
		$settings = $this->get_settings();
		return 'yes' === $settings['go_live'] ? 'production' : 'sandbox';
	}

	/**
	 * Fire the `request.sent` event when a payment request is initiated.
	 *
	 * @param string $method Payment method (e.g. "card").
	 * @param string $reference Transaction reference (tx_ref).
	 * @param string $path Request path (e.g. "/v3/charges").
	 * @param null   $logger Log to the wc logger instance for flutterwave woocoomerce.
	 * @return void
	 */
	public function track_request_sent( string $method, string $reference, string $path, $logger = null ): void {
		$payload = array(
			'app_id'          => $this->get_app_id(),
			'environment'     => $this->get_current_environment(),
			'api_version'     => 'v3',
			'library_version' => $this->library_version(),
			'method'          => $method,
			'path'            => $path,
			'reference'       => $reference,
		);

		$cache_key = 'signoz_event:request_sent:' . md5( wp_json_encode( $payload ) );

		if ( get_transient( $cache_key ) ) {
			return; // Already sent recently.
		}

		if ( ! is_null( $logger ) ) {
			$logger->info( 'request.sent: ' . wp_json_encode( $payload ) );
		}

		$this->send(
			'request.sent',
			$payload
		);

		set_transient( $cache_key, true, 300 ); // 5 minute TTL.
	}

	/**
	 * Fire the `app.transaction` event after a successful payment.
	 *
	 * @param string $reference Transaction reference (tx_ref).
	 * @param string $currency ISO 4217 currency code.
	 * @param float  $amount Transaction amount.
	 * @param string $method Payment method (e.g. "card").
	 * @param float  $fee Transaction fee.
	 * @return void
	 */
	public function track_transaction(
		string $reference,
		string $currency,
		float $amount,
		string $method,
		float $fee
	): void {
		$this->app_id      = $this->get_app_id();
		$this->environment = $this->get_current_environment();
		$this->send(
			'app.transaction',
			array(
				'app_id'    => $this->app_id,
				'reference' => $reference,
				'currency'  => $currency,
				'amount'    => $amount,
				'fee'       => $fee,
				'method'    => $method,
			)
		);
	}

	/**
	 * Fire the `app.error` event when a payment fails.
	 *
	 * @param string $error_code    Short machine-readable error code.
	 * @param string $error_message Human-readable error description.
	 * @return void
	 */
	public function track_error( string $error_code, string $error_message ): void {
		$this->app_id      = $this->get_app_id();
		$this->environment = $this->get_current_environment();
		$this->send(
			'app.error',
			array(
				'app_id'          => $this->app_id,
				'library'         => self::LIBRARY,
				'library_version' => $this->library_version(),
				'error_code'      => $error_code,
				'error_message'   => $error_message,
			)
		);
	}

	/**
	 * Dispatch an event to the SigNoz service.
	 * Uses non-blocking HTTP so the payment flow is never delayed.
	 *
	 * @param string $event_name SigNoz event name (e.g. "app.created").
	 * @param array  $data       Event payload.
	 * @return void
	 */
	private function send( string $event_name, array $data ): void {
		$payload = array(
			'name'      => $event_name,
			'data'      => $data,
			'timestamp' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
		);

		wp_remote_post(
			self::BASE_URL . '/events',
			array(
				'headers'  => array(
					'Content-Type' => 'application/json',
					'x-api-key'    => self::API_KEY,
				),
				'body'     => wp_json_encode( $payload ),
				'blocking' => false,
				'timeout'  => 5,
			)
		);
	}

	/**
	 * Return the plugin version, falling back gracefully if the constant is not yet defined.
	 *
	 * @return string
	 */
	private function library_version(): string {
		return defined( 'FLW_WC_VERSION' ) ? FLW_WC_VERSION : '3.1.0';
	}
}
