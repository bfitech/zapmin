<?php


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore as zc;
use BFITech\ZapStore as zs;
use BFITech\ZapAdmin as za;


if (!defined('HTDOCS'))
	define('HTDOCS', __DIR__ . '/htdocs-test');


class RouterPatched extends zc\Router {

	public static function header($header_string, $replace=false) {
		// total silence
		return;
	}
	public static function header_halt($str=null) {
		if ($str)
			echo $str;
	}
}

class AdminRouteTest extends TestCase {

	protected static $logger;
	protected static $dbfile;

	protected static $core;
	protected static $store;
	protected static $adm;

	public static function setUpBeforeClass() {
		$logfile = HTDOCS . '/zapmin-test-route.log';
		self::$dbfile = HTDOCS . '/zapmin-test-route.sq3';
		foreach ([$logfile, self::$dbfile] as $fpath) {
			if (file_exists($fpath))
				unlink($fpath);
		}

		self::$logger = new zc\Logger(
			zc\Logger::DEBUG, $logfile);

		$_SERVER['REQUEST_URI'] = '/';
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	public static function tearDownAfterClass() {
	}

	public function test_constructor() {
		# must fake these earlier because Router will
		# process it immediately upon instantiation
		$_SERVER['REQUEST_URI'] = '/usr/test';

		# must use this patched Router to silent output
		$core = new RouterPatched(null, null, false, self::$logger);

		# other fake HTTP variables can wait after Router
		# instantiation
		$_COOKIE['hello_world'] = 'test';

		# let's not use kwargs for a change, see 'prefix'
		# parameter and matched with REQUEST_URI
		$adm = new za\AdminRoute('/ignored', null, false, [
			'dbtype' => 'sqlite3',
			'dbname' => ':memory:',
			], null, false, 'hello_world', '/usr',
			$core, null, self::$logger);

		$this->assertEquals($adm->adm_get_token_name(), 'hello_world');

		ob_start();
		$adm->route('/test', function($args) use($adm){
			$this->assertEquals(
				$args['cookie']['hello_world'], 'test');
			echo "HELLO, FRIEND";
		}, 'GET');
		$this->assertEquals('HELLO, FRIEND', ob_get_clean());

		# there's a matched route, shutdown functions will never
		# be called
		$adm::$core->shutdown();
	}

	private function make_router() {
		# use new instance on every test
		$core = new RouterPatched(
			null, null, false, self::$logger);
		$store = new zs\SQLite3(
			['dbname' => self::$dbfile], self::$logger);
		return new za\AdminRoute([
			'force_create_table' => true,
			'core_instance' => $core,
			'store_instance' => $store,
			'logger_instance' => self::$logger,
		]);
	}

	public function test_home() {
		$_SERVER['REQUEST_URI'] = '/';
		$adm = $this->make_router();

		ob_start();
		$adm->route('/', [$adm, 'route_home']);
		$rv = ob_get_clean();

		$this->assertNotEquals(
			strpos(strtolower($rv), 'it wurks'), false);
	}

	public function test_status() {
		$_SERVER['REQUEST_URI'] = '/status';
		$adm = $this->make_router();

		ob_start();
		$adm->route('/status', [$adm, 'route_status']);
		extract(json_decode(ob_get_clean(), true));
		$this->assertEquals($errno, 1);
	}

	public function test_login() {
		$_SERVER['REQUEST_URI'] = '/login';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$adm = $this->make_router();

		ob_start();
		$adm->route('/login', [$adm, 'route_login'], 'POST');
		extract(json_decode(ob_get_clean(), true));
		$this->assertEquals($errno, 3);
	}

}

