<?php
/**
 * Plugin Name: WooCommerce Wallee
 * Plugin URI: https://wordpress.org/plugins/woo-wallee
 * Description: Process WooCommerce payments with Wallee
 * Version: 1.0.13
 * License: Apache2
 * License URI: http://www.apache.org/licenses/LICENSE-2.0
 * Author: customweb GmbH
 * Author URI: https://www.customweb.com
 * Requires at least: 4.4
 * Tested up to: 4.9
 *
 * Text Domain: woocommerce-wallee
 * Domain Path: /languages/
 *
 */
if (!defined('ABSPATH')) {
	exit(); // Exit if accessed directly.
}

if (!class_exists('WooCommerce_Wallee')) {

	/**
	 * Main WooCommerce Wallee Class
	 *
	 * @class WooCommerce_Wallee
	 */
	final class WooCommerce_Wallee {
		
		/**
		 * WooCommerce Wallee version.
		 *
		 * @var string
		 */
		private $version = '1.0.13';
		
		/**
		 * The single instance of the class.
		 *
		 * @var WooCommerce_Wallee
		 */
		protected static $_instance = null;
		private $logger = null;

		/**
		 * Main WooCommerce Wallee Instance.
		 *
		 * Ensures only one instance of WooCommerce Wallee is loaded or can be loaded.
		 *
		 * @return WooCommerce_Wallee - Main instance.
		 */
		public static function instance(){
			if (self::$_instance === null) {
				self::$_instance = new self();
			}
			return self::$_instance;
		}

		/**
		 * WooCommerce Wallee Constructor.
		 */
		protected function __construct(){
			$this->define_constants();
			$this->includes();
			$this->init_hooks();
		}

		public function get_version(){
			return $this->version;
		}

		/**
		 * Define WC Wallee Constants.
		 */
		private function define_constants(){
			$this->define('WC_WALLEE_PLUGIN_FILE', __FILE__);
			$this->define('WC_WALLEE_ABSPATH', dirname(__FILE__) . '/');
			$this->define('WC_WALLEE_PLUGIN_BASENAME', plugin_basename(__FILE__));
			$this->define('WC_WALLEE_VERSION', $this->version);
			$this->define('WC_WALLEE_REQUIRED_PHP_VERSION', '5.6');
			$this->define('WC_WALLEE_REQUIRED_WP_VERSION', '4.4');
			$this->define('WC_WALLEE_REQUIRED_WC_VERSION', '3.0');
		}

		/**
		 * Include required core files used in admin and on the frontend.
		 */
		public function includes(){
			/**
			 * Class autoloader.
			 */
			require_once (WC_WALLEE_ABSPATH . 'includes/class-wc-wallee-autoloader.php');
			require_once (WC_WALLEE_ABSPATH . 'wallee-sdk/autoload.php');
			
			require_once (WC_WALLEE_ABSPATH . 'includes/class-wc-wallee-migration.php');
			require_once (WC_WALLEE_ABSPATH . 'includes/class-wc-wallee-email.php');
			require_once (WC_WALLEE_ABSPATH . 'includes/class-wc-wallee-return-handler.php');
			require_once (WC_WALLEE_ABSPATH . 'includes/class-wc-wallee-webhook-handler.php');
			require_once (WC_WALLEE_ABSPATH . 'includes/class-wc-wallee-unique-id.php');
			require_once (WC_WALLEE_ABSPATH . 'includes/class-wc-wallee-customer-document.php');
			require_once (WC_WALLEE_ABSPATH . 'includes/class-wc-wallee-cron.php');
			
			if (is_admin()) {
				require_once (WC_WALLEE_ABSPATH . 'includes/admin/class-wc-wallee-admin.php');
			}
		}

		private function init_hooks(){
			register_activation_hook(__FILE__, array(
				'WC_Wallee_Migration',
				'install_wallee_db' 
			));
			register_activation_hook(__FILE__, array(
				'WC_Wallee_Cron',
				'activate' 
			));
			register_deactivation_hook(__FILE__, array(
				'WC_Wallee_Cron',
				'deactivate' 
			));
			
			add_action('plugins_loaded', array(
				$this,
				'loaded' 
			), 0);
			add_action('init', array(
				$this,
				'register_order_statuses' 
			));
			add_action('init', array(
				$this,
				'set_device_id_cookie' 
			));
			add_action('wp_enqueue_scripts', array(
				$this,
				'enqueue_javascript_script' 
			));
			add_filter('script_loader_tag', array(
				$this,
				'set_js_async' 
			), 20, 3);
		}

		/**
		 * Load Localization files.
		 *
		 * Note: the first-loaded translation file overrides any following ones if the same translation is present.
		 *
		 * Locales found in:
		 *      - WP_LANG_DIR/woocommerce-wallee/woocommerce-wallee-LOCALE.mo
		 */
		public function load_plugin_textdomain(){
			$locale = apply_filters('plugin_locale', get_locale(), 'woocommerce-wallee');
			
			load_textdomain('woocommerce-wallee', WP_LANG_DIR . '/woocommerce-wallee/woocommerce-wallee' . $locale . '.mo');
			load_plugin_textdomain('woocommerce-wallee', false, plugin_basename(dirname(__FILE__)) . '/languages');
		}

		/**
		 * Init WooCommerce Wallee when plugins are loaded. 
		 */
		public function loaded(){
			
			// Set up localisation.
			$this->load_plugin_textdomain();
			
			add_filter('woocommerce_payment_gateways', array(
				$this,
				'add_gateways' 
			));
			add_filter('wc_order_statuses', array(
				$this,
				'add_order_statuses' 
			));
			add_filter('wc_order_is_editable', array(
				$this,
				'order_editable_check' 
			), 10, 2);
			add_filter('woocommerce_before_calculate_totals', array(
				$this,
				'before_calculate_totals' 
			), 10);
			add_filter('woocommerce_after_calculate_totals', array(
				$this,
				'after_calculate_totals' 
			), 10);
			add_filter('woocommerce_valid_order_statuses_for_payment_complete', array(
				$this,
				'valid_order_status_for_completion' 
			), 10, 2);
			add_filter('woocommerce_form_field_args', array(
				$this,
				'modify_form_fields_args' 
			), 10, 3);
			add_action('woocommerce_checkout_update_order_review', array(
				$this,
				'update_additional_customer_data' 
			));
		}

		public function register_order_statuses(){
			register_post_status('wc-wallee-redirected',
					array(
						'label' => 'Redirected',
						'public' => true,
						'exclude_from_search' => false,
						'show_in_admin_all_list' => true,
						'show_in_admin_status_list' => true,
						'label_count' => _n_noop('Redirected <span class="count">(%s)</span>', 'Redirected <span class="count">(%s)</span>') 
					));
			register_post_status('wc-wallee-waiting',
					array(
						'label' => 'Waiting',
						'public' => true,
						'exclude_from_search' => false,
						'show_in_admin_all_list' => true,
						'show_in_admin_status_list' => true,
						'label_count' => _n_noop('Waiting <span class="count">(%s)</span>', 'Waiting <span class="count">(%s)</span>') 
					));
			register_post_status('wc-wallee-manual',
					array(
						'label' => 'Manual Decision',
						'public' => true,
						'exclude_from_search' => false,
						'show_in_admin_all_list' => true,
						'show_in_admin_status_list' => true,
						'label_count' => _n_noop('Manual Decision <span class="count">(%s)</span>', 'Manual Decision <span class="count">(%s)</span>') 
					));
		}

		public function set_device_id_cookie(){
			$value = WC_Wallee_Unique_Id::get_uuid();
			if (isset($_COOKIE['wc_wallee_device_id']) && !empty($_COOKIE['wc_wallee_device_id'])) {
				$value = $_COOKIE['wc_wallee_device_id'];
			}
			setcookie('wc_wallee_device_id', $value, time() + YEAR_IN_SECONDS, '/');
		}

		public function set_js_async($tag, $handle, $src){
			$async_script_handles = array('wallee-device-id-js');
			foreach($async_script_handles as $async_handle){
				if($async_handle == $handle){
					return str_replace( ' src', ' async="async" src', $tag );
				}
			}			
			return $tag;
		}

		public function enqueue_javascript_script(){
			if(is_woocommerce() || is_cart() || is_checkout()){
				$unique_id = $_COOKIE['wc_wallee_device_id'];
				$space_id = get_option('wc_wallee_space_id');
				$script_url = WC_Wallee_Helper::instance()->get_base_gateway_url() . '/s/' . 
						$space_id. '/payment/device.js?sessionIdentifier=' .
						$unique_id;
				wp_enqueue_script('wallee-device-id-js', $script_url, array(), null, false);
			}
			if(is_checkout() && !is_wc_endpoint_url( 'order-received')){
				try{
					wp_enqueue_script('wallee-remote-checkout-js', WC_Wallee_Service_Transaction::instance()->get_javascript_url(), array(
						'jquery'
					), null, true);
					wp_enqueue_script('wallee-checkout-js', WooCommerce_Wallee::instance()->plugin_url() . '/assets/js/frontend/checkout.js',
							array(
								'jquery',
								'wallee-remote-checkout-js'
							), null, true);
				}
				catch(Exception $e){
				}
			}
		}

		public function add_order_statuses($order_statuses){
			$order_statuses['wc-wallee-redirected'] = _x('Redirected', 'Order status', 'woocommerce');
			$order_statuses['wc-wallee-waiting'] = _x('Waiting', 'Order status', 'woocommerce');
			$order_statuses['wc-wallee-manual'] = _x('Manual Decision', 'Order status', 'woocommerce');
			
			return $order_statuses;
		}

		public function order_editable_check($allowed, WC_Order $order = null){
			if ($order == null) {
				return $allowed;
			}
			if ($order->get_meta('_wallee_authorized', true)) {
				return false;
			}
			return $allowed;
		}

		public function valid_order_status_for_completion($statuses, WC_Order $order = null){
			$statuses[] = 'wallee-waiting';
			$statuses[] = 'wallee-manual';
			
			return $statuses;
		}

		public function before_calculate_totals(WC_Cart $cart){
			$GLOBALS['_wc_wallee_calculating'] = true;
			;
		}

		public function after_calculate_totals(WC_Cart $cart){
			unset($GLOBALS['_wc_wallee_calculating']);
		}

		/**
		 * Add the gateways to WooCommerce
		 *
		 */
		public function add_gateways($methods){
			$space_id = get_option('wc_wallee_space_id');
			$wallee_method_configurations = WC_Wallee_Entity_Method_Configuration::load_by_states_and_space_id($space_id,
					array(
						WC_Wallee_Entity_Method_Configuration::STATE_ACTIVE,
						WC_Wallee_Entity_Method_Configuration::STATE_INACTIVE 
					));
			try {
				foreach ($wallee_method_configurations as $configuration) {
					$methods[] = new WC_Wallee_Gateway($configuration);
				}
			}
			catch (\Wallee\Sdk\ApiException $e) {
				if ($e->getCode() === 401) {
					// Ignore it because we simply are not allowed to access the API
				}
				else {
					throw $e;
				}
			}
			
			return $methods;
		}

		public function modify_form_fields_args($arguments, $key, $value = null){
			if ($key == 'billing_email') {
				$arguments['class'][] = 'address-field';
			}
			if ($key == 'billing_phone') {
				$arguments['class'][] = 'address-field';
			}
			if ($key == 'billing_first_name') {
				$arguments['class'][] = 'address-field';
			}
			if ($key == 'billing_last_name') {
				$arguments['class'][] = 'address-field';
			}
			if ($key == 'shipping_first_name') {
				$arguments['class'][] = 'address-field';
			}
			if ($key == 'shipping_last_name') {
				$arguments['class'][] = 'address-field';
			}
			
			return $arguments;
		}

		public function update_additional_customer_data($arguments){
			$post_data = array();
			if (!empty($arguments)) {
				parse_str($arguments, $post_data);
			}
			
			
			WC()->customer->set_props(
					array(
						'billing_first_name' => isset($post_data['billing_first_name']) ? wp_unslash($post_data['billing_first_name']) : null,
						'billing_last_name' => isset($post_data['billing_last_name']) ? wp_unslash($post_data['billing_last_name']) : null,
						'billing_phone' => isset($post_data['billing_phone']) ? wp_unslash($post_data['billing_phone']) : null,
						'billing_email' => isset($post_data['billing_email']) ? wp_unslash($post_data['billing_email']) : null
					));
			
			if (wc_ship_to_billing_address_only() || !isset($post_data['ship_to_different_address']) || $post_data['ship_to_different_address'] == '0') {
				WC()->customer->set_props(
						array(
							'shipping_first_name' => isset($post_data['billing_first_name']) ? wp_unslash($post_data['billing_first_name']) : null,
							'shipping_last_name' => isset($post_data['billing_last_name']) ? wp_unslash($post_data['billing_last_name']) : null 
						));
			}
			else {
				WC()->customer->set_props(
						array(
							'shipping_first_name' => isset($post_data['shipping_first_name']) ? wp_unslash($post_data['shipping_first_name']) : null,
							'shipping_last_name' => isset($post_data['shipping_last_name']) ? wp_unslash($post_data['shipping_last_name']) : null 
						));
			}
		}

		/**
		 * Define constant if not already set.
		 *
		 * @param  string $name
		 * @param  string|bool $value
		 */
		private function define($name, $value){
			if (!defined($name)) {
				define($name, $value);
			}
		}

		public function log($message, $level = WC_Log_Levels::WARNING){
			if ($this->logger == null) {
				$this->logger = new WC_Logger();
			}
			
			$this->logger->log($level, $message, array(
				'source' => 'woocommerce-wallee' 
			));
			
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log("Woocommerce Wallee: " . $message);
			}
		}

		/**
		 * Add a WooCommerce notification message
		 *
		 * @param string $message Notification message
		 * @param string $type One of notice, error or success (default notice)
		 * @return $this
		 */
		public function add_notice($message, $type = 'notice'){
			$type = in_array($type, array(
				'notice',
				'error',
				'success' 
			)) ? $type : 'notice';
			wc_add_notice($message, $type);
		}

		/**
		 * Get the plugin url.
		 * @return string
		 */
		public function plugin_url(){
			return untrailingslashit(plugins_url('/', __FILE__));
		}

		/**
		 * Get the plugin path.
		 * @return string
		 */
		public function plugin_path(){
			return untrailingslashit(plugin_dir_path(__FILE__));
		}
	}
}

WooCommerce_Wallee::instance();