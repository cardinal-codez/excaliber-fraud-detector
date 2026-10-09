<?php
/**
 * Plugin Name: HIFQA Fraud Detector for WooCommerce
 * Plugin URI: https://github.com/dilrubaummer/hifqa-fraud-detector
 * Description: HIFQA Fraud Detector scores every WooCommerce order for fraud in real time. Local signal scoring works out of the box, with optional AI and vector search layers.
 * Version: 1.0.0
 * Author: HIFQA
 * Requires at least: 6.6
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC tested up to: 11.1
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hifqa-fraud-detector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
define( 'AIFD_VERSION', '1.0.0' );
define( 'AIFD_PATH', plugin_dir_path( __FILE__ ) );
define( 'AIFD_URL', plugin_dir_url( __FILE__ ) );

// Orders are read and written through the WooCommerce order API only, so the
// plugin works with High-Performance Order Storage. It scores orders from both
// the classic checkout and the block checkout, and adds nothing to the
// checkout page itself.
add_action( 'before_woocommerce_init', 'aifd_declare_woocommerce_compatibility' );

function aifd_declare_woocommerce_compatibility() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
}

require_once AIFD_PATH . 'includes/providers/class-provider-base.php';
require_once AIFD_PATH . 'includes/providers/class-provider-openai.php';
require_once AIFD_PATH . 'includes/providers/class-provider-anthropic.php';
require_once AIFD_PATH . 'includes/providers/class-provider-gemini.php';
require_once AIFD_PATH . 'includes/providers/class-provider-ollama.php';

require_once AIFD_PATH . 'includes/rest-api.php';
require_once AIFD_PATH . 'includes/fraud-detection.php';
require_once AIFD_PATH . 'includes/admin-menu.php';
require_once AIFD_PATH . 'includes/settings-page.php';
require_once AIFD_PATH . 'includes/class-embeddings.php';
require_once AIFD_PATH . 'includes/class-pinecone.php';
require_once AIFD_PATH . 'includes/class-seed-loader.php';
require_once AIFD_PATH . 'includes/class-function-calling.php';
