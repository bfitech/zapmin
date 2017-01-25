<?php


namespace BFITech\ZapAdmin;


use BFITech\ZapCore as zc;
use BFITech\ZapStore as zs;


class AdminRoute extends AdminStore {

	public static $core = null;
	public static $store = null;

	protected $prefix = null;
	protected $routes = [];

	protected $routes_processed = false;

	private $token_name = null;
	private $token_value = null;

	public function __construct(
		$home=null, $host=null,
		$dbargs=[], $expiration=null, $create_table=false,
		$token_name=null, $route_prefix=null
	) {
		self::$core = new zc\Router($home, $host);
		self::$store = new zs\SQL($dbargs);
		self::$store->open();
		parent::__construct(self::$store, $expiration, $create_table);

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

	public function get_token_name() {
		return $this->token_name;
	}

	# route manipulation

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
	 * Collect custom request headers and append it to args.
	 *
	 * @todo Move this to core.
	 * @param array $args Router HTTP variables.
	 */
	public function get_request_headers($args) {
		$args['header'] = [];
		foreach ($_SERVER as $key => $val) {
			if (strpos($key, 'HTTP_') === 0) {
				$key = substr($key, 5, strlen($key));
				$key = strtolower($key);
				$args['header'][$key] = $val;
			}
		}
		return $args;
	}

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
			# collect request headers
			$args = $this->get_request_headers($args);
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

