<?php
namespace Hifqa\Tests\Integration;

use Hifqa\Tests\TestCase;

/**
 * Regression tests for signal bugs found by probing the 1.0.0 scoring engine.
 */
class SignalRegressionTest extends TestCase {

	/**
	 * Save an order for this customer and move its creation date into the past.
	 */
	private function make_past_order( $customer_id, $email, $days_ago ) {
		$order = $this->make_order( array(
			'customer_id'   => $customer_id,
			'billing_email' => $email,
		) );
		$order->set_date_created( time() - $days_ago * DAY_IN_SECONDS );
		$order->set_status( 'completed' );
		$order->save();

		return $order;
	}

	// ── high_velocity ──────────────────────────────────

	public function test_loyal_customer_with_old_orders_does_not_trip_high_velocity() {
		$customer = $this->make_customer();

		foreach ( array( 90, 60, 30 ) as $days_ago ) {
			$this->make_past_order( $customer, 'loyal@example.com', $days_ago );
		}

		$order = $this->make_order( array(
			'customer_id'   => $customer,
			'billing_email' => 'loyal@example.com',
		) );

		$this->assertNotContains( 'high_velocity', (array) $order->get_meta( '_aifd_fired_signals' ) );
		$this->assertSame( 'ALLOW', $order->get_meta( '_aifd_decision' ) );
	}

	public function test_three_orders_within_a_day_still_trip_high_velocity() {
		$customer = $this->make_customer();
		$props    = array(
			'customer_id'   => $customer,
			'billing_email' => 'burst@example.com',
		);

		$this->make_order( $props );
		$this->make_order( $props );
		$third = $this->make_order( $props );

		$this->assertContains( 'high_velocity', (array) $third->get_meta( '_aifd_fired_signals' ) );
	}

	public function test_orders_older_than_a_day_are_not_counted_toward_velocity() {
		$customer = $this->make_customer();

		// Two orders just outside the window, one inside it, plus this one: two in 24 hours.
		$this->make_past_order( $customer, 'edge@example.com', 2 );
		$this->make_past_order( $customer, 'edge@example.com', 1.1 );
		$this->make_past_order( $customer, 'edge@example.com', 0.5 );

		$order = $this->make_order( array(
			'customer_id'   => $customer,
			'billing_email' => 'edge@example.com',
		) );

		$this->assertNotContains( 'high_velocity', (array) $order->get_meta( '_aifd_fired_signals' ) );
	}

	// ── address_mismatch ───────────────────────────────

	public function test_digital_order_without_shipping_country_has_no_address_mismatch() {
		$order = $this->make_order( array(
			'customer_id'         => $this->make_customer(),
			'shipping_first_name' => '',
			'shipping_last_name'  => '',
			'shipping_address_1'  => '',
			'shipping_country'    => '',
		) );

		$this->assertNotContains( 'address_mismatch', (array) $order->get_meta( '_aifd_fired_signals' ) );
		$this->assertSame( 'ALLOW', $order->get_meta( '_aifd_decision' ) );
	}

	public function test_shipping_to_another_country_still_has_address_mismatch() {
		$order = $this->make_order( array(
			'customer_id'      => $this->make_customer(),
			'billing_country'  => 'US',
			'shipping_country' => 'CA',
		) );

		$this->assertContains( 'address_mismatch', (array) $order->get_meta( '_aifd_fired_signals' ) );
	}

	// ── Email matching ─────────────────────────────────

	public function test_email_is_normalized_for_matching() {
		$this->assertSame( 'john@gmail.com', aifd_normalize_email( '  John@Gmail.com ' ) );
		$this->assertTrue( aifd_same_email( 'John@Gmail.com ', 'john@gmail.com' ) );
		$this->assertFalse( aifd_same_email( '', '' ), 'two missing emails are not the same customer' );
		$this->assertFalse( aifd_same_email( null, 'john@gmail.com' ) );
	}

	/**
	 * Three orders, one address typed three ways, each from a different IP so
	 * only the email can tie them together.
	 */
	private function make_mixed_case_burst() {
		$orders = array();
		foreach ( array( 'John@Gmail.com', 'JOHN@GMAIL.COM', 'john@gmail.com' ) as $i => $email ) {
			$orders[] = $this->make_order( array(
				'billing_email'       => $email,
				'customer_ip_address' => '198.51.100.' . ( 10 + $i ),
			) );
		}
		return $orders;
	}

	public function test_email_case_does_not_hide_a_burst_from_velocity_or_card_testing() {
		$orders = $this->make_mixed_case_burst();
		$fired  = (array) end( $orders )->get_meta( '_aifd_fired_signals' );

		$this->assertContains( 'high_velocity', $fired );
		$this->assertContains( 'card_testing_pattern', $fired );
	}

	public function test_velocity_tool_matches_email_case_insensitively() {
		$this->make_mixed_case_burst();

		$agent  = new \AIFD_FunctionCalling( array(
			'customer_email' => 'john@gmail.com',
			'customer_ip'    => '203.0.113.99',
		) );
		$result = $agent->execute_tool( 'check_velocity', array( 'window_minutes' => 60 ) );

		$this->assertSame( 3, $result['email_order_count'] );
		$this->assertTrue( $result['suspicious'] );
	}

	public function test_previous_confirmed_fraud_matches_email_case_insensitively() {
		$first = $this->make_order( array( 'billing_email' => 'Fraudster@Example.com' ) );
		$first->update_meta_data( '_aifd_review_status', 'CONFIRMED_FRAUD' );
		$first->save();

		$order = $this->make_order( array( 'billing_email' => 'fraudster@example.com' ) );

		$this->assertContains( 'chargeback_history', (array) $order->get_meta( '_aifd_fired_signals' ) );
	}

	public function test_orders_without_an_email_are_not_counted_as_one_customer() {
		foreach ( array( 1, 2, 3 ) as $i ) {
			$this->make_order( array(
				'billing_email'       => '',
				'customer_ip_address' => '198.51.100.' . ( 20 + $i ),
			) );
		}

		$this->assertFalse( aifd_is_card_testing( '203.0.113.99', '' ) );
	}

	// ── Empty IP ───────────────────────────────────────

	public function test_admin_created_orders_without_an_ip_do_not_trip_card_testing() {
		$orders = array();
		foreach ( array( 'a', 'b', 'c' ) as $who ) {
			$orders[] = $this->make_order( array(
				'customer_id'         => $this->make_customer(),
				'billing_email'       => "customer-{$who}@example.com",
				'customer_ip_address' => '',
			) );
		}

		$this->assertNotContains( 'card_testing_pattern', (array) end( $orders )->get_meta( '_aifd_fired_signals' ) );
		$this->assertFalse( aifd_is_card_testing( '', 'customer-d@example.com' ) );
	}

	public function test_three_orders_from_one_ip_still_trip_card_testing() {
		$orders = array();
		foreach ( array( 'a', 'b', 'c' ) as $who ) {
			$orders[] = $this->make_order( array(
				'billing_email'       => "visitor-{$who}@example.com",
				'customer_ip_address' => '198.51.100.77',
			) );
		}

		$this->assertContains( 'card_testing_pattern', (array) end( $orders )->get_meta( '_aifd_fired_signals' ) );
	}

	// ── ip_proxy_vpn removed ───────────────────────────

	public function test_ip_proxy_vpn_signal_no_longer_exists() {
		$this->assertArrayNotHasKey( 'ip_proxy_vpn', aifd_get_default_signal_weights() );
		$this->assertCount( 16, aifd_get_default_signal_weights() );

		$order = $this->make_order();
		$this->assertArrayNotHasKey( 'ip_proxy_vpn', aifd_extract_signals( $order ) );
	}

	public function test_stale_saved_ip_proxy_vpn_weight_is_ignored() {
		// What a 1.0.0 store saves after one "Not Fraud" override: the full weight list.
		$saved                 = aifd_get_default_signal_weights();
		$saved['ip_proxy_vpn'] = 0.12;
		$saved['odd_hours']    = 0.03;
		update_option( 'aifd_merchant_weights', $saved );

		$weights = aifd_get_signal_weights();

		$this->assertArrayNotHasKey( 'ip_proxy_vpn', $weights );
		$this->assertEquals( 0.03, $weights['odd_hours'], 'known saved weights still apply' );

		// The stale weight no longer dilutes the score: one card-testing hit is 0.30 of the live total.
		$result = aifd_calculate_risk_score( $this->clean_signals( array( 'card_testing_pattern' => true ) ) );
		$this->assertEquals( round( 0.30 / array_sum( $weights ) * 100 ), $result['risk_score'] );

		$this->assertSame( $saved, get_option( 'aifd_merchant_weights' ), 'the saved option is not rewritten' );
	}

	// ── chargeback_history ("Previous confirmed fraud") ──

	public function test_abandoned_order_is_not_previous_confirmed_fraud() {
		$customer = $this->make_customer();

		// Scored on creation, so it carries the plugin's own "FRAUD CHECK PASSED" note.
		$first = $this->make_order( array( 'customer_id' => $customer, 'billing_email' => 'once@example.com' ) );
		$first->update_status( 'cancelled' ); // an unpaid order cancelled by WooCommerce

		$order = $this->make_order( array( 'customer_id' => $customer, 'billing_email' => 'once@example.com' ) );

		$this->assertNotContains( 'chargeback_history', (array) $order->get_meta( '_aifd_fired_signals' ) );
		$this->assertSame( 'ALLOW', $order->get_meta( '_aifd_decision' ) );
	}

	public function test_order_the_merchant_confirmed_as_fraud_is_previous_confirmed_fraud() {
		$first = $this->make_order( array( 'billing_email' => 'fraudster@example.com' ) );
		$first->update_meta_data( '_aifd_review_status', 'CONFIRMED_FRAUD' );
		$first->save();

		$order = $this->make_order( array( 'billing_email' => 'fraudster@example.com' ) );

		$this->assertContains( 'chargeback_history', (array) $order->get_meta( '_aifd_fired_signals' ) );
		$this->assertStringContainsString( 'Previous confirmed fraud', $order->get_meta( '_aifd_reason' ) );
	}

	public function test_false_positive_review_is_not_previous_confirmed_fraud() {
		$first = $this->make_order( array( 'billing_email' => 'cleared@example.com' ) );
		$first->update_meta_data( '_aifd_review_status', 'FALSE_POSITIVE' );
		$first->save();

		$order = $this->make_order( array( 'billing_email' => 'cleared@example.com' ) );

		$this->assertNotContains( 'chargeback_history', (array) $order->get_meta( '_aifd_fired_signals' ) );
	}

	public function test_dispute_filter_marks_previous_confirmed_fraud() {
		$first = $this->make_order( array( 'billing_email' => 'disputed@example.com' ) );

		$seen     = array();
		$disputed = function ( $has_dispute, $order ) use ( $first, &$seen ) {
			$seen[] = $order->get_id();
			return $has_dispute || $order->get_id() === $first->get_id();
		};
		add_filter( 'aifd_order_has_dispute', $disputed, 10, 2 );

		$order = $this->make_order( array( 'billing_email' => 'disputed@example.com' ) );

		remove_filter( 'aifd_order_has_dispute', $disputed, 10 );

		$this->assertContains( $first->get_id(), $seen );
		$this->assertContains( 'chargeback_history', (array) $order->get_meta( '_aifd_fired_signals' ) );
	}

	public function test_dispute_filter_only_sees_orders_with_the_same_email() {
		$other = $this->make_order( array( 'billing_email' => 'someone-else@example.com' ) );

		$disputed = function ( $has_dispute, $order ) use ( $other ) {
			return $has_dispute || $order->get_id() === $other->get_id();
		};
		add_filter( 'aifd_order_has_dispute', $disputed, 10, 2 );

		$order = $this->make_order( array( 'billing_email' => 'innocent@example.com' ) );

		remove_filter( 'aifd_order_has_dispute', $disputed, 10 );

		$this->assertNotContains( 'chargeback_history', (array) $order->get_meta( '_aifd_fired_signals' ) );
	}
}
