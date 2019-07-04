<?php

require_once __DIR__ . '/Common.php';


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger;
use BFITech\ZapCoreDev\RouterDev;
use BFITech\ZapCoreDev\RoutingDev;
use BFITech\ZapStore\SQLite3;

use BFITech\ZapAdmin\Admin;
use BFITech\ZapAdmin\AuthCtrl;
use BFITech\ZapAdmin\AuthManage;
use BFITech\ZapAdmin\RouteDefault;
use BFITech\ZapAdmin\Error;


class AdminRouteTest extends TestCase {

	public static $logger;
	public static $sql;

	public static function setUpBeforeClass() {
		$logfile = testdir() . '/zapmin-route.log';
		if (file_exists($logfile))
			unlink($logfile);
		self::$logger = new Logger(
			Logger::DEBUG, $logfile);
	}

	public function setUp() {
		self::$logger->info("TEST STARTED.");
	}

	public function tearDown() {
		foreach (['usess', 'udata', 'meta'] as $table) {
			self::$sql->query_raw(
				"DELETE FROM udata WHERE uid>1");
		}
	}

	public function test_constructor() {
		$_SERVER['REQUEST_URI'] = '/usr/test';

		# use 'foo' as token name
		$_COOKIE['foo'] = 'test';

		// $logfile = testdir() . '/zapmin-route.log';
		// $logger = new Logger(Logger::ERROR, $logfile);

		$core = (new RouterDev())
			->config('home', '/usr')
			->config('shutdown', false)
			->config('logger', self::$logger);

		self::$sql = $sql = new SQLite3(
			['dbname' => ':memory:'], self::$logger);

		$admin = new Admin($sql, self::$logger);
		$admin
			->config('expire', 3600)
			->config('token_name', 'bar')
			->config('check_tables', true);

		$ctrl = new AuthCtrl($admin, self::$logger);
		$manage = new AuthManage($admin, self::$logger);

		# change token name via config
		$rdev = (new RouteDefault($core, $ctrl, $manage));
		$this->assertEquals($rdev::$admin->get_token_name(), 'bar');

		$rdev->route('/test', function($args) use($rdev){
			$this->assertEquals(
				$args['cookie']['foo'], 'test');
			echo "HELLO, FRIEND";
		}, 'GET');
		$this->assertEquals('HELLO, FRIEND', $core::$body_raw);
	}

	private function make_router($sql=null) {
		$_SERVER['REQUEST_URI'] = "/";

		# use new instance on every matching mock HTTP request
		$core = (new RouterDev())
			->config('home', '/')
			->config('logger', self::$logger);

		if (!$sql)
			self::$sql = $sql = new SQLite3(
				['dbname' => ':memory:'], self::$logger);

		$admin = new Admin($sql, self::$logger);
		$admin
			->config('expire', 3600)
			->config('token_name', 'test-zapmin')
			->config('check_tables', true);

		$ctrl = new AuthCtrl($admin, self::$logger);
		$manage = new AuthManage($admin, self::$logger);

		return (new RouteDefault($core, $ctrl, $manage));
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

		$rdev = $this->make_router();
		$token_name = $rdev::$admin->get_token_name();
		$this->assertEquals($token_name, 'test-zapmin');

		$rdev->route('/', function($args) use($rdev) {
			$rdev->route_home();
		}, 'GET');

		$this->assertEquals(
			'<h1>It wurks!</h1>', $rdev::$core::$body_raw);
		$this->assertTrue(strpos(
			strtolower($rdev::$core::$body_raw), 'it wurks') > 0);
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
	 	$this->markTestIncomplete("Reworking ...");

		$adm = $this->make_router();
		$core = $adm->core;
		$rdev = new RoutingDev($core);

		# unauthed
		$rdev->request('/status', 'GET');
		$adm->route('/status', [$adm, 'route_status']);
		$this->assertEquals($core::$code, 401);
		$this->assertEquals($core::$errno, Err::USER_NOT_LOGGED_IN);

		$token = $this->login_sequence($adm, $adm->store);

		# authed via header
		$_SERVER['HTTP_AUTHORIZATION'] = sprintf(
			"%s-%s", 'test-zapmin', $token);
		$rdev->request('/status', 'GET');
		$adm->route('/status', [$adm, 'route_status']);
		$this->assertEquals($core::$errno, Err::USER_NOT_LOGGED_IN);

		$_SERVER['HTTP_AUTHORIZATION'] = sprintf(
			"%s %s", 'test-zapmin', $token);
		$rdev->request('/status', 'GET');
		$adm->route('/status', [$adm, 'route_status']);
		$this->assertEquals($core::$errno, 0);
		$this->assertEquals($core::$data['uid'], 1);

		# authed via cookie
		$this->request_authed(
			$rdev, '/status', 'GET', [], $token);
		$adm->route('/status', [$adm, 'route_status']);
		$this->assertEquals($core::$errno, 0);
		$this->assertEquals($core::$data['uid'], 1);
	}

	public function test_login_logout() {
	 	$this->markTestIncomplete("Reworking ...");

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
	 	$this->markTestIncomplete("Reworking ...");

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
	 	$this->markTestIncomplete("Reworking ...");

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
	 	$this->markTestIncomplete("Reworking ...");

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
	 	$this->markTestIncomplete("Reworking ...");

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
	 	$this->markTestIncomplete("Reworking ...");

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
	 	$this->markTestIncomplete("Reworking ...");

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
	 	$this->markTestIncomplete("Reworking ...");

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
