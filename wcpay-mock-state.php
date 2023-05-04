<?php

/**
 * Plugin Name: WCPay Mock State
 * Description: Mock UI State for WCPay
 */

class WCPay_Mock_State {

	const PLUGIN_SLUG = 'wcpay-mock-state';

	/**
	 * @var string[]
	 * These are the routes that are able to be overridden by the plugin.
	 * The key is used to define the path to the available JSON files, and the value is the route to override.
	 */
	private $routes = [
		'deposits'        => 'payments/deposits/overview-all',
		// recent deposits on the overview page
		'recent-deposits' => 'payments/deposits?page=1&pagesize=3&sort=date&direction=desc',
		'disputes' => 'payments/disputes?',

	];

	/**
	 * @var array|null
	 */
	private $overrides = null;
	/**
	 * @var WCPay_Mockable_State[]|null
	 */
	private $mockable_states = null;

	public function __construct() {
		add_filter( 'rest_request_after_callbacks', [ $this, 'maybe_overwrite_rest_response' ], 10, 3 );
		add_action( 'admin_bar_menu', [ $this, 'add_menu' ], 300 );
		add_action( 'template_redirect', [ $this, 'act' ] );
		add_action( 'admin_init', [ $this, 'act' ] );
	}


	private function load_mockable_states() {
		if ( $this->mockable_states === null ) {
			$this->mockable_states = [];

			$data              = get_transient( self::PLUGIN_SLUG );
			$overridden_states = $data === false ? [] : json_decode( $data, true );

			foreach ( $this->routes as $key => $path ) {
				$this->mockable_states[ $key ] = new WCPay_Mockable_State( $key, $overridden_states[ $key ] ?? null );
			}
		}

		return $this->mockable_states;
	}

	private function update_state( $key, $value ) {
		$this->load_mockable_states();
		$mockable = $this->mockable_states[ $key ] ?? null;
		if ( ! $mockable ) {
			return;
		}
		if ( $value !== '' && ! in_array( $value, $mockable->get_available_states(), true ) ) {
			return;
		}
		$mockable->set_current_state( $value );

		$to_save = [];
		foreach ( $this->mockable_states as $k => $mockable_state ) {
			$to_save[ $k ] = $mockable_state->get_current_state();
		}

		set_transient( self::PLUGIN_SLUG, json_encode( array_filter( $to_save ) ), DAY_IN_SECONDS );
	}

	/**
	 * @return null|string
	 */
	private function find_route_key() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$rest_prefix = rest_get_url_prefix();
		$request_uri = $_SERVER['REQUEST_URI'];

		$is_rest_request = ( false !== strpos( $request_uri, $rest_prefix ) );
		if ( ! $is_rest_request ) {
			return null;
		}

		foreach ( $this->routes as $key => $value ) {
			if ( strpos( $request_uri, $value ) !== false ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * @param WP_REST_Response|null $response
	 * @param null $handler
	 * @param WP_REST_Request|null $request
	 *
	 * @return WP_REST_Response|null
	 */
	public function maybe_overwrite_rest_response( $response = null, $handler = null, $request = null ) {
		if ( ! ( $request instanceof WP_REST_Request ) ) {
			return $response;
		}

		$route_key = $this->find_route_key();
		$override  = $this->find_override( $route_key );

		$override_data = $override ? $override->get_state_data() : null;

		if ( $override_data === null ) { // technically null might be a valid response, but we'll assume it's not
			return $response;
		}

		if ( $response instanceof WP_REST_Response ) {
			$response->set_data( $override_data );
		} else {
			$response = new WP_REST_Response( $override_data );
		}

		return $response;
	}

	/**
	 * @param string|null $route_key
	 *
	 * @return WCPay_Mockable_State|null
	 */
	private function find_override( ?string $route_key ): ?WCPay_Mockable_State {
		if ( $route_key === null ) {
			return null;
		}
		$this->load_mockable_states();

		return $this->mockable_states[ $route_key ] ?? null;
	}

	/**
	 * Checks if an action should be performed, and performs it.
	 *
	 * This is done on `template_redirect`, meaning that most changes
	 * will necessitate a refresh/redirect to the same page.
	 */
	public function act() {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) || ! isset( $_GET[ self::PLUGIN_SLUG . '-action' ] ) ) {
			return;
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], self::PLUGIN_SLUG ) ) {
			echo 'Invalid nonce. Go back, refresh, and try again!';
			exit;
		}

		$return_url = remove_query_arg( self::PLUGIN_SLUG . '-action' );
		$return_url = remove_query_arg( self::PLUGIN_SLUG . '-override', $return_url );
		$return_url = remove_query_arg( '_wpnonce', $return_url );

		$action   = sanitize_key( $_GET[ self::PLUGIN_SLUG . '-action' ] );
		$override = sanitize_key( $_GET[ self::PLUGIN_SLUG . '-override' ] );

		// do a quick safety check to make sure the override exists

		$this->update_state( $action, $override );

		wp_safe_redirect( $return_url );
		exit;
	}

	public function add_menu( WP_Admin_Bar $admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; // Only available for admins in the front-end.
		}

		$root_id = self::PLUGIN_SLUG;

		$separator = '<span style="display:block; border-bottom: 1px solid grey; margin: 3px;"></span>';

		$root_meta          = [];
		$root_meta['title'] = 'WCPay dev shortcuts. Do not use unless you know what you are doing.';

		$admin_bar->add_menu(
			[
				'id'    => $root_id,
				'title' => 'WCPay Mock State',
				'meta'  => $root_meta,
			]
		);
		$this->load_mockable_states();
		foreach ( $this->mockable_states as $key => $mockable_state ) {
			$path_root = $root_id . '/' . $key;

			$active_state = $mockable_state->get_current_state();

			$admin_bar->add_menu(
				[
					'id'     => $path_root,
					'parent' => $root_id,
					'title'  => $this->render_icon( $active_state !== null ) . $this->title_case( $key ),
				]
			);
			$admin_bar->add_menu(
				[
					'id'     => $path_root . '/reset',
					'parent' => $path_root,
					'title'  => $this->render_icon( $active_state === null ) . 'Reset',
					'href'   => $this->get_action_url( $key, '' ),
					'meta'   => [
						'html' => $separator,
					],
				]
			);

			foreach ( $mockable_state->get_available_states() as $available_state ) {
				$admin_bar->add_menu(
					[
						'id'     => $path_root . '/' . $available_state,
						'parent' => $path_root,
						'title'  => $this->render_icon( $active_state === $available_state ) . $this->title_case( $available_state ),
						'href'   => $this->get_action_url( $key, $available_state ),
					]
				);
			}

		}

	}

	/**
	 * Generates an action URL with a nonce.
	 *
	 * @param string $key The action, which should be performed, see `act()`.
	 * @param string $override The action, which should be performed, see `act()`.
	 * @param bool $preserve_url Whether the action should land you on the same page.
	 *
	 * @return string
	 */
	protected function get_action_url( $key, $override ) {
		$url = add_query_arg(
			[
				self::PLUGIN_SLUG . '-action'   => $key,
				self::PLUGIN_SLUG . '-override' => $override
			]
		);

		return wp_nonce_url(
			$url,
			self::PLUGIN_SLUG
		);
	}

	private function title_case( string $override ): string {
		return ucwords( str_replace( '-', ' ', $override ) );
	}

	/**
	 * @param bool $is_active
	 *
	 * @return string
	 */
	private function render_icon( bool $is_active ) {
		return '<span style="display: inline-block; width: 14px; text-align: center; margin-right: 5px;' . ( $is_active ? 'color: green;' : '' ) . ' ">' . ( $is_active ? '●' : '○' ) . '</span> ';
	}
}

class WCPay_Mockable_State {

	/**
	 * @var string
	 */
	private $mock;
	/**
	 * @var string
	 */
	private $state;

	public function __construct( string $mock, ?string $state ) {
		$this->mock  = $mock;
		$this->state = $state ?? '';
	}

	public function get_available_states(): array {
		$available_states = [];
		foreach ( glob( $this->get_mock_path() . '/*.json' ) as $file ) {
			$available_states[] = substr( basename( $file ), 0, - 5 );
		}

		return $available_states;
	}

	private function get_mock_path(): string {
		return __DIR__ . '/data/' . $this->mock;
	}

	private function get_state_path(): string {
		return $this->get_mock_path() . '/' . $this->state . '.json';
	}

	/**
	 * @return array|null
	 */
	public function get_state_data() {
		if ( ! file_exists( $this->get_state_path() ) ) {
			return null;
		}

		return json_decode( file_get_contents( $this->get_state_path() ), true );
	}

	public function get_current_state(): ?string {
		return $this->state === '' ? null : $this->state;
	}

	public function set_current_state( ?string $state ): void {
		$this->state = $state ?? '';
	}

}

new WCPay_Mock_State();