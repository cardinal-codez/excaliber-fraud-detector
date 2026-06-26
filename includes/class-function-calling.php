<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIFD_FunctionCalling {

    private $signals;
    private $pinecone;
    private $max_iterations = 10;

    public function __construct( $signals ) {
        $this->signals  = $signals;
        $this->pinecone = new AIFD_Pinecone();
    }

    // ══════════════════════════════════════════
    // TOOL DEFINITIONS
    // ══════════════════════════════════════════

    public function get_tools() {
        return array(
            array(
                'type'     => 'function',
                'function' => array(
                    'name'        => 'check_signal_weights',
                    'description' => 'Evaluate the 17 fraud signals using merchant-personalized weights. Returns a weighted risk score and list of triggered signals.',
                    'parameters'  => array(
                        'type'       => 'object',
                        'properties' => array(
                            'include_reasons' => array(
                                'type'        => 'boolean',
                                'description' => 'Whether to include human-readable reasons for each triggered signal',
                            ),
                        ),
                        'required' => array(),
                    ),
                ),
            ),
            array(
                'type'     => 'function',
                'function' => array(
                    'name'        => 'search_fraud_patterns',
                    'description' => 'Search the Pinecone vector store for historically confirmed fraud patterns similar to this order. Returns similarity scores and fraud type descriptions.',
                    'parameters'  => array(
                        'type'       => 'object',
                        'properties' => array(
                            'top_k' => array(
                                'type'        => 'integer',
                                'description' => 'Number of similar patterns to return (1-10)',
                            ),
                        ),
                        'required' => array(),
                    ),
                ),
            ),
            array(
                'type'     => 'function',
                'function' => array(
                    'name'        => 'check_velocity',
                    'description' => 'Check if this customer or IP address has placed multiple orders in a short time window — a key indicator of card testing fraud.',
                    'parameters'  => array(
                        'type'       => 'object',
                        'properties' => array(
                            'window_minutes' => array(
                                'type'        => 'integer',
                                'description' => 'Time window to check in minutes (default 60)',
                            ),
                        ),
                        'required' => array(),
                    ),
                ),
            ),
            array(
                'type'     => 'function',
                'function' => array(
                    'name'        => 'calculate_final_risk',
                    'description' => 'Calculate the final risk score and decision. Call this last after checking signals, patterns, and velocity.',
                    'parameters'  => array(
                        'type'       => 'object',
                        'properties' => array(
                            'signal_score'    => array(
                                'type'        => 'number',
                                'description' => 'Weighted signal score from check_signal_weights',
                            ),
                            'pattern_score'   => array(
                                'type'        => 'number',
                                'description' => 'Highest similarity score from search_fraud_patterns (0-1)',
                            ),
                            'pattern_type'    => array(
                                'type'        => 'string',
                                'description' => 'Fraud type from the most similar pattern found',
                            ),
                            'velocity_risk'   => array(
                                'type'        => 'boolean',
                                'description' => 'Whether velocity check detected suspicious behavior',
                            ),
                            'reasoning'       => array(
                                'type'        => 'string',
                                'description' => 'Plain English explanation combining all evidence',
                            ),
                        ),
                        'required' => array( 'signal_score', 'reasoning' ),
                    ),
                ),
            ),
        );
    }

    // ══════════════════════════════════════════
    // TOOL EXECUTION
    // ══════════════════════════════════════════

    public function execute_tool( $name, $args ) {

        switch ( $name ) {
            case 'check_signal_weights':
                return $this->tool_check_signal_weights( $args );
            case 'search_fraud_patterns':
                return $this->tool_search_fraud_patterns( $args );
            case 'check_velocity':
                return $this->tool_check_velocity( $args );
            case 'calculate_final_risk':
                return $this->tool_calculate_final_risk( $args );
            default:
                return array( 'error' => 'Unknown tool: ' . $name );
        }

    }

    // ── Tool 1: check_signal_weights ────────────

    private function tool_check_signal_weights( $args ) {

        $risk_result    = aifd_calculate_risk_score( $this->signals );
        $include_reasons = $args['include_reasons'] ?? true;

        $result = array(
            'weighted_score'  => $risk_result['risk_score'],
            'risk_level'      => $risk_result['risk_level'],
            'fired_signals'   => $risk_result['fired_signals'],
            'signal_count'    => count( $risk_result['fired_signals'] ),
        );

        if ( $include_reasons ) {
            $result['reasons'] = $risk_result['reasons'];
        }

        return $result;

    }

    // ── Tool 2: search_fraud_patterns ──────────

    private function tool_search_fraud_patterns( $args ) {

        $top_k   = min( 10, max( 1, (int) ( $args['top_k'] ?? 5 ) ) );
        $matches = $this->pinecone->search_similar_patterns( $this->signals, $top_k );

        if ( empty( $matches ) ) {
            return array(
                'found'   => false,
                'message' => 'No similar fraud patterns found in history',
            );
        }

        $results = array();

        foreach ( $matches as $match ) {
            $metadata  = $match['metadata'] ?? array();
            $results[] = array(
                'similarity'  => round( $match['score'] ?? 0, 3 ),
                'fraud_type'  => $metadata['fraud_type'] ?? 'unknown',
                'description' => $metadata['description'] ?? '',
                'times_seen'  => $metadata['times_seen'] ?? 0,
                'confidence'  => $metadata['confidence'] ?? 0,
            );
        }

        return array(
            'found'           => true,
            'pattern_count'   => count( $results ),
            'top_match'       => $results[0],
            'all_matches'     => $results,
        );

    }

    // ── Tool 3: check_velocity ─────────────────

    private function tool_check_velocity( $args ) {

        $window   = (int) ( $args['window_minutes'] ?? 60 );
        $email    = $this->signals['customer_email'] ?? '';
        $ip       = $this->signals['customer_ip'] ?? '';
        $cutoff   = time() - ( $window * 60 );

        $recent_orders = wc_get_orders( array(
            'limit'  => 100,
            'return' => 'objects',
        ));

        $ip_count    = 0;
        $email_count = 0;

        foreach ( $recent_orders as $order ) {
            $order_time = $order->get_date_created()->getTimestamp();
            if ( $order_time < $cutoff ) continue;

            if ( ! empty( $ip ) && $order->get_customer_ip_address() === $ip ) {
                $ip_count++;
            }
            if ( ! empty( $email ) && $order->get_billing_email() === $email ) {
                $email_count++;
            }
        }

        $is_suspicious = ( $ip_count >= 3 || $email_count >= 3 );

        return array(
            'suspicious'        => $is_suspicious,
            'ip_order_count'    => $ip_count,
            'email_order_count' => $email_count,
            'window_minutes'    => $window,
            'message'           => $is_suspicious
                ? "SUSPICIOUS: {$ip_count} orders from same IP, {$email_count} from same email in {$window} minutes"
                : "Normal: {$ip_count} orders from same IP, {$email_count} from same email in {$window} minutes",
        );

    }

    // ── Tool 4: calculate_final_risk ───────────

    private function tool_calculate_final_risk( $args ) {

        $signal_score   = (float) ( $args['signal_score']  ?? 50 );
        $pattern_score  = (float) ( $args['pattern_score'] ?? 0 );
        $pattern_type   = (string) ( $args['pattern_type'] ?? '' );
        $velocity_risk  = (bool) ( $args['velocity_risk']  ?? false );
        $reasoning      = (string) ( $args['reasoning']    ?? '' );

        // Recalibrate signal score
        // Max realistic score with most signals firing ≈ 40
        // Rescale to 0-80 range so patterns and velocity add meaningful boost
        $calibrated_signal = min( 80, round( $signal_score * 2 ) );

        // Boost score if strong Pinecone pattern match
        // Pattern boost
        $pattern_boost = 0;
        if ( $pattern_score >= 0.90 ) {
            $pattern_boost = 20;
        } elseif ( $pattern_score >= 0.80 ) {
            $pattern_boost = 15;
        } elseif ( $pattern_score >= 0.70 ) {
            $pattern_boost = 10;
        } elseif ( $pattern_score >= 0.60 ) {
            $pattern_boost = 5;
        }

        // Boost if velocity suspicious
        $velocity_boost = $velocity_risk ? 10 : 0;

        // Final score
        $final_score = min( 100, $calibrated_signal + $pattern_boost + $velocity_boost );

        // Thresholds from settings
        $block_threshold  = (int) get_option( 'aifd_block_threshold', 70 );
        $review_threshold = (int) get_option( 'aifd_review_threshold', 40 );

        if ( $final_score >= $block_threshold ) {
            $risk_level = 'HIGH';
            $decision   = 'BLOCK';
        } elseif ( $final_score >= $review_threshold ) {
            $risk_level = 'MEDIUM';
            $decision   = 'REVIEW';
        } else {
            $risk_level = 'LOW';
            $decision   = 'ALLOW';
        }

       return array(
            'risk_score'        => $final_score,
            'risk_level'        => $risk_level,
            'decision'          => $decision,
            'reason'            => $reasoning,
            'signal_score'      => $signal_score,
            'calibrated_signal' => $calibrated_signal,
            'pattern_boost'     => $pattern_boost,
            'velocity_boost'    => $velocity_boost,
        );

    }

    // ══════════════════════════════════════════
    // AGENTIC LOOP — OpenAI
    // ══════════════════════════════════════════

    public function run_openai() {

        $api_key = get_option( 'aifd_openai_key', '' );
        $model   = get_option( 'aifd_openai_model', 'gpt-4o-mini' );

        if ( empty( $api_key ) ) {
            return $this->fallback( 'OpenAI key not configured' );
        }

        $messages = array(
            array(
                'role'    => 'system',
                'content' => 'You are a fraud detection agent. Analyze the order using the available tools. Always call check_signal_weights and search_fraud_patterns first, then check_velocity if needed, then calculate_final_risk last. Be thorough.',
            ),
            array(
                'role'    => 'user',
                'content' => $this->build_order_summary(),
            ),
        );

        $tools      = $this->get_tools();
        $iterations = 0;
        $final_result = null;

        while ( $iterations < $this->max_iterations ) {

            $iterations++;

            $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
                'timeout' => 60,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ),
                'body' => json_encode( array(
                    'model'    => $model,
                    'messages' => $messages,
                    'tools'    => $tools,
                )),
            ));

            if ( is_wp_error( $response ) ) {
                return $this->fallback( 'OpenAI request failed' );
            }

            $data    = json_decode( wp_remote_retrieve_body( $response ), true );
            $message = $data['choices'][0]['message'] ?? array();
            $reason  = $data['choices'][0]['finish_reason'] ?? '';

            // Add assistant message to history
            $messages[] = $message;

            // No more tool calls — done!
            if ( $reason === 'stop' || empty( $message['tool_calls'] ) ) {

                // Extract final result from tool call results
                if ( $final_result ) {
                    return array_merge( $final_result, array( 'source' => 'openai_function_calling' ) );
                }

                // Fallback — parse text response
                $content = $message['content'] ?? '';
                $content = preg_replace( '/```json|```/', '', $content );
                $parsed  = json_decode( trim( $content ), true );

                return $parsed
                    ? array_merge( $parsed, array( 'source' => 'openai_function_calling' ) )
                    : $this->fallback( 'Could not parse final response' );

            }

            // Execute each tool call
            $tool_results = array();

            foreach ( $message['tool_calls'] as $tool_call ) {

                $tool_name = $tool_call['function']['name'];
                $tool_args = json_decode( $tool_call['function']['arguments'] ?? '{}', true );

                $result = $this->execute_tool( $tool_name, $tool_args );

                // Save final risk result
                if ( $tool_name === 'calculate_final_risk' ) {
                    $final_result = $result;
                }

                $tool_results[] = array(
                    'role'         => 'tool',
                    'tool_call_id' => $tool_call['id'],
                    'content'      => json_encode( $result ),
                );

            }

            // Add all tool results to messages
            foreach ( $tool_results as $tr ) {
                $messages[] = $tr;
            }

        }

        return $this->fallback( 'Max iterations reached' );

    }

    // ══════════════════════════════════════════
    // AGENTIC LOOP — Anthropic
    // ══════════════════════════════════════════

    public function run_anthropic() {

        $api_key = get_option( 'aifd_anthropic_key', '' );
        $model   = get_option( 'aifd_anthropic_model', 'claude-haiku-4-5-20251001' );

        if ( empty( $api_key ) ) {
            return $this->fallback( 'Anthropic key not configured' );
        }

        // Convert tools to Anthropic format
        $tools = array_map( function( $tool ) {
            return array(
                'name'         => $tool['function']['name'],
                'description'  => $tool['function']['description'],
                'input_schema' => $tool['function']['parameters'],
            );
        }, $this->get_tools() );

        $messages = array(
            array(
                'role'    => 'user',
                'content' => $this->build_order_summary(),
            ),
        );

        $iterations   = 0;
        $final_result = null;

        while ( $iterations < $this->max_iterations ) {

            $iterations++;

            $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
                'timeout' => 60,
                'headers' => array(
                    'x-api-key'         => $api_key,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type'      => 'application/json',
                ),
                'body' => json_encode( array(
                    'model'      => $model,
                    'max_tokens' => 1024,
                    'system'     => 'You are a fraud detection agent. Use the available tools to analyze this order. Call check_signal_weights and search_fraud_patterns first, then check_velocity if needed, then calculate_final_risk last.',
                    'messages'   => $messages,
                    'tools'      => $tools,
                )),
            ));

            if ( is_wp_error( $response ) ) {
                return $this->fallback( 'Anthropic request failed' );
            }

            $data        = json_decode( wp_remote_retrieve_body( $response ), true );
            $stop_reason = $data['stop_reason'] ?? '';
            $content     = $data['content'] ?? array();

            // Add assistant message to history
            $messages[] = array(
                'role'    => 'assistant',
                'content' => $content,
            );

            // No more tool calls
            if ( $stop_reason === 'end_turn' || $stop_reason !== 'tool_use' ) {

                if ( $final_result ) {
                    return array_merge( $final_result, array( 'source' => 'anthropic_function_calling' ) );
                }

                // Extract text response
                foreach ( $content as $block ) {
                    if ( $block['type'] === 'text' ) {
                        $text   = preg_replace( '/```json|```/', '', $block['text'] );
                        $parsed = json_decode( trim( $text ), true );
                        if ( $parsed ) {
                            return array_merge( $parsed, array( 'source' => 'anthropic_function_calling' ) );
                        }
                    }
                }

                return $this->fallback( 'Could not parse Anthropic response' );

            }

            // Execute tool calls
            $tool_results = array();

            foreach ( $content as $block ) {

                if ( $block['type'] !== 'tool_use' ) continue;

                $tool_name = $block['name'];
                $tool_args = $block['input'] ?? array();
                $result    = $this->execute_tool( $tool_name, $tool_args );

                if ( $tool_name === 'calculate_final_risk' ) {
                    $final_result = $result;
                }

                $tool_results[] = array(
                    'type'        => 'tool_result',
                    'tool_use_id' => $block['id'],
                    'content'     => json_encode( $result ),
                );

            }

            $messages[] = array(
                'role'    => 'user',
                'content' => $tool_results,
            );

        }

        return $this->fallback( 'Max iterations reached' );

    }

    // ══════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════

    private function build_order_summary() {

        return "Analyze this order for fraud:

Order Total:      $" . $this->signals['order_total'] . "
Customer Email:   " . $this->signals['customer_email'] . "
Billing Country:  " . $this->signals['billing_country'] . "
Shipping Country: " . $this->signals['shipping_country'] . "
Payment Method:   " . $this->signals['payment_method'] . "
Previous Orders:  " . $this->signals['previous_order_count'] . "
Store Average:    $" . round( $this->signals['store_average'] ?? 0 ) . "

Use the available tools to thoroughly analyze this order.
Start with check_signal_weights, then search_fraud_patterns,
then check_velocity if needed, then calculate_final_risk last.";

    }

    private function fallback( $reason ) {
        return array(
            'risk_score' => 50,
            'risk_level' => 'MEDIUM',
            'decision'   => 'REVIEW',
            'reason'     => $reason . ' — manual review needed',
            'source'     => 'fallback',
        );
    }

}