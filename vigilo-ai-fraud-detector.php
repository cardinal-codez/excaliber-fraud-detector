<?php
/**
 * Plugin Name: Vigilo AI Fraud Detector
 * Plugin URI: https://github.com/dilrubaummer/vigilo-ai-fraud-detector
 * Description: Real-time fraud detection for WooCommerce. Local signal scoring out of the box, with optional AI and vector search layers.
 * Version: 1.0.0
 * Author: Dilruba
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: vigilo-ai-fraud-detector
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
define( 'AIFD_PATH', plugin_dir_path( __FILE__ ) );
define( 'AIFD_URL', plugin_dir_url( __FILE__ ) );

require_once AIFD_PATH . 'includes/providers/class-provider-base.php';
require_once AIFD_PATH . 'includes/providers/class-provider-openai.php';
require_once AIFD_PATH . 'includes/providers/class-provider-anthropic.php';
require_once AIFD_PATH . 'includes/providers/class-provider-gemini.php';
require_once AIFD_PATH . 'includes/providers/class-provider-ollama.php';

// In vigilo-ai-fraud-detector.php
require_once plugin_dir_path(__FILE__) . 'includes/rest-api.php';
require_once plugin_dir_path(__FILE__) . 'includes/fraud-detection.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin-menu.php';
require_once AIFD_PATH . 'includes/settings-page.php';
require_once AIFD_PATH . 'includes/class-embeddings.php';
require_once AIFD_PATH . 'includes/class-pinecone.php';
require_once AIFD_PATH . 'includes/class-seed-loader.php';
require_once AIFD_PATH . 'includes/class-function-calling.php';