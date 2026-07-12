<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ══════════════════════════════════════════════════════
// CAPABILITY GATES — Local-first, opt-in AI/vector layers
// ══════════════════════════════════════════════════════

function aifd_tier1_ready() {

    if ( ! get_option( 'aifd_pinecone_key' ) )  return false;
    if ( ! get_option( 'aifd_pinecone_host' ) ) return false;

    // Circuit breaker — skip if Pinecone failed recently
    if ( get_transient( 'aifd_pinecone_circuit_open' ) ) return false;

    return true;

}

function aifd_tier2_ready() {

    $provider = get_option( 'aifd_ai_provider', 'openai' );

    $key_map = array(
        'openai'    => 'aifd_openai_key',
        'anthropic' => 'aifd_anthropic_key',
        'gemini'    => 'aifd_gemini_key',
        'ollama'    => null, // no key needed, but host must be set
    );

    if ( $provider === 'ollama' ) {
        if ( ! get_option( 'aifd_ollama_host' ) ) return false;
    } else {
        $key_option = $key_map[ $provider ] ?? null;
        if ( $key_option && ! get_option( $key_option ) ) return false;
    }

    // Circuit breaker — skip if provider failed recently
    if ( get_transient( 'aifd_provider_circuit_open' ) ) return false;

    return true;

}

/**
 * Trip the circuit breaker for 10 minutes after repeated failures
 */
function aifd_trip_circuit( $key ) {
    $fail_count = (int) get_transient( $key . '_fails' ) + 1;
    set_transient( $key . '_fails', $fail_count, 5 * MINUTE_IN_SECONDS );

    if ( $fail_count >= 3 ) {
        set_transient( $key . '_circuit_open', true, 10 * MINUTE_IN_SECONDS );
    }
}

// ══════════════════════════════════════════════════════
// MAIN HOOK
// ══════════════════════════════════════════════════════

add_action( 'woocommerce_new_order', 'aifd_catch_new_order', 10, 1 );
// Add NEW hook to enforce status AFTER payment gateway
add_action( 'woocommerce_order_status_changed', 'aifd_enforce_block_status', 99, 3 );

add_action( 'woocommerce_payment_complete', 'aifd_enforce_block_on_payment', 99, 1 );

// function aifd_catch_new_order( $order_id ) {

//     $order = wc_get_order( $order_id );

//     // Extract 16 fraud signals
//     $fraud_signals = aifd_extract_signals( $order );

//     // Get AI score via selected provider
//     $ai_result = aifd_call_provider( $fraud_signals );

//     // Get rule based backup score
//     $risk_result = aifd_calculate_risk_score( $fraud_signals );

//     // Update order + save meta
//     aifd_update_order_status( $order_id, $ai_result, $risk_result );

//     // Send email alert
//     aifd_send_alert_email( $order_id, $ai_result );

//     // Log
//     aifd_log_order_with_ai( $order_id, $fraud_signals, $risk_result, $ai_result );

// }

function aifd_catch_new_order( $order_id ) {

    $order = wc_get_order( $order_id );

    // Extract signals — always runs, no external calls
    $fraud_signals = aifd_extract_signals( $order );

    // ── Tier 0 — Local scoring (ALWAYS runs, never fails) ──
    $risk_result = aifd_calculate_risk_score( $fraud_signals );

    // Start with local result as the source of truth
    $final_result = array(
        'risk_score' => $risk_result['risk_score'],
        'risk_level' => $risk_result['risk_level'],
        'decision'   => $risk_result['decision'],
        'reason'     => implode( '. ', $risk_result['reasons'] ) ?: __( 'No risk signals detected.', 'ai-fraud-detector' ),
        'source'     => 'local_rules',
    );

    $pinecone_context = array( 'available' => false );

    // ── Tier 1 — Vector memory (opt-in) ─────────────────────
    if ( aifd_tier1_ready() ) {
        try {
            $pinecone_context = aifd_get_pinecone_context( $fraud_signals );
        } catch ( Exception $e ) {
            aifd_trip_circuit( 'aifd_pinecone' );
        }
    }

    // ── Tier 2 — AI reasoning (opt-in) ──────────────────────
    if ( aifd_tier2_ready() ) {
        try {
            $ai_result = aifd_call_provider( $fraud_signals );

            // Only trust the AI result if it didn't fall back internally
            if ( ( $ai_result['source'] ?? '' ) !== 'fallback' ) {
                $final_result = $ai_result;
            } else {
                aifd_trip_circuit( 'aifd_provider' );
            }
        } catch ( Exception $e ) {
            aifd_trip_circuit( 'aifd_provider' );
        }
    }

    // Update order + notify — always runs, regardless of which tier produced the score
    aifd_update_order_status( $order_id, $final_result, $risk_result );
    aifd_send_alert_email( $order_id, $final_result );
    aifd_log_order_with_ai( $order_id, $fraud_signals, $risk_result, $final_result );

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

    // Same country = never risky
    if ( $billing === $shipping ) return false;

    // Get store's home country from WooCommerce settings
    $store_country = WC()->countries->get_base_country();
    // Returns 'IN' for India, 'QA' for Qatar, 'US' for USA etc

    // Never flag if shipping TO store's home country
    // These are likely legitimate local customers
    if ( $shipping === $store_country ) return false;

    // Never flag if billing FROM store's home country
    if ( $billing === $store_country ) return false;

    $high_risk_countries = array(
        'NG', 'GH', 'CI', 'CM', 'SN', // Africa
        'RU', 'UA', 'BY', 'RO', 'BG', // Eastern Europe
        'CN', 'VN', 'ID', 'PH', 'MY', // Southeast Asia
        'PK', 'BD',                     // South Asia
        'BR', 'CO', 'MX', 'VE',        // Latin America
    );

    $high_value_destinations = array(
        'US', 'GB', 'CA', 'AU',
        'DE', 'FR', 'NL', 'CH',
        'SE', 'NO', 'DK', 'FI',
        'JP', 'SG', 'HK', 'AE',
        'QA', 'SA', 'KW',              // Gulf countries
    );

    // Remove store country from both lists
    // So store's home country is never flagged
    $high_risk_countries      = array_diff( $high_risk_countries,      array( $store_country ) );
    $high_value_destinations  = array_diff( $high_value_destinations,  array( $store_country ) );

    // Flag if billing from high risk → shipping to high value
    if (
        in_array( $billing, $high_risk_countries ) &&
        in_array( $shipping, $high_value_destinations )
    ) {
        return true;
    }

    // Flag reverse — billing in rich country → shipping to fraud hub
    if (
        in_array( $shipping, $high_risk_countries ) &&
        in_array( $billing, $high_value_destinations )
    ) {
        return true;
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
        'card_testing_pattern'   => __( 'Card testing pattern detected', 'ai-fraud-detector' ),
        'disposable_email'       => __( 'Disposable or suspicious email', 'ai-fraud-detector' ),
        'high_velocity'          => __( 'High order velocity from same customer', 'ai-fraud-detector' ),
        'address_mismatch'       => __( 'Billing and shipping country mismatch', 'ai-fraud-detector' ),
        'freight_forwarder'      => __( 'Shipping to freight forwarder address', 'ai-fraud-detector' ),
        'chargeback_history'     => __( 'Customer has previous chargeback history', 'ai-fraud-detector' ),
        'first_order_high_value' => __( 'High value first order from new customer', 'ai-fraud-detector' ),
        'ip_proxy_vpn'           => __( 'Order placed via VPN or proxy', 'ai-fraud-detector' ),
        'risky_country_combo'    => __( 'High-risk billing/shipping country combination', 'ai-fraud-detector' ),
        'mismatched_names'       => __( 'Billing and shipping names differ', 'ai-fraud-detector' ),
        'no_phone_number'        => __( 'No phone number provided', 'ai-fraud-detector' ),
        'payment_method_risk'    => __( 'Risky payment method used', 'ai-fraud-detector' ),
        'multiple_addresses'     => __( 'Multiple shipping addresses for same email', 'ai-fraud-detector' ),
        'order_total_anomaly'    => __( 'Order value far above store average', 'ai-fraud-detector' ),
        'odd_hours'              => __( 'Order placed between midnight and 5am', 'ai-fraud-detector' ),
        'guest_checkout'         => __( 'Guest checkout — no account created', 'ai-fraud-detector' ),
        'new_account'            => __( 'Account created less than 24 hours ago', 'ai-fraud-detector' ),
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

    $weights  = aifd_get_signal_weights();
    $delta    = 0.02;
    $floor    = 0.01;

    // Track override counts per signal
    $override_counts = get_option( 'aifd_signal_override_counts', array() );

    foreach ( $fired_signals as $signal ) {
        if ( isset( $weights[ $signal ] ) ) {

            // Reduce weight
            $weights[ $signal ] = max( $floor, $weights[ $signal ] - $delta );

            // Increment override count for this signal
            $override_counts[ $signal ] = ( $override_counts[ $signal ] ?? 0 ) + 1;
        }
    }

    update_option( 'aifd_merchant_weights', $weights );
    update_option( 'aifd_signal_override_counts', $override_counts );

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
            /* translators: %d: fraud risk score out of 100 */
            sprintf( __( 'AI Fraud Detector: HIGH RISK blocked. Score: %d/100.', 'ai-fraud-detector' ), $score ) );
        $order->add_order_note(
            /* translators: 1: fraud risk score out of 100, 2: reason for the decision */
            '🚨 ' . sprintf( __( 'FRAUD ALERT: Blocked by AI Fraud Detector. Score: %1$d/100. Reason: %2$s', 'ai-fraud-detector' ), $score, $reason ),
            false
        );
    } elseif ( $decision === 'REVIEW' ) {
        $order->add_order_note(
            /* translators: 1: fraud risk score out of 100, 2: reason for the decision */
            '⚠️ ' . sprintf( __( 'FRAUD WARNING: Flagged for review. Score: %1$d/100. Reason: %2$s', 'ai-fraud-detector' ), $score, $reason ),
            false
        );
    } else {
        $order->add_order_note(
            /* translators: %d: fraud risk score out of 100 */
            '✅ ' . sprintf( __( 'FRAUD CHECK PASSED. Score: %d/100.', 'ai-fraud-detector' ), $score ),
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
        /* translators: 1: order ID, 2: fraud risk score out of 100 */
        ? '🚨 ' . sprintf( __( 'FRAUD ALERT: Order #%1$d Blocked — Score %2$d/100', 'ai-fraud-detector' ), $order_id, $score )
        /* translators: 1: order ID, 2: fraud risk score out of 100 */
        : '⚠️ ' . sprintf( __( 'FRAUD WARNING: Order #%1$d Needs Review — Score %2$d/100', 'ai-fraud-detector' ), $order_id, $score );

    $message  = __( 'AI Fraud Detector has flagged an order.', 'ai-fraud-detector' ) . "\n\n";
    $message .= __( 'Order ID:', 'ai-fraud-detector' ) . "    #" . $order_id . "\n";
    $message .= __( 'Decision:', 'ai-fraud-detector' ) . "    " . $decision . "\n";
    $message .= __( 'Risk Score:', 'ai-fraud-detector' ) . "  " . $score . "/100\n";
    $message .= __( 'AI Provider:', 'ai-fraud-detector' ) . " " . strtoupper( $provider ) . "\n";
    $message .= __( 'Reason:', 'ai-fraud-detector' ) . "      " . $reason . "\n\n";
    $message .= __( 'Customer:', 'ai-fraud-detector' ) . "    " . $order->get_billing_first_name() . " " . $order->get_billing_last_name() . "\n";
    $message .= __( 'Email:', 'ai-fraud-detector' ) . "       " . $order->get_billing_email() . "\n";
    $message .= __( 'Total:', 'ai-fraud-detector' ) . "       " . $order->get_currency() . " " . $order->get_total() . "\n";
    $message .= __( 'Billing:', 'ai-fraud-detector' ) . "     " . $order->get_billing_country() . "\n";
    $message .= __( 'Shipping:', 'ai-fraud-detector' ) . "    " . $order->get_shipping_country() . "\n\n";
    $message .= __( 'View Order:', 'ai-fraud-detector' ) . "  " . admin_url( 'post.php?post=' . $order_id . '&action=edit' ) . "\n\n";
    $message .= "---\n" . __( 'AI Fraud Detector Plugin', 'ai-fraud-detector' ) . "\n";

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

/**
 * Save confirmed fraud pattern to Pinecone
 */
function aifd_save_confirmed_pattern_to_pinecone( $order_id ) {

    $order    = wc_get_order( $order_id );
    $pinecone = new AIFD_Pinecone();

    if ( ! $pinecone->is_configured() ) {
        return false;
    }

    // Get signals that fired on this order
    $fired_signals = $order->get_meta( '_aifd_fired_signals' );
    $risk_score    = $order->get_meta( '_aifd_risk_score' );
    $reason        = $order->get_meta( '_aifd_reason' );
    $provider      = get_option( 'aifd_ai_provider', 'openai' );

    if ( empty( $fired_signals ) ) {
        return false;
    }

    // Build pattern text from fired signals
    $pattern_text = implode( ' ', $fired_signals );

    // Add payment method + country context
    $payment = $order->get_payment_method();
    $billing = $order->get_billing_country();
    $shipping = $order->get_shipping_country();

    if ( ! empty( $payment ) ) {
        $pattern_text .= ' ' . $payment . '_payment';
    }
    if ( ! empty( $billing ) && ! empty( $shipping ) ) {
        $pattern_text .= ' ' . $billing . '_to_' . $shipping;
    }

    // Embed using provider-native model
    $embeddings = new AIFD_Embeddings();
    $embedding  = $embeddings->embed( $pattern_text, $provider );

    if ( ! $embedding ) {
        return false;
    }

    // Get merchant namespace
    $namespace = $embeddings->get_namespace( $provider );

    // Store in Pinecone with high confidence
    $success = $pinecone->upsert(
        'confirmed_' . $order_id . '_' . $provider,
        $embedding,
        array(
            'pattern'     => $pattern_text,
            'fraud_type'  => 'confirmed_fraud',
            'confidence'  => 1.0,
            'times_seen'  => 1,
            'source'      => 'merchant_confirmed',
            'provider'    => $provider,
            'order_id'    => $order_id,
            'risk_score'  => $risk_score,
            'description' => 'Merchant confirmed fraud — order #' . $order_id . '. ' . substr( $reason ?? '', 0, 200 ),
        ),
        $namespace
    );

    if ( $success ) {
        // Update times_seen for similar existing patterns
        aifd_increment_similar_pattern_count( $order_id, $pinecone, $embedding, $namespace );
    }

    return $success;

}

/**
 * Increment times_seen for similar existing patterns
 * Makes the vector store smarter over time
 */
function aifd_increment_similar_pattern_count( $order_id, $pinecone, $embedding, $namespace ) {

    // Find the closest existing pattern
    $matches = $pinecone->query( $embedding, 1, $namespace );

    if ( empty( $matches ) ) return;

    $top_match = $matches[0];
    $similarity = $top_match['score'] ?? 0;

    // Only update if very similar (>0.90) and not the pattern we just saved
    if ( $similarity < 0.90 ) return;
    if ( strpos( $top_match['id'], 'confirmed_' . $order_id ) !== false ) return;

    $metadata = $top_match['metadata'] ?? array();
    $times_seen = (int) ( $metadata['times_seen'] ?? 1 );

    // Re-upsert with incremented times_seen
    $pinecone->upsert(
        $top_match['id'],
        $top_match['values'] ?? $embedding, // use original vector if available
        array_merge( $metadata, array(
            'times_seen' => $times_seen + 1,
        )),
        $namespace
    );

}

function aifd_enforce_block_status( $order_id, $old_status, $new_status ) {

    // Only care about orders moving TO processing
    if ( $new_status !== 'processing' ) return;

    $order    = wc_get_order( $order_id );
    $decision = $order->get_meta( '_aifd_decision' );

    // If our AI said BLOCK → override back to on-hold!
    if ( $decision === 'BLOCK' ) {

        // Remove this action temporarily to avoid infinite loop
        remove_action( 'woocommerce_order_status_changed', 'aifd_enforce_block_status', 99 );

        $order->update_status(
            'on-hold',
            /* translators: %d: fraud risk score out of 100 */
            '🚨 ' . sprintf( __( 'AI Fraud Detector: Order blocked (score: %d/100). Payment gateway override prevented.', 'ai-fraud-detector' ), (int) $order->get_meta( '_aifd_risk_score' ) )
        );

        $order->save();

        // Re-add the action
        add_action( 'woocommerce_order_status_changed', 'aifd_enforce_block_status', 99, 3 );

    }

}

function aifd_enforce_block_on_payment( $order_id ) {

    $order    = wc_get_order( $order_id );
    $decision = $order->get_meta( '_aifd_decision' );

    if ( $decision === 'BLOCK' ) {

        $order->update_status(
            'on-hold',
            '🚨 ' . __( 'AI Fraud Detector: Payment received but order blocked due to HIGH fraud risk.', 'ai-fraud-detector' )
        );

        $order->save();

    }

}

// ══════════════════════════════════════════════════════
// LOGGING
// ══════════════════════════════════════════════════
function aifd_log_order_with_ai( $order_id, $signals, $rule_result, $ai_result ) {

    if ( ! function_exists( 'wc_get_logger' ) ) {
        return;
    }

    // Never log raw PII — the signal booleans carry the risk info.
    unset( $signals['customer_email'], $signals['customer_ip'], $signals['billing_phone'] );

    $log_entry  = "Order #" . $order_id . "\n";
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
    $log_entry .= "📊 SIGNALS:\n";
    $log_entry .= wp_json_encode( $signals, JSON_PRETTY_PRINT );

    wc_get_logger()->info( $log_entry, array( 'source' => 'ai-fraud-detector' ) );

}