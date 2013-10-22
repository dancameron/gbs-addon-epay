<?php
/**
 * ePay Payments offsite payment processor.
 *
 * @package GBS
 * @subpackage Payment Processing_Processor
 */

class ePay_Payments extends Group_Buying_Offsite_Processors {
	const API_URL = 'https://ssl.ditonlinebetalingssystem.dk/remote/payment.asmx?WSDL';
	// credentials
	const MERCHANT_ID_OPTION = 'gb_epay_merchant_id';
	const API_PASSWORD_OPTION = 'gb_epay_api_password';
	// token
	const TOKEN_KEY = 'gb_token_key'; // Combine with $blog_id to get the actual meta key
	// options
	const CANCEL_URL_OPTION = 'gb_epay_cancel_url';
	const ACCEPT_URL_OPTION = 'gb_epay_acceptreturn_url';
	const CURRENCY_CODE_OPTION = 'gb_epay_ap_currency';
	// gbs
	const PAYMENT_METHOD = 'ePay';
	// vars
	protected static $instance;

	private static $merchant_id;
	private static $api_password;

	private static $cancel_url = '';
	private static $accept_url = '';
	private static $currency_code = 'DKK';

	/**
	 * instance
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( !( isset( self::$instance ) && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Payment method function for GBS
	 *
	 */
	public function get_payment_method() {
		return self::PAYMENT_METHOD;
	}

	/**
	 * Register payment method
	 *
	 */
	public static function register() {
		self::add_payment_processor( __CLASS__, self::__( 'ePay Payments' ) );
	}

	public static function returned_from_offsite() {
		return isset( $_GET['gb_checkout_action'] );
	}

	/**
	 * Set variables, add meta boxes to the deal page, process payments and setting payments.
	 */
	protected function __construct() {
		parent::__construct();
		// variables
		self::$merchant_id = get_option( self::MERCHANT_ID_OPTION );
		self::$api_password = get_option( self::API_PASSWORD_OPTION );

		self::$cancel_url = get_option( self::CANCEL_URL_OPTION, Group_Buying_Carts::get_url() );
		self::$accept_url = get_option( self::ACCEPT_URL_OPTION, add_query_arg( array( 'gb_checkout_action' => 'back_from_epay' ), Group_Buying_Checkouts::get_url() ) );
		self::$currency_code = get_option( self::CURRENCY_CODE_OPTION, 'DKK' );

		// payment options
		add_action( 'admin_init', array( $this, 'register_settings' ), 10, 0 );

		// Send offsite and handle the return
		add_action( 'gb_send_offsite_for_payment', array( $this, 'send_offsite' ), 10, 1 );
		add_action( 'gb_load_cart', array( $this, 'back_from_epay' ), 10, 0 );

		// Remove the review page since it's at payfast
		add_filter( 'gb_checkout_pages', array( $this, 'remove_review_page' ) );

		// payment processing
		add_action( 'purchase_completed', array( $this, 'capture_purchase' ), 10, 1 );
		add_action( self::CRON_HOOK, array( $this, 'capture_pending_payments' ) );
		if ( self::DEBUG ) {
			add_action( 'init', array( $this, 'capture_pending_payments' ), 10000 );
		}
		add_action( 'gb_manually_capture_purchase', array( $this, 'manually_capture_purchase' ), 10, 1 );

		// checkout controls customizations
		add_filter( 'gb_checkout_payment_controls', array( $this, 'payment_controls' ), 20, 2 );

		// Limitations
		add_filter( 'group_buying_template_meta_boxes/deal-expiration.php', array( $this, 'display_exp_meta_box' ), 10 );
		add_filter( 'group_buying_template_meta_boxes/deal-price.php', array( $this, 'display_price_meta_box' ), 10 );
		add_filter( 'group_buying_template_meta_boxes/deal-limits.php', array( $this, 'display_limits_meta_box' ), 10 );
	}

	/**
	 * The review page is unnecessary (or, rather, it's offsite)
	 *
	 * @param array   $pages
	 * @return array
	 */
	public function remove_review_page( $pages ) {
		unset( $pages[Group_Buying_Checkouts::REVIEW_PAGE] );
		return $pages;
	}

	/**
	 * Instead of redirecting to the GBS checkout page,
	 * redirect.
	 *
	 * @param Group_Buying_Carts $cart
	 * @return void
	 */
	public function send_offsite( Group_Buying_Checkouts $checkout ) {
		$cart = $checkout->get_cart();
		if ( $cart->get_total( self::get_payment_method() ) < 0.01 ) {
			// Nothing to do here, another payment handler intercepted and took care of everything
			// See if we can get that payment and just return it
			$payments = Group_Buying_Payment::get_payments_for_purchase( $cart->get_id() );
			foreach ( $payments as $payment_id ) {
				$payment = Group_Buying_Payment::get_instance( $payment_id );
				return $payment;
			}
		}

		// Don't send someone returning away again.
		if ( $_REQUEST['gb_checkout_action'] == Group_Buying_Checkouts::PAYMENT_PAGE ) {

			$filtered_total = self::get_payment_request_total( $checkout );
			if ( $filtered_total < 0.01 ) {
				return;
			}
			$checkout->save_cache_on_redirect( NULL ); // Save cache since it's not being saved via wp_redirect

			$reference_token = substr( md5( serialize( $cart->get_products() ) ) . get_current_user_id(), -18 );
			self::set_token( $reference_token ); // Set the token so we can reference the purchase later

			$item_array = array();
			foreach ( $cart->get_products() as $item ) {
				$item_array[] = get_the_title( $item['deal_id'] );
			}
			// memo
			$description = self::__( 'Item(s): ' ) . implode( ', ', $item_array );
			
			?>
				<div id="payment-wrap"></div>
				<script type="text/javascript">
					paymentwindow = new PaymentWindow({
						'merchantnumber': "<?php echo self::$merchant_id ?>",
						'amount': "<?php echo $filtered_total*100 ?>",
						'currency': "<?php echo self::$currency_code ?>",
						'windowstate': "4",
						'paymentcollection': "1",
						'orderid': "<?php echo $reference_token ?>",
						'language': "<?php echo apply_filters( 'epay_language', 1 ) ?>",
						'accepturl': "<?php echo self::$accept_url ?>",
						'cancelurl': "<?php echo self::$cancel_url ?>",
						'ordertext': "<?php echo urlencode( get_bloginfo( 'name' ) ) ?>",
						'description': "<?php echo urlencode( $description ) ?>"
					});
					paymentwindow.append('payment-wrap');
					paymentwindow.open();
				</script>
			<?php
			exit();
		}
	}

	/**
	 * We're on the checkout page, just back from ePay.
	 * Unset the token if they're not returning from ePay
	 *
	 * @return void
	 */
	public function back_from_epay() {
		if ( self::validate_return() ) {
			// let the checkout know that this isn't a fresh start
			$_REQUEST['gb_checkout_action'] = 'valid_return_from_epay';
		} elseif ( !isset( $_REQUEST['gb_checkout_action'] ) ) {
			// this is a new checkout. clear the token so we don't give things away for free
			self::unset_token();
		}
	}

	public static function validate_return() {
		if ( isset( $_REQUEST['txnid'] ) && $_REQUEST['txnid'] != '' ) {
			return TRUE;
		}
		return FALSE;
	}

	////////////////////////////////////////////////////////
	// back from epay and ready to process the payment //
	////////////////////////////////////////////////////////

	/**
	 * Process a payment
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return Group_Buying_Payment|bool FALSE if the payment failed, otherwise a Payment object
	 */
	public function process_payment( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {

		if ( $purchase->get_total( self::get_payment_method() ) < 0.01 ) {
			// Nothing to do here, another payment handler intercepted and took care of everything
			// See if we can get that payment and just return it
			$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
			foreach ( $payments as $payment_id ) {
				$payment = Group_Buying_Payment::get_instance( $payment_id );
				return $payment;
			}
		}

		// Payment Data
		$payment_data = array();
		foreach ( $_REQUEST as $k => $v ) {
			$payment_data[$k] = $v;
		}

		// create loop of deals for the payment post
		$deal_info = array();
		foreach ( $purchase->get_products() as $item ) {
			if ( isset( $item['payment_method'][self::get_payment_method()] ) ) {
				if ( !isset( $deal_info[$item['deal_id']] ) ) {
					$deal_info[$item['deal_id']] = array();
				}
				$deal_info[$item['deal_id']][] = $item;
			}
		}
		if ( isset( $checkout->cache['shipping'] ) ) {
			$shipping_address = array();
			$shipping_address['first_name'] = $checkout->cache['shipping']['first_name'];
			$shipping_address['last_name'] = $checkout->cache['shipping']['last_name'];
			$shipping_address['street'] = $checkout->cache['shipping']['street'];
			$shipping_address['city'] = $checkout->cache['shipping']['city'];
			$shipping_address['zone'] = $checkout->cache['shipping']['zone'];
			$shipping_address['postal_code'] = $checkout->cache['shipping']['postal_code'];
			$shipping_address['country'] = $checkout->cache['shipping']['country'];
		}

		// create new payment
		$payment_id = Group_Buying_Payment::new_payment( array(
				'payment_method' => self::get_payment_method(),
				'purchase' => $purchase->get_id(),
				'amount' => $_REQUEST['amount'],
				'data' => array(
					'txnid' => $_REQUEST['txnid'],
					'orderid' => $_REQUEST['orderid'],
					'api_response' => $payment_data,
					'uncaptured_deals' => $deal_info,
					'token' => self::get_token()
				),
				'deals' => $deal_info,
				'shipping_address' => $shipping_address,
			), Group_Buying_Payment::STATUS_AUTHORIZED );
		if ( !$payment_id ) {
			return FALSE;
		}
		$payment = Group_Buying_Payment::get_instance( $payment_id );
		do_action( 'payment_authorized', $payment );

		// remove token so that user can purchase again.
		self::unset_token();

		// finalize
		return $payment;
	}

	///////////////////////////////////
	// Post Payment/Purchase Methods //
	///////////////////////////////////

	/**
	 * Facility to capture a purchase manually.
	 *
	 * @param Group_Buying_Payment $payment
	 * @return void
	 */
	public function manually_capture_purchase( Group_Buying_Payment $payment ) {
		$this->capture_payment( $payment );
	}

	/**
	 * Capture a pre-authorized payment
	 *
	 * @param Group_Buying_Purchase $purchase
	 * @return void
	 */
	public function capture_purchase( Group_Buying_Purchase $purchase ) {
		$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance( $payment_id );
			$this->capture_payment( $payment );
		}
	}

	/**
	 * Try to capture all pending payments
	 *
	 * @return void
	 */
	public function capture_pending_payments() {
		// Filter the post query so that it returns only payments in the last 90 days
		add_filter( 'posts_where', array( __CLASS__, 'filter_where' ) );
		$payments = Group_Buying_Payment::get_pending_payments( self::get_payment_method(), FALSE );
		remove_filter( 'posts_where', array( __CLASS__, 'filter_where' ) );

		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance( $payment_id );
			$this->capture_payment( $payment );
		}
	}

	public function capture_payment( Group_Buying_Payment $payment ) {
		// is this the right payment processor? does the payment still need processing?
		if ( $payment->get_payment_method() == self::get_payment_method() && $payment->get_status() != Group_Buying_Payment::STATUS_COMPLETE ) {

			$data = $payment->get_data();
			if ( isset( $data['txnid'] ) && $data['txnid'] ) {

				// items we need to capture
				$items_to_capture = $this->items_to_capture( $payment );
				if ( $items_to_capture ) {

					// Retrieve Payment
					$response = self::api_capture( $data['txnid'], $data['api_response']['amount'] );

					if ( !$response )
						return FALSE;

					// if not set create an array
					if ( !isset( $data['capture_response'] ) ) {
						$data['capture_response'] = array();
					}
					// Set the response within the data
					$data['capture_response'][] = $response;

					// Set data regardless if a successful payment was captured this time around.
					$payment->set_data( $data );

					do_action( 'payment_captured', $payment, array_keys( $items_to_capture ) );
					$payment->set_status( Group_Buying_Payment::STATUS_COMPLETE );
					do_action( 'payment_complete', $payment );
				}
			}
		}
	}


	private function api_capture( $txnid = '', $amount = 0 ) {
		$epay_params = array();
		$epay_params["merchantnumber"] = self::$merchant_id;
		$epay_params["transactionid"] = $txnid;
		$epay_params["amount"] = $amount;
		$epay_params["pwd"] = self::$api_password;
		$epay_params["pbsResponse"] = '-1';
		$epay_params["epayresponse"] = '-1';
		
		$client = new SoapClient( self::API_URL );

		$result = $client->capture( $epay_params );

		if ( $result->captureResult == TRUE ) {
			do_action( 'gb_error', __CLASS__ . '::' . __FUNCTION__ . ' - Success', self::getEpayError( $result ) );
			return $result;
		} 
		else {
			if ( $result->epayresponse != "-1" ) {
				do_action( 'gb_error', __CLASS__ . '::' . __FUNCTION__ . ' - Error', self::getEpayError( $result->epayresponse ) );
			}
			elseif ( $result->pbsResponse != "-1" ) {
				do_action( 'gb_error', __CLASS__ . '::' . __FUNCTION__ . ' - Error', self::getPbsError( $result->pbsResponse ) );
			}
			else {
				do_action( 'gb_error', __CLASS__ . '::' . __FUNCTION__ . ' - Unknown Error', $result );
			}
			return FALSE;
		}

	}

	public static function getEpayError( $epay_response_code ) {
		$epay_params = array();
		$epay_params["merchantnumber"] = self::$merchant_id;
		$epay_params["pwd"] = self::$api_password;
		$epay_params["language"] = apply_filters( 'epay_language', 1 );
		$epay_params["epayresponsecode"] = $epay_response_code;
		$epay_params["epayresponse"] = "-1";
		$client = new SoapClient( self::API_URL );
		$result = $client->getEpayError( $epay_params );

		if ( $result->getEpayErrorResult == "true" )
			return $result->epayresponsestring;
		else
			return 'An unknown error occured';

	}

	public static function getPbsError( $pbs_response_code ) {
		$epay_params = array();
		$epay_params["merchantnumber"] = self::$merchant_id;
		$epay_params["language"] = apply_filters( 'epay_language', 1 );
		$epay_params["pbsresponsecode"] = $pbs_response_code;
		$epay_params["pwd"] = self::$api_password;
		$epay_params["epayresponse"] = "-1";
		$client = new SoapClient( self::API_URL );
		$result = $client->getPbsError( $epay_params );

		if ( $result->getPbsErrorResult == "true" )
			return $result->pbsresponsestring;
		else
			return 'An unknown error occured';
	}


	///////////////
	// Utilities //
	///////////////

	/**
	 * get the currency code, which is filtered
	 *
	 */
	private function get_currency_code() {
		return apply_filters( 'gb_epay_currency_code', self::$currency_code );
	}

	public static function set_token( $token ) {
		global $blog_id;
		update_user_meta( get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY, $token );
	}

	public static function unset_token() {
		global $blog_id;
		delete_user_meta( get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY );
	}

	public static function get_token() {
		global $blog_id;
		return get_user_meta( get_current_user_id(), $blog_id.'_'.self::TOKEN_KEY, TRUE );
	}


	/////////////
	// Options //
	/////////////


	public function register_settings() {
		$page = Group_Buying_Payment_Processors::get_settings_page();
		$section = 'gb_epay_settings';
		add_settings_section( $section, self::__( 'ePay Payment Options' ), array( $this, 'display_settings_section' ), $page );
		register_setting( $page, self::MERCHANT_ID_OPTION );
		register_setting( $page, self::API_PASSWORD_OPTION );

		register_setting( $page, self::CURRENCY_CODE_OPTION );
		//register_setting( $page, self::ACCEPT_URL_OPTION );
		register_setting( $page, self::CANCEL_URL_OPTION );

		add_settings_field( self::MERCHANT_ID_OPTION, self::__( 'Merchant Number' ), array( $this, 'display_merchant_id_field' ), $page, $section );
		add_settings_field( self::API_PASSWORD_OPTION, self::__( 'API Password' ), array( $this, 'display_api_password_field' ), $page, $section );
		add_settings_field( self::CURRENCY_CODE_OPTION, self::__( 'Currency Code' ), array( $this, 'display_currency_code_field' ), $page, $section );
		add_settings_field( self::ACCEPT_URL_OPTION, self::__( 'Return URL' ), array( $this, 'display_return_field' ), $page, $section );
		add_settings_field( self::CANCEL_URL_OPTION, self::__( 'Cancel URL' ), array( $this, 'display_cancel_field' ), $page, $section );
	}

	public function display_merchant_id_field() {
		echo '<input type="text" name="'.self::MERCHANT_ID_OPTION.'" value="'.self::$merchant_id.'" size="80" />';
		echo '<p class="description">API credentials can be found by following <a href="https://www.epay.com/documentation/api">this documentation</a>.</p>';
	}

	public function display_api_password_field() {
		echo '<input type="password" name="'.self::API_PASSWORD_OPTION.'" value="'.self::$api_password.'" size="80" />';
	}

	public function display_return_field() {
		echo '<input type="text" name="'.self::ACCEPT_URL_OPTION.'" value="'.self::$accept_url.'" size="80" class="disabled" />';
	}

	public function display_cancel_field() {
		echo '<input type="text" name="'.self::CANCEL_URL_OPTION.'" value="'.self::$cancel_url.'" size="80" />';
	}

	public function display_currency_code_field() {
		echo '<input type="text" name="'.self::CURRENCY_CODE_OPTION.'" value="'.self::$currency_code.'" size="5" />';
		echo '<p class="description">http://tech.epay.dk/en/currency-codes</p>';
	}

	//////////////
	// Filters //
	//////////////


	public static function checkout_icon_todo() {
		return '<img src="'.GB_EPAY_URLRESOURCES.'/dk.gif" title="ePay" id="epay_button"/>';
	}

	public function payment_controls( $controls, Group_Buying_Checkouts $checkout ) {
		if ( isset( $controls['review'] ) ) {
			ob_start();
			?>
				<div id="epay_button"></div>
				<script charset="UTF-8" src="https://ssl.ditonlinebetalingssystem.dk/integration/ewindow/paymentwindow.js" type="text/javascript"></script>
				<script type="text/javascript">
					jQuery(document).ready(function($){
						var checkout_form = jQuery("#gb_checkout_payment");

						// bind to submittion
						checkout_form.bind('submit', function (e) {

							// vars
							var form = $(this);
							var form_url = checkout_form.attr( 'action' );

							// Prevent loop if already submitted
							if ( form.data('submitted') !== true ) {

								// Prevent synchronousness submission
								e.preventDefault();

								// Set to submitted to prevent loop
								form.data('submitted', true );

								// hide stuff
								jQuery("#checkout_epay_icon").hide();
								jQuery('.checkout_block').fadeOut();
								jQuery('#epay_button').append(gb_ajax_gif);
								// scroll
								jQuery('body,html').animate({
									scrollTop: $("#gb_checkout_payment").offset().top
								}, 800);

								// send AJAX request
								jQuery.post(
									form_url,
									$(this).serialize(),
									function( response ) {
										console.log(response);
										// If the return a checkout page, then an error occurred.
										if ( response.indexOf("html") >= 0 ) {
											form.submit(); // resubmit
											return false;
										}
										else {
											// Set to submitted to prevent loop
											$("#epay_button").html( response ).fadeIn(); // Add button
										}
									}
								);
								return false;
							}
						});
					});
				</script>
			<?php
			$js = ob_get_clean();
			$controls['review'] = str_replace( 'value="'.self::__( 'Review' ).'"', ' id="checkout_epay_icon" src="'.GB_EPAY_URLRESOURCES.'/epay-blue.jpg" value="'.self::__( 'Mercadopago' ).'"', $controls['review'] );
			$controls['review'] = str_replace( 'type="submit"', 'type="image"', $controls['review'] );
			$controls['review'] .= $js;
		}
		return $controls;
	}

	public function filter_where( $where = '' ) {
		// posts 90 days old
		$where .= " AND post_date >= '" . date( 'Y-m-d', current_time( 'timestamp' )-apply_filters( 'gb_paypal_ap_endingperiod_for_preapproval', 7776000 ) ) . "'";
		return $where;
	}

	//////////////////
	// Limitations //
	//////////////////

	public function display_exp_meta_box() {
		return GB_PATH . '/controllers/payment_processors/meta-boxes/exp-only.php';
	}

	public function display_price_meta_box() {
		return GB_PATH . '/controllers/payment_processors/meta-boxes/no-dyn-price.php';
	}

	public function display_limits_meta_box() {
		return GB_PATH . '/controllers/payment_processors/meta-boxes/no-tipping.php';
	}
}
ePay_Payments::register();
