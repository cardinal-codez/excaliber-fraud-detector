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

// Plugin loaded successfully
add_action( 'admin_notices', function() {
    echo '<div class="notice notice-success">
        <p>AI Fraud Detector is active! 🛡️</p>
    </div>';
});

// Hook into WooCommerce new order
add_action( 'woocommerce_new_order', 'aifd_catch_new_order', 10, 1 );

function aifd_catch_new_order( $order_id ) {

    $order = wc_get_order( $order_id );

    // ── Extract fraud signals ──────────────────
    $fraud_signals = aifd_extract_signals( $order );

    // ── Calculate risk score ───────────────────
    $risk_result = aifd_calculate_risk_score( $fraud_signals );

    // ── Log everything ─────────────────────────
    aifd_log_order( $order_id, $fraud_signals, $risk_result );

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