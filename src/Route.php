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

	/** Router instancs */
	public static $core;
	/** AuthCtrl instance. */
	public static $ctrl;
	/** AuthManage instance. */
	public static $manage;

	/** Token name */
	protected $token_name;
	/** Token expiration */
	protected $expiration;

	/**
	 * Constructor.
	 *
	 * @param Router $core Router instance.
	 * @param AuthCtrl $ctrl AuthCtrl instance.
	 * @param AuthManage $manage AuthManage instance.
	 */
	public function __construct(
		Router $core, AuthCtrl $ctrl, AuthManage $manage
	) {
		self::$core = $core;
		self::$ctrl = $ctrl;
		self::$manage = $manage;

		$this->token_name = $ctrl::$admin->get_token_name();
		$this->expiration = $ctrl::$admin->get_expiration();
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
				static::$ctrl->set_token_value(
					$args['cookie'][$token_name]);
				static::$manage->set_token_value(
					$args['cookie'][$token_name]);
			} elseif (isset($args['header']['authorization'])) {
				# via request header
				$auth = explode(' ', $args['header']['authorization']);
				if (count($auth) == 2 && $auth[0] == $token_name)
					static::$ctrl->set_token_value($auth[1]);
			}
			# execute calback
			$callback($args);
		}, $method, $is_raw);
	}

}
