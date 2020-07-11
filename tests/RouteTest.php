<?php


use BFITech\ZapCoreDev\TestCase;
use BFITech\ZapCore\Logger;
use BFITech\ZapCoreDev\RouterDev;
use BFITech\ZapCoreDev\RoutingDev;
use BFITech\ZapStore\SQLite3;
use BFITech\ZapAdmin\Admin;
use BFITech\ZapAdmin\AuthCtrl;
use BFITech\ZapAdmin\AuthManage;
use BFITech\ZapAdmin\Route;
use BFITech\ZapAdmin\RouteDefault;
use BFITech\ZapAdmin\Error;


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
		self::$sql = null;
		self::$logger->info("TEST DONE.");
	}

	public function test_constructor() {
		$_SERVER['REQUEST_URI'] = '/usr/test';

		# set 'foo' cookie via GLOBALS
		$_COOKIE['foo'] = 'test';

		$rdev = new RoutingDev;
		$log = self::$logger;
		$core = $rdev::$core;
		$core
			->config('home', '/usr')
			->config('shutdown', false)
			->config('logger', $log);

		$sql = new SQLite3(['dbname' => ':memory:'], $log);

		$admin = new Admin($sql, $log);
		$admin
			->config('expire', 3600)
			# change default token name
			->config('token_name', 'bar')
			->config('check_tables', true);

		$ctrl = new AuthCtrl($admin, $log);
		$manage = new AuthManage($admin, $log);

		$eq = $this->eq();

		$zcore = new RouteDefault($core, $ctrl, $manage);
		# token name successfully changed
		$eq($zcore::$admin->get_token_name(), 'bar');

		### $_COOKIES are passed this way, but not if we use
		### $rdev->request('/test') which always resets all HTTP
		### variables and fills $_COOKIES from fourth parameter.
		$core->route('/test', function($args) use($zcore, $eq){
			$eq($args['cookie']['foo'], 'test');
			echo "HELLO, FRIEND";
		});
		$eq('HELLO, FRIEND', $core::$body_raw);
	}

	/** Common test instances. */
	private function make_zcore() {
		### Use new instance on every mock HTTP request. Do not re-use
		### the router. Re-using SQL within one test method is OK.
		$rdev = new RoutingDev;

		$log = self::$logger;
		$core = $rdev::$core
			->config('home', '/')
			->config('logger', $log);

		### renew database if null, typically after tearDown
		if (!self::$sql)
			self::$sql = new SQLite3(['dbname' => ':memory:'], $log);

		### admin instance
		$admin = (new Admin(self::$sql, $log))
			->config('expire', 3600)
			->config('token_name', 'test-zapmin')
			->config('check_tables', true);

		### ctrl instance
		$ctrl = new AuthCtrl($admin, $log);
		### manage instance
		$manage = new AuthManage($admin, $log);

		### RouteDefault instance.
		$zcore = new RouteDefault($core, $ctrl, $manage);

		return [$zcore, $rdev, $core];
	}

	public function test_home() {
		extract(self::vars());
		$body_raw = '<h1>It wurks!</h1>';

		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/', 'GET')
			->route('/', [$zcore, 'route_home']);
		$eq($body_raw, $core::$body_raw);

		# route with deprecated route method
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev->request('/', 'GET');
		$zcore->route('/', [$zcore, 'route_home']);
		$eq($body_raw, $core::$body_raw);
	}

	/**
	 * Successful login for admin to further test user management.
	 *
	 * @return array Admin cookie.
	 */
	private function login_sequence() {
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/login', 'POST', [
				'post' => [
					'uname' => 'root',
					'upass' => 'admin',
				]
			])
			->route('/login', [$zcore, 'route_login'], 'POST');
		$token_name = $zcore::$admin->get_token_name();
		return [$token_name => $core::$data['token']];
	}

	public function test_status() {
		$eq = $this->eq();

		# unauthed
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/status', 'GET')
			->route('/status', [$zcore, 'route_status']);
		$eq($core::$code, 401);
		$eq($core::$errno, Error::USER_NOT_LOGGED_IN);

		### login
		$cookie = $this->login_sequence();
		$tname = $zcore::$admin->get_token_name();
		$tval = $cookie[$tname];

		# authed via invalid header
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev->request('/status', 'GET', $cookie);
		$_SERVER['HTTP_AUTHORIZATION'] = sprintf(
			"%s-%s", $tname, $tval);
		$core->route('/status', [$zcore, 'route_status']);
		$eq($core::$code, 401);
		$eq($core::$errno, Error::USER_NOT_LOGGED_IN);

		# authed via valid header
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev->request('/status', 'GET');
		$_SERVER['HTTP_AUTHORIZATION'] = sprintf(
			"%s %s", $tname, $tval);
		$core->route('/status', [$zcore, 'route_status']);
		$eq($core::$code, 200);
		$eq($core::$data['uid'], 1);

		# authed via $_COOKIES
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev->request('/status', 'GET');
		$_COOKIE[$tname] = $tval;
		$core->route('/status', [$zcore, 'route_status']);
		$eq($core::$code, 200);
		$eq($core::$data['uid'], 1);

		# authed via cookie
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/status', 'GET', [], $cookie)
			->route('/status', [$zcore, 'route_status']);
		$eq($core::$code, 200);
		$eq($core::$data['uid'], 1);
	}

	public function test_login_logout() {
		$eq = $this->eq();

		# login, incomplete data
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/login', 'POST')
			->route('/login', [$zcore, 'route_login'], 'POST');
		$eq($core::$code, 401);
		$eq($core::$errno, Error::DATA_INCOMPLETE);

		### login
		$cookie = $this->login_sequence();

		# logout ok
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/logout', 'GET', [], $cookie)
			->route('/logout', [$zcore, 'route_logout']);
		$eq($core::$code, 200);
		$eq($core::$errno, 0);

		# cannot relogout
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/logout', 'GET', [], $cookie)
			->route('/logout', [$zcore, 'route_logout']);
		$eq($core::$code, 401);
		$eq($core::$errno, Error::USER_NOT_LOGGED_IN);
	}

	public function test_chpasswd() {
		$eq = $this->eq();

		### login
		$cookie = $this->login_sequence();

		# incomplete data
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/chpasswd', 'POST', [], $cookie)
			->route('/chpasswd', [$zcore, 'route_chpasswd'], 'POST');
		$eq($core::$code, 401);
		$eq($core::$errno, Error::DATA_INCOMPLETE);

		# success
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/chpasswd', 'POST', [
				'post' => [
					'pass0' => 'admin',
					'pass1' => 'admin1',
					'pass2' => 'admin1',
				],
			], $cookie)
			->route('/chpasswd', [$zcore, 'route_chpasswd'], 'POST');
		$eq($core::$code, 200);
		$eq($core::$errno, 0);
	}

	public function test_chbio() {
		$eq = $this->eq();

		### login
		$cookie = $this->login_sequence();

		# success
		list($router, $rdev, $core) = $this->make_zcore();
		$args = ['post' => ['fname' => 'The Handyman']];
		$rdev
			->request('/chbio', 'POST', [
				'post' => ['fname' => 'The Handyman'],
			], $cookie)
			->route('/chbio', [$router, 'route_chbio'], 'POST');
		$eq($core::$code, 200);
		$eq($core::$errno, 0);

		# invalid url
		list($router, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/chbio', 'POST', [
				'post' => ['site' => 'Wrongurl'],
			], $cookie)
			->route('/chbio', [$router, 'route_chbio'], 'POST');
		$eq($core::$code, 401);
		$eq($core::$errno, Error::SITEURL_INVALID);

		# success
		list($router, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/chbio', 'POST', [
				'post' => ['site' => 'http://bfi.io'],
			], $cookie)
			->route('/chbio', [$router, 'route_chbio'], 'POST');
		$eq($core::$code, 200);
		$eq($core::$errno, 0);

		### check new value via /status
		list($router, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/status', 'GET', [], $cookie)
			->route('/status', [$router, 'route_status']);
		$eq($core::$data['fname'], 'The Handyman');
		$eq($core::$data['site'], 'http://bfi.io');
	}

	public function test_register() {
		$eq = $this->eq();

		# incomplete data
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/register', 'POST')
			->route('/register', [$zcore, 'route_register'], 'POST');
		$eq($core::$code, 401);
		$eq($core::$errno, Error::DATA_INCOMPLETE);

		# success
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/register', 'POST', [
				'post' => [
					'addname' => 'jim',
					'addpass1' => '123456',
					'addpass2' => '123456',
					'email' => 'here@exampe.org',
				],
			])
			->route('/register', [$zcore, 'route_register'], 'POST');
		$eq($core::$errno, 0);
		$eq($core::$data['uname'], 'jim');
	}

	public function test_useradd() {
		$eq = $this->eq();

		# unauthed
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/useradd', 'POST')
			->route('/useradd', [$zcore, 'route_useradd'], 'POST');
		$eq($core::$errno, Error::USER_NOT_LOGGED_IN);

		### login
		$cookie = $this->login_sequence();

		# incomplete data
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/useradd', 'POST', [
				'post' => ['x' => ''],
			], $cookie)
			->route('/useradd', [$zcore, 'route_useradd'], 'POST');
		$eq($core::$code, 403);
		$eq($core::$errno, Error::DATA_INCOMPLETE);

		# success
		list($zcore, $rdev, $core) = $this->make_zcore();
		$args = [
			'post' => [
				'addname' => 'jim',
				'addpass1' => '123456',
				'email' => 'here@exampe.org',
			],
		];
		$rdev
			->request('/useradd', 'POST', $args, $cookie)
			->route('/useradd', [$zcore, 'route_useradd'], 'POST');
		$eq($core::$errno, 0);

		# re-adding fails because of non-unique email
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/useradd', 'POST', $args, $cookie)
			->route('/useradd', [$zcore, 'route_useradd'], 'POST');
		$eq($core::$code, 403);
		$eq($core::$errno, Error::EMAIL_EXISTS);
	}

	public function test_userdel() {
		$eq = $this->eq();

		# unauthed
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/userdel', 'POST')
			->route('/userdel', [$zcore, 'route_userdel'], 'POST');
		$eq($core::$errno, Error::USER_NOT_LOGGED_IN);

		### login
		$cookie = $this->login_sequence();

		# incomplete data
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/userdel', 'POST', [
				'post' => ['x' => ''],
			], $cookie)
			->route('/userdel', [$zcore, 'route_userdel'], 'POST');
		$eq($core::$code, 403);
		$eq($core::$errno, Error::DATA_INCOMPLETE);

		### adding
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/useradd', 'POST', [
				'post' => [
					'addname' => 'jimmy',
					'addpass1' => '123456',
					'email' => 'here@exampe.org',
				],
			], $cookie)
			->route('/useradd', [$zcore, 'route_useradd'], 'POST');
		$eq($core::$errno, 0);

		# uid=3 not found
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/userdel', 'POST', [
				'post' => ['uid' => 3],
			], $cookie)
			->route('/userdel', [$zcore, 'route_userdel'], 'POST');
		$eq($core::$errno, Error::USER_NOT_FOUND);

		# success for uid=2
		$args['post']['uid'] = 2;
		$rdev
			->request('/userdel', 'POST', [
				'post' => ['uid' => 3],
			], $cookie)
			->route('/userdel', [$zcore, 'route_userdel'], 'POST');
		$eq($core::$errno, 0);
	}

	public function test_userlist() {
		$eq = $this->eq();

		# unauthed
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/userlist')
			->route('/userlist', [$zcore, 'route_userlist']);
		$eq($core::$errno, Error::USER_NOT_LOGGED_IN);

		### login
		$cookie = $this->login_sequence();

		### adding
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/useradd', 'POST', [
				'post' => [
					'addname' => 'jimmy',
					'addpass1' => '123456',
					'email' => 'here@exampe.org',
				],
			], $cookie)
			->route('/useradd', [$zcore, 'route_useradd'], 'POST');

		# success
		list($zcore, $rdev, $core) = $this->make_zcore();
		$rdev
			->request('/userlist', 'GET', [], $cookie)
			->route('/userlist', [$zcore, 'route_userlist']);
		$eq($core::$errno, 0);
		$eq($core::$data[1]['uname'], 'jimmy');
	}

	public function test_byway() {
		$eq = $this->eq();

		# minimum expiration is hardcoded 60 sec
		list($zcore, $_, $_) = $this->make_zcore();
		$zcore::$admin->set_expiration(10);
		$eq(600, $zcore::$admin->get_expiration());

		# incomplete data
		list($zcore, $rdev, $core) = $this->make_zcore();
		$args = ['post' => ['uname' => 'someone']];
		$rdev
			->request('/byway', 'POST', $args)
			->route('/byway', [$zcore, 'route_byway'], 'POST');
		$eq($core::$errno, Error::DATA_INCOMPLETE);

		# success
		list($zcore, $rdev, $core) = $this->make_zcore();
		$args['post']['uservice'] = '[github]';
		$rdev
			->request('/byway', 'POST', $args)
			->route('/byway', [$zcore, 'route_byway'], 'POST');
		$eq($core::$errno, 0);
		$eq($core::$data['uname'], '+someone:[github]');
	}

}
