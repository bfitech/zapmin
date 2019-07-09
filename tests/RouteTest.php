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


class RouteTest extends TestCase {

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

	/** Mocker router. */
	private function make_router($sql=null) {
		### use new instance on every matching mock HTTP request
		$core = (new RouterDev())
			->config('home', '/')
			->config('logger', self::$logger);

		### always renew database from scratch
		self::$sql = $sql = new SQLite3(
			['dbname' => ':memory:'], self::$logger);

		$admin = new Admin($sql, self::$logger);
		$admin
			->config('expire', 3600)
			->config('token_name', 'test-zapmin')
			->config('check_tables', true);

		$ctrl = new AuthCtrl($admin, self::$logger);
		$manage = new AuthManage($admin, self::$logger);

		return new RouteDefault($core, $ctrl, $manage);
	}

	/** Simulated request with a valid authentication cookie. */
	private function request_authed(
		$rdev, $url, $method, $args, $token
	) {
		$rdev->request($url, $method, $args, [
			'test-zapmin' => $token
		]);
	}

	/** Common test instances. */
	private function make_tester() {
		$router = $this->make_router();
		$core = $router::$core;
		$admin = $router::$admin;
		$rdev = new RoutingDev($core);
		return [$router, $router::$core, $router::$admin, $rdev];
	}

	public function test_home() {
		list($router, $core, $_, $rdev) = $this->make_tester();

		$token_name = $router::$admin->get_token_name();
		$this->assertEquals($token_name, 'test-zapmin');

		$rdev->request('/', 'GET');
		$router->route('/', function($args) use($router) {
			$router->route_home();
		}, 'GET');

		$this->assertEquals(
			'<h1>It wurks!</h1>', $core::$body_raw);
		$this->assertTrue(strpos(
			strtolower($core::$body_raw), 'it wurks') > 0);
	}

	/**
	 * Successful login for admin to further test user management.
	 */
	private function login_sequence($router) {
		list($router, $core, $_, $rdev) = $this->make_tester();

		$login_data = [
			'post' => [
				'uname' => 'root',
				'upass' => 'admin',
			]
		];

		$rdev->request('/login', 'POST', $login_data);
		$router->route(
			'/login', function($args) use($router, $login_data) {
				$router->route_login($login_data);
		}, 'POST');

		$this->assertEquals($core::$code, 200);
		$this->assertEquals($core::$errno, 0);
		$this->assertEquals($core::$data['uid'], 1);
		return $core::$data['token'];
	}

	public function test_status() {
		list($router, $core, $admin, $rdev) = $this->make_tester();

		# unauthed
		$rdev->request('/status', 'GET');
		$router->route('/status', function($args) use($router) {
			$router->route_status();
		}, 'GET');
		$this->assertEquals($core::$code, 401);
		$this->assertEquals($core::$errno, Error::USER_NOT_LOGGED_IN);

		### login
		$token = $this->login_sequence($router);

		# authed via header
		$_SERVER['HTTP_AUTHORIZATION'] = sprintf(
			"%s-%s", 'test-zapmin', $token);
		$rdev->request('/status', 'GET');
		$router->route('/status', function($args) use($router) {
			$router->route_status();
		}, 'GET');
		$this->assertEquals($core::$errno, Error::USER_NOT_LOGGED_IN);

		$_SERVER['HTTP_AUTHORIZATION'] = sprintf(
			"%s %s", 'test-zapmin', $token);
		$rdev->request('/status', 'GET');
		$router->route('/status', function($args) use($router) {
			$router->route_status();
		}, 'GET');
		$this->assertEquals($core::$errno, 0);
		$this->assertEquals($core::$data['uid'], 1);

		# authed via cookie
		$this->request_authed(
			$rdev, '/status', 'GET', [], $token);
		$router->route('/status', function($args) use($router) {
			$router->route_status();
		}, 'GET');
		$this->assertEquals($core::$errno, 0);
		$this->assertEquals($core::$data['uid'], 1);
	}

	public function test_login_logout() {
		list($router, $core, $admin, $rdev) = $this->make_tester();

		# login incomplete data
		$rdev->request('/login', 'POST');
		$router->route('/login', function($args) use($router) {
			$router->route_login(['post' => []]);
		}, 'POST');
		$this->assertEquals($core::$code, 401);
		$this->assertEquals($core::$errno, Error::DATA_INCOMPLETE);

		### login
		$token = $this->login_sequence($router);

		# logout success
		$this->request_authed($rdev, '/logout', 'GET', [], $token);
		$router->route('/logout', function($args) use($router) {
			$router->route_logout([]);
		}, 'GET');
		$this->assertEquals($core::$errno, 0);
	}

	public function test_chpasswd() {
		list($router, $core, $admin, $rdev) = $this->make_tester();

		### login
		$token = $this->login_sequence($router);

		# incomplete data
		$this->request_authed($rdev, '/chpasswd', 'POST', [], $token);
		$router->route(
			'/chpasswd', function($args) use($router) {
				$router->route_chpasswd(['post' => []]);
		}, 'POST');
		$this->assertEquals($core::$code, 401);
		$this->assertEquals($core::$errno, Error::DATA_INCOMPLETE);

		# success
		$post = [
			'post' => [
				'pass0' => 'admin',
				'pass1' => 'admin1',
				'pass2' => 'admin1',
			],
		];
		$this->request_authed(
			$rdev, '/chpasswd', 'POST', $post, $token);
		$router->route(
			'/chpasswd', function($args) use($router, $post) {
				$router->route_chpasswd($post);
		}, 'POST');
		$this->assertEquals($core::$code, 200);
		$this->assertEquals($core::$errno, 0);
	}

	public function test_chbio() {
		list($router, $core, $admin, $rdev) = $this->make_tester();

		### login
		$token = $this->login_sequence($router);

		# success
		$post = ['post' => ['fname' => 'The Handyman']];
		$this->request_authed($rdev, '/chbio', 'POST', $post, $token);
		$router->route(
			'/chbio', function($args) use($router, $post) {
				$router->route_chbio($post);
		}, 'POST');
		$this->assertEquals($core::$code, 200);
		$this->assertEquals($core::$errno, 0);

		# invalid url
		$post = ['post' => ['site' => 'Wrongurl']];
		$this->request_authed($rdev, '/chbio', 'POST', $post, $token);
		$router->route(
			'/chbio', function($args) use($router, $post) {
				$router->route_chbio($post);
		}, 'POST');
		$this->assertEquals($core::$code, 401);
		$this->assertEquals($core::$errno, Error::SITEURL_INVALID);

		# success
		$post = ['post' => ['site' => 'http://bfi.io']];
		$this->request_authed($rdev, '/chbio', 'POST', $post, $token);
		$router->route(
			'/chbio', function($args) use($router, $post) {
				$router->route_chbio($post);
		}, 'POST');
		$this->assertEquals($core::$code, 200);
		$this->assertEquals($core::$errno, 0);

		### check new value via /status
		$this->request_authed($rdev, '/status', 'GET', [], $token);
		$router->route('/status', function($args) use($router) {
			$router->route_status();
		}, 'GET');
		$this->assertEquals($core::$data['fname'], 'The Handyman');
		$this->assertEquals($core::$data['site'], 'http://bfi.io');
	}

	public function test_register() {
		list($router, $core, $admin, $rdev) = $this->make_tester();

		# incomplete data
		$rdev->request('/register', 'POST', ['post' => []]);
		$router->route('/register', function($args) use($router) {
			$router->route_register(['post' => []]);
		}, 'POST');
		$this->assertEquals($core::$code, 401);
		$this->assertEquals($core::$errno, Error::DATA_INCOMPLETE);

		# success
		$post = [
			'post' => [
				'addname' => 'jim',
				'addpass1' => '123456',
				'addpass2' => '123456',
				'email' => 'here@exampe.org',
			],
		];
		$rdev->request('/register', 'POST', $post);
		$router->route(
			'/chbio', function($args) use($router, $post) {
				$router->route_register($post);
		}, 'POST');
		$this->assertEquals($core::$errno, 0);
	}

	public function test_useradd() {
		list($router, $core, $admin, $rdev) = $this->make_tester();

		### login
		$token = $this->login_sequence($router);

		# incomplete data
		$post = ['post' => ['x' => '']];
		$this->request_authed($rdev, '/useradd', 'POST', $post, $token);
		$router->route(
			'/useradd', function($args) use($router, $post) {
				$router->route_useradd($post);
		}, 'POST');
		$this->assertEquals($core::$code, 403);
		$this->assertEquals($core::$errno, Error::DATA_INCOMPLETE);

		# success
		$post = [
			'post' => [
				'addname' => 'jim',
				'addpass1' => '123456',
				'email' => 'here@exampe.org',
			],
		];
		$this->request_authed($rdev, '/useradd', 'POST', $post, $token);
		$router->route(
			'/useradd', function($args) use($router, $post) {
				$router->route_useradd($post);
		}, 'POST');
		$this->assertEquals($core::$errno, 0);

		# re-adding fails because of non-unique email
		$this->request_authed($rdev, '/useradd', 'POST', $post, $token);
		$router->route(
			'/useradd', function($args) use($router, $post) {
				$router->route_useradd($post);
		}, 'POST');
		$this->assertEquals($core::$code, 403);
		$this->assertEquals($core::$errno, Error::EMAIL_EXISTS);
	}

	public function test_userdel() {
		list($router, $core, $admin, $rdev) = $this->make_tester();

		### login
		$token = $this->login_sequence($router);

		# incomplete data
		$post = ['post' => ['x' => '']];
		$this->request_authed($rdev, '/userdel', 'POST', $post, $token);
		$router->route(
			'/userdel', function($args) use($router, $post) {
				$router->route_userdel($post);
		}, 'POST');
		$this->assertEquals($core::$code, 403);
		$this->assertEquals($core::$errno, Error::DATA_INCOMPLETE);

		### adding
		$post = [
			'post' => [
				'addname' => 'jimmy',
				'addpass1' => '123456',
				'email' => 'here@exampe.org',
			],
		];
		$this->request_authed($rdev, '/useradd', 'POST', $post, $token);
		$router->route(
			'/useradd', function($args) use($router, $post) {
				$router->route_useradd($post);
		}, 'POST');
		$this->assertEquals($core::$errno, 0);

		# uid=3 not found
		$post = ['post' => ['uid' => 3]];
		$this->request_authed($rdev, '/userdel', 'POST', $post, $token);
		$router->route(
			'/userdel', function($args) use($router, $post) {
				$router->route_userdel($post);
		}, 'POST');
		$this->assertEquals($core::$errno, Error::USER_NOT_FOUND);

		# success for uid=2
		$post = ['post' => ['uid' => 2]];
		$this->request_authed($rdev, '/userdel', 'POST', $post, $token);
		$router->route(
			'/userdel', function($args) use($router, $post) {
				$router->route_userdel($post);
		}, 'POST');
		$this->assertEquals($core::$errno, 0);
	}

	public function test_userlist() {
		list($router, $core, $admin, $rdev) = $this->make_tester();

		### login
		$token = $this->login_sequence($router);

		### adding
		$post = [
			'post' => [
				'addname' => 'jimmy',
				'addpass1' => '123456',
				'email' => 'here@exampe.org',
			],
		];
		$this->request_authed($rdev, '/useradd', 'POST', $post, $token);
		$router->route(
			'/useradd', function($args) use($router, $post) {
				$router->route_useradd($post);
		}, 'POST');
		$this->assertEquals($core::$errno, 0);

		# success
		$this->request_authed(
			$rdev, '/userlist', 'GET', [], $token);
		$router->route('/userlist', function($args) use($router) {
			$router->route_userlist(['get' => []]);
		});
		$this->assertEquals($core::$errno, 0);
		$this->assertEquals($core::$data[1]['uname'], 'jimmy');
	}

	public function test_byway() {
		list($router, $core, $admin, $rdev) = $this->make_tester();

		# minimum expiration is hardcoded 60 sec
		$admin->set_expiration(10);
		$this->assertEquals(600, $admin->get_expiration());

		$post = ['post' => ['uname' => 'someone']];

		# incomplete data
		$rdev->request('/byway', 'POST', $post);
		$router->route(
			'/byway', function($args) use($router, $post) {
				$router->route_byway($post);
		}, 'POST');
		$this->assertEquals($core::$errno, Error::DATA_INCOMPLETE);

		# success
		$post['post']['uservice'] = '[github]';
		$rdev->request('/byway', 'POST', $post);
		$router->route(
			'/byway', function($args) use($router, $post) {
				$router->route_byway($post);
		}, 'POST');
		$this->assertEquals($core::$errno, 0);
		$this->assertEquals($core::$data['uname'], '+someone:[github]');
	}

}
