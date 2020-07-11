<?php declare(strict_types=1);


namespace BFITech\ZapAdmin;


/**
 * RouteDefault dispatcher.
 *
 * @see Demo.DemoRun for example deployment.
 */
class WebDefault {

	/**
	 * List of RouteAdmin callbacks with their associated path and
	 * request method.
	 */
	public static $zcol = [
		['/',         'route_home'],
		['/status',   'route_status'],
		['/login',    'route_login',    'POST'],
		['/logout',   'route_logout',   ['GET', 'POST']],
		['/chpasswd', 'route_chpasswd', 'POST'],
		['/chbio',    'route_chbio',    'POST'],
		['/register', 'route_register', 'POST'],
		['/useradd',  'route_useradd',  'POST'],
		['/userdel',  'route_userdel',  'POST'],
		['/userlist', 'route_userlist'],
		['/byway',    'route_byway',    'POST'],
	];

	private static $zcore;

	/**
	 * Constructor.
	 *
	 * @param RouteAdmin $zcore RouteAdmin instance. Not to be confused
	 *     with zapcore Router which is typically instantiated as $core.
	 * @param bool $run If true, execute routing immediately. Otherwise,
	 *     defer execution. Useful when you want to merge routing with
	 *     other application workflow.
	 */
	public function __construct(RouteAdmin $zcore, bool $run=true) {
		self::$zcore = $zcore;

		$zcol =& self::$zcol;
		foreach ($zcol as $key => $zdata) {
			if (count($zdata) < 3)
				$zdata[] = 'GET';
			if (is_array($zdata[2]))
				$zdata[2][] = 'OPTIONS';
			else
				$zdata[2] = [$zdata[2], 'OPTIONS'];
			if (count($zdata) < 4)
				$zdata[] = false;
			$zcol[$key] = $zdata;
		}

		// @codeCoverageIgnoreStart
		if ($run)
			$this->run();
		// @codeCoverageIgnoreEnd
	}

	/**
	 * @codeCoverageIgnore
	 */
	private function run() {
		$zcore = self::$zcore;
		foreach (self::$zcol as $zdata) {
			list($path, $cbname, $method, $is_raw) = $zdata;
			$zcore::$core->route(
				$path, [$zcore, $cbname], $method, $is_raw);
		}
	}

}
