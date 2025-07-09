<?php
/**
 * Plugin Name: WooCommerce Product Markup
 * Plugin URI: https://rakmyat.com/
 * Description: Add fixed or percentage markup to all WooCommerce products (Polylang Compatible)
 * Version: 1.1.0
 * Author: Yousef Abdallah
 * Author URI: https://rakmyat.com/
 * Requires at least: 5.0
 * Tested up to: 6.3
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

class WooCommerce_Product_Markup {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('plugins_loaded', array($this, 'init_after_plugins_loaded'));
    }
    
    public function init() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Clear price cache when settings change
        add_action('update_option_wc_markup_settings', array($this, 'clear_price_cache'));
    }
    
    public function init_after_plugins_loaded() {
        // Apply markup to product prices with higher priority to ensure compatibility
        add_filter('woocommerce_product_get_price', array($this, 'apply_markup'), 99, 2);
        add_filter('woocommerce_product_get_regular_price', array($this, 'apply_markup'), 99, 2);
        add_filter('woocommerce_product_get_sale_price', array($this, 'apply_markup'), 99, 2);
        
        // Handle variable products
        add_filter('woocommerce_product_variation_get_price', array($this, 'apply_markup'), 99, 2);
        add_filter('woocommerce_product_variation_get_regular_price', array($this, 'apply_markup'), 99, 2);
        add_filter('woocommerce_product_variation_get_sale_price', array($this, 'apply_markup'), 99, 2);
        
        // Handle variation prices
        add_filter('woocommerce_variation_prices_price', array($this, 'apply_markup'), 99, 2);
        add_filter('woocommerce_variation_prices_regular_price', array($this, 'apply_markup'), 99, 2);
        add_filter('woocommerce_variation_prices_sale_price', array($this, 'apply_markup'), 99, 2);
        
        // Additional hooks for better compatibility
        add_filter('woocommerce_get_price_html', array($this, 'apply_markup_to_price_html'), 99, 2);
        
        // Polylang specific hooks
        if (function_exists('pll_current_language')) {
            add_filter('woocommerce_product_get_price', array($this, 'apply_markup_polylang'), 999, 2);
            add_filter('woocommerce_product_get_regular_price', array($this, 'apply_markup_polylang'), 999, 2);
            add_filter('woocommerce_product_variation_get_price', array($this, 'apply_markup_polylang'), 999, 2);
            add_filter('woocommerce_product_variation_get_regular_price', array($this, 'apply_markup_polylang'), 999, 2);
        }
        
        // Hook into cart and checkout
        add_action('woocommerce_before_calculate_totals', array($this, 'apply_markup_to_cart'), 99);
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Product Markup',
            'Product Markup',
            'manage_woocommerce',
            'wc-product-markup',
            array($this, 'admin_page')
        );
    }
    
    public function register_settings() {
        register_setting('wc_markup_settings', 'wc_markup_settings');
        
        add_settings_section(
            'wc_markup_section',
            'Markup Settings',
            array($this, 'section_callback'),
            'wc_markup_settings'
        );
        
        add_settings_field(
            'markup_enabled',
            'Enable Markup',
            array($this, 'markup_enabled_callback'),
            'wc_markup_settings',
            'wc_markup_section'
        );
        
        add_settings_field(
            'markup_type',
            'Markup Type',
            array($this, 'markup_type_callback'),
            'wc_markup_settings',
            'wc_markup_section'
        );
        
        add_settings_field(
            'markup_value',
            'Markup Value',
            array($this, 'markup_value_callback'),
            'wc_markup_settings',
            'wc_markup_section'
        );
        
        add_settings_field(
            'apply_to_all_languages',
            'Apply to All Languages',
            array($this, 'apply_to_all_languages_callback'),
            'wc_markup_settings',
            'wc_markup_section'
        );
    }
    
    public function section_callback() {
        echo '<p>Configure markup settings for all WooCommerce products across all languages.</p>';
    }
    
    public function markup_enabled_callback() {
        $options = get_option('wc_markup_settings');
        $enabled = isset($options['markup_enabled']) ? $options['markup_enabled'] : 0;
        echo '<input type="checkbox" name="wc_markup_settings[markup_enabled]" value="1" ' . checked(1, $enabled, false) . ' />';
        echo '<label for="wc_markup_settings[markup_enabled]"> Enable markup for all products</label>';
    }
    
    public function markup_type_callback() {
        $options = get_option('wc_markup_settings');
        $type = isset($options['markup_type']) ? $options['markup_type'] : 'percentage';
        
        echo '<select name="wc_markup_settings[markup_type]">';
        echo '<option value="percentage" ' . selected($type, 'percentage', false) . '>Percentage (%)</option>';
        echo '<option value="fixed" ' . selected($type, 'fixed', false) . '>Fixed Amount</option>';
        echo '</select>';
    }
    
    public function markup_value_callback() {
        $options = get_option('wc_markup_settings');
        $value = isset($options['markup_value']) ? $options['markup_value'] : 0;
        
        echo '<input type="number" name="wc_markup_settings[markup_value]" value="' . esc_attr($value) . '" step="0.01" min="0" />';
        echo '<p class="description">Enter the markup value (e.g., 10 for 10% or 5.00 for $5.00 fixed)</p>';
    }
    
    public function apply_to_all_languages_callback() {
        $options = get_option('wc_markup_settings');
        $apply_all = isset($options['apply_to_all_languages']) ? $options['apply_to_all_languages'] : 1;
        echo '<input type="checkbox" name="wc_markup_settings[apply_to_all_languages]" value="1" ' . checked(1, $apply_all, false) . ' />';
        echo '<label for="wc_markup_settings[apply_to_all_languages]"> Apply markup to products in all languages (recommended for Polylang)</label>';
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>WooCommerce Product Markup</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('wc_markup_settings');
                do_settings_sections('wc_markup_settings');
                submit_button();
                ?>
            </form>
            
            <div class="notice notice-info">
                <p><strong>How it works:</strong></p>
                <ul>
                    <li>• <strong>Percentage:</strong> Enter a number like 10 for 10% markup</li>
                    <li>• <strong>Fixed Amount:</strong> Enter a number like 5.00 for $5.00 markup</li>
                    <li>• Changes apply to all products immediately</li>
                    <li>• Original prices are preserved - markup is applied dynamically</li>
                    <li>• <strong>Polylang Compatible:</strong> Works with all language versions of your products</li>
                </ul>
            </div>
            
            <?php if (function_exists('pll_current_language')): ?>
            <div class="notice notice-success">
                <p><strong>Polylang Detected:</strong> Plugin is optimized for multilingual compatibility.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    public function apply_markup($price, $product) {
        // Skip if we're in admin or if already processed
        if (is_admin() || $this->is_already_processed($product)) {
            return $price;
        }
        
        return $this->calculate_markup($price, $product);
    }
    
    public function apply_markup_polylang($price, $product) {
        // Skip if we're in admin
        if (is_admin()) {
            return $price;
        }
        
        // Get markup settings
        $options = get_option('wc_markup_settings');
        
        // Check if markup should apply to all languages
        if (empty($options['apply_to_all_languages'])) {
            return $price;
        }
        
        return $this->calculate_markup($price, $product);
    }
    
    public function apply_markup_to_price_html($price_html, $product) {
        // This ensures markup is applied to displayed prices
        return $price_html;
    }
    
    public function apply_markup_to_cart($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        
        if (did_action('woocommerce_before_calculate_totals') >= 2) {
            return;
        }
        
        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $original_price = $product->get_price();
            $new_price = $this->calculate_markup($original_price, $product);
            
            if ($new_price !== $original_price) {
                $product->set_price($new_price);
            }
        }
    }
    
    private function calculate_markup($price, $product) {
        // Get markup settings
        $options = get_option('wc_markup_settings');
        
        // Check if markup is enabled
        if (empty($options['markup_enabled'])) {
            return $price;
        }
        
        // Get markup type and value
        $markup_type = isset($options['markup_type']) ? $options['markup_type'] : 'percentage';
        $markup_value = isset($options['markup_value']) ? floatval($options['markup_value']) : 0;
        
        // If no markup value, return original price
        if ($markup_value <= 0) {
            return $price;
        }
        
        // If no price, return original
        if (empty($price) || $price <= 0) {
            return $price;
        }
        
        // Apply markup based on type
        if ($markup_type === 'percentage') {
            // Percentage markup
            $new_price = $price * (1 + ($markup_value / 100));
        } else {
            // Fixed amount markup
            $new_price = $price + $markup_value;
        }
        
        return $new_price;
    }
    
    private function is_already_processed($product) {
        // Check if this product has already been processed to avoid double markup
        $processed_products = wp_cache_get('wc_markup_processed_products', 'wc_markup');
        if (!$processed_products) {
            $processed_products = array();
        }
        
        $product_id = $product->get_id();
        if (in_array($product_id, $processed_products)) {
            return true;
        }
        
        $processed_products[] = $product_id;
        wp_cache_set('wc_markup_processed_products', $processed_products, 'wc_markup', 300);
        
        return false;
    }
    
    public function clear_price_cache() {
        // Clear WooCommerce price cache
        if (function_exists('wc_delete_product_transients')) {
            // Get all products
            $products = wc_get_products(array('limit' => -1));
            foreach ($products as $product) {
                wc_delete_product_transients($product->get_id());
            }
        }
        
        // Clear object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        // Clear our custom cache
        wp_cache_delete('wc_markup_processed_products', 'wc_markup');
        
        // Clear Polylang cache if available
        if (function_exists('pll_cache_flush')) {
            pll_cache_flush();
        }
    }
}

// Initialize the plugin
new WooCommerce_Product_Markup();

// Activation hook
register_activation_hook(__FILE__, function() {
    // Set default options
    $default_options = array(
        'markup_enabled' => 0,
        'markup_type' => 'percentage',
        'markup_value' => 0,
        'apply_to_all_languages' => 1
    );
    
    if (!get_option('wc_markup_settings')) {
        add_option('wc_markup_settings', $default_options);
    }
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    // Clear any cached data
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
    
    // Clear our custom cache
    wp_cache_delete('wc_markup_processed_products', 'wc_markup');
    
    // Clear Polylang cache if available
    if (function_exists('pll_cache_flush')) {
        pll_cache_flush();
    }
});
?>
