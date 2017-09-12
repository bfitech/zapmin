<?php


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger;
use BFITech\ZapCoreDev\CoreDev;
use BFITech\ZapCoreDev\RouterDev as Router;
use BFITech\ZapStore\SQLite3;
use BFITech\ZapAdmin\AdminRouteDefault;
use BFITech\ZapAdmin\AdminStoreError as Err;
use BFITech\ZapAdmin\AdminRouteError;


class AdminRouteTest extends TestCase {

	public static $logger;

	public static function setUpBeforeClass() {
		CoreDev::testdir(__FILE__);

		$logfile = __TESTDIR__ . '/zapmin-route.log';
		if (file_exists($logfile))
			unlink($logfile);

		self::$logger = new Logger(
			Logger::DEBUG, $logfile);

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
		$_SERVER['REQUEST_URI'] = '/usr/test';

		# use 'foo' as token name
		$_COOKIE['foo'] = 'test';

		$logfile = __TESTDIR__ . '/zapmin-route.log';
		$logger = new Logger(Logger::ERROR, $logfile);

		$core = (new Router())
			->config('home', '/usr')
			->config('shutdown', false)
			->config('logger', $logger);

		$store = new SQLite3(['dbname' => ':memory:']);

		# change token name via config
		$adm = (new AdminRouteDefault($store, $logger, null, $core))
			->config('token_name', 'bar');
		$this->assertEquals($adm->adm_get_token_name(), 'bar');

		# change back token name via setter
		$adm->adm_set_token_name('foo');
		$this->assertEquals($adm->adm_get_token_name(), 'foo');

		$adm->route('/test', function($args) use($adm){
			$this->assertEquals(
				$args['cookie']['foo'], 'test');
			echo "HELLO, FRIEND";
		}, 'GET');
		$this->assertEquals('HELLO, FRIEND', $core::$body_raw);

		# since there's a matched route, calling shutdown manually
		# will take no effect
		$adm->core->shutdown();

		unlink($logfile);
	}

	private function make_router($store=null) {
		if (!$store)
			$store = new SQLite3(
				['dbname' => ':memory:'], self::$logger);
		# use new instance on every matching mock HTTP request
		$core = (new Router())
			->config('home', '/')
			->config('logger', self::$logger);
		return (new AdminRouteDefault(
				$store, self::$logger, null, $core))
			->config('token_name', 'test-zapmin');
	}

	/**
	 * @depends test_constructor
	 */
	public function test_home() {
		$_SERVER['REQUEST_URI'] = '/';
		$adm = $this->make_router();
		$core = $adm->core;

		$token_name = $adm->adm_get_token_name();
		$this->assertEquals($token_name, 'test-zapmin');

		$adm->route('/', [$adm, 'route_home']);
		$this->assertTrue(
			strpos(strtolower($core::$body_raw), 'it wurks') > 0);
	}

	private function login_cleanup($core=null) {
		$_SERVER['REQUEST_URI'] = '/';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		if (isset($_SERVER['HTTP_AUTHORIZATION']))
			unset($_SERVER['HTTP_AUTHORIZATION']);
		$_GET = $_POST = $_COOKIE = [];
		if ($core) {
			$core::$code = 200;
			$core::$head = [];
			$core::$body = null;
		}
	}

	private function login_sequence($store=null) {
		$this->login_cleanup();

		$_SERVER['REQUEST_URI'] = '/login';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['uname'] = 'root';
		$_POST['upass'] = 'admin';

		$adm = $this->make_router($store);
		$core = $adm->core;

		$adm->route('/login', [$adm, 'route_login'], 'POST');
		extract($core::$body);
		$this->assertEquals($core::$code, 200);
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
		$core = $adm->core;

		$adm->route('/status', [$adm, 'route_status']);
		extract($core::$body);

		$this->assertEquals($core::$code, 401);
		$this->assertEquals($errno, Err::USER_NOT_LOGGED_IN);

		###

		$this->login_sequence($adm->store);

		###

		$_SERVER['REQUEST_URI'] = '/status';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$adm = $this->make_router($adm->store);
		$core = $adm->core;

		$adm->route('/status', [$adm, 'route_status']);
		extract($core::$body);
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
		$core = $adm->core;

		$adm->route('/login', [$adm, 'route_login'], 'POST');
		extract($core::$body);
		$this->assertEquals($core::$code, 401);
		$this->assertEquals($errno, Err::DATA_INCOMPLETE);

		###

		$this->login_sequence($adm->store);

		###

		$_SERVER['REQUEST_URI'] = '/logout';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$adm = $this->make_router($adm->store);

		$adm->route('/logout', [$adm, 'route_logout']);
		extract($core::$body);
		$this->assertEquals($errno, 0);
	}

	/**
	 * @depends test_login_logout
	 */
	public function test_chpasswd() {
		$adm = $this->make_router();
		$this->login_sequence($adm->store);
		$core = $adm->core;

		###

		$_SERVER['REQUEST_URI'] = '/chpasswd';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$adm = $this->make_router($adm->store);

		$adm->route('/chpasswd', [$adm, 'route_chpasswd'], 'POST');
		extract($core::$body);
		$this->assertEquals($core::$code, 401);
		$this->assertEquals($errno, Err::DATA_INCOMPLETE);

		###

		$_POST['pass0'] = 'admin';
		$_POST['pass1'] = 'admin1';
		$_POST['pass2'] = 'admin1';
		$adm = $this->make_router($adm->store);

		$adm->route('/chpasswd', [$adm, 'route_chpasswd'], 'POST');
		extract($core::$body);
		$this->assertEquals($core::$code, 200);
		$this->assertEquals($errno, 0);

	}

	/**
	 * @depends test_chpasswd
	 */
	public function test_chbio() {
		$adm = $this->make_router();
		$core = $adm->core;
		$this->login_sequence($adm->store);

		###

		$_SERVER['REQUEST_URI'] = '/chbio';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['fname'] = 'The Handyman';
		$adm = $this->make_router($adm->store);

		$adm->route('/chbio', [$adm, 'route_chbio'], 'POST');
		extract($core::$body);
		$this->assertEquals($core::$code, 200);
		$this->assertEquals($errno, 0);

		###

		$_POST['site'] = 'Wrongurl';
		$adm = $this->make_router($adm->store);

		$adm->route('/chbio', [$adm, 'route_chbio'], 'POST');
		extract($core::$body);
		$this->assertEquals($core::$code, 401);
		$this->assertEquals($errno, Err::SITEURL_INVALID);

		###

		$_POST['site'] = 'http://www.bfinews.com';
		$adm = $this->make_router($adm->store);

		$adm->route('/chbio', [$adm, 'route_chbio'], 'POST');
		extract($core::$body);
		$this->assertEquals($core::$code, 200);
		$this->assertEquals($errno, 0);

		###

		$_SERVER['REQUEST_URI'] = '/status';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$adm = $this->make_router($adm->store);

		$adm->route('/status', [$adm, 'route_status']);
		extract($core::$body);
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
		$core = $adm->core;

		$adm->route('/register', [$adm, 'route_register'], 'POST');
		extract($core::$body);
		$this->assertEquals($core::$code, 401);
		$this->assertEquals($errno, Err::DATA_INCOMPLETE);

		###

		$_POST = [
			'addname' => 'jim',
			'addpass1' => '123456',
			'addpass2' => '123456',
			'email' => 'here@exampe.org',
		];
		$adm = $this->make_router($adm->store);

		$adm->route('/register', [$adm, 'route_register'], 'POST');
		extract($core::$body);
		$this->assertEquals($errno, 0);
	}

	/**
	 * @depends test_register
	 */
	public function test_useradd() {
		$adm = $this->make_router();
		$core = $adm->core;
		$this->login_sequence($adm->store);

		###

		$_POST['x'] = '';
		$_SERVER['REQUEST_URI'] = '/useradd';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$adm = $this->make_router($adm->store);

		$adm->route('/useradd', [$adm, 'route_useradd'], 'POST');
		extract($core::$body);
		$this->assertEquals($core::$code, 403);
		$this->assertEquals($errno, Err::DATA_INCOMPLETE);

		###

		$_POST = [
			'addname' => 'jim',
			'addpass1' => '123456',
			'email' => 'here@exampe.org',
		];
		$_SERVER['REQUEST_URI'] = '/useradd';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$adm = $this->make_router($adm->store);

		$adm->route('/useradd', [$adm, 'route_useradd'], 'POST');
		extract($core::$body);
		$this->assertEquals($errno, 0);

		###

		$adm = $this->make_router($adm->store);

		$adm->route('/useradd', [$adm, 'route_useradd'], 'POST');
		extract($core::$body);
		# cannot reuse email
		$this->assertEquals($core::$code, 403);
		$this->assertEquals($errno, Err::EMAIL_EXISTS);
	}

	/**
	 * @depends test_useradd
	 */
	public function test_userdel() {
		$adm = $this->make_router();
		$core = $adm->core;
		$this->login_sequence($adm->store);

		###

		$_POST['x'] = '';
		$_SERVER['REQUEST_URI'] = '/userdel';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$adm = $this->make_router($adm->store);

		$adm->route('/userdel', [$adm, 'route_userdel'], 'POST');
		extract($core::$body);
		$this->assertEquals($core::$code, 403);
		$this->assertEquals($errno, Err::DATA_INCOMPLETE);

		###

		$_POST = [
			'addname' => 'jimmy',
			'addpass1' => '123456',
			'email' => 'here@exampe.org',
		];
		$_SERVER['REQUEST_URI'] = '/useradd';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$adm = $this->make_router($adm->store);

		$adm->route('/useradd', [$adm, 'route_useradd'], 'POST');
		extract($core::$body);
		$this->assertEquals($errno, 0);

		###

		$adm = $this->make_router($adm->store);

		$adm->route('/useradd', [$adm, 'route_useradd'], 'POST');
		extract($core::$body);
		# cannot reuse email
		$this->assertEquals($errno, Err::EMAIL_EXISTS);

		###

		$_POST['addname'] = 'jimmy';
		$adm = $this->make_router($adm->store);

		$adm->route('/useradd', [$adm, 'route_useradd'], 'POST');
		extract($core::$body);
		# cannot reuse uname
		$this->assertEquals($errno, Err::EMAIL_EXISTS);
	}

	/**
	 * @depends test_userdel
	 */
	public function test_userlist() {
		$adm = $this->make_router();
		$core = $adm->core;
		$this->login_sequence($adm->store);

		###

		$_SERVER['REQUEST_URI'] = '/userlist';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$adm = $this->make_router($adm->store);

		$adm->route('/userlist', [$adm, 'route_userlist'], 'POST');
		extract($core::$body);
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
		$adm->adm_set_byway_expiration(10);
		$this->assertEquals(
			600, $adm->adm_get_byway_expiration());
		$core = $adm->core;

		$adm->route('/byway', [$adm, 'route_byway'], 'POST');
		extract($core::$body);
		$this->assertEquals($errno, Err::DATA_INCOMPLETE);

		###

		$_POST['service'] = [
			'uname' => 'someone',
			'uservice' => 'github',
		];
		$adm = $this->make_router($adm->store);

		$adm->route('/byway', [$adm, 'route_byway'], 'POST');
		extract($core::$body);
		$this->assertEquals($errno, 0);
		$this->assertEquals($data['uname'], '+someone:github');
	}

}
