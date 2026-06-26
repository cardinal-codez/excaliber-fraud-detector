<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ══════════════════════════════════════════════════════
// MAIN HOOK
// ══════════════════════════════════════════════════════

add_action( 'woocommerce_new_order', 'aifd_catch_new_order', 10, 1 );

function aifd_catch_new_order( $order_id ) {

    $order = wc_get_order( $order_id );

    // Extract 16 fraud signals
    $fraud_signals = aifd_extract_signals( $order );

    // Get AI score via selected provider
    $ai_result = aifd_call_provider( $fraud_signals );

    // Get rule based backup score
    $risk_result = aifd_calculate_risk_score( $fraud_signals );

    // Update order + save meta
    aifd_update_order_status( $order_id, $ai_result, $risk_result );

    // Send email alert
    aifd_send_alert_email( $order_id, $ai_result );

    // Log
    aifd_log_order_with_ai( $order_id, $fraud_signals, $risk_result, $ai_result );

}

// ══════════════════════════════════════════════════════
// SIGNAL WEIGHTS
// ══════════════════════════════════════════════════════

function aifd_get_signal_weights() {

    $defaults = array(

        // Tier 1 — Critical
        'card_testing_pattern'   => 0.30,
        'disposable_email'       => 0.25,
        'high_velocity'          => 0.25,
        'address_mismatch'       => 0.20,
        'freight_forwarder'      => 0.20,

        // Tier 2 — Strong
        'chargeback_history'     => 0.22,
        'first_order_high_value' => 0.15,
        'ip_proxy_vpn'           => 0.12,
        'risky_country_combo'    => 0.12,
        'mismatched_names'       => 0.10,
        'no_phone_number'        => 0.10,

        // Tier 3 — Contextual
        'payment_method_risk'    => 0.08,
        'multiple_addresses'     => 0.08,
        'order_total_anomaly'    => 0.07,
        'odd_hours'              => 0.05,
        'guest_checkout'         => 0.05,
        'new_account'            => 0.05,

    );

    // Merge with merchant personalized weights
    $merchant_weights = get_option( 'aifd_merchant_weights', array() );

    return wp_parse_args( $merchant_weights, $defaults );

}

// ══════════════════════════════════════════════════════
// SIGNAL EXTRACTION — 16 SIGNALS
// ══════════════════════════════════════════════════════

function aifd_extract_signals( $order ) {

    $order_id         = $order->get_id();
    $order_total      = (float) $order->get_total();
    $customer_email   = $order->get_billing_email();
    $customer_ip      = $order->get_customer_ip_address();
    $customer_id      = $order->get_customer_id();
    $billing_country  = $order->get_billing_country();
    $billing_phone    = $order->get_billing_phone();
    $billing_name     = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
    $shipping_country = $order->get_shipping_country();
    $shipping_name    = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
    $payment_method   = $order->get_payment_method();
    $date_created     = $order->get_date_created()->date( 'Y-m-d H:i:s' );
    $order_hour       = (int) $order->get_date_created()->date( 'H' );

    // Previous orders from same email
    $previous_orders = wc_get_orders( array(
        'billing_email' => $customer_email,
        'limit'         => 20,
        'return'        => 'ids',
    ));

    // Store average order value
    $store_average = aifd_get_store_average_order_value();

    // Signal checks
    $disposable_email     = aifd_is_suspicious_email( $customer_email );
    $address_mismatch     = ( $billing_country !== $shipping_country );
    $mismatched_names     = ! empty( $shipping_name ) && ( $billing_name !== $shipping_name );
    $is_guest             = ( $customer_id === 0 );
    $is_new_customer      = ( count( $previous_orders ) <= 1 );
    $is_odd_hours         = ( $order_hour >= 0 && $order_hour <= 5 );
    $is_high_value        = ( $order_total > 500 );
    $has_phone            = ! empty( $billing_phone );
    $order_total_anomaly  = ( $store_average > 0 && $order_total > ( $store_average * 3 ) );
    $payment_method_risk  = in_array( $payment_method, array( 'cod', 'cheque', 'prepaid' ) );
    $risky_country_combo  = aifd_is_risky_country_combo( $billing_country, $shipping_country );
    $freight_forwarder    = aifd_is_freight_forwarder( $order->get_shipping_address_1() );
    $card_testing_pattern = aifd_is_card_testing( $customer_ip, $customer_email );
    $high_velocity        = ( count( $previous_orders ) >= 3 );
    $new_account          = aifd_is_new_account( $customer_id );
    $multiple_addresses   = aifd_has_multiple_addresses( $customer_email );
    $chargeback_history   = aifd_has_chargeback_history( $customer_email, $customer_ip );

    return array(
        // Order info
        'order_id'             => $order_id,
        'order_total'          => $order_total,
        'payment_method'       => $payment_method,
        'date_created'         => $date_created,
        'customer_email'       => $customer_email,
        'customer_ip'          => $customer_ip,
        'billing_country'      => $billing_country,
        'shipping_country'     => $shipping_country,
        'billing_phone'        => $billing_phone,

        // Tier 1 — Critical
        'card_testing_pattern'   => $card_testing_pattern,
        'disposable_email'       => $disposable_email,
        'high_velocity'          => $high_velocity,
        'address_mismatch'       => $address_mismatch,
        'freight_forwarder'      => $freight_forwarder,

        // Tier 2 — Strong
        'chargeback_history'     => $chargeback_history,
        'first_order_high_value' => ( $is_new_customer && $is_high_value ),
        'ip_proxy_vpn'           => false, // future: IP lookup API
        'risky_country_combo'    => $risky_country_combo,
        'mismatched_names'       => $mismatched_names,
        'no_phone_number'        => ! $has_phone,

        // Tier 3 — Contextual
        'payment_method_risk'    => $payment_method_risk,
        'multiple_addresses'     => $multiple_addresses,
        'order_total_anomaly'    => $order_total_anomaly,
        'odd_hours'              => $is_odd_hours,
        'guest_checkout'         => $is_guest,
        'new_account'            => $new_account,

        // Extra context for AI prompt
        'is_new_customer'        => $is_new_customer,
        'previous_order_count'   => count( $previous_orders ),
        'store_average'          => $store_average,
        'has_phone'              => $has_phone,
    );

}

// ══════════════════════════════════════════════════════
// SIGNAL HELPER FUNCTIONS
// ══════════════════════════════════════════════════════

function aifd_is_suspicious_email( $email ) {

    $disposable_domains = array(
        'tempmail.com', 'mailinator.com', '10minutemail.com',
        'guerrillamail.com', 'throwaway.email', 'temp-mail.org',
        'yopmail.com', 'sharklasers.com', 'trashmail.com',
        'fakeinbox.com', 'spamgourmet.com', 'maildrop.cc',
        'dispostable.com', 'throwam.com', 'spamherelots.com',
    );

    $email_domain = strtolower( substr( strrchr( $email, '@' ), 1 ) );

    if ( in_array( $email_domain, $disposable_domains ) ) {
        return true;
    }

    // Random character pattern
    $local_part = substr( $email, 0, strpos( $email, '@' ) );
    if ( preg_match( '/[a-z0-9]{15,}/', $local_part ) ) {
        return true;
    }

    return false;

}

function aifd_is_risky_country_combo( $billing, $shipping ) {

    $risky_combos = array(
        array( 'NG', 'US' ), array( 'NG', 'GB' ),
        array( 'GH', 'US' ), array( 'GH', 'GB' ),
        array( 'RU', 'US' ), array( 'RU', 'GB' ),
        array( 'CN', 'US' ), array( 'CN', 'GB' ),
        array( 'VN', 'US' ), array( 'ID', 'US' ),
    );

    foreach ( $risky_combos as $combo ) {
        if ( in_array( $billing, $combo ) && in_array( $shipping, $combo ) ) {
            return true;
        }
    }

    return false;

}

/**
 * Check if customer has chargeback history
 */
function aifd_has_chargeback_history( $email, $ip ) {

    $previous_orders = wc_get_orders( array(
        'billing_email' => $email,
        'limit'         => 20,
        'status'        => array( 'refunded', 'cancelled', 'failed' ),
        'return'        => 'objects',
    ));

    if ( empty( $previous_orders ) ) {
        return false;
    }

    foreach ( $previous_orders as $order ) {

        // Check if previously blocked by our plugin
        $previous_decision = $order->get_meta( '_aifd_decision' );
        if ( $previous_decision === 'BLOCK' ) {
            return true;
        }

        // Check order notes for chargeback keywords
        $notes = wc_get_order_notes( array(
            'order_id' => $order->get_id(),
            'type'     => 'internal',
        ));

        foreach ( $notes as $note ) {
            $note_lower = strtolower( $note->content );
            if (
                strpos( $note_lower, 'chargeback' ) !== false ||
                strpos( $note_lower, 'dispute' )    !== false ||
                strpos( $note_lower, 'fraud' )      !== false
            ) {
                return true;
            }
        }

    }

    return false;

}

function aifd_is_freight_forwarder( $address ) {

    $keywords = array(
        'freight', 'forwarder', 'forwarding', 'reshipping',
        'package forwarder', 'myus', 'shipito', 'planet express',
        'reship', 'forward2me', 'borderlinx'
    );

    $address_lower = strtolower( $address );

    foreach ( $keywords as $keyword ) {
        if ( strpos( $address_lower, $keyword ) !== false ) {
            return true;
        }
    }

    return false;

}

function aifd_is_card_testing( $ip, $email ) {

    $recent_orders = wc_get_orders( array(
        'limit'  => 100,
        'return' => 'objects',
    ));

    $ip_count     = 0;
    $email_count  = 0;
    $one_hour_ago = time() - 3600;

    foreach ( $recent_orders as $order ) {
        $order_time = $order->get_date_created()->getTimestamp();
        if ( $order_time < $one_hour_ago ) continue;

        if ( $order->get_customer_ip_address() === $ip ) {
            $ip_count++;
        }
        if ( $order->get_billing_email() === $email ) {
            $email_count++;
        }
    }

    return ( $ip_count >= 3 || $email_count >= 3 );

}

function aifd_is_new_account( $customer_id ) {

    if ( $customer_id === 0 ) return false;

    $user = get_user_by( 'id', $customer_id );
    if ( ! $user ) return false;

    $registered = strtotime( $user->user_registered );
    $age_hours  = ( time() - $registered ) / 3600;

    return ( $age_hours < 24 );

}

function aifd_has_multiple_addresses( $email ) {

    $orders = wc_get_orders( array(
        'billing_email' => $email,
        'limit'         => 20,
        'return'        => 'objects',
    ));

    $addresses = array();

    foreach ( $orders as $order ) {
        $address = $order->get_shipping_address_1() . $order->get_shipping_country();
        $addresses[] = md5( strtolower( $address ) );
    }

    $unique_addresses = array_unique( $addresses );

    return ( count( $unique_addresses ) >= 3 );

}

function aifd_get_store_average_order_value() {

    $cached          = get_option( 'aifd_store_average', 0 );
    $last_calculated = get_option( 'aifd_average_calculated', 0 );

    if ( ( time() - $last_calculated ) > WEEK_IN_SECONDS ) {

        $orders = wc_get_orders( array(
            'limit'  => 100,
            'status' => array( 'completed', 'processing' ),
            'return' => 'objects',
        ));

        if ( empty( $orders ) ) return 0;

        $total = array_sum( array_map( function( $o ) {
            return (float) $o->get_total();
        }, $orders ) );

        $average = $total / count( $orders );

        update_option( 'aifd_store_average', $average );
        update_option( 'aifd_average_calculated', time() );

        return $average;

    }

    return $cached;

}

// ══════════════════════════════════════════════════════
// WEIGHTED RISK SCORE CALCULATOR
// ══════════════════════════════════════════════════════

function aifd_calculate_risk_score( $signals ) {

    $weights       = aifd_get_signal_weights();
    $max_possible  = array_sum( $weights );
    $score         = 0;
    $reasons       = array();
    $fired_signals = array();

    $signal_map = array(
        'card_testing_pattern'   => 'Card testing pattern detected',
        'disposable_email'       => 'Disposable or suspicious email',
        'high_velocity'          => 'High order velocity from same customer',
        'address_mismatch'       => 'Billing and shipping country mismatch',
        'freight_forwarder'      => 'Shipping to freight forwarder address',
        'chargeback_history'     => 'Customer has previous chargeback history',
        'first_order_high_value' => 'High value first order from new customer',
        'ip_proxy_vpn'           => 'Order placed via VPN or proxy',
        'risky_country_combo'    => 'High-risk billing/shipping country combination',
        'mismatched_names'       => 'Billing and shipping names differ',
        'no_phone_number'        => 'No phone number provided',
        'payment_method_risk'    => 'Risky payment method used',
        'multiple_addresses'     => 'Multiple shipping addresses for same email',
        'order_total_anomaly'    => 'Order value far above store average',
        'odd_hours'              => 'Order placed between midnight and 5am',
        'guest_checkout'         => 'Guest checkout — no account created',
        'new_account'            => 'Account created less than 24 hours ago',
    );

    foreach ( $signal_map as $signal => $description ) {
        if ( ! empty( $signals[ $signal ] ) ) {
            $weight          = $weights[ $signal ] ?? 0;
            $score          += $weight;
            $reasons[]       = $description . ' (+' . round( $weight * 100 ) . ')';
            $fired_signals[] = $signal;
        }
    }

    // Normalize to 0-100
    $normalized = $max_possible > 0
        ? round( ( $score / $max_possible ) * 100 )
        : 0;

    $normalized = min( 100, $normalized );

    // Get thresholds from settings
    $block_threshold  = (int) get_option( 'aifd_block_threshold', 70 );
    $review_threshold = (int) get_option( 'aifd_review_threshold', 40 );

    if ( $normalized >= $block_threshold ) {
        $risk_level = 'HIGH';
        $decision   = 'BLOCK';
    } elseif ( $normalized >= $review_threshold ) {
        $risk_level = 'MEDIUM';
        $decision   = 'REVIEW';
    } else {
        $risk_level = 'LOW';
        $decision   = 'ALLOW';
    }

    return array(
        'risk_score'    => $normalized,
        'risk_level'    => $risk_level,
        'decision'      => $decision,
        'reasons'       => $reasons,
        'fired_signals' => $fired_signals,
    );

}

// ══════════════════════════════════════════════════════
// AI PROVIDER ROUTER
// ══════════════════════════════════════════════════════

function aifd_call_provider( $signals ) {

    $provider_name = get_option( 'aifd_ai_provider', 'openai' );

    // Use function calling for OpenAI and Anthropic
    if ( in_array( $provider_name, array( 'openai', 'anthropic' ) ) ) {

        $agent = new AIFD_FunctionCalling( $signals );

        $result = ( $provider === 'anthropic' )
            ? $agent->run_anthropic()
            : $agent->run_openai();

        return $result;

    }

    // Search Pinecone (namespace + provider-native embedding handled internally)
    $pinecone_context = aifd_get_pinecone_context( $signals );

    // Build prompt with Pinecone context included
    // Gemini and Ollama use prompt-based approach (no function calling yet)
    $prompt = aifd_build_prompt( $signals, $pinecone_context );

    /*switch ( $provider_name ) {
        case 'anthropic':
            $provider = new AIFD_Provider_Anthropic();
            break;
        case 'gemini':
            $provider = new AIFD_Provider_Gemini();
            break;
        case 'ollama':
            $provider = new AIFD_Provider_Ollama();
            break;
        case 'openai':
        default:
            $provider = new AIFD_Provider_OpenAI();
            break;
    }*/
     switch ( $provider ) {
        case 'gemini':
            $p = new AIFD_Provider_Gemini();
            break;
        case 'ollama':
        default:
            $p = new AIFD_Provider_Ollama();
            break;
    }

    $result = $provider->call( $prompt );

    // Attach Pinecone info for logging
    $result['pinecone_matches'] = count( $pinecone_context['matches'] ?? array() );
    $result['pinecone_context'] = $pinecone_context['context_summary'] ?? '';

    return $result;

}

function aifd_build_prompt( $signals, $pinecone_context = array() ) {

    $context_text = '';

    if ( ! empty( $pinecone_context['available'] ) && ! empty( $pinecone_context['context_summary'] ) ) {
        $context_text = "

HISTORICAL PATTERN CONTEXT FROM VECTOR DATABASE:
" . $pinecone_context['context_summary'] . "

Use this historical context alongside the order signals to make a more informed decision.";
    }

    return "You are a fraud detection expert for an ecommerce store.

Analyze this order and return a JSON fraud risk assessment.

ORDER SIGNALS:
- Order Total: $" . $signals['order_total'] . "
- Customer Email: " . $signals['customer_email'] . "
- Billing Country: " . $signals['billing_country'] . "
- Shipping Country: " . $signals['shipping_country'] . "
- Address Mismatch: " . ( $signals['address_mismatch'] ? 'YES' : 'NO' ) . "
- Disposable Email: " . ( $signals['disposable_email'] ? 'YES' : 'NO' ) . "
- Card Testing Pattern: " . ( $signals['card_testing_pattern'] ? 'YES' : 'NO' ) . "
- Freight Forwarder: " . ( $signals['freight_forwarder'] ? 'YES' : 'NO' ) . "
- High Velocity: " . ( $signals['high_velocity'] ? 'YES' : 'NO' ) . "
- Risky Country Combo: " . ( $signals['risky_country_combo'] ? 'YES' : 'NO' ) . "
- Chargeback History: " . ( $signals['chargeback_history'] ? 'YES' : 'NO' ) . "
- New Customer: " . ( $signals['is_new_customer'] ? 'YES' : 'NO' ) . "
- Previous Orders: " . $signals['previous_order_count'] . "
- First Order High Value: " . ( $signals['first_order_high_value'] ? 'YES' : 'NO' ) . "
- Order Total Anomaly: " . ( $signals['order_total_anomaly'] ? 'YES' : 'NO' ) . "
- Store Average: $" . round( $signals['store_average'] ?? 0 ) . "
- Has Phone: " . ( $signals['has_phone'] ? 'YES' : 'NO' ) . "
- Mismatched Names: " . ( $signals['mismatched_names'] ? 'YES' : 'NO' ) . "
- Odd Hours: " . ( $signals['odd_hours'] ? 'YES' : 'NO' ) . "
- Guest Checkout: " . ( $signals['guest_checkout'] ? 'YES' : 'NO' ) . "
- Payment Method: " . $signals['payment_method'] . "
" . $context_text . "

Return ONLY valid JSON — no extra text:
{
  \"risk_score\": 75,
  \"risk_level\": \"HIGH\",
  \"decision\": \"BLOCK\",
  \"reason\": \"Plain English explanation of why this order is suspicious\"
}

Risk levels: LOW (0-39), MEDIUM (40-69), HIGH (70-100)
Decisions: ALLOW, REVIEW, BLOCK";

}

/*function aifd_build_prompt( $signals ) {

    return "You are a fraud detection expert for an ecommerce store.

Analyze this order and return a JSON fraud risk assessment.

Order signals:
- Order Total: $" . $signals['order_total'] . "
- Customer Email: " . $signals['customer_email'] . "
- Billing Country: " . $signals['billing_country'] . "
- Shipping Country: " . $signals['shipping_country'] . "
- Address Mismatch: " . ( $signals['address_mismatch'] ? 'YES' : 'NO' ) . "
- Disposable Email: " . ( $signals['disposable_email'] ? 'YES' : 'NO' ) . "
- Card Testing Pattern: " . ( $signals['card_testing_pattern'] ? 'YES' : 'NO' ) . "
- Freight Forwarder: " . ( $signals['freight_forwarder'] ? 'YES' : 'NO' ) . "
- High Velocity: " . ( $signals['high_velocity'] ? 'YES' : 'NO' ) . "
- Risky Country Combo: " . ( $signals['risky_country_combo'] ? 'YES' : 'NO' ) . "
- New Customer: " . ( $signals['is_new_customer'] ? 'YES' : 'NO' ) . "
- Previous Orders: " . $signals['previous_order_count'] . "
- Chargeback History: " . ( $signals['chargeback_history'] ? 'YES' : 'NO' ) . "
- First Order High Value: " . ( $signals['first_order_high_value'] ? 'YES' : 'NO' ) . "
- Order Total Anomaly: " . ( $signals['order_total_anomaly'] ? 'YES' : 'NO' ) . "
- Store Average Order Value: $" . round( $signals['store_average'] ?? 0 ) . "
- Has Phone: " . ( $signals['has_phone'] ? 'YES' : 'NO' ) . "
- Mismatched Names: " . ( $signals['mismatched_names'] ? 'YES' : 'NO' ) . "
- Odd Hours: " . ( $signals['odd_hours'] ? 'YES' : 'NO' ) . "
- Guest Checkout: " . ( $signals['guest_checkout'] ? 'YES' : 'NO' ) . "
- Payment Method: " . $signals['payment_method'] . "

Return ONLY valid JSON — no extra text:
{
  \"risk_score\": 75,
  \"risk_level\": \"HIGH\",
  \"decision\": \"BLOCK\",
  \"reason\": \"Plain English explanation of why this order is suspicious\"
}

Risk levels: LOW (0-39), MEDIUM (40-69), HIGH (70-100)
Decisions: ALLOW, REVIEW, BLOCK";

}*/

// ══════════════════════════════════════════════════════
// PER-MERCHANT WEIGHT ADJUSTMENT
// ══════════════════════════════════════════════════════

function aifd_adjust_weights_on_override( $fired_signals ) {

    $weights = aifd_get_signal_weights();
    $delta   = 0.02;
    $floor   = 0.01;

    foreach ( $fired_signals as $signal ) {
        if ( isset( $weights[ $signal ] ) ) {
            $weights[ $signal ] = max( $floor, $weights[ $signal ] - $delta );
        }
    }

    update_option( 'aifd_merchant_weights', $weights );

}

// ══════════════════════════════════════════════════════
// ORDER STATUS + META SAVE
// ══════════════════════════════════════════════════════

function aifd_update_order_status( $order_id, $ai_result, $risk_result ) {

    $order    = wc_get_order( $order_id );
    $decision = $ai_result['decision'];
    $score    = $ai_result['risk_score'];
    $reason   = $ai_result['reason'];
    $level    = $ai_result['risk_level'];

    // Save AI results as order meta
    $order->update_meta_data( '_aifd_risk_score',     $score );
    $order->update_meta_data( '_aifd_risk_level',     $level );
    $order->update_meta_data( '_aifd_decision',       $decision );
    $order->update_meta_data( '_aifd_reason',         $reason );
    $order->update_meta_data( '_aifd_provider',       $ai_result['source'] ?? 'unknown' );
    $order->update_meta_data( '_aifd_review_status',  'PENDING' );
    $order->update_meta_data( '_aifd_fired_signals',  $risk_result['fired_signals'] ?? array() );

    // Update stats counters
    if ( $decision === 'BLOCK' ) {
        update_option( 'aifd_total_blocked',
            get_option( 'aifd_total_blocked', 0 ) + 1 );
        update_option( 'aifd_total_saved',
            get_option( 'aifd_total_saved', 0 ) + floatval( $order->get_total() ) );
    } elseif ( $decision === 'REVIEW' ) {
        update_option( 'aifd_total_review',
            get_option( 'aifd_total_review', 0 ) + 1 );
    } else {
        update_option( 'aifd_total_allowed',
            get_option( 'aifd_total_allowed', 0 ) + 1 );
    }

    // Update WooCommerce order status
    if ( $decision === 'BLOCK' ) {
        $order->update_status( 'on-hold',
            'AI Fraud Detector: HIGH RISK blocked. Score: ' . $score . '/100.' );
        $order->add_order_note(
            '🚨 FRAUD ALERT: Blocked by AI Fraud Detector. Score: ' . $score . '/100. Reason: ' . $reason,
            false
        );
    } elseif ( $decision === 'REVIEW' ) {
        $order->add_order_note(
            '⚠️ FRAUD WARNING: Flagged for review. Score: ' . $score . '/100. Reason: ' . $reason,
            false
        );
    } else {
        $order->add_order_note(
            '✅ FRAUD CHECK PASSED. Score: ' . $score . '/100.',
            false
        );
    }

    $order->save();

}

// ══════════════════════════════════════════════════════
// EMAIL ALERT
// ══════════════════════════════════════════════════════

function aifd_send_alert_email( $order_id, $ai_result ) {

    if ( $ai_result['decision'] === 'ALLOW' ) return;

    $admin_email = get_option( 'admin_email' );
    $order       = wc_get_order( $order_id );
    $decision    = $ai_result['decision'];
    $score       = $ai_result['risk_score'];
    $reason      = $ai_result['reason'];
    $provider    = $ai_result['source'] ?? 'AI';

    $subject = $decision === 'BLOCK'
        ? '🚨 FRAUD ALERT: Order #' . $order_id . ' Blocked — Score ' . $score . '/100'
        : '⚠️ FRAUD WARNING: Order #' . $order_id . ' Needs Review — Score ' . $score . '/100';

    $message  = "AI Fraud Detector has flagged an order.\n\n";
    $message .= "Order ID:    #" . $order_id . "\n";
    $message .= "Decision:    " . $decision . "\n";
    $message .= "Risk Score:  " . $score . "/100\n";
    $message .= "AI Provider: " . strtoupper( $provider ) . "\n";
    $message .= "Reason:      " . $reason . "\n\n";
    $message .= "Customer:    " . $order->get_billing_first_name() . " " . $order->get_billing_last_name() . "\n";
    $message .= "Email:       " . $order->get_billing_email() . "\n";
    $message .= "Total:       $" . $order->get_total() . "\n";
    $message .= "Billing:     " . $order->get_billing_country() . "\n";
    $message .= "Shipping:    " . $order->get_shipping_country() . "\n\n";
    $message .= "View Order:  " . admin_url( 'post.php?post=' . $order_id . '&action=edit' ) . "\n\n";
    $message .= "---\nAI Fraud Detector Plugin\n";

    wp_mail( $admin_email, $subject, $message );

}

/**
 * Get Pinecone context for order signals
*/

/**
 * Get Pinecone context for order signals
 */
function aifd_get_pinecone_context( $signals ) {

    $pinecone = new AIFD_Pinecone();

    if ( ! $pinecone->is_configured() ) {
        return array(
            'available'       => false,
            'matches'         => array(),
            'context_summary' => 'Pinecone not configured',
        );
    }

    $matches = $pinecone->search_similar_patterns( $signals, 5 );

    if ( empty( $matches ) ) {
        return array(
            'available'       => true,
            'matches'         => array(),
            'context_summary' => 'No similar patterns found in history',
        );
    }

    $fraud_matches = array();
    $legit_matches = array();

    foreach ( $matches as $match ) {
        $metadata   = $match['metadata'] ?? array();
        $fraud_type = $metadata['fraud_type'] ?? 'unknown';
        $score      = round( $match['score'] ?? 0, 3 );
        $times_seen = $metadata['times_seen'] ?? 0;
        $confidence = $metadata['confidence'] ?? 0;

        if ( $fraud_type === 'legitimate' ) {
            $legit_matches[] = array(
                'score'      => $score,
                'times_seen' => $times_seen,
                'confidence' => $confidence,
            );
        } else {
            $fraud_matches[] = array(
                'fraud_type'  => $fraud_type,
                'score'       => $score,
                'times_seen'  => $times_seen,
                'confidence'  => $confidence,
                'description' => $metadata['description'] ?? '',
            );
        }
    }

    $summary_parts = array();

    if ( ! empty( $fraud_matches ) ) {
        $top = $fraud_matches[0];
        $summary_parts[] = "Similar fraud pattern found: {$top['fraud_type']} " .
                          "(similarity: {$top['score']}, seen {$top['times_seen']} times, " .
                          "confidence: {$top['confidence']})";

        if ( ! empty( $top['description'] ) ) {
            $summary_parts[] = "Pattern description: {$top['description']}";
        }
    }

    if ( ! empty( $legit_matches ) ) {
        $top = $legit_matches[0];
        $summary_parts[] = "Also matches legitimate pattern " .
                          "(similarity: {$top['score']}, seen {$top['times_seen']} times)";
    }

    if ( empty( $summary_parts ) ) {
        $summary_parts[] = 'No strong pattern matches found';
    }

    return array(
        'available'       => true,
        'matches'         => $matches,
        'fraud_matches'   => $fraud_matches,
        'legit_matches'   => $legit_matches,
        'context_summary' => implode( '. ', $summary_parts ),
    );

}

// ══════════════════════════════════════════════════════
// LOGGING
// ══════════════════════════════════════════════════════

function aifd_log_order_with_ai( $order_id, $signals, $rule_result, $ai_result ) {
    write_log( "Logging order #$order_id with AI result: " . json_encode( $ai_result ) );

    $log_file = AIFD_PATH . 'fraud-log.txt';

    $log_entry  = "========================================\n";
    $log_entry .= date( 'Y-m-d H:i:s' ) . ' - Order #' . $order_id . "\n";
    $log_entry .= "========================================\n";
    $log_entry .= "🤖 AI SCORING (" . strtoupper( $ai_result['source'] ?? 'unknown' ) . "):\n";
    $log_entry .= "   Score:    " . $ai_result['risk_score'] . "/100\n";
    $log_entry .= "   Level:    " . $ai_result['risk_level'] . "\n";
    $log_entry .= "   Decision: " . $ai_result['decision'] . "\n";
    $log_entry .= "   Reason:   " . $ai_result['reason'] . "\n";

    // Show function calling bonuses if available
    if ( isset( $ai_result['pattern_boost'] ) ) {
        $log_entry .= "   Breakdown:\n";
        $log_entry .= "     Raw signal score:   " . ( $ai_result['signal_score']      ?? 0 ) . "/100\n";
        $log_entry .= "     Calibrated signal:  " . ( $ai_result['calibrated_signal'] ?? 0 ) . "/100\n";
        $log_entry .= "     Pattern boost:      +" . ( $ai_result['pattern_boost']    ?? 0 ) . "\n";
        $log_entry .= "     Velocity boost:     +" . ( $ai_result['velocity_boost']   ?? 0 ) . "\n";
    }
    $log_entry .= "----------------------------------------\n";
    // Replace the PINECONE CONTEXT section with this:
    $log_entry .= "🔍 PINECONE CONTEXT:\n";

    // Function calling handles Pinecone internally
    if ( isset( $ai_result['pattern_boost'] ) ) {
        $boost = (int) ( $ai_result['pattern_boost'] ?? 0 );
        if ( $boost > 0 ) {
            $log_entry .= "   Status:   Searched via function calling agent ✅\n";
            $log_entry .= "   Boost:    +" . $boost . " points from pattern match\n";
        } else {
            $log_entry .= "   Status:   Searched via function calling — no strong match\n";
        }
    } elseif ( isset( $ai_result['pinecone_matches'] ) ) {
        // Old non-function-calling path
        $log_entry .= "   Matches:  " . $ai_result['pinecone_matches'] . " similar patterns found\n";
        $log_entry .= "   Context:  " . ( $ai_result['pinecone_context'] ?? 'None' ) . "\n";
    } else {
        $log_entry .= "   Status:   Not searched\n";
    }

    $log_entry .= "----------------------------------------\n";

    $log_entry .= "----------------------------------------\n";
    $log_entry .= "📊 SIGNALS:\n";
    $log_entry .= json_encode( $signals, JSON_PRETTY_PRINT ) . "\n\n";

    file_put_contents( $log_file, $log_entry, FILE_APPEND );

}