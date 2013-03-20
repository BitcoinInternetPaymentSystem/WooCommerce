<?php
/*
Plugin Name: WooCommerce BIPS
Plugin URI: https://BIPS.me/shopmodules
Description: Extends WooCommerce with an bitcoin gateway.
Version: 1.0
Author: Kris
Author URI: https://BIPS.me
 
Copyright: © 2013 BIPS.
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/
add_action('plugins_loaded', 'woocommerce_BIPS_init', 0);

function woocommerce_BIPS_init()
{
	if (!class_exists('WC_Payment_Gateway'))
	{
		return;
	}

	/**
	* Add the Gateway to WooCommerce
	**/
	function woocommerce_add_BIPS_gateway($methods)
	{
		$methods[] = 'WC_BIPS';
		return $methods;
	}

	add_filter('woocommerce_payment_gateways', 'woocommerce_add_BIPS_gateway');

	class WC_BIPS extends WC_Payment_Gateway
	{
		public function __construct()
		{
			$this->id = 'BIPS';
			$this->icon = plugins_url( 'images/bitcoin.png', __FILE__ );
			$this->medthod_title = 'BIPS';
			$this->has_fields = false;

			$this->init_form_fields();
			$this->init_settings();

			$this->title = $this->settings['title'];
			$this->description = $this->settings['description'];
			$this->apikey = $this->settings['apikey'];
			$this->secret = $this->settings['secret'];
			$this->debug = $this->settings['debug'];

			$this->msg['message'] = "";
			$this->msg['class'] = "";

			add_action('init', array(&$this, 'check_BIPS_response'));

			add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));  // WC < 2.0
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));  // WC >= 2.0
			add_action('woocommerce_receipt_BIPS', array(&$this, 'receipt_page'));

			add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'check_BIPS_response' ) );

            // Valid for use.
            $this->enabled = ( 'yes' == $this->settings['enabled'] ) && !empty( $this->apikey ) && !empty( $this->secret ) && $this->is_valid_for_use();

            // Checking if apikey is not empty.
            $this->apikey == '' ? add_action( 'admin_notices', array( &$this, 'apikey_missing_message' ) ) : '';

            // Checking if app_secret is not empty.
            $this->secret == '' ? add_action( 'admin_notices', array( &$this, 'secret_missing_message' ) ) : '';
		}

		public function is_valid_for_use()
		{
			// bitcoin can be used in any country in any currency
			//if ( !in_array( get_woocommerce_currency() , array( 'BTC', 'USD' ) ) ) {
			//    return false;
			//}

			return true;
		}

		function init_form_fields()
		{
			$this->form_fields = array(
				'enabled' => array(
					'title' => __( 'Enable/Disable', 'BIPS' ),
					'type' => 'checkbox',
					'label' => __( 'Enable BIPS', 'BIPS' ),
					'default' => 'yes'
				),
				'title' => array(
					'title' => __( 'Title', 'BIPS' ),
					'type' => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'BIPS' ),
					'default' => __( 'BIPS', 'BIPS' )
				),
				'description' => array(
					'title' => __( 'Description', 'BIPS' ),
					'type' => 'textarea',
					'description' => __( 'This controls the description which the user sees during checkout.', 'BIPS' ),
					'default' => __( 'Pay with Bitcoin', 'BIPS' )
				),
				'apikey' => array(
					'title' => __( 'BIPS API Key', 'BIPS' ),
					'type' => 'password',
					'description' => __( 'Please enter your BIPS Merchant API key', 'BIPS' ) . ' ' . sprintf( __( 'You can get this information in: %sBIPS Account%s.', 'BIPS' ), '<a href="https://BIPS.me/merchant" target="_blank">', '</a>' ),
					'default' => ''
				),
				'secret' => array(
					'title' => __( 'BIPS Secret', 'BIPS' ),
					'type' => 'password',
					'description' => __( 'Please enter your BIPS IPN secret', 'BIPS' ) . ' ' . sprintf( __( 'You can get this information in: %sBIPS Account%s.', 'BIPS' ), '<a href="https://BIPS.me/merchant" target="_blank">', '</a>' ),
					'default' => ''
				),
				'debug' => array(
					'title' => __( 'Debug Log', 'BIPS' ),
					'type' => 'checkbox',
					'label' => __( 'Enable logging', 'BIPS' ),
					'default' => 'no',
					'description' => __( 'Log BIPS events, such as API requests, inside <code>woocommerce/logs/BIPS.txt</code>', 'BIPS'  ),
				)
			);
		}

		public function admin_options()
		{
			?>
			<h3><?php _e('BIPS Checkout', 'BIPS');?></h3>

			<?php if ( empty( $this->email ) ) : ?>
				<div id="wc_get_started">
					<span class="main"><?php _e('Get started with BIPS Checkout', 'BIPS'); ?></span>
					<span><a href="https://BIPS.me/shopmodules">BIPS Checkout</a> <?php _e('provides a secure way to collect and transmit bitcoin to your payment gateway.', 'BIPS'); ?></span>

					<p><a href="https://BIPS.me/signup" target="_blank" class="button button-primary"><?php _e('Join for free', 'BIPS'); ?></a> <a href="https://BIPS.me/shopmodules" target="_blank" class="button"><?php _e('Learn more about WooCommerce and BIPS', 'BIPS'); ?></a></p>

				</div>
			<?php else : ?>
				<p><a href="https://BIPS.me/shopmodules">BIPS Checkout</a> <?php _e('provides a secure way to collect and transmit bitcoin to your payment gateway.', 'BIPS'); ?></p>
			<?php endif; ?>

			<table class="form-table">
				<?php $this->generate_settings_html(); ?>
			</table>
			<?php
		}

		/**
		*  There are no payment fields for BIPS, but we want to show the description if set.
		**/
		function payment_fields()
		{
			if ($this->description)
				echo wpautop(wptexturize($this->description));
		}

		/**
		* Receipt Page
		**/
		function receipt_page($order)
		{
			$order = new WC_Order($order);

			$item_names = array();

			if (sizeof($order->get_items()) > 0) : foreach ($order->get_items() as $item) :
				if ($item['qty']) $item_names[] = $item['name'] . ' x ' . $item['qty'];
			endforeach; endif;

			$item_name = sprintf( __('Order %s' , 'woocommerce'), $order->get_order_number() ) . " - " . implode(', ', $item_names);


			$ch = curl_init();
			curl_setopt_array($ch, array(
			CURLOPT_URL => 'https://BIPS.me/api/v1/invoice',
			CURLOPT_USERPWD => $this->apikey,
			CURLOPT_POSTFIELDS => 'price=' . number_format($order->order_total, 2, '.', '') . '&currency=' . get_woocommerce_currency() . '&item=' . $item_name . '&custom=' . json_encode(array('email' => $order->billing_email, 'order_id' => $order->id, 'order_key' => $order->order_key, 'returnurl' => rawurlencode(esc_url($this->get_return_url($order))), 'cancelurl' => rawurlencode(esc_url($order->get_cancel_order_url())))),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPAUTH => CURLAUTH_BASIC));
			header('Location: ' . curl_exec($ch));
			curl_close($ch);
		}

		/**
		* Process the payment and return the result
		**/
		function process_payment($order_id)
		{
			$order = new WC_Order($order_id);
			return array(
				'result' => 'success',
				'redirect' => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(get_option('woocommerce_pay_page_id'))))
			);
		}

		/**
		* Check for valid BIPS server callback
		**/
		function check_BIPS_response()
		{
			$BIPS = $_POST;
			$hash = hash('sha512', $BIPS['transaction']['hash'] . $this->secret);

			if ($BIPS['hash'] == $hash && $BIPS['status'] == 1) {
				// Do your magic here, and return 200 OK to BIPS.

				$order_id = intval($BIPS['custom']['order_id']);
				$order_key = $BIPS['custom']['order_key'];

				$order = new WC_Order($order_id);

				if ($order->order_key !== $order_key)
				{
					if ($this->debug=='yes') $this->log->add( 'BIPS', 'Error: Order Key does not match invoice.' );
				}
				else
				{
					if ($order->status == 'completed')
					{
						if ($this->debug=='yes') $this->log->add( 'BIPS', 'Aborting, Order #' . $order_id . ' is already complete.' );
					}
					else
					{
						// Validate Amount
						if ($BIPS['fiat']['amount'] >= $order->get_total())
						{
							// Payment completed
							$order->add_order_note( __('IPN: Payment completed notification from BIPS', 'woocommerce') );
							$order->payment_complete();

							if ($this->debug=='yes') $this->log->add( 'BIPS', 'Payment complete.' );
						}
						else
						{
							if ($this->debug == 'yes')
							{
								$this->log->add( 'BIPS', 'Payment error: Amounts do not match (gross ' . $BIPS['fiat']['amount'] . ')' );
							}

							// Put this order on-hold for manual checking
							$order->update_status( 'on-hold', sprintf( __( 'IPN: Validation error, amounts do not match (gross %s).', 'woocommerce' ), $BIPS['fiat']['amount'] ) );
						}
					}
				}

				header('HTTP/1.1 200 OK');
				exit;
			}
		}

        /**
         * Adds error message when not configured the api key.
         *
         * @return string Error Mensage.
         */
        public function apikey_missing_message() {
            $message = '<div class="error">';
                $message .= '<p>' . sprintf( __( '<strong>Gateway Disabled</strong> You should enter your API key in BIPS configuration. %sClick here to configure!%s' , 'wcBIPS' ), '<a href="' . get_admin_url() . 'admin.php?page=woocommerce_settings&amp;tab=payment_gateways">', '</a>' ) . '</p>';
            $message .= '</div>';

            echo $message;
        }

        /**
         * Adds error message when not configured the secret.
         *
         * @return String Error Mensage.
         */
        public function secret_missing_message() {
            $message = '<div class="error">';
                $message .= '<p>' . sprintf( __( '<strong>Gateway Disabled</strong> You should enter your IPN secret in BIPS configuration. %sClick here to configure!%s' , 'wcBIPS' ), '<a href="' . get_admin_url() . 'admin.php?page=woocommerce_settings&amp;tab=payment_gateways">', '</a>' ) . '</p>';
            $message .= '</div>';

            echo $message;
        }
	}
}
