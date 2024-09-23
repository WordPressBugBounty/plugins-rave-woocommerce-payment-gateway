<?php
/**
 * The client-specific functionality of the plugin.
 *
 * This class handles all events from the Rave class
 *
 * @link       https://flutterwave.com
 * @since      2.3.2
 * @class      FLW_WC_Payment_Gateway_Event_Handler
 * @package    Flutterwave\WooCommerce
 * @subpackage FLW_WC_Payment_Gateway/includes
 */

declare(strict_types=1);

require FLW_WC_DIR_PATH . 'includes/contracts/class-flw-wc-payment-gateway-event-handler-interface.php';

use Flutterwave\WooCommerce\Contracts\FLW_WC_Payment_Gateway_Event_Handler_Interface;
/**
 * This is the class that handles all events from the Rave class
 * */
class FLW_WC_Payment_Gateway_Event_Handler implements FLW_WC_Payment_Gateway_Event_Handler_Interface {
	/**
	 * WC_Order This is the order object.
	 *
	 * @var WC_Order This is the order object.
	 */
	private WC_Order $order;

	/**
	 * FLW_WC_Payment_Gateway_Event_Handler constructor.
	 *
	 * @param WC_Order $order This is the order object.
	 */
	public function __construct( WC_Order $order ) {
		$this->order = $order;
	}

	/**
	 * Check Amount Equals.
	 *
	 * Checks to see whether the given amounts are equal using a proper floating
	 * point comparison with an Epsilon which ensures that insignificant decimal
	 * places are ignored in the comparison.
	 *
	 * eg. 100.00 is equal to 100.0001
	 *
	 * @param Float $amount1 1st amount for comparison.
	 * @param Float $amount2  2nd amount for comparison.
	 * @since 2.3.3
	 * @return bool
	 */
	public function amounts_equal( $amount1, $amount2 ): bool {
		return ! ( abs( floatval( $amount1 ) - floatval( $amount2 ) ) > FLW_WC_EPSILON );
	}

	/**
	 * This is called when the Flutterwave class is initialized
	 *
	 * @param object $initialization_data - This is the transaction data as returned from the Flutterwave payment gateway.
	 */
	public function on_init( object $initialization_data ) {
		// Save the transaction to your DB.
		$this->order->add_order_note( esc_html__( 'Payment initialized via Flutterwave', 'rave-woocommerce-payment-gateway' ) );
		update_post_meta( $this->order->get_id(), '_flw_payment_txn_ref', $initialization_data->txref );
		$this->order->add_order_note( esc_html__( 'Your transaction reference: ', 'rave-woocommerce-payment-gateway' ) . $initialization_data->txref );
	}

	/**
	 * This is called only when a transaction is successful.
	 *
	 * @param object $transaction_data - This is the transaction data as returned from the Flutterwave payment gateway.
	 */
	public function on_successful( object $transaction_data ) {
		if ( 'successful' === $transaction_data->status ) {
			$amount = (float) $transaction_data->amount;

			if ( $transaction_data->currency !== $this->order->get_currency() || ! $this->amounts_equal( $amount, $this->order->get_total() ) ) {
				$this->order->update_status( 'on-hold' );
				$customer_note  = 'Thank you for your order.<br>';
				$customer_note .= 'Your payment successfully went through, but we have to put your order <strong>on-hold</strong> ';
				$customer_note .= 'because the we couldn\t verify your order. Please, contact us for information regarding this order.';
				$admin_note     = esc_html__( 'Attention: New order has been placed on hold because of incorrect payment amount or currency. Please, look into it.', 'rave-woocommerce-payment-gateway' ) . '<br>';
				$admin_note    .= esc_html__( 'Amount paid: ', 'rave-woocommerce-payment-gateway' ) . $transaction_data->currency . ' ' . $amount . ' <br>' . esc_html__( 'Order amount: ', 'rave-woocommerce-payment-gateway' ) . $this->order->get_currency() . ' ' . $this->order->get_total() . ' <br>' . esc_html__( ' Reference: ', 'rave-woocommerce-payment-gateway' ) . $transaction_data->tx_ref;

				$this->order->add_order_note( $customer_note, 1 );
				$this->order->add_order_note( $admin_note );

			} else {
				$this->order->payment_complete( $this->order->get_id() );
				$payment_method = $this->order->get_payment_method();

				$flw_settings = get_option( 'woocommerce_' . $payment_method . '_settings' );

				if ( isset( $flw_settings['autocomplete_order'] ) && 'yes' === $flw_settings['autocomplete_order'] ) {
					$this->order->update_status( 'completed' );
				}
				$this->order->add_order_note( 'Payment was successful on Flutterwave' );
				$this->order->add_order_note( 'Flutterwave transaction reference: ' . $transaction_data->flw_ref );

				$customer_note  = 'Thank you for your order.<br>';
				$customer_note .= 'Your payment was successful, we are now <strong>processing</strong> your order.';
				$this->order->add_order_note( $customer_note, 1 );
			}
			wc_add_notice( $customer_note, 'notice' );
			// get order_id from the txref.
			$get_order_id = explode( '_', $transaction_data->tx_ref );
			$order_id     = $get_order_id[1];
			// save the card token returned here.
			FLW_WC_Payment_Gateway::save_card_details( $transaction_data, $this->order->get_user_id(), $order_id );
			WC()->cart->empty_cart();
		} else {
			$this->on_failure( $transaction_data );
		}
	}

	/**
	 * This is called only when a transaction failed
	 *
	 * @param object $transaction_data - This is the transaction data as returned from the Flutterwave payment gateway.
	 */
	public function on_failure( object $transaction_data ) {
		$this->order->update_status( 'failed' );
		$this->order->add_order_note( esc_html__( 'The payment failed on Flutterwave', 'rave-woocommerce-payment-gateway' ) );
		$customer_note  = 'Your payment <strong>failed</strong>. ';
		$customer_note .= 'Please, try again or use another Payment Method on the modal.';
		$reason         = $transaction_data->processor_response ?? ' - ';

		$this->order->add_order_note( esc_html__( 'Reason for Failure : ', 'rave-woocommerce-payment-gateway' ) . $reason );

		wc_add_notice( $customer_note, 'notice' );
	}

	/**
	 * This is called when a transaction is requeryed from the payment gateway
	 *
	 * @param string $transaction_reference - This is the transaction reference (txref) of the transaction you want to requery.
	 * */
	public function on_requery( string $transaction_reference ) {
		// Do something, anything!.
		$this->order->add_order_note( esc_html__( 'Confirming payment on Flutterwave', 'rave-woocommerce-payment-gateway' ) );
	}

	/**
	 * This is called a transaction requery returns with an error
	 *
	 * @param object $requery_response - This is the response from the payment gateway when a transaction is requeryed.
	 * */
	public function on_requery_error( $requery_response ) {
		// Do something, anything!.
		$this->order->add_order_note( esc_html__( 'An error occured while confirming payment on Flutterwave', 'rave-woocommerce-payment-gateway' ) );
		$this->order->update_status( 'on-hold' );
		$customer_note  = 'Thank you for your order.<br>';
		$customer_note .= 'We had an issue confirming your payment, but we have put your order <strong>on-hold</strong>. ';
		$customer_note .= 'Please, contact us for information regarding this order.';
		$admin_note     = esc_html__( 'Attention: New order has been placed on hold because we could not confirm the payment. Please, look into it.', 'rave-woocommerce-payment-gateway' ) . '<br>';
		$admin_note    .= esc_html( 'Payment Responce: ' ) . $requery_response->message;

		$this->order->add_order_note( $customer_note, 1 );
		$this->order->add_order_note( $admin_note );

		wc_add_notice( $customer_note, 'notice' );
	}

	/**
	 * This is called when a transaction is canceled by the user
	 *
	 * @param string $transaction_reference - This is the transaction reference (txref) of the transaction you want to requery.
	 * */
	public function on_cancel( string $transaction_reference ) {
		// Note: Sometimes a payment can be successful, before a user clicks the cancel button so proceed with caution.
		$this->order->add_order_note( esc_html__( 'The customer clicked on the cancel button on Checkout.', 'rave-woocommerce-payment-gateway' ) );
		$this->order->update_status( 'cancelled' );
		$admin_note  = esc_html__( 'Attention: Customer clicked on the cancel button on the payment gateway. We have updated the order to cancelled status. ', 'rave-woocommerce-payment-gateway' ) . '<br>';
		$admin_note .= esc_html__( 'Please, confirm from the order notes that there is no note of a successful transaction. If there is, this means that the user was debited and you either have to give value for the transaction or refund the customer.', 'rave-woocommerce-payment-gateway' );
		$this->order->add_order_note( $admin_note );
	}

	/**
	 * This is called when a transaction doesn't return with a success or a failure response. This can be a timedout transaction on the Rave server or an abandoned transaction by the customer.
	 *
	 * @param string $transaction_reference - This is the transaction reference (txref) of the transaction you want to requery.
	 * @param object $data - This is the data returned from the payment gateway.
	 * */
	public function on_timeout( string $transaction_reference, object $data ) {
		// Get the transaction from your DB using the transaction reference (txref)
		// Queue it for requery. Preferably using a queue system. The requery should be about 15 minutes after.
		// Ask the customer to contact your support and you should escalate this issue to the flutterwave support team. Send this as an email and as a notification on the page. just incase the page timesout or disconnects.
		$this->order->add_order_note( esc_html__( 'The payment didn\'t return a valid response. It could have timed out or abandoned by the customer on Flutterwave', 'rave-woocommerce-payment-gateway' ) );
		$this->order->update_status( 'on-hold' );
		$customer_note  = 'Thank you for your order.<br>';
		$customer_note .= 'We had an issue confirming your payment, but we have put your order <strong>on-hold</strong>. ';
		$customer_note .= esc_html__( 'Please, contact us for information regarding this order.', 'woocomerce-rave' );
		$admin_note     = esc_html__( 'Attention: New order has been placed on hold because we could not get a definite response from the payment gateway. Kindly contact the Rave support team at hi@flutterwave.com to confirm the payment.', 'rave-woocommerce-payment-gateway' ) . ' <br>';
		$admin_note    .= esc_html__( 'Payment Reference: ', 'rave-woocommerce-payment-gateway' ) . $transaction_reference;

		$this->order->add_order_note( $customer_note, 1 );
		$this->order->add_order_note( $admin_note );

		wc_add_notice( $customer_note, 'notice' );
	}

	/**
	 * This is called when a webhook is received from the payment gateway
	 *
	 * @param string $event_type The type of event received. eg: charge.successful.
	 * @param object $event_data The data sent with the event.
	 * */
	public function on_webhook( string $event_type, object $event_data ) {
		$status = 'pending';
		// TODO: Save the event data to clients database.
	}
}


