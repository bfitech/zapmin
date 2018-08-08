<?php


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger;
use BFITech\ZapCommonDev\CommonDev;
use BFITech\ZapCoreDev\RouterDev;
use BFITech\ZapCoreDev\RoutingDev;
use BFITech\ZapStore\SQLite3;
use BFITech\ZapAdmin\AdminRouteDefault;
use BFITech\ZapAdmin\AdminStoreError as Err;


class AdminRouteTest extends TestCase {

	public static $logger;

	public static function setUpBeforeClass() {
		CommonDev::testdir(__FILE__);
		$logfile = __TESTDIR__ . '/zapmin-route.log';
		if (file_exists($logfile))
			unlink($logfile);
		self::$logger = new Logger(
			Logger::DEBUG, $logfile);
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

		$core = (new RouterDev())
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
		$core = (new RouterDev())
			->config('home', '/')
			->config('logger', self::$logger);
		# @note: AdminRoute->route is different from
		# RouterDev->route.
		return (new AdminRouteDefault(
				$store, self::$logger, null, $core))
			->config('token_name', 'test-zapmin');
	}

	/**
	 * Simulated request with a valid authentication cookie.
	 */
	private function request_authed(
		$rdev, $url, $method, $args, $token
	) {
		$rdev->request($url, $method, $args, [
			'test-zapmin' => $token
		]);
	}

	public function test_home() {
		$adm = $this->make_router();
		$core = $adm->core;
		$rdev = new RoutingDev($core);

		$token_name = $adm->adm_get_token_name();
		$this->assertEquals($token_name, 'test-zapmin');

		$rdev->request('/', 'GET');
		$core->route('/', [$adm, 'route_home']);
		$this->assertTrue(
			strpos(strtolower($core::$body_raw), 'it wurks') > 0);
	}

	/**
	 * Successful login for admin to further test user management.
	 */
	private function login_sequence($adm) {

		$core = $adm->core;
		$rdev = new RoutingDev($core);

		$rdev
			->request('/login', 'POST', [
				'post' => [
					'uname' => 'root',
					'upass' => 'admin',
				]
			]);
		$adm->route('/login', [$adm, 'route_login'], 'POST');

		$this->assertEquals($core::$code, 200);
		$this->assertEquals($core::$errno, 0);
		$this->assertEquals($core::$data['uid'], 1);
		return $core::$data['token'];
	}

	public function test_status() {
		$adm = $this->make_router();
		$core = $adm->core;
		$rdev = new RoutingDev($core);

		# unauthed
		$rdev->request('/status', 'GET');
		$adm->route('/status', [$adm, 'route_status']);
		$this->assertEquals($core::$code, 401);
		$this->assertEquals($core::$errno, Err::USER_NOT_LOGGED_IN);

		$token = $this->login_sequence($adm, $adm->store);

		# authed via cookie
		$this->request_authed(
			$rdev, '/status', 'GET', [], $token);
		$adm->route('/status', [$adm, 'route_status']);
		$this->assertEquals($core::$errno, 0);
		$this->assertEquals($core::$data['uid'], 1);

		# authed via header
		$_SERVER['HTTP_AUTHORIZATION'] = sprintf(
			"%s %s", 'test-zapmin', $token);
		$rdev->request('/status', 'GET');
		$adm->route('/status', [$adm, 'route_status']);
		$this->assertEquals($core::$errno, 0);
		$this->assertEquals($core::$data['uid'], 1);
	}

	public function test_login_logout() {

		$adm = $this->make_router();
		$core = $adm->core;
		$rdev = new RoutingDev($core);

		$rdev->request('/login', 'POST', ['post' => []]);
		$adm->route('/login', [$adm, 'route_login'], 'POST');
		$this->assertEquals($core::$code, 401);
		$this->assertEquals($core::$errno, Err::DATA_INCOMPLETE);

		###

		$token = $this->login_sequence($adm);

		###

		$this->request_authed($rdev, '/logout', 'GET', [], $token);
		$adm->route('/logout', [$adm, 'route_logout']);
		$this->assertEquals($core::$errno, 0);
	}

	public function test_chpasswd() {
		$adm = $this->make_router();
		$core = $adm->core;
		$rdev = new RoutingDev($core);

		###

		$token = $this->login_sequence($adm);

		###

		$post = [];

		$this->request_authed(
			$rdev, '/chpasswd', 'POST', ['post' => $post], $token);
		$adm->route('/chpasswd', [$adm, 'route_chpasswd'], 'POST');
		$this->assertEquals($core::$code, 401);
		$this->assertEquals($core::$errno, Err::DATA_INCOMPLETE);

		###

		$post = [
			'pass0' => 'admin',
			'pass1' => 'admin1',
			'pass2' => 'admin1',
		];

		$this->request_authed(
			$rdev, '/chpasswd', 'POST', ['post' => $post], $token);
		$adm->route('/chpasswd', [$adm, 'route_chpasswd'], 'POST');
		$this->assertEquals($core::$code, 200);
		$this->assertEquals($core::$errno, 0);

	}

	public function test_chbio() {
		$adm = $this->make_router();
		$core = $adm->core;
		$rdev = new RoutingDev($core);
		$token = $this->login_sequence($adm);

		###

		$this->request_authed($rdev, '/chbio', 'POST',
			['post' => ['fname' => 'The Handyman']], $token);
		$adm->route('/chbio', [$adm, 'route_chbio'], 'POST');
		$this->assertEquals($core::$code, 200);
		$this->assertEquals($core::$errno, 0);

		###

		$this->request_authed($rdev, '/chbio', 'POST',
			['post' => ['site' => 'Wrongurl']], $token);
		$adm->route('/chbio', [$adm, 'route_chbio'], 'POST');
		$this->assertEquals($core::$code, 401);
		$this->assertEquals($core::$errno, Err::SITEURL_INVALID);

		###

		$this->request_authed($rdev, '/chbio', 'POST',
			['post' => ['site' => 'http://www.bfinews.com']], $token);
		$adm->route('/chbio', [$adm, 'route_chbio'], 'POST');
		$this->assertEquals($core::$code, 200);
		$this->assertEquals($core::$errno, 0);

		###

		$this->request_authed($rdev, '/status', 'GET', [], $token);
		$adm->route('/status', [$adm, 'route_status']);
		$this->assertEquals($core::$data['fname'], 'The Handyman');
	}

	public function test_register() {
		$adm = $this->make_router();
		$core = $adm->core;
		$rdev = new RoutingDev($core);

		$rdev->request('/register', 'POST', []);
		$adm->route('/register', [$adm, 'route_register'], 'POST');
		$this->assertEquals($core::$code, 401);
		$this->assertEquals($core::$errno, Err::DATA_INCOMPLETE);

		###

		$post = [
			'addname' => 'jim',
			'addpass1' => '123456',
			'addpass2' => '123456',
			'email' => 'here@exampe.org',
		];
		$rdev->request('/register', 'POST', ['post' => $post]);
		$adm->route('/register', [$adm, 'route_register'], 'POST');
		$this->assertEquals($core::$errno, 0);
	}

	public function test_useradd() {
		$adm = $this->make_router();
		$core = $adm->core;
		$rdev = new RoutingDev($core);
		$token = $this->login_sequence($adm);

		###

		$post = ['x' => ''];
		$this->request_authed($rdev, '/useradd', 'POST',
			['post' => $post], $token);
		$adm->route('/useradd', [$adm, 'route_useradd'], 'POST');
		$this->assertEquals($core::$code, 403);
		$this->assertEquals($core::$errno, Err::DATA_INCOMPLETE);

		###

		$post = [
			'addname' => 'jim',
			'addpass1' => '123456',
			'email' => 'here@exampe.org',
		];
		$this->request_authed($rdev, '/useradd', 'POST',
			['post' => $post], $token);
		$adm->route('/useradd', [$adm, 'route_useradd'], 'POST');
		$this->assertEquals($core::$errno, 0);

		###

		$this->request_authed($rdev, '/useradd', 'POST',
			['post' => $post], $token);
		$adm->route('/useradd', [$adm, 'route_useradd'], 'POST');
		# cannot reuse email
		$this->assertEquals($core::$code, 403);
		$this->assertEquals($core::$errno, Err::EMAIL_EXISTS);
	}

	public function test_userdel() {
		$adm = $this->make_router();
		$core = $adm->core;
		$rdev = new RoutingDev($core);
		$token = $this->login_sequence($adm);

		###

		$post = ['x' => ''];
		$this->request_authed($rdev, '/userdel', 'POST',
			['post' => $post], $token);
		$adm->route('/userdel', [$adm, 'route_userdel'], 'POST');
		$this->assertEquals($core::$code, 403);
		$this->assertEquals($core::$errno, Err::DATA_INCOMPLETE);

		###

		$post = [
			'addname' => 'jimmy',
			'addpass1' => '123456',
			'email' => 'here@exampe.org',
		];
		$this->request_authed($rdev, '/useradd', 'POST',
			['post' => $post], $token);
		$adm->route('/useradd', [$adm, 'route_useradd'], 'POST');
		$this->assertEquals($core::$errno, 0);

		###

		$post = ['uid' => 3];
		$this->request_authed($rdev, '/userdel', 'POST',
			['post' => $post], $token);
		$adm->route('/userdel', [$adm, 'route_userdel'], 'POST');
		$this->assertEquals($core::$errno, Err::USER_NOT_FOUND);

		###

		$post = ['uid' => 2];
		$this->request_authed($rdev, '/userdel', 'POST',
			['post' => $post], $token);
		$adm->route('/userdel', [$adm, 'route_userdel'], 'POST');
		$this->assertEquals($core::$errno, 0);
	}

	public function test_userlist() {
		$adm = $this->make_router();
		$core = $adm->core;
		$rdev = new RoutingDev($core);
		$token = $this->login_sequence($adm);

		###

		$post = [
			'addname' => 'jimmy',
			'addpass1' => '123456',
			'email' => 'here@exampe.org',
		];
		$this->request_authed($rdev, '/useradd', 'POST',
			['post' => $post], $token);
		$adm->route('/useradd', [$adm, 'route_useradd'], 'POST');
		$this->assertEquals($core::$errno, 0);

		###

		$this->request_authed($rdev, '/userlist', 'POST', [], $token);
		$adm->route('/userlist', [$adm, 'route_userlist'], 'POST');
		$this->assertEquals($core::$errno, 0);
		$this->assertEquals($core::$data[0]['uname'], 'root');
	}

	/**
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
		$rdev = new RoutingDev($core);

		$post = [
			'service' => [
				'uname' => 'someone',
			],
		];

		$rdev->request('/byway', 'POST');
		$adm->route('/byway', [$adm, 'route_byway'], 'POST');
		$this->assertEquals($core::$errno, Err::DATA_INCOMPLETE);

		###

		$post['service']['uservice'] = 'github';

		$rdev->request('/byway', 'POST', ['post' => $post]);
		$adm->route('/byway', [$adm, 'route_byway'], 'POST');
		$this->assertEquals($core::$errno, 0);
		$this->assertEquals($core::$data['uname'], '+someone:github');
	}

}
