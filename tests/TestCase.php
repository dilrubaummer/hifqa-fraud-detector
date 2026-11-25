<?php
/**
 * Shared base for Hifqa tests.
 */

namespace Hifqa\Tests;

use WC_Order;
use WP_UnitTestCase;

abstract class TestCase extends WP_UnitTestCase {

	/** @var array[] Outgoing HTTP requests seen during the test: [ url, args ]. */
	protected $http_requests = array();

	/** @var callable|null Returns the fake response for a request. */
	protected $http_responder = null;

	public function set_up() {
		parent::set_up();

		// Pin the site clock to midday so the "odd hours" signal never fires by accident.
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 12 - (int) gmdate( 'G' ) );

		update_option( 'woocommerce_default_country', 'US' );

		// Never hit the network. Tests opt in to fake responses via respond_with().
		$this->http_requests  = array();
		$this->http_responder = null;
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );

		reset_phpmailer_instance();
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10 );
		parent::tear_down();
	}

	public function intercept_http( $preempt, $args, $url ) {
		$this->http_requests[] = array( 'url' => $url, 'args' => $args );

		if ( $this->http_responder ) {
			return call_user_func( $this->http_responder, $url, $args );
		}

		return new \WP_Error( 'http_blocked', 'Network disabled in tests' );
	}

	/**
	 * Fake every outgoing HTTP request with the callable's return value.
	 */
	protected function respond_with( callable $responder ) {
		$this->http_responder = $responder;
	}

	protected static function json_response( $body, $code = 200 ) {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array( 'code' => $code, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	// ── Scripted AI agent runs (tool-call path) ────────

	/**
	 * A complete agent run, in the order the system prompt asks for, ending in
	 * calculate_final_risk with this reasoning. $claims are extra figures the
	 * model sends along with it, which the store must ignore.
	 */
	protected function full_agent_run( $reasoning, array $claims = array() ) {
		return array(
			array(
				array( 'check_signal_weights', array( 'include_reasons' => true ) ),
				array( 'search_fraud_patterns', array() ),
			),
			array( array( 'check_velocity', array( 'window_minutes' => 60 ) ) ),
			array( array( 'calculate_final_risk', array_merge( $claims, array( 'reasoning' => $reasoning ) ) ) ),
		);
	}

	/**
	 * Fake an OpenAI agent. Each turn is a list of [ tool name, arguments ]; after
	 * the last turn the model stops. The script starts over for the next order.
	 */
	protected function openai_agent_replies_with( array $turns ) {
		update_option( 'aifd_ai_provider', 'openai' );
		update_option( 'aifd_openai_key', 'sk-test' );

		$replies = array();
		foreach ( $turns as $t => $calls ) {
			$tool_calls = array();
			foreach ( $calls as $c => $call ) {
				$tool_calls[] = array(
					'id'       => "call_{$t}_{$c}",
					'type'     => 'function',
					'function' => array( 'name' => $call[0], 'arguments' => wp_json_encode( (object) $call[1] ) ),
				);
			}
			$replies[] = array( 'choices' => array( array(
				'finish_reason' => 'tool_calls',
				'message'       => array( 'role' => 'assistant', 'content' => null, 'tool_calls' => $tool_calls ),
			) ) );
		}
		$replies[] = array( 'choices' => array( array(
			'finish_reason' => 'stop',
			'message'       => array( 'role' => 'assistant', 'content' => 'Done.' ),
		) ) );

		$this->reply_in_turn( $replies );
	}

	/**
	 * Fake an Anthropic agent, with the same turns as openai_agent_replies_with().
	 */
	protected function anthropic_agent_replies_with( array $turns ) {
		update_option( 'aifd_ai_provider', 'anthropic' );
		update_option( 'aifd_anthropic_key', 'ak-test' );

		$replies = array();
		foreach ( $turns as $t => $calls ) {
			$blocks = array();
			foreach ( $calls as $c => $call ) {
				$blocks[] = array( 'type' => 'tool_use', 'id' => "toolu_{$t}_{$c}", 'name' => $call[0], 'input' => (object) $call[1] );
			}
			$replies[] = array( 'stop_reason' => 'tool_use', 'content' => $blocks );
		}
		$replies[] = array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => 'Done.' ) ) );

		$this->reply_in_turn( $replies );
	}

	/**
	 * Answer successive requests with successive replies, starting over after the last.
	 */
	private function reply_in_turn( array $replies ) {
		$next = 0;
		$this->respond_with( function () use ( $replies, &$next ) {
			$reply = $replies[ $next ];
			$next  = ( $next + 1 ) % count( $replies );
			return self::json_response( $reply );
		} );
	}

	/**
	 * Hosts contacted during the test, in order.
	 */
	protected function requested_hosts() {
		return array_map( function ( $r ) {
			return wp_parse_url( $r['url'], PHP_URL_HOST );
		}, $this->http_requests );
	}

	/**
	 * A registered, established customer (account older than 24h).
	 */
	protected function make_customer() {
		$user_id = self::factory()->user->create( array(
			'role'            => 'customer',
			'user_registered' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
		) );
		return $user_id;
	}

	/**
	 * Build and save an order. Defaults describe a clean, low-risk order;
	 * override any field to add risk signals.
	 */
	protected function make_order( array $props = array() ) {
		$props = array_merge( array(
			'customer_id'         => 0,
			'billing_first_name'  => 'Jane',
			'billing_last_name'   => 'Doe',
			'billing_email'       => 'jane.doe@example.com',
			'billing_phone'       => '555-0100',
			'billing_country'     => 'US',
			'shipping_first_name' => 'Jane',
			'shipping_last_name'  => 'Doe',
			'shipping_address_1'  => '1 Main Street',
			'shipping_country'    => 'US',
			'payment_method'      => 'stripe',
			'customer_ip_address' => '203.0.113.10',
			'total'               => 50,
		), $props );

		$order = new WC_Order();
		foreach ( $props as $key => $value ) {
			$order->{ 'set_' . $key }( $value );
		}
		$order->save(); // Fires woocommerce_new_order → Hifqa scoring.

		return wc_get_order( $order->get_id() );
	}

	/**
	 * Order props that are about as suspicious as a single order can look.
	 */
	protected function risky_order_props( array $overrides = array() ) {
		return array_merge( array(
			'customer_id'         => 0,
			'billing_email'       => 'buyer@mailinator.com',
			'billing_phone'       => '',
			'billing_country'     => 'NG',
			'shipping_first_name' => 'John',
			'shipping_last_name'  => 'Smith',
			'shipping_address_1'  => 'Shipito Freight Forwarding, Unit 4',
			'shipping_country'    => 'US',
			'payment_method'      => 'cod',
			'total'               => 900,
		), $overrides );
	}

	/**
	 * All signal flags off — a baseline for scoring tests.
	 */
	protected function clean_signals( array $overrides = array() ) {
		$flags = array_fill_keys( array_keys( aifd_get_signal_weights() ), false );
		return array_merge( $flags, $overrides );
	}

	protected function sent_mail_subjects() {
		$mailer = tests_retrieve_phpmailer_instance();
		return wp_list_pluck( $mailer->mock_sent, 'subject' );
	}

	protected function hifqa_mail_subjects() {
		return array_values( array_filter( $this->sent_mail_subjects(), function ( $s ) {
			return strpos( $s, 'FRAUD' ) !== false;
		} ) );
	}
}
