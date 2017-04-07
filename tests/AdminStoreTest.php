<?php


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore as zc;
use BFITech\ZapStore as zs;
use BFITech\ZapAdmin as za;


if (!defined('HTDOCS'))
	define('HTDOCS', __DIR__ . '/htdocs-test');


class AdminStoreTest extends TestCase {

	protected static $sql;
	protected static $store;

	protected static $pwdless_uid;

	public static function postFormatter($args) {
		return ['post' => $args];
	}

	public static function loginOK($uname='root', $upass='admin') {
		$result = self::$store->adm_login(self::postFormatter([
			'uname' => $uname,
			'upass' => $upass,
		]));
		self::$store->adm_set_user_token($result[1]['token']);
		self::$store->adm_status();
	}

	public static function setUpBeforeClass() {
		$logfile = HTDOCS . '/zapmin-test-sqlite3.log';
		if (file_exists($logfile))
			unlink($logfile);
		$logger = new zc\Logger(zc\Logger::DEBUG, $logfile);
		self::$sql = new zs\SQLite3([
			'dbname' => ':memory:'
		], $logger);
		self::$store = new za\AdminStore(self::$sql, 600);
	}

	public static function tearDownAfterClass() {
		#self::$sql = null;
	}

	public function tearDown() {
		if (self::$store->adm_get_safe_user_data())
			self::$store->adm_logout();
	}

	public function test_constructor() {
		# test on sqlite3 only
		if (self::$sql->get_connection_params()['dbtype'] != 'sqlite3')
			return;

		$logfile = HTDOCS . '/zapmin-test-constructor.log';
		if (file_exists($logfile))
			unlink($logfile);
		$logger = new zc\Logger(zc\Logger::ERROR, $logfile);
		$dbfile = HTDOCS . '/zapmin-test-constructor.sq3';
		$sql = new zs\SQLite3([
			'dbname' => $dbfile,
		], $logger);

		# minimum expiration
		$store= new za\AdminStore($sql, 60);
		$this->assertEquals($store->adm_get_expiration(), 600);

		# default maximum expiration with forced table overwrite
		$store= new za\AdminStore($sql, null, true, $logger);
		$this->assertEquals($store->adm_get_expiration(), 3600 * 2);

		# working on invalid connection
		$sql->close();
		try {
			$store = new za\AdminStore($sql, null, true, $logger);
		} catch(za\AdminStoreError $e) {}

		unlink($dbfile);
	}

	public function test_table() {
		$uname = self::$sql->query(
			"SELECT uname FROM udata LIMIT 1")['uname'];
		$this->assertEquals($uname, 'root');
	}

	public function test_set_user_token() {
		# loading user data is forbidden
		$this->assertEquals(
			self::$store->adm_get_safe_user_data()[0], 1);

		# set an invalid token
		self::$store->adm_set_user_token('invalid token');

		# reset status
		self::$store->adm_status();

		# loading user data is still forbidden
		$this->assertEquals(
			self::$store->adm_get_safe_user_data()[0], 1);

		# cannot sign out with no valid session
		$this->assertEquals(
			self::$store->adm_logout()[0], 1);
	}

	public function test_login() {
		# invalid post data
		$args = ['uname' => 'admin'];
		$this->assertEquals(
			self::$store->adm_login($args)[0], 2);

		# incomplete post data
		$args = self::postFormatter($args);
		$this->assertEquals(
			self::$store->adm_login($args)[0], 3);

		# invalid user
		$args['post']['upass'] = '1243';
		$this->assertEquals(
			self::$store->adm_login($args)[0], 4);

		# invalid password
		$args['post']['uname'] = 'root';
		$this->assertEquals(
			self::$store->adm_login($args)[0], 5);

		# success
		$args['post']['upass'] = 'admin';
		$login_data = self::$store->adm_login($args);
		$this->assertEquals($login_data[0], 0);
		$token = $login_data[1]['token'];
		# unlike passwordless login, this has no sid
		$this->assertEquals(isset($login_data['sid']), false);

		# simulating next load
		self::$store->adm_set_user_token($token);
		self::$store->adm_status();
		# re-login will fail
		$this->assertEquals(
			self::$store->adm_login($args)[0], 1);
	}

	private function _test_session_expiration_sequence(
		$callback=null
	) {
		$login_data = self::$store->adm_login([
			'post' => [
				'uname' => 'root',
				'upass' => 'admin',
			],
		]);
		$this->assertEquals($login_data[0], 0);
		$token = $login_data[1]['token'];

		# callback to mess up with session data
		if ($callback)
			$callback($token);

		self::$store->adm_set_user_token($token);
		self::$store->adm_status();
	}

	public function test_session_expiration() {
		# normal login
		$this->_test_session_expiration_sequence();
		$this->assertEquals(
			self::$store->adm_get_safe_user_data()[0], 0);
		$this->assertEquals(self::$store->adm_logout()[0], 0);

		# simulate expired session
		$fake_expire_callback = function($token) {
			self::$sql->query_raw(sprintf(
				"UPDATE usess SET expire=%s WHERE token='%s'",
				self::$sql->stmt_fragment(
					'datetime', ['delta' => -3600]),
				$token));
		};
		$this->_test_session_expiration_sequence(
			$fake_expire_callback);
		$this->assertEquals(
			self::$store->adm_get_safe_user_data()[0], 1);
		$this->assertEquals(self::$store->adm_logout()[0], 1);

		# normal login again
		$this->_test_session_expiration_sequence();
		$this->assertEquals(
			self::$store->adm_get_safe_user_data()[0], 0);
		$this->assertEquals(self::$store->adm_logout()[0], 0);
	}

	public function test_logout() {
		$this->assertEquals(self::$store->adm_logout()[0], 1);
		self::loginOK();
		$this->assertEquals(self::$store->adm_logout()[0], 0);
	}

	public function test_change_password() {
		# not logged in
		$args = ['pass1' => '123'];
		$this->assertEquals(
			self::$store->adm_change_password($args)[0], 1);

		self::loginOK();

		# invalid data
		$this->assertEquals(
			self::$store->adm_change_password($args)[0], 3);

		# incomplete data
		$args['pass2'] = '1234';
		$args = self::postFormatter($args);
		$result = self::$store->adm_change_password($args, true);
		$this->assertEquals($result[0], 4);

		# wrong old password
		$args['post']['pass0'] = '1234';
		$result = self::$store->adm_change_password($args, true);
		$this->assertEquals($result[0], 5);

		# new passwords don't verify
		$args['post']['pass0'] = 'admin';
		$result = self::$store->adm_change_password($args, true);
		$this->assertEquals($result[0], 6);
		$this->assertEquals($result[1], 1);

		# new password too short
		$args['post']['pass2'] = '123';
		$result = self::$store->adm_change_password($args, true);
		$this->assertEquals($result[0], 6);
		$this->assertEquals($result[1], 2);

		# success
		$args['post']['pass1'] = '1234';
		$args['post']['pass2'] = '1234';
		$this->assertEquals(
			self::$store->adm_change_password($args, true)[0], 0);

		# logout
		$this->assertEquals(self::$store->adm_logout()[0], 0);

		# relogin with old password will fail
		try {
			self::loginOK();
		} catch (Exception $e) {
			$this->assertEquals(self::$store->adm_status(), null);
		}

		# login with new password
		self::loginOK('root', '1234');

		# change password back without old password requirement
		$args['post']['pass1'] = $args['post']['pass2'] = 'admin';
		$this->assertEquals(
			self::$store->adm_change_password($args)[0], 0);
	}

	public function test_change_bio() {
		self::loginOK();
		$safe_data = self::$store->adm_get_safe_user_data()[1];
		$this->assertEquals($safe_data['fname'], '');

		# change fname
		$r = self::$store->adm_change_bio([
			'post' => [
				'fname' => 'The Administrator']]);

		$safe_data = self::$store->adm_get_safe_user_data()[1];
		$this->assertEquals($safe_data['site'], '');
		$this->assertEquals($safe_data['fname'], 'The Administrator');
	}

	public function test_self_register() {
		$args = ['post' => [
			'addname' => 'root',
			'addpass1' => 'asdf',
			'addpass2' => 'asdf']];

		# user exists
		$this->assertEquals(
			self::$store->adm_add_user($args, true, true)[0], 7);

		# success
		$args['post']['addname'] = 'john';
		$this->assertEquals(
			self::$store->adm_add_user($args, true, true)[0], 0);
		# autologin, this should happen immediately prior to
		# sending anything to client
		self::loginOK('john', 'asdf');
		$user_data = self::$store->adm_status();
		$this->assertEquals($user_data['uname'], 'john');
		self::$store->adm_logout();

		# using shorthand, with email required
		$args['post']['addname'] = 'jack';
		$args['post']['addpass1'] = 'qwer';
		# not typing password twice and no email
		unset($args['post']['addpass2']);
		$result = self::$store->adm_self_add_user($args, true, true);
		$this->assertEquals($result[0], 3);

		# invalid email
		$args['post']['addpass2'] = 'qwer';
		$args['post']['email'] = '#qwer';
		$result = self::$store->adm_self_add_user($args, true, true);
		$this->assertEquals($result[0], 5);
		$this->assertEquals($result[1], 0);

		# success
		$args['post']['email'] = 'test+bed@example.org';
		$this->assertEquals(
			self::$store->adm_self_add_user($args, true, true)[0], 0);

		# email exists
		$args['post']['addname'] = 'jonathan';
		$result = self::$store->adm_self_add_user($args, true, true);
		$this->assertEquals($result[0], 5);
		$this->assertEquals($result[1], 1);

		# uname too long
		$args['post']['addname'] = str_repeat('jonathan', 24);
		$this->assertEquals(
			self::$store->adm_self_add_user($args, true, true)[0], 4);

		# email too long
		$args['post']['addname'] = 'jonathan';
		$args['post']['email'] = str_repeat('jonathan', 12) . '@l.co';
		$result = self::$store->adm_self_add_user($args, true, true);
		$this->assertEquals($result[0], 5);
		$this->assertEquals($result[1], 0);
	}

	/**
	 * @depends test_self_register
	 */
	public function test_register() {
		$args = ['post' => [
			'addname' => 'john',
			'addpass1' => 'asdf']];

		# no authn, self-registration disabled
		$this->assertEquals(
			self::$store->adm_add_user($args, false, false)[0], 2);

		# as 'john' with default callback
		self::loginOK('john', 'asdf');
		# no authz
		$result = self::$store->adm_add_user($args);
		$this->assertEquals($result[0], 1);
		$this->assertEquals($result[1], 1);
		self::$store->adm_logout();

		# as root, with unavailable name
		self::loginOK();
		# user exists
		$this->assertEquals(self::$store->adm_add_user($args)[0], 7);

		# as root, with available name
		$args['post']['addname'] = 'jocelyn';
		# success, no autologin
		$this->assertEquals(self::$store->adm_add_user($args)[0], 0);
		self::$store->adm_logout();

		# with callback
		$cbf = function($_args) {
			# only 'root' and 'john' are allowed to add new user
			if (in_array($_args['uname'], ['root', 'john']))
				return 0;
			return 1;
		};

		# try to add 'jonah'
		$args['post']['addname'] = 'jonah';
		$args['post']['addpass1'] = '123';

		# as 'jocelyn'
		self::loginOK('jocelyn', 'asdf');
		$uname = self::$store->adm_get_safe_user_data()[1]['uname'];
		$cbp = ['uname' => $uname];
		# no authz, doesn't satisfy callback
		$result = self::$store->adm_add_user(
			$args, false, false, false, $cbf, $cbp);
		$this->assertEquals($result[0], 1);
		$this->assertEquals($result[1], 1);
		self::$store->adm_logout();

		# as 'john'
		self::loginOK('john', 'asdf');
		$uname = self::$store->adm_get_safe_user_data()[1]['uname'];
		$cbp = ['uname' => $uname];
		# pass authz but password doesn't check out
		$result = self::$store->adm_add_user(
			$args, false, false, false, $cbf, $cbp);
		$this->assertEquals($result[0], 6);
		$this->assertEquals($result[1], 2);

		# as 'john'
		$args['post']['addpass1'] = 'asdfgh';
		# success
		$this->assertEquals(
			self::$store->adm_add_user(
				$args, false, false, false, $cbf, $cbp)[0], 0);
		# name contains white space
		$args['post']['addname'] = 'john smith';
		$this->assertEquals(
			self::$store->adm_add_user(
				$args, false, false, false, $cbf, $cbp), [4, 1]);
		# name starts with plus sign
		$args['post']['addname'] = '+jacqueline';
		$this->assertEquals(
			self::$store->adm_add_user(
				$args, false, false, false, $cbf, $cbp), [4, 2]);
		self::$store->adm_logout();

		# try sign in as 'jonah', no exception thrown
		self::loginOK('jonah', 'asdfgh');
	}

	/**
	 * @depends test_register
	 */
	public function test_delete_user() {

		$args = self::postFormatter(['uid' => '0']);

		# cannot list user when not signed in
		$this->assertEquals(
			self::$store->adm_list_user($args)[1], 1);

		self::loginOK();
		$user_list = self::$store->adm_list_user($args)[1];
		# so far we have 5 users
		$this->assertEquals(count($user_list), 5);
		self::$store->adm_logout();

		# create uid arrays
		$uids = array_map(function($_arr){
			return (string)$_arr['uid'];
		}, $user_list);

		# no authn
		$this->assertEquals(
			self::$store->adm_delete_user($args)[0], 1);

		# as 'jonah'
		self::loginOK('jonah', 'asdfgh');
		# with default callback, any user cannot delete another user 
		# except root
		$this->assertEquals(
			self::$store->adm_delete_user($args)[0], 3);
		# but s/he can self-delete
		$args['post']['uid'] = self::$store->adm_status()['uid'];
		$this->assertEquals(
			self::$store->adm_delete_user($args)[0], 0);
		# logout is still allowed since it doesn't check sid
		self::$store->adm_logout();
		# unable to re-login because user is no longer found
		$this->assertEquals(self::$store->adm_login(self::postFormatter([
			'uname' => 'jonah',
			'upass' => 'asdfgh',
		]))[0], 4);

		# with callback
		$cbf = function($_args) {
			# only 'root' and 'john' are allowed to delete
			if (in_array($_args['uname'], ['root', 'john']))
				return 0;
			return 1;
		};

		# as john
		self::loginOK('john', 'asdf');
		$uname = self::$store->adm_get_safe_user_data()[1]['uname'];
		$cbp = ['uname' => $uname];
		# user doesn't exist
		$this->assertEquals(
			self::$store->adm_delete_user($args, $cbf, $cbp)[0], 5);
		# cannot delete 'root'
		$args['post']['uid'] = '1';
		$this->assertEquals(
			self::$store->adm_delete_user($args, $cbf, $cbp)[0], 4);
		# success, delete 'jocelyn' uid=3
		$args['post']['uid'] = '3';
		$this->assertEquals(
			self::$store->adm_delete_user($args, $cbf, $cbp)[0], 0);
		self::$store->adm_logout();

		# sign in as 'jocelyn' fails
		try {
			self::loginOK('jocelyn', '1234');
		} catch (Exception $e) {
			$this->assertEquals(self::$store->adm_status(), null);
		}
	}

	public function test_self_register_passwordless() {
		$args = ['post' => null];

		# no 'service' in args
		$result = self::$store->adm_self_add_user_passwordless($args);
		$this->assertEquals($result[0], 2);
		$this->assertEquals($result[1], 0);

		# not enough args
		$args['service'] = ['uname' => '1234'];
		$result = self::$store->adm_self_add_user_passwordless($args);
		$this->assertEquals($result[0], 2);
		$this->assertEquals($result[1], 1);

		# success
		$args['service']['uservice'] = 'github';
		$result = self::$store->adm_self_add_user_passwordless($args);
		$this->assertEquals($result[0], 0);
		$this->assertEquals($result[1]['uname'], '+1234:github');

		# use token
		$token = $result[1]['token'];
		self::$store->adm_set_user_token($token);

		# signing in success
		$result = self::$store->adm_get_safe_user_data();
		$this->assertEquals($result[0], 0);
		$this->assertEquals($result[1]['uname'], '+1234:github');

		# use this in dependent next test
		self::$pwdless_uid = $result[1]['uid'];
	}

	/**
	 * @depends test_self_register_passwordless
	 */
	public function test_login_passwordless() {
		$args = ['service' => [
			'uname' => '1234',
			'uservice' => 'google',
		]];
		$result = self::$store->adm_self_add_user_passwordless($args);
		$this->assertEquals($result[0], 0);
		$this->assertEquals($result[1]['uname'], '+1234:google');
		$this->assertEquals(isset($result[1]['sid']), true);

		# uid doesn't increment
		$args['service']['uservice'] = 'github';
		$result = self::$store->adm_self_add_user_passwordless($args);
		$this->assertEquals($result[0], 0);
		$this->assertEquals($result[1]['uname'], '+1234:github');
		$this->assertEquals($result[1]['uid'],
			self::$pwdless_uid);

		# set token
		self::$store->adm_set_user_token($result[1]['token']);
		self::$store->adm_status();

		# passwordless login can't change password
		$args['post']['pass1'] = $args['post']['pass2'] = 'blablabla';
		$this->assertEquals(
			self::$store->adm_change_password($args)[0], 2);
	}
}

