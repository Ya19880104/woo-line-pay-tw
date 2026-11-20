<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define Form Fields to be used in LINEPay Admin.
 *
 * @class WC_Gateway_LINEPay_Admin
 * @version 1.0.0
 * @author LINEPay
 */
class WC_Gateway_LINEPay_Settings {

	/**
	 * @var	WC_Gateway_LINEPay
	 */
	private $linepay_gateway;

	/**
	 * Setup class.
	 *
	 * @param WC_Gateway_LINEPay $linepay_gateway LINE Pay gateway instance.
	 * @since 1.0.0
	 */
	public function __construct( $linepay_gateway ) {
		$this->linepay_gateway = $linepay_gateway;
	}

	/**
	 * Returns Form Fields information as an array.
	 *
	 * @return array
	 */
	public function get_form_fields() {

		// Administrator's refundable status.
		$admin_statuses	= $this->linepay_gateway->wc_get_order_statuses( WC_Gateway_LINEPay_Const::USER_STATUS_ADMIN );

		// Consumer's refundable status.
		$customer_statuses	= $this->linepay_gateway->wc_get_order_statuses( WC_Gateway_LINEPay_Const::USER_STATUS_CUSTOMER );

		return array(
			'enabled' 			=> array(
				'title'         => __( 'Enable/Disable', 'woocommerce-gateway-linepay' ),
				'type'			=> 'checkbox',
				'label'			=> __( 'Starting up LINE Pay plugin...', 'woocommerce-gateway-linepay' ),
				'default'		=> 'no'
			),
			'title'             => array(
				'title'         => __('Title', 'woocommerce-gateway-linepay'),
				'type'          => 'text',
				'description'   => __('This controls the title which the user sees during checkout.', 'woocommerce-gateway-linepay'),
				'default'       => __('Line Pay', 'woocommerce-gateway-linepay'),
				'desc_tip'      => true,
			),
			'description' 		=> array(
				'title'       	=> __('Description', 'woocommerce-gateway-linepay'),
				'type'        	=> 'textarea',
				'description' 	=> __('This controls the description which the user sees during checkout.', 'woocommerce-gateway-linepay'),
				'desc_tip'    	=> true,
			),
			'log_enabled' 		=> array(
				'title'       	=> __( 'Log Enable', 'woocommerce-gateway-linepay' ),
				'type'        	=> 'checkbox',
				'label'       	=> __( 'Starting up LINE Pay plugin log...', 'woocommerce-gateway-linepay' ),
				'default'     	=> 'no',
				'description' 	=> __( 'Log entries are available under WooCommerce > Status > Logs.', 'woocommerce-gateway-linepay' ),
			),
			'log_level'			=> array(
				'title'       	=> __( 'Log Level', 'woocommerce-gateway-linepay' ),
				'type'          => 'select',
				'class'       	=> 'wc-enhanced-select',
				'description' 	=> __( 'Select the level of information to log. You can select Debug, Info, or Error. However, please note that website performance may decrease if you log too much information. We recommend you log only the important items at the Error level.', 'woocommerce-gateway-linepay' ),
				'desc_tip'    	=> true,
				'default'     	=> 'error',
				'options'     	=> array(
						'error'	=> __( 'Error', 'woocommerce-gateway-linepay' ),
						'info'	=> __( 'Info', 'woocommerce-gateway-linepay' ),
						'Debug'	=> __( 'debug', 'woocommerce-gateway-linepay' ),
				),
			),
			'sandbox'            => array(
				'title'			 => __( 'Sandbox Mode', 'woocommerce-gateway-linepay' ),
				'type'			 => 'checkbox',
				'label'			 => __( 'Enable sandbox mode.', 'woocommerce-gateway-linepay' ),
				'description'	 => '',
				'default'		 => 'no'
			),
			'sandbox_channel_id' => array(
				'title'			 => __( 'Sandbox Channel ID', 'woocommerce-gateway-linepay' ),
				'type'			 => 'text',
				'description'	 => __( 'Enter your Channel ID.', 'woocommerce-gateway-linepay' ),
				'desc_tip'		 => true,
				'default'		 => '',
			),
			'sandbox_channel_secret' => array(
				'title'			=> __( 'Sandbox Channel Secret Key', 'woocommerce-gateway-linepay' ),
				'type'			=> 'text',
				'description'	=> __( 'Enter your Channel SecretKey.', 'woocommerce-gateway-linepay' ),
				'desc_tip'		=> true,
				'default'		=> '',
			),
			'channel_id' => array(
				'title'			=> __( 'Channel ID', 'woocommerce-gateway-linepay' ),
				'type'			=> 'text',
				'description'	=> __( 'Enter your Channel ID.', 'woocommerce-gateway-linepay' ),
				'desc_tip'		=> true,
				'default'		=> '',
			),
			'channel_secret' => array(
				'title'			=> __( 'Channel Secret Key', 'woocommerce-gateway-linepay' ),
				'type'			=> 'text',
				'description'	=> __( 'Enter your Channel SecretKey.', 'woocommerce-gateway-linepay' ),
				'desc_tip'		=> true,
				'default'		=> '',
			),
			'branch_name' => array(
				'title'       => __( 'Branch name', 'woocommerce-gateway-linepay' ),
				'type'        => 'text',
				'description' => __( 'Optional branch name sent with LINE Pay payment requests.', 'woocommerce-gateway-linepay' ),
				'desc_tip'    => true,
				'default'     => '',
			),
			'payment_type' => array(
				'title'       => __( 'Payment Type', 'woocommerce-gateway-linepay' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'You can only select regular payment.', 'woocommerce-gateway-linepay' ),
				'desc_tip'    => true,
				'default'     => 'normal',
				'options'     => array(
					'normal'  => __( 'Normal', 'woocommerce-gateway-linepay' ),
				),
			),
			'payment_action' => array(
				'title'       => __( 'Payment Action', 'woocommerce-gateway-linepay' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'You can only select auto-acquisition.', 'woocommerce-gateway-linepay' ),
				'desc_tip'    => true,
				'default'     => 'authorization/capture',
				'options'     => array(
					'authorization/capture' => __( 'Authorization/Capture', 'woocommerce-gateway-linepay' ),
				),
			),
			'admin_refund' => array (
				'title'			=> __( 'Statuses that Allow Managers to Refund Orders', 'woocommerce-gateway-linepay' ),
				'type'			=> 'multiselect',
				'class'			=> 'chosen_select',
				'description'	=> __( 'Please select the statuses in which managers can refund orders.', 'woocommerce-gateway-linepay' ),
				'desc_tip'		=> true,
				'options'		=> $admin_statuses,
			),
			'customer_refund' => array (
				'title'			=> __( 'Statuses that Allow Customers to Request Refunds', 'woocommerce-gateway-linepay' ),
				'type'			=> 'multiselect',
				'class'			=> 'chosen_select',
				'description'	=> __( 'Please select the statuses that allow customers to request refunds. Some statuses do not allow refunds.', 'woocommerce-gateway-linepay' ),
				'desc_tip'		=> true,
				'options'		=> $customer_statuses,
			),
			'general_logo_size' => array(
				'title'			=> __( 'General Logo Size', 'woocommerce-gateway-linepay' ),
				'type'			=> 'select',
				'description'	=> __( 'Please select the size of your main LINE Pay logo.', 'woocommerce-gateway-linepay' ),
				'desc_tip'		=> true,
				'default'		=> '5',
				'options'		=> array(
					'1' => '238x78',
					'2' => '119x39',
					'3' => '98x32',
					'4' => '85x28',
					'5' => '74x24',
					'6' => '61x20',
				),
			),
			'custom_logo' 		=> array(
				'title'			=> __( 'Custom Logo', 'woocommerce-gateway-linepay' ),
				'id'			=> 'wc_linepay_custom_logo',
				'type'			=> 'text',
				'desc_tip'		=> true,
				'description'	=> __( 'You can also customize your LINE Pay logo by uploading an image or entering an image URL.', 'woocommerce-gateway-linepay' )
			),
		);

	}

}
