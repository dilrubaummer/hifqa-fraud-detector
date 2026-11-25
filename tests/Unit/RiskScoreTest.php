<?php
namespace Hifqa\Tests\Unit;

use Hifqa\Tests\TestCase;

/**
 * Weighted local scoring, thresholds and per-merchant weight learning.
 */
class RiskScoreTest extends TestCase {

	public function test_no_signals_scores_zero_and_allows() {
		$result = aifd_calculate_risk_score( $this->clean_signals() );

		$this->assertSame( 0.0, (float) $result['risk_score'] );
		$this->assertSame( 'LOW', $result['risk_level'] );
		$this->assertSame( 'ALLOW', $result['decision'] );
		$this->assertSame( array(), $result['fired_signals'] );
	}

	public function test_every_signal_scores_100_and_blocks() {
		$all    = array_fill_keys( array_keys( aifd_get_signal_weights() ), true );
		$result = aifd_calculate_risk_score( $all );

		$this->assertSame( 100.0, (float) $result['risk_score'] );
		$this->assertSame( 'BLOCK', $result['decision'] );
		$this->assertCount( 16, $result['fired_signals'] );
	}

	// ── Claims made in readme.txt, README.md and ARCHITECTURE.md ──

	/**
	 * The highest local score any $count signals can reach: the $count
	 * strongest default weights, scored by the real scorer (same rounding).
	 */
	private function best_score_with( $count ) {
		$weights = aifd_get_default_signal_weights();
		arsort( $weights );
		$strongest = array_fill_keys( array_slice( array_keys( $weights ), 0, $count ), true );

		return (int) aifd_calculate_risk_score( $this->clean_signals( $strongest ) )['risk_score'];
	}

	/**
	 * The fewest signals that can reach $threshold with the default weights.
	 */
	private function fewest_signals_to_reach( $threshold ) {
		$total = count( aifd_get_default_signal_weights() );
		for ( $count = 1; $count <= $total; $count++ ) {
			if ( $this->best_score_with( $count ) >= $threshold ) {
				return $count;
			}
		}
		return null;
	}

	/**
	 * The docs say that with the local rules alone, Review needs at least four
	 * signals at once and Block at least six, that the strongest single signal
	 * is worth 13 points, and that the default weights add up to 2.27. If the
	 * weights or the default thresholds change, this fails until the docs
	 * (readme.txt, README.md, ARCHITECTURE.md) are updated with it.
	 */
	public function test_local_only_numbers_quoted_in_the_docs() {
		$thresholds = aifd_get_default_thresholds();

		$this->assertSame( array( 'block' => 60, 'review' => 40 ), $thresholds, 'Docs: Review from 40, Block from 60' );
		$this->assertEqualsWithDelta( 2.27, array_sum( aifd_get_default_signal_weights() ), 0.0001, 'Docs: the weights add up to 2.27' );
		$this->assertSame( 16, count( aifd_get_default_signal_weights() ), 'Docs: 16 signals' );
		$this->assertSame( 13, $this->best_score_with( 1 ), 'Docs: the strongest signal is worth 13 points' );
		$this->assertSame( 4, $this->fewest_signals_to_reach( $thresholds['review'] ), 'Docs: Review needs at least four signals' );
		$this->assertSame( 6, $this->fewest_signals_to_reach( $thresholds['block'] ), 'Docs: Block needs at least six signals' );
	}

	public function test_score_is_normalised_share_of_total_weight() {
		$weights = aifd_get_signal_weights();
		$result  = aifd_calculate_risk_score( $this->clean_signals( array( 'card_testing_pattern' => true ) ) );

		$expected = round( $weights['card_testing_pattern'] / array_sum( $weights ) * 100 );
		$this->assertEquals( $expected, $result['risk_score'] );
	}

	public function test_reasons_list_each_fired_signal_with_its_weight() {
		$result = aifd_calculate_risk_score( $this->clean_signals( array( 'disposable_email' => true ) ) );

		$this->assertSame( array( 'disposable_email' ), $result['fired_signals'] );
		$this->assertStringContainsString( 'Disposable or suspicious email (+25)', $result['reasons'][0] );
	}

	public function test_custom_thresholds_are_respected() {
		update_option( 'aifd_block_threshold', 10 );
		update_option( 'aifd_review_threshold', 5 );

		$result = aifd_calculate_risk_score( $this->clean_signals( array( 'card_testing_pattern' => true ) ) );
		$this->assertSame( 'BLOCK', $result['decision'] );

		$result = aifd_calculate_risk_score( $this->clean_signals( array( 'odd_hours' => true ) ) );
		$this->assertSame( 'ALLOW', $result['decision'], '5% weight → score 2, below review threshold 5' );
	}

	public function test_score_exactly_on_threshold_uses_higher_band() {
		$weights = aifd_get_signal_weights();
		$score   = round( $weights['card_testing_pattern'] / array_sum( $weights ) * 100 );

		update_option( 'aifd_review_threshold', $score );
		update_option( 'aifd_block_threshold', 100 );

		$result = aifd_calculate_risk_score( $this->clean_signals( array( 'card_testing_pattern' => true ) ) );
		$this->assertSame( 'REVIEW', $result['decision'] );
	}

	public function test_merchant_weights_override_defaults() {
		update_option( 'aifd_merchant_weights', array( 'odd_hours' => 0.5 ) );

		$weights = aifd_get_signal_weights();
		$this->assertSame( 0.5, $weights['odd_hours'] );
		$this->assertSame( 0.30, $weights['card_testing_pattern'], 'Untouched signals keep defaults' );
	}

	public function test_override_lowers_fired_signal_weights_and_counts_them() {
		aifd_adjust_weights_on_override( array( 'guest_checkout', 'no_phone_number' ) );

		$weights = aifd_get_signal_weights();
		$this->assertEqualsWithDelta( 0.03, $weights['guest_checkout'], 0.0001 );
		$this->assertEqualsWithDelta( 0.08, $weights['no_phone_number'], 0.0001 );
		$this->assertSame( 0.30, $weights['card_testing_pattern'] );

		$counts = get_option( 'aifd_signal_override_counts' );
		$this->assertSame( 1, $counts['guest_checkout'] );
	}

	public function test_override_never_drops_a_weight_below_the_floor() {
		for ( $i = 0; $i < 10; $i++ ) {
			aifd_adjust_weights_on_override( array( 'odd_hours' ) );
		}

		$weights = aifd_get_signal_weights();
		$this->assertEqualsWithDelta( 0.01, $weights['odd_hours'], 0.0001 );
		$this->assertSame( 10, get_option( 'aifd_signal_override_counts' )['odd_hours'] );
	}

	public function test_override_ignores_unknown_signals() {
		aifd_adjust_weights_on_override( array( 'not_a_signal' ) );
		$this->assertArrayNotHasKey( 'not_a_signal', aifd_get_signal_weights() );
	}
}
