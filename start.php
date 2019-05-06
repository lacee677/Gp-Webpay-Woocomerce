<?php

/*
 * Plugin Name: GP Webpay
 * Plugin URI: 
 * Description: e-commerce plugin for CSOB Payment Gateway implemented as extension of WooCommerce e-shop
 * Version: 1.0
 * Author: Ladislav Misurák
 * Author URI: https://misurak.eu/
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

 
add_action('plugins_loaded', 'woocommerce_gp_pay_init', 0);

register_activation_hook(__FILE__, 'install_transaction_db_table');

function install_transaction_db_table() {
    global $wpdb;
    $sql = "DROP TABLE IF EXISTS gpTransaction";
    $wpdb->query($sql);

    $sql = "
	        CREATE TABLE IF NOT EXISTS gpTransaction (
                payId int NOT NULL AUTO_INCREMENT,
	        	orderNumber varchar(10) NOT NULL,
	          	paymentStatus varchar(30) NOT NULL,
	          	created datetime NOT NULL,
	          	updated datetime DEFAULT NULL,
			 	cart varchar (1000) DEFAULT NULL,
	          	PRIMARY KEY (payId)
	        );
	    ";

    $wpdb->query($sql);
}

function woocommerce_gp_pay_init() {
    require_once ("GpWebPay.php");

    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }


    $log = new Logger();

    class GP_Payment_Gateway extends WC_Payment_Gateway {

        public function __construct() {

            $this->init_settings();
            $this->id = 'GP_Payment_Gateway';
            $this->method_title = 'ČSOB Platobná brána';
            $this->icon = plugins_url('gp-webpay') . "/assets/img/logo.gif";
            $this->urlGate = isset($this->settings ['urlGate']) ? $this->settings['urlGate'] : '';
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->urlGate = $this->get_option('urlGate');
            $this->merchantId = $this->get_option('merchantId');
            $this->publicKey = $this->get_option('publicKey');
            $this->privateKey = $this->get_option('privateKey');
            $this->privateKeyPassword = $this->get_option('privateKeyPassword');
            $this->currency = get_woocommerce_currency();
            $this->msg['message'] = "";
            $this->msg['class'] = "";

            $GpWebPay = new GPWebPay($this->prepareDatabaseConfig());

            $this->GpWebPay = $GpWebPay;

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
                &$this,
                'process_admin_options'
            ));
            $this->init_form_fields();

            add_action('woocommerce_receipt_' . $this->id, array(
                &$this,
                'receipt_page'
            ));
        }

        public function prepareDatabaseConfig() {
            global $wpdb;
            $databaseConfig = new DatabaseConfig ();
            $databaseConfig->host = $wpdb->dbhost;
            $databaseConfig->name = $wpdb->dbname;
            $databaseConfig->user = $wpdb->dbuser;
            $databaseConfig->password = $wpdb->dbpassword;

            return $databaseConfig;
        }

        /**
         * Admin Panel Options
         */
        public function admin_options() {
            echo '<h3>' . 'GPWebpay Payment Gateway' . '</h3>';
            echo '<table class="form-table">';
            $this->generate_settings_html();
            echo '</table>';
        }

        /**
         * There are no payment fields for EBS, but we want to show the description if set.
         */
        function payment_fields() {
            if ($this->description)
                echo wpautop(wptexturize($this->description));
        }

        /**
         * Receipt Page
         */
        public function receipt_page($order) {
            echo $this->save_order_process($order);
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'merchantId' => array(
                    'title' => 'ID obchodníka',
                    'type' => 'text',
                    'required' => true
                ),
                'urlGate' => array(
                    'title' => 'Adresa brány',
                    'type' => 'text',
                    'required' => true
                ),
                'publicKey' => array(
                    'title' => 'Veřejný klíč',
                    'type' => 'text',
                    'required' => true
                ),
                'privateKey' => array(
                    'title' => 'Soubor privátního klíče',
                    'type' => 'text',
                    'required' => true
                ),
                'privateKeyPassword' => array(
                    'title' => 'Heslo privátního klíče',
                    'type' => 'text'
                ),
                'enabled' => array(
                    'title' => __('Povolit/Zakázat', 'gp'),
                    'type' => 'checkbox',
                    'label' => 'Zapnuto',
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => 'Titulek',
                    'type' => 'text',
                    'desc_tip' => true
                ),
                'description' => array(
                    'title' => 'Popis',
                    'type' => 'textarea',
                    'desc_tip' => true
                ),
            );
        }

        public function save_order_process($order_id) {
            $order = new WC_Order($order_id);
            $this->GpWebPay->log->write('orderNumber ' . $order->get_order_number());

            $partsOforderNumber = $this->exploderOrderNumber($order->get_order_number());
            $orderNo = $order->get_order_number();
            $location = $this->get_return_url($order);
            $customer = WC()->customer;
            $data;
            $returnUrl = plugins_url('returnUrl.php?orderNumber=' . $order_id, __FILE__);

            $this->GpWebPay->log->write('Checking order ' . $orderNo . ", orderId " . $order_id);
            $row = $this->GpWebPay->selectTransaction($partsOforderNumber[0]);
            $this->GpWebPay->log->write('after select');

            foreach (WC()->cart->get_cart() as $item => $values) {
                $product = $values['data'];
                $cartDesc = ' ' . $product->get_title();
            }
            $cartDesc = mb_substr(trim($cartDesc), 0, 37, 'utf-8') . "...";

            $paymentId = $row['payId'];
            $paymentStatus = $row['paymentStatus'];
            $this->GpWebPay->log->write('loaded paymentStatus: ' . $paymentStatus . ' PayId: ' . $paymentId);

            $urlGate = $this->urlGate;
            $this->GpWebPay->log->write('payment/init, url: ' . $urlGate);

            if (is_null($paymentId) || is_null($row['cart']) ) {

                $this->GpWebPay->log->write('payment not inicialized OR payment cancelled or declined OR detected cart changes');
            
                /* SAVE TO DATABASE */

                $payId = $this->GpWebPay->insertTransaction($partsOforderNumber[0], $data);
                $this->GpWebPay->log->write('paymentId: ' . $payId);


                $data = createPaymentInitData($this->merchantId, $payId, $partsOforderNumber[0], $order->get_total(), $returnUrl, $cartDesc, "Objednavka " . $order->get_order_number(), $order->get_user_id(), $this->privateKey, $this->privateKeyPassword, $this->publicKey);
                $this->GpWebPay->log->write('payment/init data: ' . json_encode($data));

                //$this->GpWebPay->updateTransaction($partsOforderNumber[0], $data);
                //$this->GpWebPay->log->write('Updated payId: ' . $payId);
                
                $this->GpWebPay->log->write('Trying payment');
                
            }
            else{
                $this->GpWebPay->updateTransactionStatus($paymentId, "moved");

                $payId = $this->GpWebPay->insertTransaction($partsOforderNumber[0], $data);
                $this->GpWebPay->log->write('paymentId: ' . $payId);

                $data = createPaymentInitData($this->merchantId, $payId,  $partsOforderNumber[0], $order->get_total(), $returnUrl, $cartDesc, "Objednavka " . $order->get_order_number(), $order->get_user_id(), $this->privateKey, $this->privateKeyPassword, $this->publicKey);
                $this->GpWebPay->log->write('payment/init data: ' . json_encode($data));

                //$this->GpWebPay->updateTransaction($partsOforderNumber[0], $data);
                //$this->GpWebPay->log->write('Updated payId: ' . $payId);

                $this->GpWebPay->log->write('Trying payment again');
               
            }

            /* REDIRECT TO GATEWAY */

            $params = "";
            $params = http_build_query($data);

            header('Location: ' . $urlGate.'?'.$params);
        }

        public function process_payment($order_id) {
            $order = new WC_Order($order_id);
            if (version_compare(WOOCOMMERCE_VERSION, '2.1.0', '<=')) {
                $return = array(
                    'result' => 'success',
                    'redirect' => get_permalink(get_option('woocommerce_pay_page_id'))
                );
            } else {
                $return = array(
                    'result' => 'success',
                    'redirect' => $order->get_checkout_payment_url(true)
                );
            }
            return $return;
        }

        public function showMessage() {
            return '<div class="box ' . $this->msg ['class'] . '-box">' . $this->msg ['message'] . '</div>' . $content;
        }

        public function prepareResponse($result_array = null) {
            $response = new Response();

            $this->GpWebPay->log->write('Response_full: ' . json_encode($_GET));
            
            $response->orderNumber = $_GET ['orderNumber'];
            $response->OPERATION = $_GET ['OPERATION'];
            $response->ORDERNUMBER = $_GET ['ORDERNUMBER'];
            $response->MERORDERNUM = $_GET ['MERORDERNUM'];
            $response->PRCODE = $_GET ['PRCODE'];
            $response->SRCODE = $_GET ['SRCODE'];
            $response->RESULTTEXT = $_GET ['RESULTTEXT'];
            $response->DIGEST = $_GET ['DIGEST'];

            $this->GpWebPay->log->write('Response: ' . json_encode($response));

            return $response;
        }

        public function exploderOrderNumber($orderNumber) {
            return explode('.', $orderNumber);
        }

        public function processOrder($order_id) {
            $order = new WC_Order($order_id);
            $partsOforderNumber = $this->exploderOrderNumber($order->get_order_number());
            $response = $this->prepareResponse();

            $this->GpWebPay->log->write("Processing response, orderNumber: " . $response->orderNumber . " OPERATION: " . $response->OPERATION . " ORDERNUMBER: " . $response->ORDERNUMBER . " PRCODE: " . $response->PRCODE . " SRCODE: " . $response->SRCODE . " RESULTTEXT: " . $response->RESULTTEXT. " DIGEST: " . $response->DIGEST);

            $sign = new CSignature(dirname(__FILE__) . $this->privateKey, $this->privateKeyPassword, dirname(__FILE__) . $this->publicKey);
            $test = $response->OPERATION ."|". $response->ORDERNUMBER ."|".$response->MERORDERNUM ."|". $response->PRCODE ."|". $response->SRCODE ."|". $response->RESULTTEXT;
            echo $sign->verify($test, $response->DIGEST);
            if ($sign->verify($test, $response->DIGEST) == false) {
                $this->GpWebPay->log->write('Response signature verification failed for orderNumber ' . $response->orderNumber);
                $redirect = $order->get_checkout_payment_url(true);
                $this->msg ['message'] = 'Nepodarilo se overit podpis odpovedi. Skúste znova';
                $this->msg ['class'] = 'error';

                $order->update_status('failed');

                wc_add_notice(__($this->msg ['message'], 'gp') . $error_message, 'error');
                $location = $order->get_checkout_payment_url();
                wp_safe_redirect($location);
                exit();
            }

            $this->GpWebPay->log->write('Response SRCODE=' . $response->SRCODE . " PRCODE=". $response->PRCODE . " Message: [". $response->RESULTTEXT . "] for orderNumber " . $response->orderNumber);

            if ($response->PRCODE == 0 and $response->SRCODE == 0) {
                $this->GpWebPay->log->write("Payment success");
                $this->GpWebPay->updateTransactionStatus($response->ORDERNUMBER, "completed");
                $this->msg ['class'] = 'woocommerce_message';
                $this->msg ['message'] = sprintf(__('Platba bola spracovaná. Číslo objednávky: %s', 'gp'), $partsOforderNumber [0]);
                $order->add_order_note($this->msg ['message']);
                WC()->cart->empty_cart();
                $order->payment_complete();
                $order->update_status('processing');

                /*$order->reduce_order_stock();*/

                $location = $this->get_return_url($order);
                wp_safe_redirect($location);
                exit();
            }
            else{
                $this->GpWebPay->log->write("Payment fail");
                $this->msg ['class'] = 'error';
                $this->msg ['message'] = sprintf(__('Platba nebola spracovaná. Skúste znova.', 'gp'));

                wc_add_notice(__($this->msg ['message'], 'gp'), 'error');
                $location = $order->get_checkout_payment_url();
                wp_safe_redirect($location);
                exit();
                
            }
 
        }

    }

    function woocommerce_add_gpwebpay_gateway($methods) {
        $methods [] = 'GP_Payment_Gateway';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_gpwebpay_gateway');
}
