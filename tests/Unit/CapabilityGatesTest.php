<?php
namespace excaliber\Tests\Unit;

use excaliber\Tests\TestCase;

/**
 * Tier gates (vector + AI layers are opt-in) and the circuit breaker.
 */
class CapabilityGatesTest extends TestCase {

	public function test_vector_tier_off_without_pinecone_keys() {
		$this->assertFalse( aifd_tier1_ready() );

		update_option( 'aifd_pinecone_key', 'pk' );
		$this->assertFalse( aifd_tier1_ready(), 'Host is also required' );

		update_option( 'aifd_pinecone_host', 'https://idx.pinecone.io' );
		$this->assertTrue( aifd_tier1_ready() );
	}

	public function test_ai_tier_off_without_provider_key() {
		update_option( 'aifd_ai_provider', 'openai' );
		$this->assertFalse( aifd_tier2_ready() );

		update_option( 'aifd_openai_key', 'sk-test' );
		$this->assertTrue( aifd_tier2_ready() );
	}

	public function test_ai_tier_checks_the_selected_providers_key() {
		update_option( 'aifd_openai_key', 'sk-test' );
		update_option( 'aifd_ai_provider', 'gemini' );
		$this->assertFalse( aifd_tier2_ready(), 'OpenAI key does not enable Gemini' );

		update_option( 'aifd_gemini_key', 'g-test' );
		$this->assertTrue( aifd_tier2_ready() );
	}

	public function test_ollama_needs_a_host_not_a_key() {
		update_option( 'aifd_ai_provider', 'ollama' );
		$this->assertFalse( aifd_tier2_ready() );

		update_option( 'aifd_ollama_host', 'http://localhost:11434' );
		$this->assertTrue( aifd_tier2_ready() );
	}

	public function test_circuit_opens_after_three_failures() {
		update_option( 'aifd_openai_key', 'sk-test' );

		aifd_trip_circuit( 'aifd_provider' );
		aifd_trip_circuit( 'aifd_provider' );
		$this->assertTrue( aifd_tier2_ready(), 'Two failures keep the circuit closed' );

		aifd_trip_circuit( 'aifd_provider' );
		$this->assertFalse( aifd_tier2_ready(), 'Third failure opens it' );
	}

	public function test_pinecone_circuit_is_separate_from_provider_circuit() {
		update_option( 'aifd_pinecone_key', 'pk' );
		update_option( 'aifd_pinecone_host', 'https://idx.pinecone.io' );
		update_option( 'aifd_openai_key', 'sk-test' );

		for ( $i = 0; $i < 3; $i++ ) {
			aifd_trip_circuit( 'aifd_pinecone' );
		}

		$this->assertFalse( aifd_tier1_ready() );
		$this->assertTrue( aifd_tier2_ready() );
	}
}
