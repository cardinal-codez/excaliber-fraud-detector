<?php
/**
 * Plugin Name: AI Fraud Detector
 * Plugin URI: https://github.com/yourusername/ai-fraud-detector
 * Description: AI powered fraud detection for WooCommerce
 * Version: 0.1.0
 * Author: Dilruba
 * License: GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Your OpenAI API Key
define( 'AIFD_OPENAI_KEY', '***REMOVED-OPENAI-KEY***' );

// Plugin loaded successfully
add_action( 'admin_notices', function() {
    echo '<div class="notice notice-success">
        <p>AI Fraud Detector is active! 🛡️</p>
    </div>';
});

add_action('admin_menu', 'aifd_register_menu');

function aifd_register_menu() {
    add_menu_page(
        'AI Fraud Detector',
        'Fraud Detector',
        'manage_options',
        'ai-fraud-detector',
        'aifd_render_dashboard',
        'dashicons-shield',
        56
    );
}

function aifd_render_dashboard() {
    include plugin_dir_path(__FILE__) . 'admin/dashboard.php';
}

// Enqueue React dashboard script
add_action( 'admin_enqueue_scripts', 'aifd_enqueue_react' );

function aifd_enqueue_react( $hook ) {
    if ( $hook !== 'toplevel_page_ai-fraud-detector' ) return;

    $asset_file = include plugin_dir_path( __FILE__ ) . 'build/index.asset.php';

    wp_enqueue_script(
        'aifd-dashboard',
        plugin_dir_url( __FILE__ ) . 'build/index.js',
        $asset_file['dependencies'],
        $asset_file['version'],
        true
    );

    // Pass data from PHP to React
    wp_localize_script( 'aifd-dashboard', 'aifdData', array(
        'apiUrl'   => rest_url( 'aifd/v1/' ),
        'nonce'    => wp_create_nonce( 'wp_rest' ),
        'blocked'  => get_option( 'aifd_total_blocked', 0 ),
        'review'   => get_option( 'aifd_total_review', 0 ),
        'allowed'  => get_option( 'aifd_total_allowed', 0 ),
        'saved'    => get_option( 'aifd_total_saved', 0 ),
    ));
}

// Hook into WooCommerce new order
add_action( 'woocommerce_new_order', 'aifd_catch_new_order', 10, 1 );

function aifd_catch_new_order( $order_id ) {

    $order = wc_get_order( $order_id );

    // ── Extract fraud signals ──────────────────
    $fraud_signals = aifd_extract_signals( $order );

    // Get AI risk score from OpenAI
    $ai_result = aifd_get_ai_risk_score( $fraud_signals );

    // ── Calculate risk score ───────────────────
    $risk_result = aifd_calculate_risk_score( $fraud_signals );

    // Update order status based on AI decision
    aifd_update_order_status( $order_id, $ai_result );

    // Send email alert if needed
    aifd_send_alert_email( $order_id, $ai_result );

    // Log everything
    aifd_log_order_with_ai( $order_id, $fraud_signals, $risk_result, $ai_result );

}

/**
 * Extract all fraud signals from an order
 */
function aifd_extract_signals( $order ) {

    $order_id        = $order->get_id();
    $order_total     = $order->get_total();
    $customer_email  = $order->get_billing_email();
    $customer_ip     = $order->get_customer_ip_address();
    $customer_id     = $order->get_customer_id();
    $billing_country = $order->get_billing_country();
    $billing_phone   = $order->get_billing_phone();
    $shipping_country = $order->get_shipping_country();
    $payment_method  = $order->get_payment_method();
    $date_created    = $order->get_date_created()->date('Y-m-d H:i:s');
    $order_hour      = (int) $order->get_date_created()->date('H');

    // Previous orders from same email
    $previous_orders = wc_get_orders( array(
        'billing_email' => $customer_email,
        'limit'         => 10,
        'return'        => 'ids',
    ) );

    // Check for suspicious email patterns
    $suspicious_email = aifd_is_suspicious_email( $customer_email );

    return array(
        'order_id'             => $order_id,
        'order_total'          => $order_total,
        'payment_method'       => $payment_method,
        'date_created'         => $date_created,
        'customer_email'       => $customer_email,
        'customer_ip'          => $customer_ip,
        'is_guest'             => ( $customer_id === 0 ),
        'is_new_customer'      => ( count($previous_orders) <= 1 ),
        'previous_order_count' => count($previous_orders),
        'billing_country'      => $billing_country,
        'billing_phone'        => $billing_phone,
        'shipping_country'     => $shipping_country,
        'address_mismatch'     => ( $billing_country !== $shipping_country ),
        'is_odd_hours'         => ( $order_hour >= 0 && $order_hour <= 5 ),
        'is_high_value'        => ( (float) $order_total > 500 ),
        'has_phone'            => ! empty( $billing_phone ),
        'suspicious_email'     => $suspicious_email,
    );

}

/**
 * Check if email looks suspicious
 */
function aifd_is_suspicious_email( $email ) {
    // List of disposable email providers
    $disposable_domains = array(
        'tempmail.com', 'mailinator.com', '10minutemail.com',
        'guerrillamail.com', 'throwaway.email', 'temp-mail.org'
    );

    $email_domain = strtolower( substr( strrchr( $email, '@' ), 1 ) );

    if ( in_array( $email_domain, $disposable_domains ) ) {
        return true;
    }

    // Check if email has too many random characters
    $local_part = substr( $email, 0, strpos( $email, '@' ) );
    if ( preg_match( '/[a-z0-9]{15,}/', $local_part ) ) {
        return true;
    }

    return false;
}

/**
 * Calculate risk score from signals
 */
function aifd_calculate_risk_score( $signals ) {

    $score   = 0;
    $reasons = array();

    if ( $signals['address_mismatch'] ) {
        $score += 30;
        $reasons[] = 'Billing and shipping country mismatch (+30)';
    }

    if ( $signals['is_new_customer'] ) {
        $score += 10;
        $reasons[] = 'New customer (+10)';
    }

    if ( $signals['is_guest'] ) {
        $score += 5;
        $reasons[] = 'Guest checkout (+5)';
    }

    if ( $signals['is_high_value'] ) {
        $score += 20;
        $reasons[] = 'High value order over $500 (+20)';
    }

    if ( $signals['is_odd_hours'] ) {
        $score += 15;
        $reasons[] = 'Ordered at odd hours 12am-5am (+15)';
    }

    if ( $signals['suspicious_email'] ) {
        $score += 10;
        $reasons[] = 'Suspicious email pattern (+10)';
    }

    if ( ! $signals['has_phone'] ) {
        $score += 10;
        $reasons[] = 'No phone number provided (+10)';
    }

    // Cap the score at 100
    if ( $score > 100 ) {
        $score = 100;
    }

    // Determine risk level
    if ( $score >= 70 ) {
        $risk_level = 'HIGH';
        $decision   = 'BLOCK';
    } elseif ( $score >= 40 ) {
        $risk_level = 'MEDIUM';
        $decision   = 'REVIEW';
    } else {
        $risk_level = 'LOW';
        $decision   = 'ALLOW';
    }

    return array(
        'risk_score'  => $score,
        'risk_level'  => $risk_level,
        'decision'    => $decision,
        'reasons'     => $reasons,
    );
}

/**
 * Log order with signals and risk result
 */
function aifd_log_order( $order_id, $signals, $risk_result ) {

    $log_file = plugin_dir_path( __FILE__ ) . 'fraud-log.txt';

    $log_entry  = "========================================\n";
    $log_entry .= date('Y-m-d H:i:s') . ' - Order #' . $order_id . "\n";
    $log_entry .= "========================================\n";
    $log_entry .= "RISK SCORE:  " . $risk_result['risk_score'] . "/100\n";
    $log_entry .= "RISK LEVEL:  " . $risk_result['risk_level'] . "\n";
    $log_entry .= "DECISION:    " . $risk_result['decision'] . "\n";
    $log_entry .= "----------------------------------------\n";
    $log_entry .= "REASONS:\n";

    foreach ( $risk_result['reasons'] as $reason ) {
        $log_entry .= "  • " . $reason . "\n";
    }

    $log_entry .= "----------------------------------------\n";
    $log_entry .= "SIGNALS:\n";
    $log_entry .= json_encode( $signals, JSON_PRETTY_PRINT ) . "\n\n";

    file_put_contents( $log_file, $log_entry, FILE_APPEND );

}

/**
 * Get AI risk score from OpenAI
 */
function aifd_get_ai_risk_score( $signals ) {

    $api_key = AIFD_OPENAI_KEY;

    // Build the prompt
    $prompt = "You are a fraud detection expert for an ecommerce store.
    
        Analyze this order and return a JSON response with fraud risk assessment.

        Order details:
        - Order Total: $" . $signals['order_total'] . "
        - Customer Email: " . $signals['customer_email'] . "
        - Billing Country: " . $signals['billing_country'] . "
        - Shipping Country: " . $signals['shipping_country'] . "
        - Address Mismatch: " . ($signals['address_mismatch'] ? 'YES' : 'NO') . "
        - Is Guest: " . ($signals['is_guest'] ? 'YES' : 'NO') . "
        - Is New Customer: " . ($signals['is_new_customer'] ? 'YES' : 'NO') . "
        - Previous Orders: " . $signals['previous_order_count'] . "
        - Ordered at Odd Hours: " . ($signals['is_odd_hours'] ? 'YES' : 'NO') . "
        - High Value Order: " . ($signals['is_high_value'] ? 'YES' : 'NO') . "
        - Has Phone: " . ($signals['has_phone'] ? 'YES' : 'NO') . "
        - Suspicious Email: " . ($signals['suspicious_email'] ? 'YES' : 'NO') . "
        - Payment Method: " . $signals['payment_method'] . "

        Return ONLY a JSON object like this — no extra text:
        {
        \"risk_score\": 75,
        \"risk_level\": \"HIGH\",
        \"decision\": \"BLOCK\",
        \"reason\": \"Plain English explanation of why this order is suspicious\"
        }

        Risk levels: LOW (0-39), MEDIUM (40-69), HIGH (70-100)
        Decisions: ALLOW, REVIEW, BLOCK";
    write_log($prompt);
    // Call OpenAI API
    $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
        'timeout' => 30,
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ),
        'body' => json_encode( array(
            'model'       => 'gpt-4o-mini',
            'max_tokens'  => 200,
            'messages'    => array(
                array(
                    'role'    => 'system',
                    'content' => 'You are a fraud detection expert. Always respond with valid JSON only.'
                ),
                array(
                    'role'    => 'user',
                    'content' => $prompt
                )
            )
        ) )
    ) );

    write_log($response);

    // Handle errors
    if ( is_wp_error( $response ) ) {
        return array(
            'risk_score' => 50,
            'risk_level' => 'MEDIUM',
            'decision'   => 'REVIEW',
            'reason'     => 'AI scoring unavailable — manual review needed',
            'source'     => 'fallback'
        );
    }

    // Parse response
    $body    = wp_remote_retrieve_body( $response );
    $data    = json_decode( $body, true );
    $content = $data['choices'][0]['message']['content'] ?? '';

    // Clean and decode JSON
    $content = trim( $content );
    $content = preg_replace( '/```json|```/', '', $content );
    $result  = json_decode( $content, true );

    if ( ! $result ) {
        return array(
            'risk_score' => 50,
            'risk_level' => 'MEDIUM',
            'decision'   => 'REVIEW',
            'reason'     => 'AI response parsing failed — manual review needed',
            'source'     => 'fallback'
        );
    }

    $result['source'] = 'openai';
    return $result;

}

/**
 * Log order with both rule and AI results
 */
function aifd_log_order_with_ai( $order_id, $signals, $rule_result, $ai_result ) {

    $log_file = plugin_dir_path( __FILE__ ) . 'fraud-log.txt';

    $log_entry  = "========================================\n";
    $log_entry .= date('Y-m-d H:i:s') . ' - Order #' . $order_id . "\n";
    $log_entry .= "========================================\n";

    // AI Result
    $log_entry .= "🤖 AI SCORING (OpenAI):\n";
    $log_entry .= "   Score:    " . $ai_result['risk_score'] . "/100\n";
    $log_entry .= "   Level:    " . $ai_result['risk_level'] . "\n";
    $log_entry .= "   Decision: " . $ai_result['decision'] . "\n";
    $log_entry .= "   Reason:   " . $ai_result['reason'] . "\n";
    $log_entry .= "   Source:   " . $ai_result['source'] . "\n";
    $log_entry .= "----------------------------------------\n";

    // Rule Result (backup)
    $log_entry .= "📋 RULE SCORING (Backup):\n";
    $log_entry .= "   Score:    " . $rule_result['risk_score'] . "/100\n";
    $log_entry .= "   Level:    " . $rule_result['risk_level'] . "\n";
    $log_entry .= "   Decision: " . $rule_result['decision'] . "\n";
    foreach ( $rule_result['reasons'] as $reason ) {
        $log_entry .= "   • " . $reason . "\n";
    }
    $log_entry .= "----------------------------------------\n";

    // Signals
    $log_entry .= "📊 SIGNALS:\n";
    $log_entry .= json_encode( $signals, JSON_PRETTY_PRINT ) . "\n\n";

    file_put_contents( $log_file, $log_entry, FILE_APPEND );

}

/**
 * Update order status based on AI decision
 */
function aifd_update_order_status( $order_id, $ai_result ) {

    $order    = wc_get_order( $order_id );
    $decision = $ai_result['decision'];
    $score    = $ai_result['risk_score'];
    $reason   = $ai_result['reason'];

    if ( $decision === 'BLOCK' ) {

        // Put order on hold
        $order->update_status(
            'on-hold',
            'AI Fraud Detector: HIGH RISK order blocked. Score: ' . $score . '/100. Reason: ' . $reason
        );

        // Add order note visible in admin
        $order->add_order_note(
            '🚨 FRAUD ALERT: Order automatically blocked by AI Fraud Detector.' .
            ' Risk Score: ' . $score . '/100.' .
            ' Reason: ' . $reason,
            false
        );

    } elseif ( $decision === 'REVIEW' ) {

        // Add warning note but don't block
        $order->add_order_note(
            '⚠️ FRAUD WARNING: Order flagged for manual review by AI Fraud Detector.' .
            ' Risk Score: ' . $score . '/100.' .
            ' Reason: ' . $reason,
            false
        );

    } else {

        // Safe order — just add a clean note
        $order->add_order_note(
            '✅ FRAUD CHECK PASSED: AI Fraud Detector approved this order.' .
            ' Risk Score: ' . $score . '/100.',
            false
        );

    }

    $order->save();

}

/**
 * Send email alert to merchant for high risk orders
 */
function aifd_send_alert_email( $order_id, $ai_result ) {

    // Only send email for BLOCK or REVIEW
    if ( $ai_result['decision'] === 'ALLOW' ) {
        return;
    }

    $admin_email = get_option( 'admin_email' );
    $order       = wc_get_order( $order_id );
    $decision    = $ai_result['decision'];
    $score       = $ai_result['risk_score'];
    $reason      = $ai_result['reason'];

    // Email subject
    $subject = $decision === 'BLOCK'
        ? '🚨 FRAUD ALERT: Order #' . $order_id . ' Blocked — Score ' . $score . '/100'
        : '⚠️ FRAUD WARNING: Order #' . $order_id . ' Needs Review — Score ' . $score . '/100';

    // Email body
    $message  = "AI Fraud Detector has flagged an order.\n\n";
    $message .= "Order ID:    #" . $order_id . "\n";
    $message .= "Decision:    " . $decision . "\n";
    $message .= "Risk Score:  " . $score . "/100\n";
    $message .= "Reason:      " . $reason . "\n\n";
    $message .= "Customer:    " . $order->get_billing_first_name() . " " . $order->get_billing_last_name() . "\n";
    $message .= "Email:       " . $order->get_billing_email() . "\n";
    $message .= "Total:       $" . $order->get_total() . "\n";
    $message .= "Billing:     " . $order->get_billing_country() . "\n";
    $message .= "Shipping:    " . $order->get_shipping_country() . "\n\n";
    $message .= "View Order:  " . admin_url( 'post.php?post=' . $order_id . '&action=edit' ) . "\n\n";
    $message .= "---\n";
    $message .= "AI Fraud Detector Plugin\n";

    // Send email
    wp_mail( $admin_email, $subject, $message );

}