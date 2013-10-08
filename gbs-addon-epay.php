<?php
/*
Plugin Name: Group Buying Payment Processor - ePay
Version: 1
Plugin URI: http://groupbuyingsite.com/marketplace/
Description: Add epay payment processing.
Author: Dan Cameron
Author URI: http://sproutventure.com/
Contributors: Dan Cameron
Text Domain: group-buying
Domain Path: /lang
*/

add_action( 'gb_register_processors', 'gb_load_epay' );

function gb_load_epay() {
	require_once 'lib/class.epaysoap.php';
	require_once 'classes/ePay_Payments.class.php';
}
