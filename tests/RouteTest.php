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
use BFITech\ZapAdmin\Error;


/**
 * RoutingDev patch.
 *
 * Add reqauth() to default RoutingDev to shorten request() with
 * additional authentication via cookie.
 */
class Routing extends RoutingDev {

	/**
	 * See RouteTest::make_tester() to set this.
	 *
	 * Use self::$auth_router->route() on next chain if it's set,
	 * otherwise use usual self::$core->route(). Unlike the latter,
	 * the former calls set_token_value in the background based on
	 * cookie availability.
	 **/
	public static $auth_router;

	/**
	 * Similar to self::request, except the last param is token value
	 * instead of the whole cookie array. Token name is derived from
	 * self::auth_router property.
	 **/
	public function reqauth(
		string $uri=null, string $method='GET',
		array $args=null, string $token=null
	) {
		$cookie = [];
		if (self::$auth_router) {
			$token_name = self::$auth_router::$admin->get_token_name();
			if ($token)
				$cookie[$token_name] = $token;
			parent::request($uri, $method, $args, $cookie);
			return self::$auth_router;
		}
		return parent::request($uri, $method, $args, $cookie);
	}

}


class RouteTest extends TestCase {

	public static $logger;
	public static $sql;

	public static function setUpBeforeClass() {
		$logfile = self::tdir(__FILE__) . '/zapmin-route.log';
		if (file_exists($logfile))
			unlink($logfile);
		self::$logger = new Logger(Logger::DEBUG, $logfile);
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

		$log = self::$logger;
		$core = (new RouterDev())
			->config('home', '/usr')
			->config('shutdown', false)
			->config('logger', $log);

		$sql = self::$sql = new SQLite3(['dbname' => ':memory:'], $log);

		$admin = new Admin($sql, $log);
		$admin
			->config('expire', 3600)
			->config('token_name', 'bar')
			->config('check_tables', true);

		$ctrl = new AuthCtrl($admin, $log);
		$manage = new AuthManage($admin, $log);

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
		$log = self::$logger;
		### use new instance on every matching mock HTTP request
		$core = (new RouterDev())
			->config('home', '/')
			->config('logger', $log);

		### always renew database from scratch
		$sql = self::$sql = new SQLite3(['dbname' => ':memory:'], $log);

		$admin = new Admin($sql, $log);
		$admin
			->config('expire', 3600)
			->config('token_name', 'test-zapmin')
			->config('check_tables', true);

		$ctrl = new AuthCtrl($admin, $log);
		$manage = new AuthManage($admin, $log);

		return new RouteDefault($core, $ctrl, $manage);
	}

	/** Common test instances. */
	private function make_tester() {
		$router = $this->make_router();
		$core = $router::$core;
		$admin = $router::$admin;
		$rdev = new Routing($core);
		$rdev::$auth_router = $router;
		return [$router, $rdev, $router::$core];
	}

	public function test_home() {
		extract(self::vars());

		list($router, $rdev, $core) = $this->make_tester();

		$token_name = $router::$admin->get_token_name();
		$eq($token_name, 'test-zapmin');

		$rdev
			->request('/', 'GET')
			->route('/', [$router, 'route_home']);
		$eq('<h1>It wurks!</h1>', $core::$body_raw);
		$tr(strpos(strtolower($core::$body_raw), 'it wurks') > 0);
	}

	/**
	 * Successful login for admin to further test user management.
	 */
	private function login_sequence($router) {
		list($_, $rdev, $core) = $this->make_tester();
		$rdev
			->request('/login', 'POST', [
				'post' => [
					'uname' => 'root',
					'upass' => 'admin',
				]
			])
			->route('/login', [$router, 'route_login'], 'POST');
		return $core::$data['token'];
	}

	public function test_status() {
		$eq = $this->eq();

		list($router, $rdev, $core) = $this->make_tester();

		# unauthed
		$rdev
			->request('/status', 'GET')
			->route('/status', [$router, 'route_status']);
		$eq($core::$code, 401);
		$eq($core::$errno, Error::USER_NOT_LOGGED_IN);

		### login
		$token = $this->login_sequence($router);

		# authed via invalid header
		$rdev->request('/status', 'GET');
		$_SERVER['HTTP_AUTHORIZATION'] = sprintf(
			"%s-%s", 'test-zapmin', $token);
		$router->route('/status', [$router, 'route_status']);
		$eq($core::$errno, Error::USER_NOT_LOGGED_IN);

		# authed via valid header
		$rdev->request('/status', 'GET');
		$_SERVER['HTTP_AUTHORIZATION'] = sprintf(
			"%s %s", 'test-zapmin', $token);
		$router->route('/status', [$router, 'route_status']);
		$eq($core::$errno, 0);
		$eq($core::$data['uid'], 1);

		# authed via cookie
		$rdev->request('/status', 'GET');
		$_COOKIE['test-zapmin'] = $token;
		$router->route('/status', [$router, 'route_status']);
		$_COOKIE = [];
		$eq($core::$errno, 0);
		$eq($core::$data['uid'], 1);

		# authed via cookie
		$rdev
			->reqauth('/status', 'GET', [], $token)
			->route('/status', [$router, 'route_status']);
		$eq($core::$errno, 0);
		$eq($core::$data['uid'], 1);
	}

	public function test_login_logout() {
		$eq = $this->eq();

		list($router, $rdev, $core) = $this->make_tester();

		# login incomplete data
		$rdev
			->request('/login', 'POST')
			->route('/login', [$router, 'route_login'], 'POST');
		$eq($core::$code, 401);
		$eq($core::$errno, Error::DATA_INCOMPLETE);

		### login
		$token = $this->login_sequence($router);

		# logout success
		$rdev
			->reqauth('/logout', 'GET', [], $token)
			->route('/logout', [$router, 'route_logout']);
		$eq($core::$errno, 0);
	}

	public function test_chpasswd() {
		$eq = $this->eq();

		list($router, $rdev, $core) = $this->make_tester();

		### login
		$token = $this->login_sequence($router);

		# incomplete data
		$rdev
			->reqauth('/chpasswd', 'POST', [], $token)
			->route('/chpasswd', [$router, 'route_chpasswd'], 'POST');
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
		$rdev
			->reqauth('/chpasswd', 'POST', $post, $token)
			->route('/chpasswd', [$router, 'route_chpasswd'], 'POST');
		$eq($core::$code, 200);
		$eq($core::$errno, 0);
	}

	public function test_chbio() {
		$eq = $this->eq();

		list($router, $rdev, $core) = $this->make_tester();

		### login
		$token = $this->login_sequence($router);

		# success
		$post = ['post' => ['fname' => 'The Handyman']];
		$rdev
			->reqauth('/chbio', 'POST', $post, $token)
			->route('/chbio', [$router, 'route_chbio'], 'POST');
		$eq($core::$code, 200);
		$eq($core::$errno, 0);

		# invalid url
		$post = ['post' => ['site' => 'Wrongurl']];
		$rdev
			->reqauth('/chbio', 'POST', $post, $token)
			->route('/chbio', [$router, 'route_chbio'], 'POST');
		$eq($core::$code, 401);
		$eq($core::$errno, Error::SITEURL_INVALID);

		# success
		$post = ['post' => ['site' => 'http://bfi.io']];
		$rdev
			->reqauth('/chbio', 'POST', $post, $token)
			->route('/chbio', [$router, 'route_chbio'], 'POST');
		$eq($core::$code, 200);
		$eq($core::$errno, 0);

		### check new value via /status
		$rdev
			->reqauth('/status', 'GET', [], $token)
			->route('/status', [$router, 'route_status']);
		$eq($core::$data['fname'], 'The Handyman');
		$eq($core::$data['site'], 'http://bfi.io');
	}

	public function test_register() {
		$eq = $this->eq();

		list($router, $rdev, $core) = $this->make_tester();

		# incomplete data
		$rdev
			->request('/register', 'POST', ['post' => []])
			->route('/register', [$router, 'route_register'], 'POST');
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
		$rdev
			->request('/register', 'POST', $post)
			->route('/register', [$router, 'route_register'], 'POST');
		$eq($core::$errno, 0);
	}

	public function test_useradd() {
		$eq = $this->eq();

		list($router, $rdev, $core) = $this->make_tester();

		### login
		$token = $this->login_sequence($router);

		# incomplete data
		$post = ['post' => ['x' => '']];
		$rdev
			->reqauth('/useradd', 'POST', $post, $token)
			->route('/useradd', [$router, 'route_useradd'], 'POST');
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
		$rdev
			->reqauth('/useradd', 'POST', $post, $token)
			->route('/useradd', [$router, 'route_useradd'], 'POST');
		$eq($core::$errno, 0);

		# re-adding fails because of non-unique email
		$rdev
			->reqauth('/useradd', 'POST', $post, $token)
			->route('/useradd', [$router, 'route_useradd'], 'POST');
		$eq($core::$code, 403);
		$eq($core::$errno, Error::EMAIL_EXISTS);
	}

	public function test_userdel() {
		$eq = $this->eq();

		list($router, $rdev, $core) = $this->make_tester();

		### login
		$token = $this->login_sequence($router);

		# incomplete data
		$post = ['post' => ['x' => '']];
		$rdev
			->reqauth('/userdel', 'POST', $post, $token)
			->route('/userdel', [$router, 'route_userdel'], 'POST');
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
		$rdev
			->reqauth('/useradd', 'POST', $post, $token)
			->route('/useradd', [$router, 'route_useradd'], 'POST');
		$eq($core::$errno, 0);

		# uid=3 not found
		$post = ['post' => ['uid' => 3]];
		$rdev
			->reqauth('/userdel', 'POST', $post, $token)
			->route('/userdel', [$router, 'route_userdel'], 'POST');
		$eq($core::$errno, Error::USER_NOT_FOUND);

		# success for uid=2
		$post = ['post' => ['uid' => 2]];
		$rdev
			->reqauth('/userdel', 'POST', $post, $token)
			->route('/userdel', [$router, 'route_userdel'], 'POST');
		$eq($core::$errno, 0);
	}

	public function test_userlist() {
		$eq = $this->eq();

		list($router, $rdev, $core) = $this->make_tester();

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
		$rdev
			->reqauth('/useradd', 'POST', $post, $token)
			->route('/useradd', [$router, 'route_useradd'], 'POST');
		$eq($core::$errno, 0);

		# success
		$rdev
			->reqauth('/userlist', 'GET', [], $token)
			->route('/userlist', [$router, 'route_userlist']);
		$eq($core::$errno, 0);
		$eq($core::$data[1]['uname'], 'jimmy');
	}

	public function test_byway() {
		$eq = $this->eq();

		list($router, $rdev, $core) = $this->make_tester();

		# minimum expiration is hardcoded 60 sec
		$router::$admin->set_expiration(10);
		$eq(600, $router::$admin->get_expiration());

		$post = ['post' => ['uname' => 'someone']];

		# incomplete data
		$rdev
			->request('/byway', 'POST', $post)
			->route('/byway', [$router, 'route_byway'], 'POST');
		$eq($core::$errno, Error::DATA_INCOMPLETE);

		# success
		$post['post']['uservice'] = '[github]';
		$rdev
			->request('/byway', 'POST', $post)
			->route('/byway', [$router, 'route_byway'], 'POST');
		$eq($core::$errno, 0);
		$eq($core::$data['uname'], '+someone:[github]');
	}

}
