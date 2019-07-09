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
use BFITech\ZapAdmin\Error;


class RouteTest extends Common {

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

		$eq = $this->eq();

		# change token name via config
		$rdev = (new RouteDefault($core, $ctrl, $manage));
		$eq($rdev::$admin->get_token_name(), 'bar');

		$rdev->route('/test', function($args) use($rdev, $eq){
			$eq($args['cookie']['foo'], 'test');
			echo "HELLO, FRIEND";
		}, 'GET');
		$eq('HELLO, FRIEND', $core::$body_raw);
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
		$eq = $this->eq();
		list($router, $core, $_, $rdev) = $this->make_tester();

		$token_name = $router::$admin->get_token_name();
		$eq($token_name, 'test-zapmin');

		$rdev->request('/', 'GET');
		$router->route('/', function($args) use($router) {
			$router->route_home();
		}, 'GET');

		$eq('<h1>It wurks!</h1>', $core::$body_raw);
		$this->tr()(
			strpos(	strtolower($core::$body_raw), 'it wurks') > 0);
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

		$eq = $this->eq();
		$eq($core::$code, 200);
		$eq($core::$errno, 0);
		$eq($core::$data['uid'], 1);
		return $core::$data['token'];
	}

	public function test_status() {
		$eq = $this->eq();
		list($router, $core, $admin, $rdev) = $this->make_tester();

		# unauthed
		$rdev->request('/status', 'GET');
		$router->route('/status', function($args) use($router) {
			$router->route_status();
		}, 'GET');
		$eq($core::$code, 401);
		$eq($core::$errno, Error::USER_NOT_LOGGED_IN);

		### login
		$token = $this->login_sequence($router);

		# authed via header
		$_SERVER['HTTP_AUTHORIZATION'] = sprintf(
			"%s-%s", 'test-zapmin', $token);
		$rdev->request('/status', 'GET');
		$router->route('/status', function($args) use($router) {
			$router->route_status();
		}, 'GET');
		$eq($core::$errno, Error::USER_NOT_LOGGED_IN);

		$_SERVER['HTTP_AUTHORIZATION'] = sprintf(
			"%s %s", 'test-zapmin', $token);
		$rdev->request('/status', 'GET');
		$router->route('/status', function($args) use($router) {
			$router->route_status();
		}, 'GET');
		$eq($core::$errno, 0);
		$eq($core::$data['uid'], 1);

		# authed via cookie
		$this->request_authed(
			$rdev, '/status', 'GET', [], $token);
		$router->route('/status', function($args) use($router) {
			$router->route_status();
		}, 'GET');
		$eq($core::$errno, 0);
		$eq($core::$data['uid'], 1);
	}

	public function test_login_logout() {
		$eq = $this->eq();
		list($router, $core, $admin, $rdev) = $this->make_tester();

		# login incomplete data
		$rdev->request('/login', 'POST');
		$router->route('/login', function($args) use($router) {
			$router->route_login(['post' => []]);
		}, 'POST');
		$eq($core::$code, 401);
		$eq($core::$errno, Error::DATA_INCOMPLETE);

		### login
		$token = $this->login_sequence($router);

		# logout success
		$this->request_authed($rdev, '/logout', 'GET', [], $token);
		$router->route('/logout', function($args) use($router) {
			$router->route_logout([]);
		}, 'GET');
		$eq($core::$errno, 0);
	}

	public function test_chpasswd() {
		$eq = $this->eq();
		list($router, $core, $admin, $rdev) = $this->make_tester();

		### login
		$token = $this->login_sequence($router);

		# incomplete data
		$this->request_authed($rdev, '/chpasswd', 'POST', [], $token);
		$router->route(
			'/chpasswd', function($args) use($router) {
				$router->route_chpasswd(['post' => []]);
		}, 'POST');
		$eq($core::$code, 401);
		$eq($core::$errno, Error::DATA_INCOMPLETE);

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
		$eq($core::$code, 200);
		$eq($core::$errno, 0);
	}

	public function test_chbio() {
		$eq = $this->eq();
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
		$eq($core::$code, 200);
		$eq($core::$errno, 0);

		# invalid url
		$post = ['post' => ['site' => 'Wrongurl']];
		$this->request_authed($rdev, '/chbio', 'POST', $post, $token);
		$router->route(
			'/chbio', function($args) use($router, $post) {
				$router->route_chbio($post);
		}, 'POST');
		$eq($core::$code, 401);
		$eq($core::$errno, Error::SITEURL_INVALID);

		# success
		$post = ['post' => ['site' => 'http://bfi.io']];
		$this->request_authed($rdev, '/chbio', 'POST', $post, $token);
		$router->route(
			'/chbio', function($args) use($router, $post) {
				$router->route_chbio($post);
		}, 'POST');
		$eq($core::$code, 200);
		$eq($core::$errno, 0);

		### check new value via /status
		$this->request_authed($rdev, '/status', 'GET', [], $token);
		$router->route('/status', function($args) use($router) {
			$router->route_status();
		}, 'GET');
		$eq($core::$data['fname'], 'The Handyman');
		$eq($core::$data['site'], 'http://bfi.io');
	}

	public function test_register() {
		$eq = $this->eq();
		list($router, $core, $admin, $rdev) = $this->make_tester();

		# incomplete data
		$rdev->request('/register', 'POST', ['post' => []]);
		$router->route('/register', function($args) use($router) {
			$router->route_register(['post' => []]);
		}, 'POST');
		$eq($core::$code, 401);
		$eq($core::$errno, Error::DATA_INCOMPLETE);

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
		$eq($core::$errno, 0);
	}

	public function test_useradd() {
		$eq = $this->eq();
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
		$eq($core::$code, 403);
		$eq($core::$errno, Error::DATA_INCOMPLETE);

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
		$eq($core::$errno, 0);

		# re-adding fails because of non-unique email
		$this->request_authed($rdev, '/useradd', 'POST', $post, $token);
		$router->route(
			'/useradd', function($args) use($router, $post) {
				$router->route_useradd($post);
		}, 'POST');
		$eq($core::$code, 403);
		$eq($core::$errno, Error::EMAIL_EXISTS);
	}

	public function test_userdel() {
		$eq = $this->eq();
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
		$eq($core::$code, 403);
		$eq($core::$errno, Error::DATA_INCOMPLETE);

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
		$eq($core::$errno, 0);

		# uid=3 not found
		$post = ['post' => ['uid' => 3]];
		$this->request_authed($rdev, '/userdel', 'POST', $post, $token);
		$router->route(
			'/userdel', function($args) use($router, $post) {
				$router->route_userdel($post);
		}, 'POST');
		$eq($core::$errno, Error::USER_NOT_FOUND);

		# success for uid=2
		$post = ['post' => ['uid' => 2]];
		$this->request_authed($rdev, '/userdel', 'POST', $post, $token);
		$router->route(
			'/userdel', function($args) use($router, $post) {
				$router->route_userdel($post);
		}, 'POST');
		$eq($core::$errno, 0);
	}

	public function test_userlist() {
		$eq = $this->eq();
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
		$eq($core::$errno, 0);

		# success
		$this->request_authed(
			$rdev, '/userlist', 'GET', [], $token);
		$router->route('/userlist', function($args) use($router) {
			$router->route_userlist(['get' => []]);
		});
		$eq($core::$errno, 0);
		$eq($core::$data[1]['uname'], 'jimmy');
	}

	public function test_byway() {
		$eq = $this->eq();
		list($router, $core, $admin, $rdev) = $this->make_tester();

		# minimum expiration is hardcoded 60 sec
		$admin->set_expiration(10);
		$eq(600, $admin->get_expiration());

		$post = ['post' => ['uname' => 'someone']];

		# incomplete data
		$rdev->request('/byway', 'POST', $post);
		$router->route(
			'/byway', function($args) use($router, $post) {
				$router->route_byway($post);
		}, 'POST');
		$eq($core::$errno, Error::DATA_INCOMPLETE);

		# success
		$post['post']['uservice'] = '[github]';
		$rdev->request('/byway', 'POST', $post);
		$router->route(
			'/byway', function($args) use($router, $post) {
				$router->route_byway($post);
		}, 'POST');
		$eq($core::$errno, 0);
		$eq($core::$data['uname'], '+someone:[github]');
	}

}
