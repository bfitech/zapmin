<?php

require_once __DIR__ . '/Common.php';


use BFITech\ZapCore\Logger;
use BFITech\ZapCoreDev\RouterDev;
use BFITech\ZapCoreDev\RoutingDev;
use BFITech\ZapStore\SQLite3;

use BFITech\ZapAdmin\Admin;
use BFITech\ZapAdmin\AuthCtrl;
use BFITech\ZapAdmin\AuthManage;
use BFITech\ZapAdmin\RouteDefault;
use BFITech\ZapAdmin\WebDefault;


class WebTest extends Common {

	public function test_web() {
		$log = new Logger(Logger::ERROR, '/dev/null');

		$core = (new RouterDev)->config('logger', $log);
		$rdev = new RoutingDev($core);
		$store = new SQLite3(['dbname' => ':memory:'], $log);

		$admin = (new Admin($store, $log))
			->config('check_tables', true)
			->init();
		$ctrl = new AuthCtrl($admin, $log);
		$manage = new AuthManage($admin, $log);

		$route = new RouteDefault($core, $ctrl, $manage);
		$web = new WebDefault($route, false);

		$eq = $this->eq();

		foreach ($web::$routes as $rtn) {
			# callback existence
			$callback = $rtn[1];
			if (!method_exists($route, $callback)) {
				$this->fail(sprintf("MISSING: %s::%s",
					get_class($route), $callback));
			}

			# reconstructed request path, identical with route path
			# since there's no compound
			$reqpath = $rtn[0];

			# satisfying method
			$method = $rtn[2] ?? 'GET';
			if (is_array($method))
				$method = $method[0];

			# fake routing
			# @fixme RoutingDev does not clear up fake HTTP variables
			# after usage, causing a polluted subsequent requests. One
			# way to clear it is resetting the params manually below.
			$rdev->request($reqpath, $method, ['post' => []]);
			$route->route($rtn[0], [$route, $callback], $method);
			if ($callback == 'route_home') {
				# home is always 200
				$eq($core::$code, '200');
				continue;
			} 
			if ($callback == 'route_byway') {
				# byway is 403 on failure
				$eq($core::$code, '403');
				continue;
			}
			# always 401 due to empty token
			$eq($core::$code, '401');
		}
	}
}
