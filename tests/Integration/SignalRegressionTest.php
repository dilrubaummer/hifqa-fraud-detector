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
}
