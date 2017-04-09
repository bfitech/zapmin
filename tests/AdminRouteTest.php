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
	public static function halt($str=null) {
		if ($str)
			echo $str;
	}
	public static function send_cookie(
		$name, $value='', $expire=0, $path='', $domain='',
		$secure=false, $httponly=false
	) {
		// do nothing
		return;
	}
}

class AdminRouteTest extends TestCase {

	public static $logger;

	public static function setUpBeforeClass() {
		$logfile = HTDOCS . '/zapmin-test-route.log';
		if (file_exists($logfile))
			unlink($logfile);

		self::$logger = new zc\Logger(
			zc\Logger::DEBUG, $logfile);

		$_SERVER['REQUEST_URI'] = '/';
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	public static function tearDownAfterClass() {
	}

	public function setUp() {
		self::$logger->info("TEST STARTED.");
	}

	public function tearDown() {
		self::$logger->info("TEST FINISHED.");
	}

	public function test_constructor() {
		# must fake these earlier because Router will
		# process it immediately upon instantiation
		$_SERVER['REQUEST_URI'] = '/usr/test';

		$logfile = HTDOCS . '/zapmin-test-route-constructor.log';
		$logger = new zc\Logger(zc\Logger::ERROR, $logfile);

		# must use this patched Router to silent output
		$core = new RouterPatched(null, null, false, $logger);

		# other fake HTTP variables can wait after Router
		# instantiation
		$_COOKIE['hello_world'] = 'test';

		# let's not use kwargs for a change, see 'prefix'
		# parameter and match it against REQUEST_URI
		$adm = new za\AdminRoute('/ignored', null, false, [
			'dbtype' => 'sqlite3',
			'dbname' => ':memory:',
			], 3600, false, 'hello_world', '/usr',
			$core, null, $logger);

		$this->assertEquals($adm->adm_get_token_name(), 'hello_world');

		ob_start();
		$adm->route('/test', function($args) use($adm){
			$this->assertEquals(
				$args['cookie']['hello_world'], 'test');
			echo "HELLO, FRIEND";
		}, 'GET');
		$this->assertEquals('HELLO, FRIEND', ob_get_clean());

		# since there's a matched route, calling shutdown functions
		# will take no effect
		$adm::$core->shutdown();

		unlink($logfile);
	}

	private function make_router($store=null) {
		if (!$store)
			$store = new zs\SQLite3(
				['dbname' => ':memory:'], self::$logger);
		# use new instance on every matching mock HTTP request
		$core = new RouterPatched(
			null, null, false, self::$logger);
		return new za\AdminRoute([
			'token_name' => 'test-zapmin',
			'core_instance' => $core,
			'store_instance' => $store,
			'logger_instance' => self::$logger,
		]);
	}

	/**
	 * @depends test_constructor
	 */
	public function test_home() {
		$_SERVER['REQUEST_URI'] = '/';
		$adm = $this->make_router();

		$token_name = $adm->adm_get_token_name();
		$this->assertEquals($token_name, 'test-zapmin');

		ob_start();
		$adm->route('/', [$adm, 'route_home']);
		$rv = ob_get_clean();

		$this->assertNotEquals(
			strpos(strtolower($rv), 'it wurks'), false);
	}

	private function login_cleanup() {
		$_SERVER['REQUEST_URI'] = '/';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		if (isset($_SERVER['HTTP_AUTHORIZATION']))
			unset($_SERVER['HTTP_AUTHORIZATION']);
		$_GET = $_POST = $_COOKIE = [];
	}

	private function login_sequence($store=null) {
		$this->login_cleanup();

		$_SERVER['REQUEST_URI'] = '/login';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['uname'] = 'root';
		$_POST['upass'] = 'admin';

		$adm = $this->make_router($store);

		ob_start();
		$adm->route('/login', [$adm, 'route_login'], 'POST');
		extract(json_decode(ob_get_clean(), true));
		$this->assertEquals($errno, 0);
		$this->assertEquals($data['uid'], 1);
		$_SERVER['HTTP_AUTHORIZATION'] = sprintf(
			'%s %s', $adm->adm_get_token_name(),
			$data['token']);
		$_GET = $_POST = $_COOKIE = [];
	}

	/**
	 * @depends test_home
	 */
	public function test_status() {
		$this->login_cleanup();

		$_SERVER['REQUEST_URI'] = '/status';
		$adm = $this->make_router();

		ob_start();
		$adm->route('/status', [$adm, 'route_status']);
		extract(json_decode(ob_get_clean(), true));

		$this->assertEquals($errno, 1);

		###

		$this->login_sequence($adm::$store);

		###

		$_SERVER['REQUEST_URI'] = '/status';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$adm = $this->make_router($adm::$store);

		ob_start();
		$adm->route('/status', [$adm, 'route_status']);
		extract(json_decode(ob_get_clean(), true));

		$this->assertEquals($errno, 0);
	}

	/**
	 * @depends test_status
	 */
	public function test_login_logout() {
		$this->login_cleanup();

		$_SERVER['REQUEST_URI'] = '/login';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$adm = $this->make_router();

		ob_start();
		$adm->route('/login', [$adm, 'route_login'], 'POST');
		extract(json_decode(ob_get_clean(), true));

		$this->assertEquals($errno, 3);

		###

		$this->login_sequence($adm::$store);

		###

		$_SERVER['REQUEST_URI'] = '/logout';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$adm = $this->make_router($adm::$store);

		ob_start();
		$adm->route('/logout', [$adm, 'route_logout']);
		extract(json_decode(ob_get_clean(), true));

		$this->assertEquals($errno, 0);
	}

	/**
	 * @depends test_login_logout
	 */
	public function test_chpasswd() {
		$adm = $this->make_router();
		$this->login_sequence($adm::$store);

		###

		$_SERVER['REQUEST_URI'] = '/chpasswd';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$adm = $this->make_router($adm::$store);

		ob_start();
		$adm->route('/chpasswd', [$adm, 'route_chpasswd'], 'POST');
		extract(json_decode(ob_get_clean(), true));

		$this->assertEquals($errno, 4);

		###

		$_POST['pass0'] = 'admin';
		$_POST['pass1'] = 'admin1';
		$_POST['pass2'] = 'admin1';
		$adm = $this->make_router($adm::$store);

		ob_start();
		$adm->route('/chpasswd', [$adm, 'route_chpasswd'], 'POST');
		extract(json_decode(ob_get_clean(), true));

		$this->assertEquals($errno, 0);

	}

	/**
	 * @depends test_chpasswd
	 */
	public function test_chbio() {
		$adm = $this->make_router();
		$this->login_sequence($adm::$store);

		###

		$_SERVER['REQUEST_URI'] = '/chbio';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['fname'] = 'The Handyman';
		$adm = $this->make_router($adm::$store);

		ob_start();
		$adm->route('/chbio', [$adm, 'route_chbio'], 'POST');
		extract(json_decode(ob_get_clean(), true));

		$this->assertEquals($errno, 0);

		###

		$_SERVER['REQUEST_URI'] = '/status';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$adm = $this->make_router($adm::$store);

		ob_start();
		$adm->route('/status', [$adm, 'route_status']);
		extract(json_decode(ob_get_clean(), true));

		$this->assertEquals($data['fname'], 'The Handyman');
	}

	/**
	 * @depends test_chbio
	 */
	public function test_register() {
		$this->login_cleanup();

		$_SERVER['REQUEST_URI'] = '/register';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$adm = $this->make_router();

		ob_start();
		$adm->route('/register', [$adm, 'route_register'], 'POST');
		extract(json_decode(ob_get_clean(), true));

		$this->assertEquals($errno, 3);

		###

		$_POST = [
			'addname' => 'jim',
			'addpass1' => '123456',
			'addpass2' => '123456',
			'email' => 'here@exampe.org',
		];
		$adm = $this->make_router($adm::$store);

		ob_start();
		$adm->route('/register', [$adm, 'route_register'], 'POST');
		extract(json_decode(ob_get_clean(), true));

		$this->assertEquals($errno, 0);
	}

	/**
	 * @depends test_register
	 */
	public function test_useradd() {
		$adm = $this->make_router();
		$this->login_sequence($adm::$store);

		###

		$_POST['x'] = '';
		$_SERVER['REQUEST_URI'] = '/useradd';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$adm = $this->make_router($adm::$store);

		ob_start();
		$adm->route('/useradd', [$adm, 'route_useradd'], 'POST');
		extract(json_decode(ob_get_clean(), true));

		$this->assertEquals($errno, 3);

		###

		$_POST = [
			'addname' => 'jim',
			'addpass1' => '123456',
			'email' => 'here@exampe.org',
		];
		$_SERVER['REQUEST_URI'] = '/useradd';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$adm = $this->make_router($adm::$store);

		ob_start();
		$adm->route('/useradd', [$adm, 'route_useradd'], 'POST');
		extract(json_decode(ob_get_clean(), true));

		$this->assertEquals($errno, 0);

		###

		$adm = $this->make_router($adm::$store);

		ob_start();
		$adm->route('/useradd', [$adm, 'route_useradd'], 'POST');
		extract(json_decode(ob_get_clean(), true));

		# cannot reuse email
		$this->assertEquals($errno, 5);
	}

	/**
	 * @depends test_useradd
	 */
	public function test_userdel() {
		$adm = $this->make_router();
		$this->login_sequence($adm::$store);

		###

		$_POST['x'] = '';
		$_SERVER['REQUEST_URI'] = '/userdel';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$adm = $this->make_router($adm::$store);

		ob_start();
		$adm->route('/userdel', [$adm, 'route_userdel'], 'POST');
		extract(json_decode(ob_get_clean(), true));

		$this->assertEquals($errno, 2);

		###

		$_POST = [
			'addname' => 'jimmy',
			'addpass1' => '123456',
			'email' => 'here@exampe.org',
		];
		$_SERVER['REQUEST_URI'] = '/useradd';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$adm = $this->make_router($adm::$store);

		ob_start();
		$adm->route('/useradd', [$adm, 'route_useradd'], 'POST');
		extract(json_decode(ob_get_clean(), true));

		$this->assertEquals($errno, 0);

		###

		$adm = $this->make_router($adm::$store);

		ob_start();
		$adm->route('/useradd', [$adm, 'route_useradd'], 'POST');
		extract(json_decode(ob_get_clean(), true));

		# cannot reuse email
		$this->assertEquals($errno, 5);
	}

	/**
	 * @depends test_userdel
	 */
	public function test_userlist() {
		$adm = $this->make_router();
		$this->login_sequence($adm::$store);

		###

		$_SERVER['REQUEST_URI'] = '/userlist';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$adm = $this->make_router($adm::$store);

		ob_start();
		$adm->route('/userlist', [$adm, 'route_userlist'], 'POST');
		extract(json_decode(ob_get_clean(), true));

		$this->assertEquals($errno, 0);
		$this->assertEquals($data[0]['uname'], 'root');
	}

	/**
	 * @depends test_userdel
	 * @fixme
	 *   The underlying adm_self_add_user_passwordless() doesn't
	 *   validate service.uname' and service.uservice'
	 *   whatsoever. This can end up in strange generated uname
	 *   such as '+myname::#mys3rv!ce'. Fix this. Never trust
	 *   3rd-party provider (responsible for service.uname) and
	 *   subclass implementation (responsible for
	 *   service.uservice).
	 */
	public function test_byway() {

		$_SERVER['REQUEST_URI'] = '/byway';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$adm = $this->make_router();

		ob_start();
		$adm->route('/byway', [$adm, 'route_byway'], 'POST');
		extract(json_decode(ob_get_clean(), true));

		$this->assertEquals($errno, 2);

		###

		$_POST['service'] = [
			'uname' => 'someone',
			'uservice' => 'github',
		];
		$adm = $this->make_router($adm::$store);

		ob_start();
		$adm->route('/byway', [$adm, 'route_byway'], 'POST');
		extract(json_decode(ob_get_clean(), true));

		$this->assertEquals($errno, 0);
		$this->assertEquals($data['uname'], '+someone:github');
	}
}

