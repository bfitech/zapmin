<?php


use PHPUnit\Framework\TestCase;
use GuzzleHttp\Cookie\FileCookieJar;
use GuzzleHttp\Client;
use BFITech\ZapCoreDev as zd;


class AdminRouteDefaultTest extends TestCase {

	public static $cookiejar;
	public static $cookiefile;

	public static $server_pid;

	private $response;
	private $code;
	private $body;

	public static function client() {
		return new Client([
			'base_uri' => 'http://localhost:9999',
			'timeout' => 2,
			'cookies' => true,
		]);
	}

	private function format_response($response, $decode_json=true) {
		$this->response = $response;
		$this->code = $response->getStatusCode();
		$body = (string)$response->getBody(); 
		if ($decode_json)
			$body = json_decode($body);
		$this->body = $body;
	}

	public function GET($path, $data=[], $decode_json=true) {
		$response = self::client()->request('GET', $path, [
			'query' => $data,
			'http_errors' => false,
			'cookies' => self::$cookiejar,
		]);
		return $this->format_response($response, $decode_json);
	}

	public function POST($path, $data=[], $decode_json=true) {
		$response = self::client()->request('POST', $path, [
			'form_params' => $data,
			'http_errors' => false,
			'cookies' => self::$cookiejar,
		]);
		return $this->format_response($response, $decode_json);
	}

	public static function setUpBeforeClass() {
		self::$server_pid = zd\CoreDev::server_up(
			__DIR__ . '/htdocs-test');
		self::$cookiefile = '/tmp/zapmin-cookie.txt';
		if (file_exists(self::$cookiefile))
			unlink(self::$cookiefile);
		self::$cookiejar = new FileCookieJar(self::$cookiefile);
	}

	public static function tearDownAfterClass() {
		if (file_exists(self::$cookiefile))
			unlink(self::$cookiefile);
		self::client()->request('GET', '/', [
			'query' => ['reloaddb' => 1]]);
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
		$this->assertEquals($this->code, 501);
		$this->POST('/chpasswd', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, 1);

		$post = ['uname' => 'root', 'upass' => 'admin'];
		$this->POST('/login', $post);

		$post = ['pass1' => '1234'];
		$this->POST('/chpasswd', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, 2);

		$post['pass2'] = '123';
		# cannot post array as value
		$post['pass0'] = ['xxx'];
		$this->POST('/chpasswd', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, 2);

		$post['pass0'] = 'xxx';
		$this->POST('/chpasswd', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, 3);

		$post['pass0'] = 'admin';
		$this->POST('/chpasswd', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, 4);
		$this->assertEquals($this->body->data, 1);

		$post['pass1'] = '123';
		$this->POST('/chpasswd', $post);
		$this->assertEquals($this->code, 401);
		$this->assertEquals($this->body->errno, 4);
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
		# end mock
	}
}

