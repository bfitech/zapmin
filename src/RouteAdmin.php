<?php


namespace BFITech\ZapAdmin;


use BFITech\ZapCore\Router;


/**
 * RouteAdmin class.
 *
 * This is a thin layer than glues router and data model together.
 *
 * By convention, Router instance is named `$core`, while instance of
 * subclasses of this class is named `$zcore`.
 *
 * @see RouteDefault for sample implemetation.
 */
abstract class RouteAdmin {

	/** Router instance. */
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
	 * @param Router $core BFITech.ZapCore.Router instance.
	 * @param AuthCtrl $ctrl AuthCtrl instance.
	 * @param AuthManage $manage AuthManage instance. Leave this blank
	 *     if you don't care about user management.
	 */
	public function __construct(
		Router $core, AuthCtrl $ctrl, AuthManage $manage=null
	) {
		self::$core = $core;
		self::$ctrl = $ctrl;
		self::$manage = $manage;

		$this->token_name = $ctrl::$admin->get_token_name();
		$this->expiration = $ctrl::$admin->get_expiration();

		$core->add_middleware([$this, 'mdw_collect_token']);
	}

	/**
	 * Set token value for both self::$ctrl and self::$manage.
	 *
	 * @param string $token_value Session token value.
	 */
	private function escalate_token_value(string $token_value) {
		self::$ctrl->set_token_value($token_value);
		if (self::$manage)
			self::$manage->set_token_value($token_value);
	}

	/**
	 * Middleware to collect authentication information.
	 *
	 * All byway implementations are using this.
	 *
	 * @param &$args Reference to Router `$args`.
	 */
	final public function mdw_collect_token(&$args) {
		$token_name = $this->token_name;
		$cookie = $args['cookie'];
		# set token if available
		if (isset($cookie[$token_name])) {
			# via cookie
			$this->escalate_token_value($cookie[$token_name]);
			return;
		}
		if (isset($args['header']['authorization'])) {
			# via request header
			$auth = explode(' ', $args['header']['authorization']);
			if (count($auth) == 2 && $auth[0] == $token_name)
				$this->escalate_token_value($auth[1]);
		}
	}

	/**
	 * Wrapper for Router::route.
	 *
	 * @param string $path Router path.
	 * @param callable $callback Router callback.
	 * @param string|array $method Router request method.
	 * @param bool $is_raw If true, accept raw request body. For POST
	 *     request only.
	 * @deprecated As of 2.3, we can use instance of `$core` directly
	 *     to execute BFITech.ZapCore.Router::route.
	 */
	public function route(
		string $path, callable $callback, $method='GET',
		bool $is_raw=null
	) {
		self::$core->route($path, function($args) use($callback) {
			$callback($args);
		}, $method, $is_raw);
	}

}
