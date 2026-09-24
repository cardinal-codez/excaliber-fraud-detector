<?php
namespace Disham\Tests\Unit;

use AIFD_Embeddings;
use AIFD_FunctionCalling;
use AIFD_Pinecone;
use AIFD_Provider_Gemini;
use AIFD_Provider_Ollama;
use AIFD_Provider_OpenAI;
use Disham\Tests\TestCase;

/**
 * AI providers, the function-calling agent, embeddings and Pinecone — all with faked HTTP.
 */
class ProvidersTest extends TestCase {

	const VERDICT = array(
		'risk_score' => 82,
		'risk_level' => 'HIGH',
		'decision'   => 'BLOCK',
		'reason'     => 'Looks like card fraud',
	);

	// ── Prompt-based providers ─────────────────────────

	public function test_openai_provider_parses_json_reply() {
		update_option( 'aifd_openai_key', 'sk-test' );
		$this->respond_with( function () {
			return self::json_response( array(
				'choices' => array( array( 'message' => array( 'content' => wp_json_encode( self::VERDICT ) ) ) ),
			) );
		} );

		$result = ( new AIFD_Provider_OpenAI() )->call( 'prompt' );

		$this->assertSame( 82, $result['risk_score'] );
		$this->assertSame( 'openai', $result['source'] );
		$this->assertSame( 'Bearer sk-test', $this->http_requests[0]['args']['headers']['Authorization'] );
	}

	public function test_provider_strips_markdown_code_fences() {
		update_option( 'aifd_openai_key', 'sk-test' );
		$this->respond_with( function () {
			$fenced = "```json\n" . wp_json_encode( self::VERDICT ) . "\n```";
			return self::json_response( array(
				'choices' => array( array( 'message' => array( 'content' => $fenced ) ) ),
			) );
		} );

		$this->assertSame( 'BLOCK', ( new AIFD_Provider_OpenAI() )->call( 'prompt' )['decision'] );
	}

	public function test_provider_falls_back_to_review_on_garbage_reply() {
		update_option( 'aifd_openai_key', 'sk-test' );
		$this->respond_with( function () {
			return self::json_response( array(
				'choices' => array( array( 'message' => array( 'content' => 'I think it is fine' ) ) ),
			) );
		} );

		$result = ( new AIFD_Provider_OpenAI() )->call( 'prompt' );
		$this->assertSame( 'fallback', $result['source'] );
		$this->assertSame( 'REVIEW', $result['decision'] );
	}

	public function test_provider_falls_back_on_http_error_with_api_message() {
		update_option( 'aifd_openai_key', 'sk-bad' );
		$this->respond_with( function () {
			return self::json_response( array( 'error' => array( 'message' => 'Incorrect API key' ) ), 401 );
		} );

		$result = ( new AIFD_Provider_OpenAI() )->call( 'prompt' );
		$this->assertSame( 'fallback', $result['source'] );
		$this->assertStringContainsString( 'Incorrect API key', $result['reason'] );
	}

	public function test_provider_without_key_makes_no_request() {
		$result = ( new AIFD_Provider_OpenAI() )->call( 'prompt' );
		$this->assertSame( 'fallback', $result['source'] );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_gemini_provider_parses_candidates_and_remaps_retired_model() {
		update_option( 'aifd_gemini_key', 'g-test' );
		update_option( 'aifd_gemini_model', 'gemini-1.5-flash' );
		$this->respond_with( function () {
			return self::json_response( array(
				'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => wp_json_encode( self::VERDICT ) ) ) ) ) ),
			) );
		} );

		$result = ( new AIFD_Provider_Gemini() )->call( 'prompt' );

		$this->assertSame( 'gemini', $result['source'] );
		$this->assertStringContainsString( 'models/gemini-2.5-flash:', $this->http_requests[0]['url'] );
	}

	public function test_ollama_provider_uses_configured_host() {
		update_option( 'aifd_ollama_host', 'http://ollama.local:11434/' );
		$this->respond_with( function () {
			return self::json_response( array( 'message' => array( 'content' => wp_json_encode( self::VERDICT ) ) ) );
		} );

		$result = ( new AIFD_Provider_Ollama() )->call( 'prompt' );

		$this->assertSame( 'ollama', $result['source'] );
		$this->assertSame( 'http://ollama.local:11434/api/chat', $this->http_requests[0]['url'] );
	}

	// ── Function-calling agent ─────────────────────────

	private function agent_signals() {
		return $this->clean_signals( array(
			'order_total'          => 900,
			'customer_email'       => 'buyer@mailinator.com',
			'customer_ip'          => '203.0.113.5',
			'billing_country'      => 'NG',
			'shipping_country'     => 'US',
			'payment_method'       => 'cod',
			'previous_order_count' => 0,
			'store_average'        => 0,
			'disposable_email'     => true,
		) );
	}

	public function test_openai_agent_runs_tools_and_uses_final_risk_tool_result() {
		update_option( 'aifd_openai_key', 'sk-test' );
		$calls = 0;
		$this->respond_with( function () use ( &$calls ) {
			$calls++;
			if ( 1 === $calls ) {
				return self::json_response( array( 'choices' => array( array(
					'finish_reason' => 'tool_calls',
					'message'       => array(
						'role'       => 'assistant',
						'tool_calls' => array( array(
							'id'       => 'call_1',
							'type'     => 'function',
							'function' => array(
								'name'      => 'calculate_final_risk',
								'arguments' => wp_json_encode( array(
									'signal_score'  => 30,
									'pattern_score' => 0.95,
									'velocity_risk' => true,
									'reasoning'     => 'Matches card fraud pattern',
								) ),
							),
						) ),
					),
				) ) ) );
			}
			return self::json_response( array( 'choices' => array( array(
				'finish_reason' => 'stop',
				'message'       => array( 'role' => 'assistant', 'content' => 'Done' ),
			) ) ) );
		} );

		$result = ( new AIFD_FunctionCalling( $this->agent_signals() ) )->run_openai();

		// 30*2 = 60 calibrated, +20 pattern boost, +10 velocity boost.
		$this->assertEquals( 90, $result['risk_score'] );
		$this->assertSame( 'BLOCK', $result['decision'] );
		$this->assertSame( 'openai_function_calling', $result['source'] );
		$this->assertSame( 2, $calls );
	}

	public function test_openai_agent_falls_back_when_api_errors() {
		update_option( 'aifd_openai_key', 'sk-bad' );
		$this->respond_with( function () {
			return self::json_response( array( 'error' => array( 'message' => 'Invalid key' ) ), 401 );
		} );

		$result = ( new AIFD_FunctionCalling( $this->agent_signals() ) )->run_openai();
		$this->assertSame( 'fallback', $result['source'] );
	}

	public function test_anthropic_agent_parses_text_verdict() {
		update_option( 'aifd_anthropic_key', 'ak-test' );
		$this->respond_with( function () {
			return self::json_response( array(
				'stop_reason' => 'end_turn',
				'content'     => array( array( 'type' => 'text', 'text' => wp_json_encode( self::VERDICT ) ) ),
			) );
		} );

		$result = ( new AIFD_FunctionCalling( $this->agent_signals() ) )->run_anthropic();

		$this->assertSame( 'anthropic_function_calling', $result['source'] );
		$this->assertSame( 'api.anthropic.com', $this->requested_hosts()[0] );
	}

	public function test_agent_rejects_unknown_tools() {
		$agent = new AIFD_FunctionCalling( $this->agent_signals() );
		$this->assertArrayHasKey( 'error', $agent->execute_tool( 'delete_everything', array() ) );
	}

	public function test_final_risk_tool_scales_with_pattern_similarity() {
		$agent = new AIFD_FunctionCalling( $this->agent_signals() );

		$low  = $agent->execute_tool( 'calculate_final_risk', array( 'signal_score' => 10, 'pattern_score' => 0.5 ) );
		$high = $agent->execute_tool( 'calculate_final_risk', array( 'signal_score' => 10, 'pattern_score' => 0.85 ) );

		$this->assertEquals( 20, $low['risk_score'] );
		$this->assertEquals( 35, $high['risk_score'] );
		$this->assertSame( 'ALLOW', $low['decision'] );
	}

	// ── Embeddings + Pinecone ──────────────────────────

	public function test_namespace_is_per_site_and_provider() {
		$this->assertSame( 'merchant_1_gemini', ( new AIFD_Embeddings() )->get_namespace( 'gemini' ) );
	}

	public function test_unknown_provider_falls_back_to_openai_embedding_config() {
		$this->assertSame( 1536, ( new AIFD_Embeddings() )->get_config( 'nope' )['dimension'] );
	}

	public function test_pattern_text_lists_active_signals_payment_and_route() {
		$text = ( new AIFD_Pinecone() )->build_pattern_text( $this->agent_signals() );
		$this->assertSame( 'disposable_email cod_payment NG_to_US', $text );
	}

	public function test_pinecone_unconfigured_makes_no_requests() {
		$pinecone = new AIFD_Pinecone();

		$this->assertFalse( $pinecone->upsert( 'id', array( 0.1 ), array() ) );
		$this->assertSame( array(), $pinecone->query( array( 0.1 ) ) );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_pinecone_upsert_reports_failure_on_http_error() {
		update_option( 'aifd_pinecone_key', 'wrong-key' );
		update_option( 'aifd_pinecone_host', 'https://idx.pinecone.io' );
		$this->respond_with( function () {
			return self::json_response( array( 'message' => 'Unauthorized' ), 401 );
		} );

		$this->assertFalse(
			( new AIFD_Pinecone() )->upsert( 'id', array( 0.1 ), array() ),
			'A 401 from Pinecone must not be reported as a successful save'
		);
	}

	public function test_pinecone_query_sends_namespace_and_returns_matches() {
		update_option( 'aifd_pinecone_key', 'pk' );
		update_option( 'aifd_pinecone_host', 'https://idx.pinecone.io/' );
		$this->respond_with( function () {
			return self::json_response( array( 'matches' => array( array( 'id' => 'seed_001', 'score' => 0.93 ) ) ) );
		} );

		$matches = ( new AIFD_Pinecone() )->query( array( 0.1 ), 3, 'merchant_1_openai' );

		$this->assertSame( 'seed_001', $matches[0]['id'] );
		$this->assertSame( 'https://idx.pinecone.io/query', $this->http_requests[0]['url'] );
		$sent = json_decode( $this->http_requests[0]['args']['body'], true );
		$this->assertSame( 'merchant_1_openai', $sent['namespace'] );
		$this->assertSame( 3, $sent['topK'] );
	}

	public function test_seed_file_has_65_well_formed_patterns() {
		$patterns = json_decode( file_get_contents( AIFD_PATH . 'data/seed-patterns.json' ), true );

		$this->assertCount( 65, $patterns );
		$legit = array_filter( $patterns, function ( $p ) {
			return 'legitimate' === $p['fraud_type'];
		} );
		$this->assertCount( 15, $legit );

		foreach ( $patterns as $p ) {
			foreach ( array( 'id', 'pattern', 'fraud_type', 'confidence', 'times_seen', 'source', 'description' ) as $key ) {
				$this->assertArrayHasKey( $key, $p, "Seed {$p['id']} missing {$key}" );
			}
		}
		$this->assertCount( 65, array_unique( wp_list_pluck( $patterns, 'id' ) ), 'Seed ids are unique' );
	}
}
