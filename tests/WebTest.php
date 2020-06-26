<?php


use BFITech\ZapCoreDev\TestCase;
use BFITech\ZapCore\Logger;
use BFITech\ZapCoreDev\RouterDev;
use BFITech\ZapCoreDev\RoutingDev;
use BFITech\ZapStore\SQLite3;
use BFITech\ZapAdmin\Admin;
use BFITech\ZapAdmin\AuthCtrl;
use BFITech\ZapAdmin\AuthManage;
use BFITech\ZapAdmin\RouteDefault;
use BFITech\ZapAdmin\WebDefault;


class WebTest extends TestCase {

	private static $sql;

	private function make_zcore() {
		$log = new Logger(Logger::ERROR, '/dev/null');

		$rdev = new RoutingDev;
		if (!self::$sql)
			self::$sql = new SQLite3(['dbname' => ':memory:'], $log);
		$core = $rdev::$core;
		$core->config('logger', $log);

		$admin = (new Admin(self::$sql, $log))
			->config('check_tables', true)
			->init();
		$ctrl = new AuthCtrl($admin, $log);
		$manage = new AuthManage($admin, $log);

		$zcore = new RouteDefault($core, $ctrl, $manage);
		return [$zcore, $rdev, $core];
	}

	/**
	 * This only checks whether corresponding callback on default
	 * routing list exists.
	 */
	public function test_web() {
		$eq = $this->eq();

		list($zcore, $_, $_) = $this->make_zcore();
		$web = new WebDefault($zcore, false);

		foreach ($web::$routes as $rtn) {
			# reset zcore each time
			list($zcore, $rdev, $core) = $this->make_zcore();

			# callback existence
			$callback = $rtn[1];
			if (!method_exists($zcore, $callback)) {
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
			$rdev->request($reqpath, $method);
			$zcore->route($rtn[0], [$zcore, $callback], $method);

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

			# always 401 on other methods due to empty token
			$eq($core::$code, '401');
		}
	}
}
