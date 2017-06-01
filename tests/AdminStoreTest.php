<?php


use PHPUnit\Framework\TestCase;
use BFITech\ZapCore\Logger;
use BFITech\ZapStore\SQLite3;
use BFITech\ZapStore\Redis;
use BFITech\ZapStore\RedisError;
use BFITech\ZapAdmin as za;
use BFITech\ZapAdmin\AdminStoreError as Err;


if (!defined('HTDOCS'))
	define('HTDOCS', __DIR__ . '/htdocs-test');


class AdminStore extends za\AdminStore {}

class AdminStorePatched extends AdminStore {

	public function authz_list_user() {
		$udata = $this->user_data;
		if (in_array($udata['uname'], ['root', 'john']))
			return true;
		return false;
	}

	public function authz_add_user() {
		$udata = $this->user_data;
		if (in_array($udata['uname'], ['root', 'john']))
			return true;
		return false;
	}

	public function authz_delete_user($uid) {
		$udata = $this->user_data;
		if (in_array($udata['uname'], ['root', 'john']))
			return true;
		if ($udata['uid'] == $uid)
			return true;
		return false;
	}

}


class AdminStoreTest extends TestCase {

	protected static $sql;
	protected static $redis;
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
		self::$adm = (new AdminStore(self::$sql))
			->config('expiration', 600);

		# redis-specific
		$redisconf = HTDOCS . '/zapmin-test-redis.json';
		if (!file_exists($redisconf)) {
			$conf = [
				'redishost' => 'localhost',
				'redispassword' => 'xoxo',
			];
			file_put_contents($redisconf,
				json_encode($conf, JSON_PRETTY_PRINT));
		} else {
			$conf = json_decode(file_get_contents($redisconf), true);
		}
		try {
			$redis = new Redis($conf, $logger);
		} catch(RedisError $e) {
			echo "ERROR: Configure your test redis server here:\n";
			echo "       '$redisconf'\n";
			exit(1);
		}
		self::$redis = $redis;
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

		# default expiration with forced table overwrite
		$adm = (new AdminStore($sql, $logger))
			->config('expiration', null)
			->config('force_create_table', true);
		$this->assertEquals($adm->adm_get_expiration(), 3600 * 2);

		# table check statement should not be logged
		$this->assertFalse(strpos(
			file_get_contents($logfile), "SELECT 1 FROM udata"));

		# minimum expiration
		$adm = new AdminStore($sql);
		$adm->config('expiration', 120);
		$this->assertEquals($adm->adm_get_expiration(), 600);

		# calling config after (implicit) init has no effect
		$adm->config('expiration', 1200)->init();
		$this->assertEquals($adm->adm_get_expiration(), 600);

		# deinit, expiration goes back to default
		$adm->deinit();
		# calling deinit repeatedly has no effect
		$adm->deinit();
		$this->assertEquals($adm->adm_get_expiration(), 3600 * 2);

		# working on invalid connection
		$adm = new AdminStore($sql, $logger);
		$sql->close();
		try {
			$adm = new AdminStore($sql, $logger);
		} catch(za\AdminStoreError $e) {}

		unlink($dbfile);
	}

	public function test_redis_cache() {
		# run test on sqlite3 only
		if (self::$sql->get_connection_params()['dbtype'] != 'sqlite3')
			return;

		$logfile = HTDOCS . '/zapmin-test-redis.log';
		$dbfile = HTDOCS . '/zapmit-test-redis.sq3';
		foreach ([$logfile, $dbfile] as $fl)
			if (file_exists($fl))
				unlink($fl);

		$logger = new Logger(Logger::DEBUG, $logfile);
		$sql = new  SQLite3(['dbname' => $dbfile], $logger);

		# default expiration with forced table overwrite
		$adm = (new AdminStore($sql, $logger, self::$redis))
			->config('force_create_table', true);

		$args = self::postFormatter([
			'uname' => 'root', 'upass' => 'admin']);
		$token = $adm->adm_login($args)[1]['token'];
		$adm->adm_set_user_token($token);
		$adm->adm_status();

		# @warning These following is very sensitive to log
		#     structure.
		$parse_log = function($pattern) use($logfile) {
			$logline = null;
			foreach (file($logfile) as $line) {
				if (stripos($line, $pattern) !== false) {
					$logline = trim($line);
					break;
				}
			}
			$logdata = trim(explode('<-', $logline)[1]);
			$logdata = rtrim($logdata, '.');
			$logdata = trim($logdata, "'");
			return json_decode($logdata, true);
		};

		# valid new cache
		$udata = $adm->adm_get_safe_user_data();
		# newly-written cache
		$logdata = $parse_log(sprintf(
			"session written to cache: '%s'", $token));
		$this->assertEquals($logdata['token'], $token);
		$adm->deinit();

		# valid old cache
		$adm->adm_set_user_token($token);
		$adm->adm_status();
		$udata = $adm->adm_get_safe_user_data();
		# retrieved cache
		$logdata = $parse_log(sprintf(
			"session read from cache: '%s'", $token));
		$this->assertEquals($logdata['token'], $token);
		$adm->deinit();

		#file_put_contents($logfile, '');
		$bogus_token = 'lalalala';

		# invalid new session cache
		$adm->adm_set_user_token($bogus_token);
		$adm->adm_status();
		$udata = $adm->adm_get_safe_user_data();
		# retrieved cache
		$logdata = $parse_log(sprintf(
			"session written to cache: '%s'", $bogus_token));
		$this->assertEquals($logdata['uid'], -1);
		$adm->deinit();

		# invalid old session cache
		$adm->adm_set_user_token($bogus_token);
		$adm->adm_status();
		$udata = $adm->adm_get_safe_user_data();
		# retrieved cache
		$logdata = $parse_log(sprintf(
			"session read from cache: '%s'", $bogus_token));
		$this->assertEquals($logdata['uid'], -1);
		$adm->deinit();

		# break cache
		file_put_contents($logfile, '');
		$token_name = $adm->adm_get_token_name();
		$key = sprintf('%s:%s', $token_name, $bogus_token);
		self::$redis->set($key, 'zzz');
		$adm->deinit();

		# broken old session cache
		$adm->adm_set_user_token($bogus_token);
		$adm->adm_status();
		$udata = $adm->adm_get_safe_user_data();
		# retrieved cache
		$logdata = $parse_log(sprintf(
			"session read from cache: '%s'", $bogus_token));
		$this->assertEquals($logdata['uid'], -2);
		$adm->deinit();

		# re-sign in
		$adm->adm_set_user_token($token);
		$adm->adm_status();
		$udata = $adm->adm_get_safe_user_data();
		# retrieved cache
		$logdata = $parse_log(sprintf(
			"session read from cache: '%s'", $token));
		$this->assertEquals($logdata['token'], $token);

		# sign out
		$adm->adm_logout();
		$this->assertNotFalse(stripos(
			file_get_contents($logfile),
			sprintf("session removed from cache: '%s'", $token))
		);

		self::$redis->get_connection()->flushdb();
	}

	public function test_upgrade_tables(){
		# run test on sqlite3 only
		if (self::$sql->get_connection_params()['dbtype'] != 'sqlite3')
			return;

		$logfile = HTDOCS . '/zapmin-test-table-update.log';
		if (file_exists($logfile))
			unlink($logfile);
		$logger = new Logger(Logger::DEBUG, $logfile);

		$sql = new SQLite3(['dbname' => ':memory:'], $logger);

		$open_adm = function($drop=null) use($sql, $logger) {
			$_adm = (new AdminStore($sql, $logger));
			if ($drop)
				$_adm->config('force_create_table', true);
			return $_adm->init();
		};

		$tab = new za\AdminStoreTables;

		# dummy drop
		$sql->query_raw("CREATE TABLE udata (key VARCHAR(20))");
		$adm = $open_adm(true);
		$this->assertEquals($adm->get_table_version(),
			$tab::TABLE_VERSION);

		# fake table upgrading from no version
		$sql->query_raw("DROP TABLE IF EXISTS meta");
		$adm = $open_adm();
		$this->assertEquals($adm->get_table_version(),
			$tab::TABLE_VERSION);

		# fake table upgrading from 0.1
		$sql->update('meta', ['version' => '0.1']);
		$this->assertEquals($adm->get_table_version(), '0.1');
		$adm = $open_adm();
		$this->assertEquals($adm->get_table_version(),
			$tab::TABLE_VERSION);

		# no table updates
		$adm = $open_adm();
		$this->assertNotFalse(strpos(
			file_get_contents($logfile), "Tables are up-to-date."));
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
			$adm->adm_get_safe_user_data()[0], Err::USER_NOT_LOGGED_IN);

		# set an invalid token
		$adm->adm_set_user_token('invalid token');

		# reset status
		$adm->adm_status();

		# loading user data is still forbidden
		$this->assertEquals(
			$adm->adm_get_safe_user_data()[0], Err::USER_NOT_LOGGED_IN);

		# cannot sign out with no valid session
		$this->assertEquals(
			$adm->adm_logout()[0], Err::USER_NOT_LOGGED_IN);
	}

	public function test_login() {
		$adm = self::$adm;

		# invalid post data
		$args = ['uname' => 'admin'];
		$this->assertEquals(
			$adm->adm_login($args)[0], Err::DATA_INCOMPLETE);

		# incomplete post data
		$args = self::postFormatter($args);
		$this->assertEquals(
			$adm->adm_login($args)[0], Err::DATA_INCOMPLETE);

		# invalid user
		$args['post']['upass'] = '1243';
		$this->assertEquals(
			$adm->adm_login($args)[0], Err::USER_NOT_FOUND);

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
			$adm->adm_login($args)[0], Err::USER_ALREADY_LOGGED_IN);
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
			$adm->adm_get_safe_user_data()[0],
			Err::USER_NOT_LOGGED_IN);
		$this->assertEquals($adm->adm_logout()[0],
			Err::USER_NOT_LOGGED_IN);

		# normal login again
		$this->_test_session_expiration_sequence();
		$this->assertEquals(
			$adm->adm_get_safe_user_data()[0], 0);
		$this->assertEquals($adm->adm_logout()[0], 0);
	}

	public function test_logout() {
		$adm = self::$adm;
		$this->assertEquals($adm->adm_logout()[0],
			Err::USER_NOT_LOGGED_IN);
		self::loginOK();
		$this->assertEquals($adm->adm_logout()[0], 0);
	}

	public function test_change_password() {
		$adm = self::$adm;
		# not logged in
		$args = ['pass1' => '123'];
		$this->assertEquals($adm->adm_change_password($args)[0],
			Err::USER_NOT_LOGGED_IN);

		self::loginOK();

		# invalid data
		$this->assertEquals(
			$adm->adm_change_password($args)[0], Err::DATA_INCOMPLETE);

		# incomplete data
		$args['pass2'] = '1234';
		$args = self::postFormatter($args);
		$result = $adm->adm_change_password($args, true);
		$this->assertEquals($result[0], Err::DATA_INCOMPLETE);

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
		$result = $adm->adm_change_bio([]);
		$this->assertEquals($result[0], Err::USER_NOT_LOGGED_IN);

		# begin process

		self::loginOK();
		$safe_data = $adm->adm_get_safe_user_data()[1];
		$this->assertEquals($safe_data['fname'], '');

		# missing arguments post
		$result = $adm->adm_change_bio( [] );
		$this->assertEquals($result[0], Err::DATA_INCOMPLETE);

		# no change
		$result = $adm->adm_change_bio(['post' => []]);
		$this->assertEquals($result[0], 0);

		# fname empty value
		$result = $adm->adm_change_bio([
			'post' => [
				'fname' => '']]);

		$safe_data = $adm->adm_get_safe_user_data()[1];
		$this->assertEquals($safe_data['fname'], '');

		# change fname
		$result = $adm->adm_change_bio([
			'post' => [
				'fname' => 'The Administrator']]);

		$safe_data = $adm->adm_get_safe_user_data()[1];
		$this->assertEquals($safe_data['site'], '');
		$this->assertEquals($safe_data['fname'], 'The Administrator');

		# site too long
		$test_site = 'http://' . str_repeat('jonathan', 12) . '.co';
		$result = $adm->adm_change_bio([
			'post' => [
				'site' => $test_site]]);
		$this->assertEquals($result[0], Err::SITEURL_INVALID);

		# change site url
		$result = $adm->adm_change_bio([
			'post' => [
				'site' => 'http://www.bfinews.com']]);

		$safe_data = $adm->adm_get_safe_user_data()[1];
		$this->assertEquals($safe_data['site'],
			'http://www.bfinews.com');
		$this->assertEquals($safe_data['fname'], 'The Administrator');
	}

	public function test_change_bio_redis() {
		# run test on sqlite3 only
		if (self::$sql->get_connection_params()['dbtype'] != 'sqlite3')
			return;

		$logfile = HTDOCS . '/zapmin-test-change-bio.log';
		if (file_exists($logfile))
			unlink($logfile);
		$logger = new Logger(Logger::DEBUG, $logfile);
		$sql = self::$sql;

		$adm = (new AdminStore($sql, $logger, self::$redis))
			->config('force_create_table', true);

		$args = self::postFormatter([
			'uname' => 'root', 'upass' => 'admin']);
		$token = $adm->adm_login($args)[1]['token'];
		$adm->adm_set_user_token($token);
		$adm->adm_status();

		# test userdata
		$safe_data = $adm->adm_get_safe_user_data()[1];
		$this->assertEquals($safe_data['fname'], '');

		# change user info
		$result = $adm->adm_change_bio([
			'post' => [
				'fname' => 'Administrator',
				'site' => 'http://code.bfinews.com']]);
		$cache_data = $adm->adm_status();

		$this->assertEquals($cache_data['site'],
			'http://code.bfinews.com');
		$this->assertEquals($cache_data['fname'], 'Administrator');

		$adm->adm_logout();

		self::$redis->get_connection()->flushdb();
	}

	public function test_self_register() {
		$adm = self::$adm;

		# missing post arguments
		$this->assertEquals(
			$adm->adm_add_user([], true, true)[0],
			Err::DATA_INCOMPLETE);

		$args = ['post' => [
			'addname' => 'root',
			'addpass1' => 'asdf',
			'addpass2' => 'asdf']];

		# user exists
		$this->assertEquals(
			$adm->adm_add_user($args, true, true)[0],
			Err::USERNAME_EXISTS);

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
		$this->assertEquals($result[0], Err::USER_ALREADY_LOGGED_IN);

		$adm->adm_logout();

		# using shorthand, with email required
		$args['post']['addname'] = 'jack';
		$args['post']['addpass1'] = 'qwer';
		# not typing password twice and no email
		unset($args['post']['addpass2']);
		$result = $adm->adm_self_add_user($args, true, true);
		$this->assertEquals($result[0], Err::DATA_INCOMPLETE);

		# invalid email
		$args['post']['addpass2'] = 'qwer';
		$args['post']['email'] = '#qwer';
		$result = $adm->adm_self_add_user($args, true, true);
		$this->assertEquals($result[0], Err::EMAIL_INVALID);

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
			$adm->adm_self_add_user($args, true, true)[0],
			Err::USERNAME_TOO_LONG);

		# email too long
		$args['post']['addname'] = 'jonathan';
		$args['post']['email'] = str_repeat('jonathan', 12) . '@l.co';
		$result = $adm->adm_self_add_user($args, true, true);
		$this->assertEquals($result[0], Err::EMAIL_INVALID);
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
			$adm->adm_add_user($args, false, false)[0],
			Err::SELF_REGISTER_NOT_ALLOWED);

		# as 'john'
		self::loginOK('john', 'asdf');
		# no authz
		$result = $adm->adm_add_user($args);
		$this->assertEquals($result[0], Err::USER_NOT_AUTHORIZED);
		$adm->adm_logout();

		# as root, with unavailable name
		self::loginOK();
		# user exists
		$this->assertEquals($adm->adm_add_user($args)[0],
			Err::USERNAME_EXISTS);

		# as root, with available name
		$args['post']['addname'] = 'jocelyn';
		# success, no autologin
		$this->assertEquals($adm->adm_add_user($args)[0], 0);
		$adm->adm_logout();

		# try to add 'jonah'
		$args['post']['addname'] = 'jonah';
		$args['post']['addpass1'] = '123';

		# as 'jocelyn'
		self::loginOK('jocelyn', 'asdf');

		# no authz
		$result = $adm->adm_add_user(
			$args, false, false, false, null, null);
		$this->assertEquals($result[0], Err::USER_NOT_AUTHORIZED);
		$adm->adm_logout();

		# use patched AdminStore
		$adm_orig = $adm;
		self::$adm = $adm = new AdminStorePatched(self::$sql);

		# as 'john'
		self::loginOK('john', 'asdf');
		# pass authz but password doesn't check out
		$result = $adm->adm_add_user(
			$args, false, false, false, null, null);
		$this->assertEquals($result[0], Err::PASSWORD_INVALID);
		$this->assertEquals($result[1], Err::PASSWORD_TOO_SHORT);

		# as 'john'
		$args['post']['addpass1'] = 'asdfgh';
		# success
		$this->assertEquals(
			$adm->adm_add_user(
				$args, false, false, false, null, null)[0], 0);
		# name contains white space
		$args['post']['addname'] = 'john smith';
		$this->assertEquals(
			$adm->adm_add_user(
				$args, false, false, false, null, null)[0],
				Err::USERNAME_HAS_WHITESPACE);
		# name starts with plus sign
		$args['post']['addname'] = '+jacqueline';
		$this->assertEquals(
			$adm->adm_add_user(
				$args, false, false, false, null, null)[0],
				Err::USERNAME_LEADING_PLUS);
		# add 'jessica'
		$args['post']['addname'] = 'jessica';
		$adm->adm_add_user($args, false, false, false, null, null);
		# sign out
		$adm->adm_logout();

		# try sign in as 'jonah', no exception thrown
		self::loginOK('jonah', 'asdfgh');

		# restore default AdminStore
		self::$adm = $adm = $adm_orig;
	}

	/**
	 * @depends test_register
	 */
	public function test_delete_user() {
		$adm = self::$adm;

		$args = self::postFormatter(['uid' => '0']);
		# cannot list user when not signed in
		$this->assertEquals(
			$adm->adm_list_user($args)[0], Err::USER_NOT_LOGGED_IN);

		self::loginOK('john', 'asdf');

		$args = self::postFormatter(['uid' => '0']);
		# cannot list user when not authorized
		$this->assertEquals(
			$adm->adm_list_user($args)[0], Err::USER_NOT_AUTHORIZED);
		# sign out
		$adm->adm_logout();

		# use patched AdminStore
		$adm_orig = $adm;
		self::$adm = $adm = new AdminStorePatched(self::$sql);

		# as 'john'
		self::loginOK('john', 'asdf');

		# check number of users
		$user_count= $adm->store->query(
			"SELECT count(uid) AS count FROM udata"
			)['count'];
		# invalid page and limit on user listing will be silently
		# set to their defaults
		$args['post']['page'] = -1e3;
		$args['post']['limit'] = 1e3;
		$user_list = $adm->adm_list_user($args)[1];
		$this->assertEquals(count($user_list), $user_count);

		# authorized user can delete anyone including herself
		$jessica_uid = $adm->store->query(
			"SELECT uid FROM udata WHERE uname=? LIMIT 1",
			['jessica'])['uid'];
		$args['post']['uid'] = $jessica_uid;
		$this->assertEquals(
			$adm->adm_delete_user($args)[0], 0);

		$adm->adm_logout();

		# restore default AdminStore
		self::$adm = $adm = $adm_orig;

		# as 'root'
		self::loginOK();

		# check number of users
		$user_count= $adm->store->query(
			"SELECT count(uid) AS count FROM udata"
			)['count'];
		# invalid page and limit on user listing will be silently
		# set to their defaults
		$args['post']['page'] = -1e3;
		$args['post']['limit'] = 1e3;
		$user_list = $adm->adm_list_user($args)[1];
		$this->assertEquals(count($user_list), $user_count);

		# root user can delete anyone except herself
		$jocelyn_uid = $adm->store->query(
			"SELECT uid FROM udata WHERE uname=? LIMIT 1",
			['jocelyn'])['uid'];
		$args['post']['uid'] = $jocelyn_uid;
		$this->assertEquals(
			$adm->adm_delete_user($args)[0], 0);

		# logout
		$adm->adm_logout();

		# create uid arrays
		$uids = array_map(function($_arr){
			return (string)$_arr['uid'];
		}, $user_list);

		# no authn
		$this->assertEquals(
			$adm->adm_delete_user($args)[0], Err::USER_NOT_LOGGED_IN);

		# as 'jonah'
		self::loginOK('jonah', 'asdfgh');
		# missing post arguments
		$this->assertEquals(
			$adm->adm_delete_user([])[0], Err::DATA_INCOMPLETE);
		# with default authz, any user cannot delete another user
		# except root
		$this->assertEquals(
			$adm->adm_delete_user($args)[0], Err::USER_NOT_AUTHORIZED);
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
		]))[0], Err::USER_NOT_FOUND);

		# use patched AdminStore
		$adm_orig = $adm;
		self::$adm = $adm = new AdminStorePatched(self::$sql);

		# as john
		self::loginOK('john', 'asdf');
		# using jonah's ID, user no longer exists
		$this->assertEquals(
			$adm->adm_delete_user($args)[0], Err::USER_NOT_FOUND);
		# cannot delete 'root'
		$args['post']['uid'] = '1';
		$this->assertEquals(
			$adm->adm_delete_user($args)[0], Err::USER_NOT_AUTHORIZED);
		# success, delete 'jocelyn' uid=3
		$args['post']['uid'] = '3';
		$this->assertEquals(
			$adm->adm_delete_user($args)[0], 0);
		$adm->adm_logout();

		# sign in as 'jocelyn' fails
		try {
			self::loginOK('jocelyn', '1234');
		} catch (Exception $e) {
			$this->assertEquals($adm->adm_status(), null);
		}

		# restore default AdminStore
		self::$adm = $adm_orig;
	}

	public function test_self_register_passwordless() {
		$adm = self::$adm;

		$args = ['post' => null];

		self::loginOK();
		$result = $adm->adm_self_add_user_passwordless($args);
		$this->assertEquals($result[0], Err::USER_ALREADY_LOGGED_IN);
		$adm->adm_logout();

		# no 'service' in args
		$result = $adm->adm_self_add_user_passwordless($args);
		$this->assertEquals($result[0], Err::DATA_INCOMPLETE);

		# not enough args
		$args['service'] = ['uname' => '1234'];
		$result = $adm->adm_self_add_user_passwordless($args);
		$this->assertEquals($result[0], Err::DATA_INCOMPLETE);

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
			$adm->adm_change_password($args)[0], Err::USER_NOT_FOUND);

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

