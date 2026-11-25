<?php
namespace Hifqa\Tests\Integration;

use Hifqa\Tests\TestCase;

/**
 * The 1.1.0 block default (60) applies only where the store saved nothing.
 */
class ThresholdDefaultsTest extends TestCase {

	public function set_up() {
		parent::set_up();
		delete_option( 'aifd_block_threshold' );
		delete_option( 'aifd_review_threshold' );
	}

	/**
	 * The stored value itself, bypassing any registered default.
	 */
	private function stored( $option ) {
		return get_option( $option, 'not-saved' );
	}

	public function test_defaults_are_60_and_40() {
		// Hard-coded on purpose: these are the documented defaults.
		$this->assertSame( array( 'block' => 60, 'review' => 40 ), aifd_get_default_thresholds() );
	}

	public function test_block_threshold_is_60_when_nothing_is_saved() {
		$this->assertSame( array( 'block' => 60, 'review' => 40 ), aifd_get_thresholds() );
	}

	public function test_saved_thresholds_survive_the_update() {
		// What a 1.0.0 store that saved its settings form has in the database.
		update_option( 'aifd_block_threshold', 70 );
		update_option( 'aifd_review_threshold', 40 );

		// Everything 1.1.0 does on a normal request: register settings, score an order, read thresholds.
		aifd_register_settings();
		$this->make_order( $this->risky_order_props() );
		$thresholds = aifd_get_thresholds();

		$this->assertSame( 70, $thresholds['block'] );
		$this->assertSame( 40, $thresholds['review'] );
		$this->assertEquals( 70, $this->stored( 'aifd_block_threshold' ) );
		$this->assertEquals( 40, $this->stored( 'aifd_review_threshold' ) );
	}

	public function test_a_saved_threshold_of_70_still_decides_orders() {
		update_option( 'aifd_block_threshold', 70 );

		$verdict = aifd_decision_for_score( 65 );

		$this->assertSame( 'REVIEW', $verdict['decision'], '65 is below the saved 70, so it is not blocked' );
	}

	public function test_default_is_read_but_never_written() {
		aifd_register_settings();
		$this->make_order( $this->risky_order_props() );
		aifd_get_thresholds();

		$this->assertSame( 'not-saved', $this->stored( 'aifd_block_threshold' ) );
		$this->assertSame( 'not-saved', $this->stored( 'aifd_review_threshold' ) );
	}

	public function test_registered_setting_defaults_match() {
		aifd_register_settings();
		$registered = get_registered_settings();

		$this->assertSame( 60, $registered['aifd_block_threshold']['default'] );
		$this->assertSame( 40, $registered['aifd_review_threshold']['default'] );
		$this->assertEquals( 60, get_option( 'aifd_block_threshold' ), 'get_option without a default uses the registered one' );
	}

	public function test_missing_threshold_options_do_not_break_scoring() {
		$order = $this->make_order();

		$this->assertSame( 'ALLOW', $order->get_meta( '_aifd_decision' ) );
		$this->assertSame( 'BLOCK', aifd_decision_for_score( 60 )['decision'] );
		$this->assertSame( 'REVIEW', aifd_decision_for_score( 59 )['decision'] );
		$this->assertSame( 'ALLOW', aifd_decision_for_score( 39 )['decision'] );
	}

	public function test_settings_page_shows_the_default_when_nothing_is_saved() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		aifd_render_settings_page();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '/name="aifd_block_threshold" value="60"/', $html );
		$this->assertMatchesRegularExpression( '/name="aifd_review_threshold" value="40"/', $html );
	}

	public function test_settings_page_shows_a_saved_value() {
		update_option( 'aifd_block_threshold', 70 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		aifd_render_settings_page();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '/name="aifd_block_threshold" value="70"/', $html );
	}

	public function test_prompt_bands_follow_the_store_thresholds() {
		$prompt = aifd_build_prompt( $this->clean_signals() );
		$this->assertStringContainsString( 'REVIEW / MEDIUM from 40, BLOCK / HIGH from 60', $prompt );
		$this->assertStringNotContainsString( '70-100', $prompt );

		update_option( 'aifd_block_threshold', 75 );
		update_option( 'aifd_review_threshold', 30 );

		$this->assertStringContainsString( 'REVIEW / MEDIUM from 30, BLOCK / HIGH from 75', aifd_build_prompt( $this->clean_signals() ) );
	}

	public function test_dashboard_receives_the_store_thresholds() {
		$GLOBALS['wp_scripts'] = null;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'aifd_block_threshold', 75 );

		aifd_enqueue_react( 'toplevel_page_hifqa-fraud-detector' );
		$data = wp_scripts()->get_data( 'aifd-dashboard', 'data' );

		$this->assertStringContainsString( '"thresholds":{"block":75,"review":40}', $data );
		$GLOBALS['wp_scripts'] = null;
	}
}
