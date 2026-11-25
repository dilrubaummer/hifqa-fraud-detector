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
