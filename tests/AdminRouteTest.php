<?php


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore as zc;
use BFITech\ZapCoreDev as zd;


class AdminRouteDefaultTest extends TestCase {

	public static $cookiefile;
	public static $logfile;
	public static $base_uri = 'http://localhost:9999';

	public static $server_pid;

	private $response;
	private $code;
	private $body;

	private function format_response($response, $expect_json) {
		if ($expect_json)
			$response[1] = json_decode($response[1]);
		$this->response = $response;
		$this->code = $response[0];
		$this->body = $response[1];
	}

	private function GET($path, $get=[], $expect_json=true) {
		$url = self::$base_uri . $path;
		$response = zc\Common::http_client([
			'url' => $url,
			'method' => 'GET',
			'get' => $get,
			'custom_opts' => [
				CURLOPT_COOKIEJAR => self::$cookiefile,
				CURLOPT_COOKIEFILE => self::$cookiefile,
			]
		]);
		$this->format_response($response, $expect_json);
	}

	private function POST($path, $post=[], $expect_json=true) {
		$url = self::$base_uri . $path;
		$response = zc\Common::http_client([
			'url' => $url,
			'method' => 'POST',
			'post' => $post,
			'custom_opts' => [
				CURLOPT_COOKIEJAR => self::$cookiefile,
				CURLOPT_COOKIEFILE => self::$cookiefile,
			]
		]);
		$this->format_response($response, $expect_json);
	}

	public static function setUpBeforeClass() {
		self::$cookiefile = __DIR__ . '/htdocs-test/zapmin-cookie.log';
		self::$logfile = __DIR__ . '/htdoces-test/zapmin.log';
		self::$server_pid = zd\CoreDev::server_up(
			__DIR__ . '/htdocs-test');
		foreach ([self::$cookiefile, self::$logfile] as $fl) {
			if (file_exists($fl))
				unlink($fl);
		}
	}

	public static function tearDownAfterClass() {
		if (file_exists(self::$cookiefile))
			unlink(self::$cookiefile);
		zc\Common::http_client([
			'url' => self::$base_uri,
			'method' => 'GET',
			'get' => ['reloaddb' => 1]
		]);
		if (self::$server_pid)
			zd\CoreDev::server_down(self::$server_pid);
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
		$this->assertEquals($this->body->errno, 1);
		$this->assertEquals($this->body->data, []);
	}

	public function test_login() {
		$post = ['uname' => 'xoot', 'usass' => 'xxxx'];
		$this->POST('/login', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, 3);

		$post['upass'] = 'xxxx';
		$this->POST('/login', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, 4);

		$post['uname'] = 'root';
		$this->POST('/login', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, 5);

		$post['upass'] = 'admin';
		$this->POST('/login', $post);
		$this->assertEquals($this->code, 200);
		$this->assertEquals($this->body->errno, 0);

		$this->POST('/login', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, 1);
	}

	public function test_logout() {

		$this->GET('/logout');
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, 1);

		$post = ['uname' => 'root', 'upass' => 'admin'];
		$this->POST('/login', $post);

		# with post is ok too
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
		$this->assertEquals($this->body->errno, 1);

		$post = ['uname' => 'root', 'upass' => 'admin'];
		$this->POST('/login', $post);

		$post = ['pass1' => '1234'];
		$this->POST('/chpasswd', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, 4);

		$post['pass2'] = '123';
		# cannot post array as value
		$post['pass0'] = ['xxx'];
		$this->POST('/chpasswd', $post);
		$this->assertEquals($this->code, 401);
		# json_decode fails, causing null body
		$this->assertEquals($this->body, null);

		$post['pass0'] = 'xxx';
		$this->POST('/chpasswd', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, 5);

		$post['pass0'] = 'admin';
		$this->POST('/chpasswd', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, 6);
		$this->assertEquals($this->body->data, 1);

		$post['pass1'] = '123';
		$this->POST('/chpasswd', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, 6);
		$this->assertEquals($this->body->data, 2);

		$post['pass1'] = '1234';
		$post['pass2'] = '1234';
		$this->POST('/chpasswd', $post);
		$this->assertEquals($this->code, 200);

		$this->GET('/logout');

		$this->POST('/login', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, 3);

		$post = ['uname' => 'root', 'upass' => '1234'];
		$this->POST('/login', $post);
		$this->assertEquals($this->code, 200);

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
		$this->assertEquals($this->body->errno, 3);

		$post['addpass2'] = 'qwer';
		$this->POST('/register', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, 3);

		$post['email'] = '~#%#!';
		$this->POST('/register', $post);
		$this->assertEquals($this->body->errno, 5);
		$this->assertEquals($this->body->data, 0);

		$post['email'] = 'w+t@c.jo';
		$this->POST('/register', $post);
		$this->assertEquals($this->body->errno, 0);
		# autologin
		$this->GET('/status');
		$this->assertEquals($this->code, 200);
		$this->GET('/logout');

		$post['addname'] = 'jonathan';
		$this->POST('/register', $post);
		$this->assertEquals($this->body->errno, 5);
		$this->assertEquals($this->body->data, 1);

		$post['addname'] = str_repeat('jonathan', 24);
		$this->POST('/register', $post);
		$this->assertEquals($this->body->errno, 4);

		$post['addname'] = 'jonathan';
		$post['email'] = str_repeat('jonathan', 24) . '@example.org';
		$this->POST('/register', $post);
		$this->assertEquals($this->body->errno, 5);
	}

	public function test_useradd() {
		$pacc = ['uname' => 'jack', 'upass' => 'qwer'];
		$this->POST('/login', $pacc);
		$this->assertEquals($this->code, 200);

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

		$this->POST('/useradd', $post);
		$this->assertEquals($this->code, 200);
		$this->POST('/logout');

		$pacc = ['uname' => 'jill', 'upass' => 'asdf'];
		$this->POST('/login', $pacc);
		$this->assertEquals($this->code, 200);
		$this->POST('/logout');

		$pacc = ['uname' => 'root', 'upass' => 'admin'];
		$this->POST('/login', $pacc);

		# user exists
		$post = [
			'addname' => 'jack',
			'addpass1' => '1513',
			'email' => 'jill@example.com',
		];
		$this->POST('/useradd', $post);
		$this->assertEquals($this->code, 403);
		$this->assertEquals($this->body->errno, 7);

		# email exists
		$post = [
			'addname' => 'jeremy',
			'addpass1' => '1513',
			'email' => 'jill@example.co.id',
		];
		$this->POST('/useradd', $post);
		$this->assertEquals($this->code, 403);
		$this->assertEquals($this->body->errno, 5);
	}

	public function test_userdel() {
		$pacc = ['uname' => 'jack', 'upass' => 'qwer'];
		$this->POST('/login', $pacc);

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
		$this->GET('/logout');

		$pacc = ['uname' => 'root', 'upass' => 'admin'];
		$this->POST('/login', $pacc);
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
		$this->assertEquals($this->body->errno, 2);
		# end mock
	}

	public function test_patched_abort() {
		$url = self::$base_uri . '/notfound';
		$response = zc\Common::http_client([
			'method' => 'GET',
			'url' => $url,
		]);
		$this->assertEquals($response[0], 404);
		$this->assertEquals($response[1], 'ERROR: 404');
	}
}

