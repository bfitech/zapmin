<?php


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger;
use BFITech\ZapStore\SQLite3;
use BFITech\ZapAdmin as za;
use BFITech\ZapAdmin\AdminStoreError as Err;


if (!defined('HTDOCS'))
	define('HTDOCS', __DIR__ . '/htdocs-test');


class AdminStore extends za\AdminStore {}

class AdminStoreTest extends TestCase {

	protected static $sql;
	protected static $adm;

	protected static $pwdless_uid;

	public static function postFormatter($args) {
		return ['post' => $args];
	}

	public static function loginOK($uname='root', $upass='admin') {
		$result = self::$adm->adm_login(self::postFormatter([
			'uname' => $uname,
			'upass' => $upass,
		]));
		self::$adm->adm_set_user_token($result[1]['token']);
		self::$adm->adm_status();
	}

	public static function setUpBeforeClass() {
		$logfile = HTDOCS . '/zapmin-test-sqlite3.log';
		if (file_exists($logfile))
			unlink($logfile);
		$logger = new Logger(Logger::DEBUG, $logfile);
		self::$sql = new SQLite3([
			'dbname' => ':memory:'
		], $logger);
		self::$adm = new AdminStore(self::$sql, 600);
	}

	public static function tearDownAfterClass() {
		#self::$sql = null;
	}

	public function tearDown() {
		if (self::$adm->adm_get_safe_user_data())
			self::$adm->adm_logout();
	}

	public function test_constructor() {
		# run test on sqlite3 only
		if (self::$sql->get_connection_params()['dbtype'] != 'sqlite3')
			return;

		$logfile = HTDOCS . '/zapmin-test-constructor.log';
		if (file_exists($logfile))
			unlink($logfile);
		$logger = new Logger(Logger::ERROR, $logfile);
		$dbfile = HTDOCS . '/zapmin-test-constructor.sq3';
		$sql = new SQLite3([
			'dbname' => $dbfile,
		], $logger);

		# minimum expiration
		$adm = new AdminStore($sql, 60);
		$this->assertEquals($adm->adm_get_expiration(), 600);

		# default maximum expiration with forced table overwrite
		$adm = new AdminStore($sql, null, true, $logger);
		$this->assertEquals($adm->adm_get_expiration(), 3600 * 2);

		# table check statement should not be logged
		$this->assertFalse(strpos(
			file_get_contents($logfile), "SELECT 1 FROM udata"));

		# working on invalid connection
		$sql->close();
		try {
			$adm = new AdminStore($sql, null, true, $logger);
		} catch(za\AdminStoreError $e) {}

		unlink($dbfile);
	}

	public function test_table() {
		$uname = self::$sql->query(
			"SELECT uname FROM udata LIMIT 1")['uname'];
		$this->assertEquals($uname, 'root');
	}

	public function test_set_user_token() {
		$adm = self::$adm;

		# loading user data is forbidden
		$this->assertEquals(
			$adm->adm_get_safe_user_data()[0], Err::USERS_NOT_LOGGED_IN);

		# set an invalid token
		$adm->adm_set_user_token('invalid token');

		# reset status
		$adm->adm_status();

		# loading user data is still forbidden
		$this->assertEquals(
			$adm->adm_get_safe_user_data()[0], Err::USERS_NOT_LOGGED_IN);

		# cannot sign out with no valid session
		$this->assertEquals(
			$adm->adm_logout()[0], Err::USERS_NOT_LOGGED_IN);
	}

	public function test_login() {
		$adm = self::$adm;

		# invalid post data
		$args = ['uname' => 'admin'];
		$this->assertEquals(
			$adm->adm_login($args)[0], Err::MISSING_POST_ARGS);

		# incomplete post data
		$args = self::postFormatter($args);
		$this->assertEquals(
			$adm->adm_login($args)[0], Err::MISSING_DICT);

		# invalid user
		$args['post']['upass'] = '1243';
		$this->assertEquals(
			$adm->adm_login($args)[0], Err::USERS_NOT_FOUND);

		# invalid password
		$args['post']['uname'] = 'root';
		$this->assertEquals(
			$adm->adm_login($args)[0], Err::WRONG_PASSWORD);

		# missing token
		$this->assertEquals(
			$adm->adm_set_user_token(false), null);

		# success
		$args['post']['upass'] = 'admin';
		$login_data = $adm->adm_login($args);
		$this->assertEquals($login_data[0], 0);
		$token = $login_data[1]['token'];
		# unlike passwordless login, this has no sid
		$this->assertEquals(isset($login_data['sid']), false);

		# simulating next load
		$adm->adm_set_user_token($token);
		$adm->adm_status();
		# re-login will fail
		$this->assertEquals(
			$adm->adm_login($args)[0], Err::USERS_ALREADY_LOGGED_IN);
	}

	private function _test_session_expiration_sequence(
		$callback=null
	) {
		$adm = self::$adm;
		$login_data = $adm->adm_login([
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

		$adm->adm_set_user_token($token);
		$adm->adm_status();
	}

	public function test_session_expiration() {
		$adm = self::$adm;
		# normal login
		$this->_test_session_expiration_sequence();
		$this->assertEquals(
			$adm->adm_get_safe_user_data()[0], 0);
		$this->assertEquals($adm->adm_logout()[0], 0);

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
			$adm->adm_get_safe_user_data()[0], Err::USERS_NOT_LOGGED_IN);
		$this->assertEquals($adm->adm_logout()[0], Err::USERS_NOT_LOGGED_IN);

		# normal login again
		$this->_test_session_expiration_sequence();
		$this->assertEquals(
			$adm->adm_get_safe_user_data()[0], 0);
		$this->assertEquals($adm->adm_logout()[0], 0);
	}

	public function test_logout() {
		$adm = self::$adm;
		$this->assertEquals($adm->adm_logout()[0], Err::USERS_NOT_LOGGED_IN);
		self::loginOK();
		$this->assertEquals($adm->adm_logout()[0], 0);
	}

	public function test_change_password() {
		$adm = self::$adm;
		# not logged in
		$args = ['pass1' => '123'];
		$this->assertEquals(
			$adm->adm_change_password($args)[0], Err::USERS_NOT_LOGGED_IN);

		self::loginOK();

		# invalid data
		$this->assertEquals(
			$adm->adm_change_password($args)[0], Err::MISSING_POST_ARGS);

		# incomplete data
		$args['pass2'] = '1234';
		$args = self::postFormatter($args);
		$result = $adm->adm_change_password($args, true);
		$this->assertEquals($result[0], Err::MISSING_DICT);

		# wrong old password
		$args['post']['pass0'] = '1234';
		$result = $adm->adm_change_password($args, true);
		$this->assertEquals($result[0], Err::OLD_PASSWORD_INVALID);

		# new passwords don't verify
		$args['post']['pass0'] = 'admin';
		$result = $adm->adm_change_password($args, true);
		$this->assertEquals($result[0], Err::PASSWORD_INVALID);
		$this->assertEquals($result[1], Err::PASSWORD_NOT_SAME);

		# new password too short
		$args['post']['pass2'] = '123';
		$result = $adm->adm_change_password($args, true);
		$this->assertEquals($result[0], Err::PASSWORD_INVALID);
		$this->assertEquals($result[1], Err::PASSWORD_TOO_SHORT);

		# success
		$args['post']['pass1'] = '1234';
		$args['post']['pass2'] = '1234';
		$this->assertEquals(
			$adm->adm_change_password($args, true)[0], 0);

		# logout
		$this->assertEquals($adm->adm_logout()[0], 0);

		# relogin with old password will fail
		try {
			self::loginOK();
		} catch (Exception $e) {
			$this->assertEquals($adm->adm_status(), null);
		}

		# login with new password
		self::loginOK('root', '1234');

		# change password back without old password requirement
		$args['post']['pass1'] = $args['post']['pass2'] = 'admin';
		$this->assertEquals(
			$adm->adm_change_password($args)[0], 0);
	}

	public function test_change_bio() {
		$adm = self::$adm;

		# not logged in
		$r = $adm->adm_change_bio([]);
		$this->assertEquals($r[0], Err::USERS_NOT_LOGGED_IN);

		# begin process

		self::loginOK();
		$safe_data = $adm->adm_get_safe_user_data()[1];
		$this->assertEquals($safe_data['fname'], '');

		# missing arguments post
		$r = $adm->adm_change_bio( [] );
		$this->assertEquals($r[0], Err::MISSING_POST_ARGS);

		# no change
		$r = $adm->adm_change_bio(['post' => []]);
		$this->assertEquals($r[0], 0);

		# fname empty value
		$r = $adm->adm_change_bio([
			'post' => [
				'fname' => '']]);

		$safe_data = $adm->adm_get_safe_user_data()[1];
		$this->assertEquals($safe_data['fname'], '');

		# change fname
		$r = $adm->adm_change_bio([
			'post' => [
				'fname' => 'The Administrator']]);

		$safe_data = $adm->adm_get_safe_user_data()[1];
		$this->assertEquals($safe_data['site'], '');
		$this->assertEquals($safe_data['fname'], 'The Administrator');

		# site too long
		$test_site = 'http://' . str_repeat('jonathan', 12) . '.co';
		$r = $adm->adm_change_bio([
			'post' => [
				'site' => $test_site]]);
		$this->assertEquals($r[0], Err::INVALID_SITE_URL);

		# change site url
		$r = $adm->adm_change_bio([
			'post' => [
				'site' => 'http://www.bfinews.com']]);

		$safe_data = $adm->adm_get_safe_user_data()[1];
		$this->assertEquals($safe_data['site'], 'http://www.bfinews.com');
		$this->assertEquals($safe_data['fname'], 'The Administrator');
	}

	public function test_self_register() {
		$adm = self::$adm;

		# missing post arguments
		$this->assertEquals(
			$adm->adm_add_user([], true, true)[0], Err::MISSING_POST_ARGS);

		$args = ['post' => [
			'addname' => 'root',
			'addpass1' => 'asdf',
			'addpass2' => 'asdf']];

		# user exists
		$this->assertEquals(
			$adm->adm_add_user($args, true, true)[0], Err::USERS_EXISTS);

		# success
		$args['post']['addname'] = 'john';
		$this->assertEquals(
			$adm->adm_add_user($args, true, true)[0], 0);
		# autologin, this should happen immediately prior to
		# sending anything to client
		self::loginOK('john', 'asdf');
		$user_data = $adm->adm_status();
		$this->assertEquals($user_data['uname'], 'john');

		$result = $adm->adm_self_add_user($args, true, true);
		$this->assertEquals($result[0], Err::USERS_ALREADY_LOGGED_IN);

		$adm->adm_logout();

		# using shorthand, with email required
		$args['post']['addname'] = 'jack';
		$args['post']['addpass1'] = 'qwer';
		# not typing password twice and no email
		unset($args['post']['addpass2']);
		$result = $adm->adm_self_add_user($args, true, true);
		$this->assertEquals($result[0], Err::MISSING_DICT);

		# invalid email
		$args['post']['addpass2'] = 'qwer';
		$args['post']['email'] = '#qwer';
		$result = $adm->adm_self_add_user($args, true, true);
		$this->assertEquals($result[0], Err::INVALID_EMAIL);

		# success
		$args['post']['email'] = 'test+bed@example.org';
		$this->assertEquals(
			$adm->adm_self_add_user($args, true, true)[0], 0);

		# email exists
		$args['post']['addname'] = 'jonathan';
		$result = $adm->adm_self_add_user($args, true, true);
		$this->assertEquals($result[0], Err::EMAIL_EXISTS);

		# uname too long
		$args['post']['addname'] = str_repeat('jonathan', 24);
		$this->assertEquals(
			$adm->adm_self_add_user($args, true, true)[0], Err::NAME_TOO_LONG);

		# email too long
		$args['post']['addname'] = 'jonathan';
		$args['post']['email'] = str_repeat('jonathan', 12) . '@l.co';
		$result = $adm->adm_self_add_user($args, true, true);
		$this->assertEquals($result[0], Err::INVALID_EMAIL);
	}

	/**
	 * @depends test_self_register
	 */
	public function test_register() {
		$adm = self::$adm;

		$args = ['post' => [
			'addname' => 'john',
			'addpass1' => 'asdf']];

		# no authn, self-registration disabled
		$this->assertEquals(
			$adm->adm_add_user($args, false, false)[0], Err::SELF_REGISTER_NOT_ALLOWED);

		# as 'john' with default callback
		self::loginOK('john', 'asdf');
		# no authz
		$result = $adm->adm_add_user($args);
		$this->assertEquals($result[0], Err::USERS_NOT_AUTHORIZED);
		$adm->adm_logout();

		# as root, with unavailable name
		self::loginOK();
		# user exists
		$this->assertEquals($adm->adm_add_user($args)[0], Err::USERS_EXISTS);

		# as root, with available name
		$args['post']['addname'] = 'jocelyn';
		# success, no autologin
		$this->assertEquals($adm->adm_add_user($args)[0], 0);
		$adm->adm_logout();

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
		$uname = $adm->adm_get_safe_user_data()[1]['uname'];
		$cbp = ['uname' => $uname];
		# no authz, doesn't satisfy callback
		$result = $adm->adm_add_user(
			$args, false, false, false, $cbf, $cbp);
		$this->assertEquals($result[0], Err::USERS_NOT_AUTHORIZED);
		$adm->adm_logout();

		# as 'john'
		self::loginOK('john', 'asdf');
		$uname = $adm->adm_get_safe_user_data()[1]['uname'];
		$cbp = ['uname' => $uname];
		# pass authz but password doesn't check out
		$result = $adm->adm_add_user(
			$args, false, false, false, $cbf, $cbp);
		$this->assertEquals($result[0], Err::PASSWORD_INVALID);
		$this->assertEquals($result[1], Err::PASSWORD_TOO_SHORT);

		# as 'john'
		$args['post']['addpass1'] = 'asdfgh';
		# success
		$this->assertEquals(
			$adm->adm_add_user(
				$args, false, false, false, $cbf, $cbp)[0], 0);
		# name contains white space
		$args['post']['addname'] = 'john smith';
		$this->assertEquals(
			$adm->adm_add_user(
				$args, false, false, false, $cbf, $cbp)[0], Err::NAME_CONTAIN_WHITESPACE);
		# name starts with plus sign
		$args['post']['addname'] = '+jacqueline';
		$this->assertEquals(
			$adm->adm_add_user(
				$args, false, false, false, $cbf, $cbp)[0], Err::NAME_LEADING_PLUS);
		$adm->adm_logout();

		# try sign in as 'jonah', no exception thrown
		self::loginOK('jonah', 'asdfgh');
	}

	/**
	 * @depends test_register
	 */
	public function test_delete_user() {
		$adm = self::$adm;

		$args = self::postFormatter(['uid' => '0']);

		# cannot list user when not signed in
		$this->assertEquals(
			$adm->adm_list_user($args)[0], Err::USERS_NOT_AUTHORIZED);

		self::loginOK();
		$user_list = $adm->adm_list_user($args)[1];
		# so far we have 5 users
		$this->assertEquals(count($user_list), 5);
		$adm->adm_logout();

		# create uid arrays
		$uids = array_map(function($_arr){
			return (string)$_arr['uid'];
		}, $user_list);

		# no authn
		$this->assertEquals(
			$adm->adm_delete_user($args)[0], Err::USERS_NOT_LOGGED_IN);

		# as 'jonah'
		self::loginOK('jonah', 'asdfgh');
		# missing post arguments
		$this->assertEquals(
			$adm->adm_delete_user([])[0], Err::MISSING_POST_ARGS);
		# with default callback, any user cannot delete another user 
		# except root
		$this->assertEquals(
			$adm->adm_delete_user($args)[0], Err::USERS_NOT_AUTHORIZED);
		# but s/he can self-delete
		$args['post']['uid'] = $adm->adm_status()['uid'];
		$this->assertEquals(
			$adm->adm_delete_user($args)[0], 0);
		# logout is still allowed since it doesn't check sid
		$adm->adm_logout();
		# unable to re-login because user is no longer found
		$this->assertEquals($adm->adm_login(self::postFormatter([
			'uname' => 'jonah',
			'upass' => 'asdfgh',
		]))[0], Err::USERS_NOT_FOUND);

		# with callback
		$cbf = function($_args) {
			# only 'root' and 'john' are allowed to delete
			if (in_array($_args['uname'], ['root', 'john']))
				return 0;
			return 1;
		};

		# as john
		self::loginOK('john', 'asdf');
		$uname = $adm->adm_get_safe_user_data()[1]['uname'];
		$cbp = ['uname' => $uname];
		# user doesn't exist
		$this->assertEquals(
			$adm->adm_delete_user($args, $cbf, $cbp)[0], Err::USERS_NOT_FOUND);
		# cannot delete 'root'
		$args['post']['uid'] = '1';
		$this->assertEquals(
			$adm->adm_delete_user($args, $cbf, $cbp)[0], Err::CANNOT_DELETE_ROOT);
		# success, delete 'jocelyn' uid=3
		$args['post']['uid'] = '3';
		$this->assertEquals(
			$adm->adm_delete_user($args, $cbf, $cbp)[0], 0);
		$adm->adm_logout();

		# sign in as 'jocelyn' fails
		try {
			self::loginOK('jocelyn', '1234');
		} catch (Exception $e) {
			$this->assertEquals($adm->adm_status(), null);
		}
	}

	public function test_self_register_passwordless() {
		$adm = self::$adm;

		$args = ['post' => null];

		# no 'service' in args
		$result = $adm->adm_self_add_user_passwordless($args);
		$this->assertEquals($result[0], Err::MISSING_SERVICE_ARGS);

		# not enough args
		$args['service'] = ['uname' => '1234'];
		$result = $adm->adm_self_add_user_passwordless($args);
		$this->assertEquals($result[0], Err::MISSING_DICT);

		# success
		$args['service']['uservice'] = 'github';
		$result = $adm->adm_self_add_user_passwordless($args);
		$this->assertEquals($result[0], 0);
		$this->assertEquals($result[1]['uname'], '+1234:github');

		# use token
		$token = $result[1]['token'];
		$adm->adm_set_user_token($token);

		# signing in success
		$result = $adm->adm_get_safe_user_data();
		$this->assertEquals($result[0], 0);
		$this->assertEquals($result[1]['uname'], '+1234:github');

		# use this in dependent next test
		self::$pwdless_uid = $result[1]['uid'];
	}

	/**
	 * @depends test_self_register_passwordless
	 */
	public function test_login_passwordless() {
		$adm = self::$adm;
		$sql = self::$sql;

		$args = ['service' => [
			'uname' => '1234',
			'uservice' => 'google',
		]];
		$result = $adm->adm_self_add_user_passwordless($args);
		$this->assertEquals($result[0], 0);
		$this->assertEquals($result[1]['uname'], '+1234:google');
		$this->assertEquals(isset($result[1]['sid']), true);

		# change expiration to 20 minutes
		$test_expire = 1200;

		# uid doesn't increment
		$args['service']['uservice'] = 'github';
		## set expiration to 20 minutes
		$adm->adm_set_byway_expiration($test_expire);
		$result = $adm->adm_self_add_user_passwordless($args);
		$this->assertEquals($result[0], 0);
		$this->assertEquals($result[1]['uname'], '+1234:github');
		$this->assertEquals($result[1]['uid'],
			self::$pwdless_uid);
		$token = $result[1]['token'];

		# set token
		$adm->adm_set_user_token($token);
		$adm->adm_status();

		# passwordless login can't change password
		$args['post']['pass1'] = $args['post']['pass2'] = 'blablabla';
		$this->assertEquals(
			$adm->adm_change_password($args)[0], Err::USERS_NOT_FOUND);

		# check expiration
		$tnow = $sql->query(sprintf(
			"SELECT (%s) AS now",
			$sql->stmt_fragment('datetime')
		))['now'];
		$dtnow = strtotime($tnow);
		$texp = $sql->query(
			"SELECT expire FROM usess " .
			"WHERE token=? ORDER BY sid DESC " .
			"LIMIT 1",
			[$token]
		)['expire'];
		$dtexp = strtotime($texp);

		# difference must be small, say, 2 seconds at most
		$diff = abs((abs($dtexp - $dtnow) - $test_expire));
		$this->assertTrue($diff <= 2);
	}
}

