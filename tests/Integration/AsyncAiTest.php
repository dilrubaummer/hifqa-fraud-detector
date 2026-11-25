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

	// ── Hourly AI budget ───────────────────────────────

	/**
	 * Capture what the plugin writes to the WooCommerce log.
	 */
	private function spy_on_log() {
		$spy = new class() extends \WC_Logger {
			public $entries = array();
			public function __construct() {
				parent::__construct( array() );
			}
			public function log( $level, $message, $context = array() ) {
				$this->entries[] = $message;
			}
		};
		add_filter( 'woocommerce_logging_class', function () use ( $spy ) {
			return $spy;
		} );
		return $spy;
	}

	/**
	 * Borderline orders that do not look like one burst (own email and IP each).
	 */
	private function make_borderline_orders( $count ) {
		$orders = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$orders[] = $this->make_order( $this->risky_order_props( array(
				'billing_email'       => "buyer{$i}@mailinator.com",
				'customer_ip_address' => "198.51.100.{$i}",
			) ) );
		}
		return $orders;
	}

	private function ai_statuses( array $orders ) {
		$statuses = array_map( function ( $order ) {
			return wc_get_order( $order->get_id() )->get_meta( '_aifd_ai_status' );
		}, $orders );
		sort( $statuses );
		return $statuses;
	}

	public function test_hourly_budget_stops_ai_calls_once_used_up() {
		$log = $this->spy_on_log();
		update_option( 'aifd_ai_hourly_budget', 2 );
		$this->ai_replies_with( array( 'risk_score' => 50, 'decision' => 'REVIEW', 'reason' => 'x' ) );

		$orders = $this->make_borderline_orders( 3 );
		$this->assertSame( 3, $this->run_queued_ai() );

		$this->assertCount( 2, $this->http_requests, 'two AI calls, not three' );
		$this->assertSame( array( 'done', 'done', 'skipped_budget' ), $this->ai_statuses( $orders ) );

		foreach ( $orders as $order ) {
			$order = wc_get_order( $order->get_id() );
			if ( 'skipped_budget' === $order->get_meta( '_aifd_ai_status' ) ) {
				$this->assertSame( 'REVIEW', $order->get_meta( '_aifd_decision' ), 'keeps its local result' );
				$this->assertSame( 'local_rules', $order->get_meta( '_aifd_provider' ) );
			}
		}

		$this->assertStringContainsString( 'hourly limit of 2 AI checks was reached', implode( "\n", $log->entries ) );
	}

	public function test_failed_ai_calls_count_toward_the_budget() {
		update_option( 'aifd_ai_hourly_budget', 1 );
		update_option( 'aifd_ai_provider', 'gemini' );
		update_option( 'aifd_gemini_key', 'AIza-test-key' );
		$this->respond_with( function () {
			return self::json_response( array( 'error' => array( 'message' => 'Overloaded' ) ), 503 );
		} );

		$orders = $this->make_borderline_orders( 2 );
		$this->run_queued_ai();

		$this->assertCount( 1, $this->http_requests );
		$this->assertSame( array( 'failed', 'skipped_budget' ), $this->ai_statuses( $orders ) );
	}

	public function test_budget_starts_again_in_a_new_hour() {
		update_option( 'aifd_ai_hourly_budget', 2 );
		set_transient( 'aifd_ai_budget', array( 'hour' => gmdate( 'YmdH', time() - HOUR_IN_SECONDS ), 'count' => 2 ), HOUR_IN_SECONDS );

		$this->assertTrue( aifd_take_ai_budget(), 'last hour\'s count does not carry over' );
		$this->assertSame( array( 'hour' => gmdate( 'YmdH' ), 'count' => 1 ), get_transient( 'aifd_ai_budget' ) );
		$this->assertTrue( aifd_take_ai_budget() );
		$this->assertFalse( aifd_take_ai_budget(), 'the third check this hour is over the limit' );
		$this->assertSame( 2, get_transient( 'aifd_ai_budget' )['count'], 'a refused check is not counted' );
	}

	public function test_budget_defaults_to_50_and_stays_in_range() {
		delete_option( 'aifd_ai_hourly_budget' );
		$this->assertSame( 50, aifd_get_ai_hourly_budget() );

		$this->assertSame( 1, aifd_sanitize_ai_hourly_budget( 0 ) );
		$this->assertSame( 1, aifd_sanitize_ai_hourly_budget( 'abc' ) );
		$this->assertSame( 25, aifd_sanitize_ai_hourly_budget( '25' ) );
		$this->assertSame( 1000, aifd_sanitize_ai_hourly_budget( 5000 ) );

		// A value stored without the settings form is still kept in range.
		update_option( 'aifd_ai_hourly_budget', 0 );
		$this->assertSame( 1, aifd_get_ai_hourly_budget() );
	}

	public function test_missing_budget_option_and_counter_do_not_break_the_queued_check() {
		delete_option( 'aifd_ai_hourly_budget' );
		delete_transient( 'aifd_ai_budget' );
		$this->ai_replies_with( array( 'risk_score' => 50, 'decision' => 'REVIEW', 'reason' => 'x' ) );

		$order = $this->make_order_and_run_ai( $this->risky_order_props() );

		$this->assertSame( 'done', $order->get_meta( '_aifd_ai_status' ) );
		$this->assertSame( 1, get_transient( 'aifd_ai_budget' )['count'] );
	}

	public function test_settings_page_shows_the_hourly_limit() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		aifd_render_settings_page();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '/name="aifd_ai_hourly_budget" value="50"/', $html );
	}

	// ── Deactivation ───────────────────────────────────

	/**
	 * Pending action IDs for one hook in one group.
	 */
	private function pending( $hook, $group ) {
		return as_get_scheduled_actions( array(
			'hook'     => $hook,
			'group'    => $group,
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => -1,
		), 'ids' );
	}

	public function test_deactivation_clears_only_our_own_queued_checks() {
		$this->ai_replies_with( array( 'risk_score' => 50, 'decision' => 'REVIEW', 'reason' => 'x' ) );

		// Seven: more than Action Scheduler's default page of five results.
		for ( $i = 1; $i <= 7; $i++ ) {
			$this->make_order( $this->risky_order_props( array(
				'billing_email'       => "buyer{$i}@mailinator.com",
				'customer_ip_address' => "198.51.100.{$i}",
			) ) );
		}

		// Actions that are not ours and must survive.
		$others = array(
			as_enqueue_async_action( 'other_plugin_task', array( 1 ), 'hifqa-fraud-detector' ), // other hook, our group name
			as_enqueue_async_action( 'other_plugin_task', array( 2 ), 'other-plugin' ),         // other hook and group
			as_enqueue_async_action( 'aifd_ai_score_order', array( 999 ), 'other-plugin' ),     // our hook name, other group
		);

		$this->assertCount( 7, $this->pending( 'aifd_ai_score_order', 'hifqa-fraud-detector' ), 'precondition: seven checks queued' );

		// What WordPress runs when the plugin is deactivated.
		$hook = 'deactivate_' . plugin_basename( AIFD_PATH . 'hifqa-fraud-detector.php' );
		$this->assertNotFalse( has_action( $hook, 'aifd_clear_queued_ai_checks' ), 'registered as the deactivation hook' );
		do_action( $hook, false );

		$this->assertSame( array(), $this->pending( 'aifd_ai_score_order', 'hifqa-fraud-detector' ) );
		foreach ( $others as $id ) {
			$this->assertSame( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler::store()->get_status( $id ), "action {$id} is not ours and stays" );
		}
	}

	public function test_agreeing_ai_verdict_sends_no_second_alert() {
		$this->ai_replies_with( array( 'risk_score' => 50, 'decision' => 'REVIEW', 'reason' => 'Agrees' ) );

		$order = $this->make_order_and_run_ai( $this->risky_order_props() );

		$this->assertSame( 'REVIEW', $order->get_meta( '_aifd_decision' ) );
		$this->assertCount( 1, $this->hifqa_mail_subjects(), 'one alert, from checkout' );
		$this->assertSame( 1, (int) get_option( 'aifd_total_review' ), 'counted once' );
	}
}
