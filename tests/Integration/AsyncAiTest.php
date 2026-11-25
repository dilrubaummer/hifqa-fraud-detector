<?php
namespace Hifqa\Tests\Integration;

use Hifqa\Tests\TestCase;

/**
 * 1.1.0: checkout runs local rules only; borderline orders are queued for the
 * AI layer, which can raise a decision after checkout and never release one.
 */
class AsyncAiTest extends TestCase {

	/**
	 * Gemini (prompt path) answering every request with this verdict, and
	 * Pinecone answering with no matches.
	 */
	private function ai_replies_with( array $verdict, $with_pinecone = false ) {
		update_option( 'aifd_ai_provider', 'gemini' );
		update_option( 'aifd_gemini_key', 'AIza-test-key' );

		if ( $with_pinecone ) {
			update_option( 'aifd_pinecone_key', 'pk' );
			update_option( 'aifd_pinecone_host', 'https://idx.pinecone.io' );
		}

		$this->respond_with( function ( $url ) use ( $verdict ) {
			if ( false !== strpos( $url, ':embedContent' ) ) {
				return self::json_response( array( 'embedding' => array( 'values' => array( 0.1 ) ) ) );
			}
			if ( false !== strpos( $url, 'pinecone.io' ) ) {
				return self::json_response( array( 'matches' => array() ) );
			}
			return self::json_response( array( 'candidates' => array( array(
				'content' => array( 'parts' => array( array( 'text' => wp_json_encode( $verdict ) ) ) ),
			) ) ) );
		} );
	}

	/**
	 * Scores 31 locally: allowed, but inside the AI band (30–59 by default).
	 * Country mismatch, risky combination (NG → CA; the test store is in the
	 * US), mismatched names, cash on delivery, guest, high-value first order.
	 */
	private function borderline_allow_props() {
		return array(
			'billing_email'       => 'shopper@example.com',
			'billing_phone'       => '555-0100',
			'billing_country'     => 'NG',
			'shipping_first_name' => 'John',
			'shipping_last_name'  => 'Smith',
			'shipping_address_1'  => '1 Main Street',
			'shipping_country'    => 'CA',
			'payment_method'      => 'cod',
			'total'               => 900,
		);
	}

	private function notes( $order ) {
		return implode( "\n", wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
	}

	// ── Checkout stays local ───────────────────────────

	public function test_checkout_makes_no_external_requests_when_ai_and_vector_memory_are_on() {
		$this->ai_replies_with( array( 'risk_score' => 50, 'decision' => 'REVIEW', 'reason' => 'x' ), true );

		$order = $this->make_order( $this->risky_order_props() );

		$this->assertSame( array(), $this->http_requests, 'no AI, embedding or Pinecone call during checkout' );
		$this->assertSame( 'REVIEW', $order->get_meta( '_aifd_decision' ), 'the local verdict is saved at checkout' );
		$this->assertSame( 'queued', $order->get_meta( '_aifd_ai_status' ) );
		$this->assertCount( 1, $this->queued_ai_checks() );

		$this->assertSame( 1, $this->run_queued_ai() );

		$hosts = array_unique( $this->requested_hosts() );
		$this->assertContains( 'idx.pinecone.io', $hosts, 'vector memory runs in the queued check' );
		$this->assertContains( 'generativelanguage.googleapis.com', $hosts );
		$this->assertSame( 'done', wc_get_order( $order->get_id() )->get_meta( '_aifd_ai_status' ) );
	}

	public function test_band_runs_from_ten_below_review_to_just_under_block() {
		// Defaults: review 40, block 60.
		$this->assertFalse( aifd_is_ai_borderline( 29 ) );
		$this->assertTrue( aifd_is_ai_borderline( 30 ) );
		$this->assertTrue( aifd_is_ai_borderline( 59 ) );
		$this->assertFalse( aifd_is_ai_borderline( 60 ) );

		update_option( 'aifd_review_threshold', 50 );
		update_option( 'aifd_block_threshold', 70 );
		$this->assertSame( array( 'min' => 40, 'max' => 69 ), aifd_get_ai_band() );

		update_option( 'aifd_review_threshold', 5 );
		$this->assertSame( 0, aifd_get_ai_band()['min'], 'the band never starts below zero' );
	}

	public function test_clear_pass_is_not_queued() {
		$this->ai_replies_with( array( 'risk_score' => 90, 'decision' => 'BLOCK', 'reason' => 'x' ) );

		$order = $this->make_order( array( 'customer_id' => $this->make_customer() ) );

		$this->assertSame( 'ALLOW', $order->get_meta( '_aifd_decision' ) );
		$this->assertSame( '', $order->get_meta( '_aifd_ai_status' ) );
		$this->assertSame( array(), $this->queued_ai_checks() );
	}

	public function test_nothing_is_queued_without_an_ai_provider() {
		// Vector memory alone is never called: it only feeds the AI layer.
		update_option( 'aifd_pinecone_key', 'pk' );
		update_option( 'aifd_pinecone_host', 'https://idx.pinecone.io' );

		$order = $this->make_order( $this->risky_order_props() );

		$this->assertSame( array(), $this->queued_ai_checks() );
		$this->assertSame( 0, $this->run_queued_ai() );
		$this->assertSame( array(), $this->http_requests );
		$this->assertSame( '', $order->get_meta( '_aifd_ai_status' ) );
	}

	public function test_resubmitted_checkout_queues_one_check() {
		$this->ai_replies_with( array( 'risk_score' => 50, 'decision' => 'REVIEW', 'reason' => 'x' ) );

		$order = $this->make_order( $this->risky_order_props() );
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );

		$this->assertCount( 1, $this->queued_ai_checks() );
	}

	// ── What the queued check may do ───────────────────

	public function test_ai_can_raise_an_allowed_order_to_review() {
		$this->ai_replies_with( array( 'risk_score' => 48, 'decision' => 'REVIEW', 'reason' => 'Odd combination' ) );

		$order = $this->make_order( $this->borderline_allow_props() );
		$this->assertSame( 'ALLOW', $order->get_meta( '_aifd_decision' ), 'precondition: allowed at checkout' );
		$this->assertSame( 31, (int) $order->get_meta( '_aifd_risk_score' ), 'precondition: inside the band' );
		$this->assertSame( array(), $this->hifqa_mail_subjects() );

		$this->run_queued_ai();
		$order = wc_get_order( $order->get_id() );

		$this->assertSame( 'REVIEW', $order->get_meta( '_aifd_decision' ) );
		$this->assertSame( 48, (int) $order->get_meta( '_aifd_risk_score' ) );
		$this->assertSame( 'Odd combination', $order->get_meta( '_aifd_reason' ) );
		$this->assertSame( 'gemini', $order->get_meta( '_aifd_provider' ) );
		$this->assertSame( 'pending', $order->get_status(), 'review does not change the order status' );
		$this->assertStringContainsString( 'FRAUD WARNING', $this->notes( $order ) );
		$this->assertCount( 1, $this->hifqa_mail_subjects() );
		$this->assertStringContainsString( 'Needs Review', $this->hifqa_mail_subjects()[0] );
		$this->assertSame( 0, (int) get_option( 'aifd_total_allowed' ) );
		$this->assertSame( 1, (int) get_option( 'aifd_total_review' ) );
	}

	public function test_ai_can_raise_a_review_to_block_and_hold_the_order() {
		$this->ai_replies_with( array( 'risk_score' => 85, 'decision' => 'BLOCK', 'reason' => 'Stolen card pattern' ) );

		$order = $this->make_order( $this->risky_order_props() );
		$order->set_status( 'processing' ); // paid by the time the check runs
		$order->save();

		$this->run_queued_ai();
		$order = wc_get_order( $order->get_id() );

		$this->assertSame( 'BLOCK', $order->get_meta( '_aifd_decision' ) );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( 0, (int) get_option( 'aifd_total_review' ) );
		$this->assertSame( 1, (int) get_option( 'aifd_total_blocked' ) );
		$this->assertCount( 1, array_filter( $this->hifqa_mail_subjects(), function ( $s ) {
			return false !== strpos( $s, 'Blocked' );
		} ) );
	}

	public function test_ai_block_flags_a_completed_order_without_reopening_it() {
		$this->ai_replies_with( array( 'risk_score' => 85, 'decision' => 'BLOCK', 'reason' => 'Stolen card pattern' ) );

		$order = $this->make_order( $this->risky_order_props() );
		$order->set_status( 'completed' ); // fulfilled before the check ran
		$order->save();

		$this->run_queued_ai();
		$order = wc_get_order( $order->get_id() );

		$this->assertSame( 'BLOCK', $order->get_meta( '_aifd_decision' ) );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertStringContainsString( 'already Completed, so its status was not changed', $this->notes( $order ) );
	}

	/**
	 * @dataProvider merchant_verdicts
	 */
	public function test_queued_check_leaves_an_order_the_merchant_already_judged( $decision, $review_status ) {
		$this->ai_replies_with( array( 'risk_score' => 85, 'decision' => 'BLOCK', 'reason' => 'x' ) );

		$order = $this->make_order( $this->risky_order_props() );
		$order->update_meta_data( '_aifd_decision', $decision );
		$order->update_meta_data( '_aifd_review_status', $review_status );
		$order->save();

		$this->run_queued_ai();
		$order = wc_get_order( $order->get_id() );

		$this->assertSame( array(), $this->http_requests, 'the AI is not asked' );
		$this->assertSame( $decision, $order->get_meta( '_aifd_decision' ) );
		$this->assertSame( 'skipped', $order->get_meta( '_aifd_ai_status' ) );
	}

	public function merchant_verdicts() {
		return array(
			'approved as a false positive' => array( 'APPROVED', 'FALSE_POSITIVE' ),
			'confirmed as fraud'           => array( 'REVIEW', 'CONFIRMED_FRAUD' ),
			'blocked by the merchant'      => array( 'BLOCKED', 'PENDING' ),
		);
	}

	public function test_queued_check_is_skipped_if_the_ai_was_switched_off_meanwhile() {
		$this->ai_replies_with( array( 'risk_score' => 85, 'decision' => 'BLOCK', 'reason' => 'x' ) );
		$order = $this->make_order( $this->risky_order_props() );

		delete_option( 'aifd_gemini_key' );
		$this->run_queued_ai();
		$order = wc_get_order( $order->get_id() );

		$this->assertSame( array(), $this->http_requests );
		$this->assertSame( 'REVIEW', $order->get_meta( '_aifd_decision' ) );
		$this->assertSame( 'skipped', $order->get_meta( '_aifd_ai_status' ) );
	}

	public function test_agreeing_ai_verdict_sends_no_second_alert() {
		$this->ai_replies_with( array( 'risk_score' => 50, 'decision' => 'REVIEW', 'reason' => 'Agrees' ) );

		$order = $this->make_order_and_run_ai( $this->risky_order_props() );

		$this->assertSame( 'REVIEW', $order->get_meta( '_aifd_decision' ) );
		$this->assertCount( 1, $this->hifqa_mail_subjects(), 'one alert, from checkout' );
		$this->assertSame( 1, (int) get_option( 'aifd_total_review' ), 'counted once' );
	}
}
