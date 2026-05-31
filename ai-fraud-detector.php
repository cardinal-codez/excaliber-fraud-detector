<?php
/**
 * Plugin Name: AI Fraud Detector
 * Plugin URI: https://github.com/dilrubaummer/ai-fraud-detector
 * Description: AI powered fraud detection for WooCommerce
 * Version: 0.1.0
 * Author: Dilruba
 * License: GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
define( 'AIFD_PATH', plugin_dir_path( __FILE__ ) );
define( 'AIFD_URL', plugin_dir_url( __FILE__ ) );
// Your OpenAI API Key
//define( 'AIFD_OPENAI_KEY', '***REMOVED-OPENAI-KEY***' );

// Plugin loaded successfully
add_action( 'admin_notices', function() {
    echo '<div class="notice notice-success">
        <p>AI Fraud Detector is active! 🛡️</p>
    </div>';
});



require_once AIFD_PATH . 'includes/providers/class-provider-base.php';
require_once AIFD_PATH . 'includes/providers/class-provider-openai.php';
require_once AIFD_PATH . 'includes/providers/class-provider-anthropic.php';
require_once AIFD_PATH . 'includes/providers/class-provider-gemini.php';
require_once AIFD_PATH . 'includes/providers/class-provider-ollama.php';

// In ai-fraud-detector.php
require_once plugin_dir_path(__FILE__) . 'includes/rest-api.php';
require_once plugin_dir_path(__FILE__) . 'includes/fraud-detection.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin-menu.php';
require_once AIFD_PATH . 'includes/settings-page.php';