<?php


namespace BFITech\ZapAdmin;


use BFITech\ZapCore\Router;


/**
 * Route class.
 *
 * This is a thin layer than glues router and storage together.
 * Subclassess extend this.
 *
 * @see RouteDefault for limited example.
 */
class Route {

	public static $core;
	public static $admin;
	public static $auth;

	protected $token_name;
	protected $expiration;

	public function __construct(Router $core, Admin $admin) {
		self::$core = $core;
		self::$admin = $admin;

		$this->token_name = $admin->get_token_name();
		$this->expiration = $admin->get_expiration();
	}

	/**
	 * Standard wrapper for Router::route.
	 *
	 * @param string $path Router path.
	 * @param callable $callback Router callback.
	 * @param string|array $method Router request method.
	 * @param bool $is_raw If true, accept raw request body, POST only.
	 */
	public function route(
		string $path, callable $callback, $method='GET',
		bool $is_raw=null
	) {
		static::$core->route($path, function($args) use($callback) {
			$token_name = $this->token_name;
			# set token if available
			if (isset($args['cookie'][$token_name])) {
				# via cookie
				$this->set_token_value($args['cookie'][$token_name]);
			} elseif (isset($args['header']['authorization'])) {
				# via request header
				$auth = explode(' ', $args['header']['authorization']);
				if (count($auth) == 2 && $auth[0] == $token_name)
					$this->set_token_value($auth[1]);
			}
			# execute calback
			$callback($args);
		}, $method, $is_raw);
	}

}
