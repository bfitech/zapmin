<?php


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Common;
use BFITech\ZapCoreDev\CoreDev;
use BFITech\ZapAdmin\AdminStoreError as Err;


if (!defined('HTDOCS'))
	define('HTDOCS', __DIR__ . '/htdocs-test');


class AdminRouteHTTPTest extends TestCase {

	public static $cookiefile;
	public static $base_uri = 'http://localhost:9999';

	public static $server_pid;

	private $response;
	private $code;
	private $body;
	private $authz = [];

	private function format_response($response, $expect_json) {
		if ($expect_json)
			$response[1] = json_decode($response[1]);
		$this->response = $response;
		$this->code = $response[0];
		$this->body = $response[1];
	}

	private function GET($path, $get=[], $expect_json=true) {
		$url = self::$base_uri . $path;
		$request = [
			'url' => $url,
			'method' => 'GET',
			'get' => $get,
			'custom_opts' => [
				CURLOPT_COOKIEJAR => self::$cookiefile,
				CURLOPT_COOKIEFILE => self::$cookiefile,
			]
		];
		if($this->authz)
			$request['custom_opts'][CURLOPT_HTTPHEADER] = $this->authz;
		$response = Common::http_client($request);
		$this->format_response($response, $expect_json);
	}

	private function POST($path, $post=[], $expect_json=true) {
		$url = self::$base_uri . $path;
		$request = [
			'url' => $url,
			'method' => 'POST',
			'post' => $post,
			'custom_opts' => [
				CURLOPT_COOKIEJAR => self::$cookiefile,
				CURLOPT_COOKIEFILE => self::$cookiefile,
			]
		];
		if($this->authz)
			$request['custom_opts'][CURLOPT_HTTPHEADER] = $this->authz;
		$response = Common::http_client($request);
		$this->format_response($response, $expect_json);
	}

	public static function _setUpBeforeClass() {
		$logfile_http = HTDOCS . '/zapmin-test-http.log';
		if (file_exists($logfile_http))
			unlink($logfile_http);
		self::$cookiefile = HTDOCS . '/zapmin-test-cookie.log';
		self::$server_pid = CoreDev::server_up(HTDOCS);
	}

	public static function tearDownAfterClass() {
		if (file_exists(self::$cookiefile))
			unlink(self::$cookiefile);
		Common::http_client([
			'url' => self::$base_uri,
			'method' => 'GET',
			'get' => ['reloaddb' => 1]
		]);
		if (self::$server_pid)
			CoreDev::server_down(self::$server_pid);
	}

	public function tearDown() {
		$this->GET('/logout');
	}

	public function test_home() {
		$this->GET('/', [], false);
		$this->assertEquals($this->code, 200);
		$this->assertEquals(
			$this->body, '<h1>It wurks!</h1>');
	}

	public function test_status() {
		$this->GET('/status');
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno,
			Err::USER_NOT_LOGGED_IN);
		$this->assertEquals($this->body->data, []);
	}

	public function test_login() {
		$post = ['uname' => 'xoot', 'usass' => 'xxxx'];
		$this->POST('/login', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, Err::DATA_INCOMPLETE);

		$post['upass'] = 'xxxx';
		$this->POST('/login', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, Err::USER_NOT_FOUND);

		$post['uname'] = 'root';
		$this->POST('/login', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, Err::WRONG_PASSWORD);

		$post['upass'] = 'admin';
		$this->POST('/login', $post);
		$this->assertEquals($this->code, 200);
		$this->assertEquals($this->body->errno, 0);

		$this->authz[] = sprintf('Authorization: %s %s', 'zapmin', 
			$this->body->data->token);
		$this->POST('/login', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno,
			Err::USER_ALREADY_LOGGED_IN);
	}

	public function test_logout() {

		$this->GET('/logout');
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno,
			Err::USER_NOT_LOGGED_IN);

		$post = ['uname' => 'root', 'upass' => 'admin'];
		$this->POST('/login', $post);

		# with post is ok too
		$this->authz[] = sprintf('Authorization: %s %s', 'zapmin', 
			$this->body->data->token);
		$this->POST('/logout');
		$this->assertEquals($this->code, 200);
		$this->assertEquals($this->body->errno, 0);
	}

	public function test_chpasswd() {
		$post = ['pass1' => '123'];
		$this->GET('/chpasswd', $post);
		$this->assertEquals($this->code, 404);
		$this->POST('/chpasswd', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno,
			Err::USER_NOT_LOGGED_IN);

		$post = ['uname' => 'root', 'upass' => 'admin'];
		$this->POST('/login', $post);

		# Set header
		$this->authz[] = sprintf('Authorization: %s %s', 'zapmin', 
			$this->body->data->token);

		$post = ['pass1' => '1234'];
		$this->POST('/chpasswd', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, Err::DATA_INCOMPLETE);

		$post['pass2'] = '123';
		# cannot post array as value
		$post['pass0'] = ['xxx'];
		$this->POST('/chpasswd', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, Err::DATA_INCOMPLETE);

		$post['pass0'] = 'xxx';
		$this->POST('/chpasswd', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno,
			Err::OLD_PASSWORD_INVALID);

		$post['pass0'] = 'admin';
		$this->POST('/chpasswd', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, Err::PASSWORD_INVALID);
		$this->assertEquals($this->body->data, Err::PASSWORD_NOT_SAME);

		$post['pass1'] = '123';
		$this->POST('/chpasswd', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, Err::PASSWORD_INVALID);
		$this->assertEquals($this->body->data, Err::PASSWORD_TOO_SHORT);

		$post['pass1'] = '1234';
		$post['pass2'] = '1234';
		$this->POST('/chpasswd', $post);
		$this->assertEquals($this->code, 200);

		$this->GET('/logout');

		$this->POST('/login', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, Err::DATA_INCOMPLETE);

		$post = ['uname' => 'root', 'upass' => '1234'];
		$this->POST('/login', $post);
		$this->assertEquals($this->code, 200);

		# set token
		$this->authz = [];
		$this->authz[] = sprintf('Authorization: %s %s', 'zapmin', 
			$this->body->data->token);

		$post['pass0'] = '1234';
		$post['pass1'] = 'admin';
		$post['pass2'] = 'admin';
		$this->POST('/chpasswd', $post);
		$this->assertEquals($this->code, 200);
	}

	public function test_chbio() {

		$post = ['fname' => 'The Administrator'];
		$this->POST('/chbio', $post);
		$this->assertEquals($this->code, 401);

		$pacc = ['uname' => 'root', 'upass' => 'admin'];
		$this->POST('/login', $pacc);

		# set token header
		$this->authz[] = sprintf('Authorization: %s %s', 'zapmin', 
			$this->body->data->token);

		$this->POST('/chbio', $post);
		$this->assertEquals($this->code, 200);

		$this->GET('/status');
		$this->assertEquals($this->body->data->site, '');
		$this->assertEquals(
			$this->body->data->fname, 'The Administrator');
	}

	public function test_register() {
		$post = ['addname' => 'jack', 'addpass1' => 'qwer'];
		$this->POST('/register', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, Err::DATA_INCOMPLETE);

		$post['addpass2'] = 'qwer';
		$this->POST('/register', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, Err::DATA_INCOMPLETE);

		$post['email'] = '~#%#!';
		$this->POST('/register', $post);
		$this->assertEquals($this->body->errno, Err::EMAIL_INVALID);

		$post['email'] = 'w+t@c.jo';
		$this->POST('/register', $post);
		$this->assertEquals($this->body->errno, 0);

		# set token header
		$this->authz[] = sprintf('Authorization: %s %s', 'zapmin', 
			$this->body->data->token);

		# autologin
		$this->GET('/status');
		$this->assertEquals($this->code, 200);
		$this->GET('/logout');

		$post['addname'] = 'jonathan';
		$this->POST('/register', $post);
		$this->assertEquals($this->body->errno, Err::EMAIL_EXISTS);

		$post['addname'] = str_repeat('jonathan', 24);
		$this->POST('/register', $post);
		$this->assertEquals($this->body->errno, Err::USERNAME_TOO_LONG);

		$post['addname'] = 'jonathan';
		$post['email'] = str_repeat('jonathan', 24) . '@example.org';
		$this->POST('/register', $post);
		$this->assertEquals($this->body->errno, Err::EMAIL_INVALID);
	}

	public function test_useradd() {
		$pacc = ['uname' => 'jack', 'upass' => 'qwer'];
		$this->POST('/login', $pacc);
		$this->assertEquals($this->code, 200);
		# set token header
		$this->authz = [];
		$this->authz[] = sprintf('Authorization: %s %s', 'zapmin', 
			$this->body->data->token);

		$post = [
			'addname' => 'jill',
			'addpass1' => 'asdf',
			'email' => 'jill@example.co.id',
		];
		$this->POST('/useradd', $post);
		$this->assertEquals($this->code, 403);
		$this->POST('/logout');

		$pacc = ['uname' => 'root', 'upass' => 'admin'];
		$this->POST('/login', $pacc);
		# set token header
		$this->authz = [];
		$this->authz[] = sprintf('Authorization: %s %s', 'zapmin', 
			$this->body->data->token);

		$this->POST('/useradd', $post);
		$this->assertEquals($this->code, 200);
		$this->POST('/logout');

		$pacc = ['uname' => 'jill', 'upass' => 'asdf'];
		$this->POST('/login', $pacc);
		# set token header
		$this->authz = [];
		$this->authz[] = sprintf('Authorization: %s %s', 'zapmin', 
			$this->body->data->token);
		$this->assertEquals($this->code, 200);
		$this->POST('/logout');

		$pacc = ['uname' => 'root', 'upass' => 'admin'];
		$this->POST('/login', $pacc);
		# set token header
		$this->authz = [];
		$this->authz[] = sprintf('Authorization: %s %s', 'zapmin', 
			$this->body->data->token);

		# user exists
		$post = [
			'addname' => 'jack',
			'addpass1' => '1513',
			'email' => 'jill@example.com',
		];
		$this->POST('/useradd', $post);
		$this->assertEquals($this->code, 403);
		$this->assertEquals($this->body->errno, Err::USERNAME_EXISTS);

		# email exists
		$post = [
			'addname' => 'jeremy',
			'addpass1' => '1513',
			'email' => 'jill@example.co.id',
		];
		$this->POST('/useradd', $post);
		$this->assertEquals($this->code, 403);
		$this->assertEquals($this->body->errno, Err::EMAIL_EXISTS);
	}

	public function test_userdel() {
		$pacc = ['uname' => 'jack', 'upass' => 'qwer'];
		$this->POST('/login', $pacc);
		# set token header
		$this->authz = [];
		$this->authz[] = sprintf('Authorization: %s %s', 'zapmin', 
			$this->body->data->token);

		# jack:uid=2, jill:uid=3

		# cannot delete others
		$post['uid'] = '3';
		$this->POST('/userdel', $post);
		$this->assertEquals($this->code, 403);

		# can self-delete
		$post['uid'] = '2';
		$this->POST('/userdel', $post);
		$this->assertEquals($this->code, 200);
		# session is already gone
		$this->GET('/logout');
		$this->assertEquals($this->code, 401);
		$this->POST('/login', $pacc);
		$this->assertEquals($this->code, 401);

		$pacc = ['uname' => 'jill', 'upass' => 'asdf'];
		$this->POST('/login', $pacc);
		$this->assertEquals($this->code, 200);
		# set token header
		$this->authz = [];
		$this->authz[] = sprintf('Authorization: %s %s', 'zapmin', 
			$this->body->data->token);
		$this->GET('/logout');

		$pacc = ['uname' => 'root', 'upass' => 'admin'];
		$this->POST('/login', $pacc);
		# set token header
		$this->authz = [];
		$this->authz[] = sprintf('Authorization: %s %s', 'zapmin', 
			$this->body->data->token);
		$post['uid'] = '3';
		$this->POST('/userdel', $post);
		$this->assertEquals($this->code, 200);
		$this->GET('/logout');

		$pacc = ['uname' => 'jill', 'upass' => 'asdf'];
		$this->POST('/login', $pacc);
		$this->assertEquals($this->code, 401);
	}

	public function test_userlist() {
		$pacc = ['uname' => 'root', 'upass' => 'admin'];
		$this->POST('/login', $pacc);
		# set token header
		$this->authz = [];
		$this->authz[] = sprintf('Authorization: %s %s', 'zapmin', 
			$this->body->data->token);

		$this->POST('/userlist', $pacc);
		$this->assertEquals(count($this->body->data), 1);

		foreach(['jaime', 'joey', 'judah'] as $jojo) {
			$this->POST('/useradd', [
				'addname' => $jojo,
				'addpass1' => '1234qwer',
				'email' => $jojo . '@example.net',
			]);
		}

		$this->POST('/userlist', $pacc);
		$this->assertEquals(count($this->body->data), 4);
	}

	public function test_byway() {
		$pacc = ['uname' => 'root', 'upass' => 'admin'];
		$this->POST('/login', $pacc);
		# set token header
		$this->authz = [];
		$this->authz[] = sprintf('Authorization: %s %s', 'zapmin', 
			$this->body->data->token);

		$this->POST('/byway');
		$this->assertEquals($this->code, 403);
		$this->POST('/logout');

		# start mock
		$post = ['service' => [
			'uname' => 'jessie']];
		$this->POST('/byway', $post);
		$this->assertEquals($this->code, 403);

		$post['service']['uservice'] = 'yahoo';
		$this->POST('/byway', $post);
		$this->assertEquals($this->code, 200);
		# set token header
		$this->authz = [];
		$this->authz[] = sprintf('Authorization: %s %s', 'zapmin', 
			$this->body->data->token);

		$this->GET('/status');
		$data = $this->body->data;
		$jessie_uid = $data->uid;
		$this->assertEquals($data->uname, '+jessie:yahoo');
		$this->POST('/logout');

		$post['service']['uname'] = 'jenny';
		$post['service']['uservice'] = 'flickr';
		$this->POST('/byway', $post);
		$this->assertEquals(
			$this->body->data->uname, '+jenny:flickr');
		$this->POST('/logout');

		$post['service']['uname'] = 'jessie';
		$post['service']['uservice'] = 'yahoo';
		$this->POST('/byway', $post);
		$this->assertEquals(
			$this->body->data->uid, $jessie_uid);

		$post['pass1'] = '1234';
		$post['pass2'] = '1234';
		$this->POST('/chpasswd', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, Err::USER_NOT_FOUND);
		# end mock
	}

	public function test_patched_abort() {
		$url = self::$base_uri . '/notfound';
		$response = Common::http_client([
			'method' => 'GET',
			'url' => $url,
		]);
		$this->assertEquals($response[0], 404);
		$this->assertEquals($response[1], 'ERROR: 404');
	}
}

