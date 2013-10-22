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
		self::add_payment_processor( __CLASS__, self::__( 'ePay Onsite Payments' ) );
	}

	public static function accepted_cards() {
		$accepted_cards = array(
				'visa',
				'mastercard',
				'amex',
				// 'diners',
				'discover',
				'jcb',
				'maestro'
			);
		return apply_filters( 'gb_accepted_credit_cards', $accepted_cards );
	}

	/**
	 * Set variables, add meta boxes to the deal page, process payments and setting payments.
	 */
	protected function __construct() {
		parent::__construct();
		// variables
		self::$merchant_id = get_option( self::MERCHANT_ID_OPTION );
		self::$api_password = get_option( self::API_PASSWORD_OPTION );
		self::$currency_code = get_option( self::CURRENCY_CODE_OPTION, 'DKK' );

		// payment options
		add_action( 'admin_init', array( $this, 'register_settings' ), 10, 0 );
		add_action( 'purchase_completed', array( $this, 'complete_purchase' ), 10, 1 );

		// Limitations
		add_filter( 'group_buying_template_meta_boxes/deal-expiration.php', array( $this, 'display_exp_meta_box' ), 10 );
		add_filter( 'group_buying_template_meta_boxes/deal-price.php', array( $this, 'display_price_meta_box' ), 10 );
		add_filter( 'group_buying_template_meta_boxes/deal-limits.php', array( $this, 'display_limits_meta_box' ), 10 );
	}

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

		$capture_payment = $this->aim_data( $checkout, $purchase );

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

	///////////////////////////////////
	// Post Payment/Purchase Methods //
	///////////////////////////////////

	/**
	 * Complete the purchase after the process_payment action, otherwise vouchers will not be activated.
	 *
	 * @param Group_Buying_Purchase $purchase
	 * @return void
	 */
	public function complete_purchase( Group_Buying_Purchase $purchase ) {
		$items_captured = array(); // Creating simple array of items that are captured
		foreach ( $purchase->get_products() as $item ) {
			$items_captured[] = $item['deal_id'];
		}
		$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance( $payment_id );
			do_action( 'payment_captured', $payment, $items_captured );
			do_action( 'payment_complete', $payment );
			$payment->set_status( Group_Buying_Payment::STATUS_COMPLETE );
		}
	}


	///////////////
	// Utilities //
	///////////////

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

	/**
	 * get the currency code, which is filtered
	 *
	 */
	private function get_currency_code() {
		return apply_filters( 'gb_epay_currency_code', self::$currency_code );
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
	}

	public function display_merchant_id_field() {
		echo '<input type="text" name="'.self::MERCHANT_ID_OPTION.'" value="'.self::$merchant_id.'" size="80" />';
		echo '<p class="description">API credentials can be found by following <a href="https://www.epay.com/documentation/api">this documentation</a>.</p>';
	}

	public function display_api_password_field() {
		echo '<input type="password" name="'.self::API_PASSWORD_OPTION.'" value="'.self::$api_password.'" size="80" />';
	}

	public function display_currency_code_field() {
		echo '<input type="text" name="'.self::CURRENCY_CODE_OPTION.'" value="'.self::$currency_code.'" size="5" />';
		echo '<p class="description">http://tech.epay.dk/en/currency-codes</p>';
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
