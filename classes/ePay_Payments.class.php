<?php
/**
 * ePay Payments offsite payment processor.
 *
 * @package GBS
 * @subpackage Payment Processing_Processor
 */

class ePay_Payments extends Group_Buying_Offsite_Processors {
	// mode
	const IS_DEMO_MODE = 'gb_epay_demo_mode';
	// credentials
	const API_USERNAME_OPTION = 'gb_epay_api_username';
	const API_PASSWORD_OPTION = 'gb_epay_api_password';
	const ACCOUNT_ID = 'gb_epay_sid';
	const SECRET_WORD = 'gb_epay_secret_word';
	// token
	const TOKEN_KEY = 'gb_token_key'; // Combine with $blog_id to get the actual meta key
	// options
	const CANCEL_URL_OPTION = 'gb_epay_cancel_url';
	const RETURN_URL_OPTION = 'gb_epay_redirect_url';
	const CURRENCY_CODE_OPTION = 'gb_epay_ap_currency';
	// gbs
	const PAYMENT_METHOD = 'ePay';
	// vars
	protected static $instance;
	
	protected static $api_mode = '';
	private static $api_username;
	private static $api_password;
	private static $account_id;
	private static $secret_word;
	
	private static $cancel_url = '';
	private static $return_url = '';
	private static $currency_code = 'USD';

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
		self::$api_mode = get_option( self::IS_DEMO_MODE, '');
		self::$api_username = get_option( self::API_USERNAME_OPTION );
		self::$api_password = get_option( self::API_PASSWORD_OPTION );
		self::$account_id = get_option( self::ACCOUNT_ID );
		self::$secret_word = get_option( self::SECRET_WORD, 'tango' );
		
		self::$cancel_url = get_option( self::CANCEL_URL_OPTION, Group_Buying_Carts::get_url() );
		self::$return_url = get_option( self::RETURN_URL_OPTION, add_query_arg( array( 'gb_checkout_action' => 'back_from_epay' ), Group_Buying_Checkouts::get_url() ) );
		self::$currency_code = get_option( self::CURRENCY_CODE_OPTION, 'USD' );

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
	 * set up the Preapproval and redirect there
	 *
	 * @param Group_Buying_Carts $cart
	 * @return void
	 */
	public function send_offsite( Group_Buying_Checkouts $checkout ) {
		$cart = $checkout->get_cart();
		if ( $cart->get_total() < 0.01 ) { // for free deals.
			return;
		}

		// Don't send someone returning away again.
		if ( $_REQUEST['gb_checkout_action'] == Group_Buying_Checkouts::PAYMENT_PAGE ) {

			// Build redirect url
			$args = self::get_charge_args( $checkout );
			if ( empty( $args ) ) {
				self::set_message( 'Problem building ePay charge link.', self::MESSAGE_STATUS_ERROR );
				$redirect_url = Group_Buying_Carts::get_url();
			}

			self::set_token( $args['gbs_custom_token'] ); // Set the token so we can reference the purchase later
			
			$redirect_url = Twocheckout_Charge::link( $args );

			wp_redirect( $redirect_url, 303 );
			exit();
		}
	}

	/**
	 * Build the args from checkout
	 * 
	 * @param  Group_Buying_Checkouts $checkout
	 * @return
	 */
	public static function get_charge_args( Group_Buying_Checkouts $checkout ) {
		
		$filtered_total = self::get_payment_request_total( $checkout );
		if ( $filtered_total < 0.01 ) {
			return array();
		}

		$i = 0;
		$user = get_userdata( get_current_user_id() );

		// Build args array
		$args = array();
		$args['sid'] = self::$account_id;
		$args['cart_order_id'] = gb_get_name() . "'s " . self::__('Cart');
		$args['total'] = gb_get_number_format( $filtered_total );
		$args['currency_code'] = self::get_currency_code();
		$args['x_receipt_link_url'] = self::$return_url;
		$args['card_holder_name'] = $checkout->cache['billing']['first_name'] . ' ' . $checkout->cache['billing']['last_name'];
		$args['street_address'] = $checkout->cache['billing']['street'];
		$args['city'] = $checkout->cache['billing']['city'];
		$args['state'] = $checkout->cache['billing']['zone'];
		$args['zip'] = $checkout->cache['billing']['postal_code'];
		$args['country'] = $checkout->cache['billing']['country'];
		$args['email'] = $user->user_email;

		if ( self::$api_mode == 'demo' ) {
			$args['demo'] = 'Y';
		}

		// Custom args
		$args['gbs_custom_user_id'] = get_current_user_id();
		$args['gbs_custom_token'] = substr( md5( serialize( $args ) ), -60 );

		// Shipping info
		if ( isset( $checkout->cache['shipping'] ) ) {
			$args['ship_name'] = $checkout->cache['shipping']['first_name'] . ' ' . $checkout->cache['shipping']['last_name'];
			$args['ship_street_address'] = $checkout->cache['shipping']['street'];
			$args['ship_city'] = $checkout->cache['shipping']['city'];
			$args['ship_state'] = $checkout->cache['shipping']['zone'];
			$args['ship_zip'] = $checkout->cache['shipping']['postal_code'];
			$args['ship_country'] = $checkout->cache['shipping']['country'];
		}

		// Products
		$cart = $checkout->get_cart();
		foreach ( $cart->get_items() as $key => $item ) {
			$deal = Group_Buying_Deal::get_instance( $item['deal_id'] );
			$args['li_'.$i.'_product_id'] = $item['deal_id'];
			$args['li_'.$i.'_name'] = html_entity_decode( strip_tags( $deal->get_title( $item['data'] ) ), ENT_QUOTES, 'UTF-8' );
			$args['li_'.$i.'_quantity'] = $item['quantity'];
			$args['li_'.$i.'_price'] = gb_get_number_format( $deal->get_price( NULL, $item['data'] ) );
			$args['li_'.$i.'_tangible'] = 'Y';
			$i++;
		}
		// Tax
		if ( $cart->get_tax_total() ) {
			$args['li_'.$i.'_type'] = 'tax';
			$args['li_'.$i.'_name'] =  self::__('Tax');
			$args['li_'.$i.'_quantity'] = 1;
			$args['li_'.$i.'_price'] = gb_get_number_format( $cart->get_tax_total() );
			$args['li_'.$i.'_tangible'] = 'N';
			$i++;
		}
		// Shipping
		if ( $cart->get_shipping_total() ) {
			$args['li_'.$i.'_type'] = 'shipping';
			$args['li_'.$i.'_name'] =  self::__('Shipping');
			$args['li_'.$i.'_quantity'] = 1;
			$args['li_'.$i.'_price'] = gb_get_number_format( $cart->get_shipping_total() );
			$args['li_'.$i.'_tangible'] = 'Y';
			$i++;
		}

		do_action( 'gb_log', __CLASS__ . '::' . __FUNCTION__ . ' - ePay Args', $args );
		return apply_filters( 'gb_epay_get_charge_args', $args );

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
		$params = array();
		foreach ($_REQUEST as $k => $v) {
			$params[$k] = $v;
		}
		$passback = Twocheckout_Return::check( $params, self::$secret_word, 'array' );
		if ( $passback['response_code'] !== 'Success' )
			return FALSE;

		return TRUE;
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
		foreach ($_REQUEST as $k => $v) {
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
				'amount' => $response['max_total_amount_of_all_payments'],
				'data' => array(
					'twoco_order_id' => $_REQUEST['order_number'],
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
			if ( isset( $data['twoco_order_id'] ) && $data['twoco_order_id'] ) {

				// items we need to capture
				$items_to_capture = $this->items_to_capture( $payment );
				if ( $items_to_capture ) {

					// Retrieve Payment
					$response = self::get_payment( $data['twoco_order_id'] );

					if ( $response['response_code'] != 'OK' )
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


	private function get_payment( $epay_order_id ) {
		Twocheckout::setCredentials( self::$api_username, self::$api_password );
		$args = array(
			'sale_id' => $epay_order_id
		);
		try {
			$response = Twocheckout_Sale::retrieve( $args, 'array' );
			do_action( 'gb_log', __CLASS__ . '::' . __FUNCTION__ . ' - ePay payment detail', $response );
			return $response;
		} catch ( Exception $e ) {
			// If on the checkout page show the error. 
			if ( isset( $_REQUEST['gb_checkout_action'] ) && $_REQUEST['gb_checkout_action'] == 'valid_return_from_epay' ) {
				self::set_message( 'API ERROR: ' . $e->getMessage() , self::MESSAGE_STATUS_ERROR );
			}
			do_action( 'gb_error', __CLASS__ . '::' . __FUNCTION__ . ' - attempt fail', $e->getMessage() );
			return;
		}

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
		register_setting( $page, self::ACCOUNT_ID );
		register_setting( $page, self::IS_DEMO_MODE );
		register_setting( $page, self::API_USERNAME_OPTION );
		register_setting( $page, self::API_PASSWORD_OPTION );
		register_setting( $page, self::SECRET_WORD );

		register_setting( $page, self::CURRENCY_CODE_OPTION );
		register_setting( $page, self::RETURN_URL_OPTION );
		register_setting( $page, self::CANCEL_URL_OPTION );

		add_settings_field( self::ACCOUNT_ID, self::__( 'Account ID' ), array( $this, 'display_account_field' ), $page, $section );
		add_settings_field( self::SECRET_WORD, self::__( 'Account Secret Word' ), array( $this, 'display_app_id_field' ), $page, $section );
		add_settings_field( self::API_USERNAME_OPTION, self::__( 'API Username' ), array( $this, 'display_api_username_field' ), $page, $section );
		add_settings_field( self::API_PASSWORD_OPTION, self::__( 'API Password' ), array( $this, 'display_api_password_field' ), $page, $section );
		add_settings_field( self::CURRENCY_CODE_OPTION, self::__( 'Currency Code' ), array( $this, 'display_currency_code_field' ), $page, $section );
		add_settings_field( self::RETURN_URL_OPTION, self::__( 'Return URL' ), array( $this, 'display_return_field' ), $page, $section );
		add_settings_field( self::IS_DEMO_MODE, self::__( 'Demo Mode' ), array( $this, 'display_api_mode_field' ), $page, $section );
		//add_settings_field( self::CANCEL_URL_OPTION, self::__( 'Cancel URL' ), array( $this, 'display_cancel_field' ), $page, $section );
	}

	public function display_api_username_field() {
		echo '<input type="text" name="'.self::API_USERNAME_OPTION.'" value="'.self::$api_username.'" size="80" />';
		echo '<p class="description">API credentials can be found by following <a href="https://www.epay.com/documentation/api">this documentation</a>.</p>';
	}

	public function display_api_password_field() {
		echo '<input type="password" name="'.self::API_PASSWORD_OPTION.'" value="'.self::$api_password.'" size="80" />';
	}

	public function display_account_field() {
		echo '<input type="text" name="'.self::ACCOUNT_ID.'" value="'.self::$account_id.'" size="80" />';
	}

	public function display_app_id_field() {
		echo '<input type="text" name="'.self::SECRET_WORD.'" value="'.self::$secret_word.'" size="80" />';
	}

	public function display_return_field() {
		echo '<input type="text" name="'.self::RETURN_URL_OPTION.'" value="'.self::$return_url.'" size="80" />';
		echo '<p class="description"><strong>Important:</strong> Set the Direct Return option to "Header Redirect", option available on the Site Management page by clicking the Account tab followed by the Site Management sub-category</p>';
	}

	public function display_cancel_field() {
		echo '<input type="text" name="'.self::CANCEL_URL_OPTION.'" value="'.self::$cancel_url.'" size="80" />';
	}

	public function display_api_mode_field() {
		echo '<label><input type="checkbox" name="'.self::IS_DEMO_MODE.'" value="demo" '.checked( 'demo', self::$api_mode, FALSE ).'/> '.self::__( 'Activate Demo Mode' ).'</label><br />';
	}

	public function display_currency_code_field() {
		echo '<input type="text" name="'.self::CURRENCY_CODE_OPTION.'" value="'.self::$currency_code.'" size="5" />';
		echo '<p class="description">ARS, AUD, BRL, GBP, CAD, DKK, EUR, HKD, INR, ILS, JPY, LTL, MYR, MXN, NZD, NOK, PHP, RON, RUB, SGD, ZAR, SEK, CHF, TRY, AED, USD.</p>';
	}

	//////////////
	// Filters //
	//////////////

	public static function checkout_icon() {
		return '<img src="https://www.epay.com/upload/images/paymentlogoshorizontal.png" title="ePay" id="epay_button"/>';
	}

	public function payment_controls( $controls, Group_Buying_Checkouts $checkout ) {
		if ( isset( $controls['review'] ) ) {
			$style = 'style="background-image: url(https://www.epay.com/upload/images/paymentlogoshorizontal.png); background-position: right center; padding: 13px 360px 13px 20px; background-repeat: no-repeat;"';
			$controls['review'] = str_replace( 'value="'.self::__( 'Review' ).'"', $style . 'value="'.self::__( 'Purchase' ).'"', $controls['review'] );
		}
		return $controls;
	}

	public function filter_where( $where = '' ) {
		// posts 90 days old
		$where .= " AND post_date >= '" . date('Y-m-d', current_time('timestamp')-apply_filters( 'gb_paypal_ap_endingperiod_for_preapproval', 7776000 ) ) . "'";
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