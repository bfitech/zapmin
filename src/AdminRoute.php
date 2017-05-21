<?php


namespace BFITech\ZapAdmin;


use BFITech\ZapCore\Common;
use BFITech\ZapCore\Logger;
use BFITech\ZapCore\Router;
use BFITech\ZapStore\SQL;
use BFITech\ZapStore\RedisConn;


/**
 * AdminRoute class.
 *
 * This is a thin layer than glues router and storage together.
 * Subclassess extend this instead of abstract AdminStore.
 *
 * @see AdminRouteDefault for limited example.
 */
class AdminRoute extends AdminStore {

	/**
	 * Core instance.
	 *
	 * Subclasses are expected to collect HTTP variables with this.
	 */
	public $core = null;

	/**
	 * Constructor.
	 *
	 * @param Router $core Router instance.
	 * @param SQL $store SQL instance.
	 * @param Logger $logger Logger instance.
	 * @param RedisConn $redis Redis instance.
	 */
	public function __construct(
		Router $core, SQL $store, Logger $logger=null,
		RedisConn $redis=null
	) {
		$this->core = $core;
		parent::__construct($store, $logger, $redis);
	}

	/**
	 * Standard wrapper for Router::route.
	 *
	 * @param string $path Router path.
	 * @param callable $callback Router callback.
	 * @param string|array $method Router request method.
	 */
	public function route($path, $callback, $method='GET') {
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

