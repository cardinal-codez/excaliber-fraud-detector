<?php
namespace Disham\Tests\Unit;

use Disham\Tests\TestCase;

/**
 * The individual fraud-signal detectors.
 */
class SignalHelpersTest extends TestCase {

	// ── Disposable / suspicious email ─────────────────

	/**
	 * @dataProvider disposable_emails
	 */
	public function test_disposable_email_domains_are_flagged( $email ) {
		$this->assertTrue( aifd_is_suspicious_email( $email ) );
	}

	public function disposable_emails() {
		return array(
			'mailinator'      => array( 'someone@mailinator.com' ),
			'yopmail'         => array( 'a@yopmail.com' ),
			'10minutemail'    => array( 'test@10minutemail.com' ),
			'uppercase domain' => array( 'someone@MAILINATOR.COM' ),
		);
	}

	public function test_random_looking_local_part_is_flagged() {
		$this->assertTrue( aifd_is_suspicious_email( 'xk3j9f8a7s6d5f4g3h2@gmail.com' ) );
	}

	/**
	 * @dataProvider normal_emails
	 */
	public function test_normal_emails_are_not_flagged( $email ) {
		$this->assertFalse( aifd_is_suspicious_email( $email ) );
	}

	public function normal_emails() {
		return array(
			'plain'          => array( 'jane.doe@example.com' ),
			'short gmail'    => array( 'bob@gmail.com' ),
			'dotted name'    => array( 'mary.ann.smith@outlook.com' ),
		);
	}

	// ── Freight forwarders ─────────────────────────────

	/**
	 * @dataProvider forwarder_addresses
	 */
	public function test_freight_forwarder_addresses_are_flagged( $address ) {
		$this->assertTrue( aifd_is_freight_forwarder( $address ) );
	}

	public function forwarder_addresses() {
		return array(
			'shipito'        => array( '1 Shipito Way' ),
			'uppercase'      => array( 'MYUS PACKAGE FORWARDING' ),
			'reshipping'     => array( 'Reshipping Services Ltd' ),
			'freight'        => array( 'Unit 5, Global Freight Park' ),
		);
	}

	public function test_normal_address_is_not_a_forwarder() {
		$this->assertFalse( aifd_is_freight_forwarder( '221B Baker Street' ) );
		$this->assertFalse( aifd_is_freight_forwarder( '' ) );
	}

	// ── Country combinations (store base = US) ─────────

	public function test_same_country_is_never_risky() {
		$this->assertFalse( aifd_is_risky_country_combo( 'NG', 'NG' ) );
	}

	public function test_high_risk_billing_to_high_value_destination_is_risky() {
		// Neither side is the store's home country (US), so the combo applies.
		$this->assertTrue( aifd_is_risky_country_combo( 'NG', 'GB' ) );
	}

	public function test_rich_billing_to_fraud_hub_shipping_is_risky() {
		$this->assertTrue( aifd_is_risky_country_combo( 'GB', 'RU' ) );
	}

	public function test_shipping_to_store_home_country_is_exempt() {
		$this->assertFalse( aifd_is_risky_country_combo( 'NG', 'US' ) );
	}

	public function test_billing_from_store_home_country_is_exempt() {
		$this->assertFalse( aifd_is_risky_country_combo( 'US', 'NG' ) );
	}

	public function test_store_home_country_is_removed_from_risk_lists() {
		update_option( 'woocommerce_default_country', 'NG' );
		$this->assertFalse( aifd_is_risky_country_combo( 'GB', 'NG' ) );
	}

	public function test_unlisted_country_pair_is_not_risky() {
		$this->assertFalse( aifd_is_risky_country_combo( 'IE', 'NZ' ) );
	}

	// ── Account age ────────────────────────────────────

	public function test_guest_is_not_a_new_account() {
		$this->assertFalse( aifd_is_new_account( 0 ) );
	}

	public function test_account_registered_today_is_new() {
		$user_id = self::factory()->user->create( array(
			'user_registered' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
		) );
		$this->assertTrue( aifd_is_new_account( $user_id ) );
	}

	public function test_established_account_is_not_new() {
		$this->assertFalse( aifd_is_new_account( $this->make_customer() ) );
	}

	public function test_missing_user_is_not_new() {
		$this->assertFalse( aifd_is_new_account( 999999 ) );
	}

	// ── Signal tiers ───────────────────────────────────

	public function test_signal_tiers() {
		$this->assertSame( 1, aifd_get_signal_tier( 'card_testing_pattern' ) );
		$this->assertSame( 2, aifd_get_signal_tier( 'chargeback_history' ) );
		$this->assertSame( 3, aifd_get_signal_tier( 'odd_hours' ) );
	}
}
