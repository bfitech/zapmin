<?php


namespace BFITech\ZapAdmin;


use BFITech\ZapCore\Common;
use BFITech\ZapCore\Logger;
use BFITech\ZapCore\Router;
use BFITech\ZapStore\SQL;


/**
 * AdminRoute class.
 *
 * This is a thin layer than glues router and storage together.
 * Subclassess extends from this instead of abstract AdminStore.
 *
 * @see AdminRouteDefault for limited example.
 */
class AdminRoute extends AdminStore {

	/**
	 * Core instance.
	 *
	 * Subclasses are expected to collect HTTP variables with this. This
	 * is no longer static to allow user to easily change different
	 * router in subclasses.
	 */
	public $core = null;

	private $prefix = null;
	private $token_name = null;
	private $token_value = null;

	/**
	 * Constructor.
	 *
	 * To use a patched core, use $core_instance parameter.
	 *
	 * @param string $home_or_kwargs Core home or kwargs.
	 * @param string $host Core host.
	 * @param string $shutdown Core shutdown function switch.
	 * @param array $dbargs Database connection parameter.
	 * @param int $expiration Expiration interval.
	 * @param bool $force_create_table Whether overwriting tables is allowed.
	 * @param string $token_name Name of authorization token. Defaults
	 *     to 'zapmin'.
	 * @param string $route_prefix Route prefix.
	 * @param Router $core_instance Use this core instance instead of
	 *     instantiating a new one.
	 * @param SQL $store_instance Use this store instance instead of
	 *     instantiating a new one.
	 * @param Logger $logger_instance Logging service.
	 *
	 * @todo Simplify parameters in upcoming minor release.
	 */
	public function __construct(
		$home_or_kwargs=null, $host=null, $shutdown=true,
		$dbargs=[], $expiration=null, $force_create_table=false,
		$token_name=null, $route_prefix=null,
		Router $core_instance=null, SQL $store_instance=null,
		Logger $logger_instance=null
	) {
		if (is_array($home_or_kwargs)) {
			extract(Common::extract_kwargs($home_or_kwargs, [
				'home' => null,
				'host' => null,
				'shutdown' => true,
				'dbargs' => [],
				'expiration' => null,
				'force_create_table' => false,
				'token_name' => null,
				'route_prefix' => null,
				'core_instance' => null,
				'store_instance' => null,
				'logger_instance' => null,
			]));
		} else {
			$home = $home_or_kwargs;
		}

		if (!$logger_instance)
			$logger_instance = new Logger();
		$this->core = $core_instance ? $core_instance
			: new Router($home, $host, $shutdown, $logger_instance);
		$store = $store_instance ? $store_instance
			: new SQL($dbargs, $logger_instance);

		parent::__construct($store, $expiration,
			$force_create_table, $logger_instance);

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
	 * Useful for e.g. setting cookie name or HTTP request header
	 * on the client side.
	 *
	 * @note This doesn't belong in AdminStore which cares about
	 *     token value but not token name.
	 */
	public function adm_get_token_name() {
		return $this->token_name;
	}

	/**
	 * Standard wrapper for Route::route().
	 *
	 * @param string $path Router path.
	 * @param callable $callback Router callback.
	 * @param string|array $method Router request method.
	 */
	public function route($path, $callback, $method='GET') {
		if ($this->prefix)
			$path = $this->prefix . $path;
		$this->core->route($path, function($args) use($callback){
			# set token if available
			if (isset($args['cookie'][$this->token_name])) {
				# via cookie
				$this->adm_set_user_token(
					$args['cookie'][$this->token_name]);
			} elseif (isset($args['header']['authorization'])) {
				# via request header
				$auth = explode(' ', $args['header']['authorization']);
				if ($auth[0] == $this->token_name) {
					$this->adm_set_user_token($auth[1]);
				}
			}
			# execute calback
			$callback($args);
		}, $method);
	}
}

