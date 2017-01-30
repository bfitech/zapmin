<?php


namespace BFITech\ZapAdmin;


use BFITech\ZapCore as zc;
use BFITech\ZapStore as zs;


class AdminRoute extends AdminStore {

	/**
	 * Core instance. Subclasses are expected to collect HTTP variables
	 * with this.
	 */
	public static $core = null;
	/**
	 * Storage instance. Subclasses are expected to manipulate tables
	 * with this.
	 */
	public static $store = null;

	/** Path prefix. */
	protected $prefix = null;
	/** Collection of routes. */
	protected $routes = [];

	/** Marker whether routes have been processed. */
	protected $routes_processed = false;

	private $token_name = null;
	private $token_value = null;

	/**
	 * Constructor.
	 *
	 * @param string $home Core home.
	 * @param string $host Core host.
	 * @param array $dbargs Database connection parameter.
	 * @param int $expiration Expiration interval.
	 * @param bool $force_create_table Whether overwriting tables is allowed.
	 * @param string $token_name Name of authorization token. Defaults
	 *     to 'zapmin'.
	 * @param string $route_prefix Route prefix.
	 */
	public function __construct(
		$home=null, $host=null,
		$dbargs=[], $expiration=null, $force_create_table=false,
		$token_name=null, $route_prefix=null
	) {
		self::$core = new zc\Router($home, $host);
		self::$store = new zs\SQL($dbargs);
		self::$store->open();
		parent::__construct(self::$store, $expiration, $force_create_table);

		if (!$token_name)
			$token_name = 'zapmin';
		$this->token_name = $token_name;
		$this->prefix = $this->verify_prefix($route_prefix);
	}

	private function verify_prefix($prefix) {
		if (!$prefix)
			return null;
		$prefix = trim($prefix, '/');
		if (!$prefix)
			return null;
		return '/' . $prefix;
	}

	/**
	 * Safely retrieve authentication token name.
	 *
	 * Useful for the client for e.g. setting cookie name or HTTP
	 * request header.
	 */
	public function get_token_name() {
		return $this->token_name;
	}

	# route manipulation

	/**
	 * Add a route.
	 */
	public function add_route($path, $callback_method, $request_method) {
		if ($this->routes_processed)
			return;
		if ($this->prefix)
			$path = $this->prefix . $path;
		$this->routes[] = [
			'path' => $path,
			'callback_method' => $callback_method,
			'request_method' => $request_method,
		];
	}

	/**
	 * Delete a registered route.
	 *
	 * @param string $path Registered path.
	 * @param string|array $request_method Request method associated
	 *     with the route.
	 * @todo
	 *     - Unlike its add_route() counterpart, this doesn't take
	 *       prefix into account.
	 *     - The way it handles request method comparison is not foolproof.
	 */
	public function delete_route($path, $request_method) {
		if ($this->routes_processed)
			return;
		$this->routes = array_filter($this->routes, function($arr){
			if ($arr['path'] != $path)
				return true;
			if ($arr['request_method'] != $request_method)
				return true;
			return false;
		});
	}

	# wrappers

	/**
	 * Add a route.
	 *
	 * @param array $route An array with keys: 'path', 'callback_method',
	 *     'request_method'. 'callback_method' may be string with proper
	 *     namespace for functions, or a tuple [object, method] for class
	 *     instances. 'callback_method' cannot accept direct static method
	 *     call without instantiation.
	 */
	private function _apply_route($route) {
		extract($route, EXTR_SKIP);
		if (is_string($callback_method)) {
			# for functions
			if (!function_exists($callback_method))
				return;
		} elseif (!method_exists(
			$callback_method[0], $callback_method[1])
		) {
			# for methods
			return;
		}
		self::$core->route($path, function($args) use($callback_method){
			# set token if available
			if (isset($args['cookie'][$this->token_name])) {
				$this->set_user_token(
					$args['cookie'][$this->token_name]);
			} elseif (isset($args['header']['authorization'])) {
				$auth = explode(' ', $args['header']['authorization']);
				if ($auth[0] == $this->token_name) {
					$this->set_user_token($auth[1]);
				}
			}
			# execute calback
			$callback_method($args);
		}, $request_method);
	}

	/**
	 * Retrieve all added routes.
	 *
	 * Useful to inspect routes before processing them.
	 *
	 * @return array An array with each element having keys: 'path', name
	 *     of 'callback_method', 'request_method'.
	 */
	public function show_routes() {
		if ($this->routes_processed)
			return;
		return array_map(function($arr){
			$arr['callback_method'] = $arr['callback_method'][1];
			return $arr;
		}, $this->routes);
	}

	/**
	 * Execute route handlers fo realz.
	 */
	public function process_routes() {
		if ($this->routes_processed)
			return;
		$this->routes_processed = true;
		foreach ($this->routes as $route)
			$this->_apply_route($route);
	}

}

