<?php


use BFITech\ZapCoreDev\TestCase;
use BFITech\ZapCore\Logger;
use BFITech\ZapCore\Config;
use BFITech\ZapStore\Redis;
use BFITech\ZapStore\RedisError;
use BFITech\ZapStore\SQL;
use BFITech\ZapStore\SQLite3;
use BFITech\ZapStore\SQLError;
use BFITech\ZapAdmin\Admin;
use BFITech\ZapAdmin\AuthCtrl;
use BFITech\ZapAdmin\AuthManage;
use BFITech\ZapAdmin\Error;


class Ctrl extends AuthCtrl {
	# nothing to patch
}

class Manage extends AuthManage {

	public function authz_list() {
		$udata = $this->get_user_data();
		if (!$udata)
			return false;
		if (in_array($udata['uname'], ['root', 'john']))
			return true;
		return false;
	}

	public function authz_add() {
		$udata = $this->get_user_data();
		if (!$udata)
			return false;
		if (in_array($udata['uname'], ['root', 'john']))
			return true;
		return false;
	}

	public function authz_delete(int $uid) {
		$udata = $this->get_user_data();
		if (!$udata)
			return false;
		if (in_array($udata['uname'], ['root', 'john']))
			return true;
		if ($udata['uid'] == $uid)
			return true;
		return false;
	}

}

/**
 * Use tests under respective database backends.
 */
abstract class AuthCommon extends TestCase {

	protected static $sql;
	protected static $redis;
	protected static $logger;

	protected static $ctrl;
	protected static $manage;

	protected static $p_ctrl;
	protected static $p_manage;

	protected static $cfile;

	/**
	 * Default configuration and environment variable lookup.
	 *
	 * Watch out the env var names. They come mostly from various
	 * official docker images hence the irregularities. In case of no
	 * env vars involved, e.g. on Travis, the setup must match
	 * the default values.
	 */
	private static function config_default() {
		return [
			'mysql' => [
				['dbhost', 'MYSQL_HOST', 'localhost'],
				['dbport', 'MYSQL_PORT', 3306],
				['dbuser', 'MYSQL_USER', 'root'],
				['dbpass', 'MYSQL_PASSWORD', ''],
				['dbname', 'MYSQL_DATABASE', 'zapstore_test_db'],
			],
			'pgsql' => [
				['dbhost', 'POSTGRES_HOST', 'localhost'],
				['dbport', 'POSTGRES_PORT', 5432],
				['dbuser', 'POSTGRES_USER', 'postgres'],
				['dbpass', 'POSTGRES_PASSWORD', ''],
				['dbname', 'POSTGRES_DB', 'zapstore_test_db'],
			],
			'sqlite3' => [
				['dbname', null, ':memory:'],
			],
			'redis' => [
				['redishost', 'REDISHOST', 'localhost'],
				['redisport', 'REDISPORT', 6379],
				['redispassword', 'REDISPASSWORD', 'xoxo'],
				['redisdatabase', 'REDISDATABASE', 10],
			],
		];
	}

	public static function conn_bail($type, $logfile) {
		printf(
			"\nERROR: Cannot connect to '%s' test database.\n\n" .
			"- Check extensions for interpreter: '%s'.\n" .
			"- Fix test configuration '%s': %s\n" .
			"- Inspect test log: %s.\n\n",
			$type, PHP_BINARY, self::$cfile,
			file_get_contents(self::$cfile), $logfile);
		exit(1);
	}

	/**
	 * Open configuration file.
	 */
	public static function open_config($engine) {
		$cfile = self::tdir(__FILE__) . "/zapmin.json";
		self::$cfile = $cfile;

		# use existing config
		if (file_exists($cfile))
			return (new Config($cfile))->get($engine);

		# create new
		file_put_contents($cfile, '[]');
		$cnf = new Config($cfile);

		# load from default values or from env vars if applicable
		foreach (self::config_default() as $section => $sval) {
			foreach ($sval as $val) {
				list($key, $env, $dfl) = $val;
				$ckey = sprintf('%s.%s', $section, $key);
				$cval = $dfl;
				if ($env !== null && getenv($env))
					$cval = getenv($env);
				$cnf->add($ckey, $cval);
			}
		}

		return $cnf->get($engine);
	}

	public static function open_connections($dbtype) {
		$logfile = self::tdir(__FILE__) . "/zapmin-$dbtype.log";
		if (file_exists($logfile))
			unlink($logfile);
		$log = self::$logger = new Logger(Logger::ERROR, $logfile);
		$params = self::open_config(null);

		try {
			$dbparams = $params[$dbtype];
			$dbparams['dbtype'] = $dbtype;
			self::$sql = new SQL($dbparams, $log);
		} catch(SQLError $err) {
			self::conn_bail($dbtype, $logfile);
		}
		try {
			# mysql doesn't cascade dropping views from tables properly
			self::$sql->query_raw("DROP VIEW v_usess");
			# drop existing test tables
			foreach (['meta', 'usess', 'udata'] as $table)
				self::$sql->query_raw("DROP TABLE $table CASCADE");
		} catch(SQLError $err) {
		}

		try {
			$redisparams = $params['redis'];
			self::$redis = new Redis($redisparams, $log);
		} catch(RedisError $err) {
			self::conn_bail($redistype, $logfile);
		}
	}

	public function setUp() {
		$log = self::$logger;
		$admin = new Admin(self::$sql, $log, self::$redis);
		$admin
			->config('expire', 3600)
			->config('check_tables', true);
		### original Auth* classess
		self::$ctrl = new AuthCtrl($admin, $log);
		self::$manage = new AuthManage($admin, $log);
		### patched Auth* subclasses
		self::$p_ctrl = new Ctrl($admin, $log);
		self::$p_manage = new Manage($admin, $log);
	}

	public function tearDown() {
		foreach (['usess', 'udata', 'meta'] as $table) {
			self::$sql->query_raw(
				"DELETE FROM udata WHERE uid>1");
		}
		if (self::$redis)
			self::$redis->get_connection()->flushdb();
	}

	public static function loginOK($uname='root', $upass='admin') {
		$result = self::$ctrl->login([
			'uname' => $uname,
			'upass' => $upass,
		]);
		$token = $result[1]['token'];

		foreach ([
			self::$ctrl, self::$manage,
			self::$p_ctrl, self::$p_manage,
		] as $au) {
			$au->reset();
			$au->set_token_value($token);
			$au->get_user_data();
		}
		if (self::$redis)
			self::$redis->get_connection()->flushdb();
		return $token;
	}

	public function test_admin() {
		$tr = $this->tr();
		# only run this on one database backend
		if (get_class($this) != 'AuthSQLite3Test') {
			$tr(true);
			return;
		}

		$logger = new Logger(Logger::ERROR, '/dev/null');
		$sql = new SQLite3(['dbname' => ':memory:'], $logger);

		$admin = new Admin($sql, $logger);
		$expire_invalid = false;
		try {
			$admin->config('expiration', 360)->init();
		} catch(Error $e) {
			$expire_invalid = true;
		}
		$tr($expire_invalid);

		$token_name_not_set = false;
		try {
			$admin->config('token_name', '')->init();
		} catch(Error $e) {
			$token_name_not_set = true;
		}
		$tr($token_name_not_set);

		$admin = (new Admin($sql, $logger))->init();
		$admin->config('token_name', 'quux')->init();
		$this->eq()($admin->get_token_name(), 'zapmin');
	}

	public function test_login() {
		$eq = $this->eq();
		$ctrl = self::$ctrl;

		# invalid post data
		$args = ['uname' => 'admin'];
		$eq($ctrl->login($args)[0], Error::DATA_INCOMPLETE);

		# incomplete post data
		$eq($ctrl->login($args)[0], Error::DATA_INCOMPLETE);

		# invalid user
		$args['upass'] = '1243';
		$eq($ctrl->login($args)[0], Error::USER_NOT_FOUND);

		# invalid password
		$args['uname'] = 'root';
		$eq($ctrl->login($args)[0], Error::WRONG_PASSWORD);

		# missing token
		$eq($ctrl->set_token_value(false), null);

		# success
		$args['upass'] = 'admin';
		$login_data = $ctrl->login($args);
		$eq($login_data[0], 0);
		$token = $login_data[1]['token'];
		# unlike passwordless login, this has no sid
		$eq(isset($login_data['sid']), false);

		# simulating next load
		$ctrl->set_token_value($token);
		$ctrl->get_user_data();
		# re-login will fail
		$eq($ctrl->login($args)[0], Error::USER_ALREADY_LOGGED_IN);
	}

	public function test_cache() {
		if (!self::$redis) {
			$this->tr()(true);
			return;
		}

		$sm = $this->sm();
		$redis = self::$redis;
		$ctrl = self::$ctrl;
		$token_name = $ctrl::$admin->get_token_name();
		$token_value = self::loginOK();

		# break the cache
		$rkey = sprintf('%s:%s', $token_name, $token_value);
		$redis->set($rkey, '3%!#%#U^#!$%^');
		$ctrl->reset();
		$ctrl->set_token_value($token_value);
		$sm($ctrl->get_user_data(), null);

		# get cache from token not obtained from valid login process
		$ctrl->reset();
		$token_bogus = $token_value . 'xxxxxxxxxxx';
		$ctrl->set_token_value($token_bogus);
		$sm($ctrl->get_user_data(), null);
		$rkey = sprintf('%s:%s', $token_name, $token_bogus);
		$sm(json_decode($redis->get($rkey), true), ['uid' => -1]);
		$sm($redis->ttl($rkey), $ctrl::$admin->get_expiration());
	}

	private function _expiration_sequence(
		$callback=null
	) {
		$ctrl = self::$ctrl;
		$ctrl->reset();

		$login_data = $ctrl->login([
			'uname' => 'root',
			'upass' => 'admin',
		]);
		$this->eq()($login_data[0], 0);
		$token = $login_data[1]['token'];
		if (self::$redis)
			$ctrl::$admin->cache_del($token);

		# callback to mess up with session data
		if ($callback)
			$callback($token);

		$ctrl->set_token_value($token);
		$ctrl->get_user_data();
	}

	public function test_session_expiration() {
		$eq = $this->eq();
		$ctrl = self::$ctrl;

		# normal login
		$this->_expiration_sequence();
		$eq($ctrl->get_safe_user_data()[0], 0);
		$eq($ctrl->logout()[0], 0);

		$ctrl->reset();
		$this->_expiration_sequence();
		$eq($ctrl->get_safe_user_data()[0], 0);
		$eq($ctrl->logout()[0], 0);

		# simulate expired session
		$fake_expire_callback = function($token) use($ctrl) {
			$sql = $ctrl::$admin::$store;
			$sql->query_raw(sprintf(
				"UPDATE usess SET expire=%s WHERE token='%s'",
				$sql->stmt_fragment(
					'datetime', ['delta' => -3600])
			, $token));
		};
		$this->_expiration_sequence($fake_expire_callback);
		$eq($ctrl->get_safe_user_data()[0], Error::USER_NOT_LOGGED_IN);
		$eq($ctrl->logout()[0], Error::USER_NOT_LOGGED_IN);

		# normal login again
		$this->_expiration_sequence();
		$eq($ctrl->get_safe_user_data()[0], 0);
		$eq($ctrl->logout()[0], 0);
	}

	public function test_logout() {
		$ctrl = self::$ctrl;
		$this->eq()($ctrl->logout()[0], Error::USER_NOT_LOGGED_IN);
		self::loginOK();
		$this->eq()($ctrl->logout()[0], 0);
	}

	public function test_change_password() {
		$eq = $this->eq();
		$ctrl = self::$ctrl;

		# not logged in
		$args = ['pass1' => '123'];
		$eq($ctrl->change_password($args)[0],
			Error::USER_NOT_LOGGED_IN);

		self::loginOK();

		# invalid data
		$eq($ctrl->change_password($args)[0], Error::DATA_INCOMPLETE);

		# incomplete data
		$args['pass2'] = '1234';
		$result = $ctrl->change_password($args, true);
		$eq($result[0], Error::DATA_INCOMPLETE);

		# wrong old password
		$args['pass0'] = '1234';
		$result = $ctrl->change_password($args, true);
		$eq($result[0], Error::OLD_PASSWORD_INVALID);

		# new passwords don't verify
		$args['pass0'] = 'admin';
		$result = $ctrl->change_password($args, true);
		$eq($result[0], Error::PASSWORD_INVALID);

		# new password too short
		$args['pass2'] = '123';
		$result = $ctrl->change_password($args, true);
		$eq($result[0], Error::PASSWORD_INVALID);
		$eq($result[1], Error::PASSWORD_TOO_SHORT);

		# success
		$args['pass1'] = '1234';
		$args['pass2'] = '1234';
		$eq($ctrl->change_password($args, true)[0], 0);

		# logout
		$eq($ctrl->logout()[0], 0);

		# relogin with old password will fail
		try {
			self::loginOK();
		} catch (Exception $e) {
			$eq($ctrl->get_user_data(), null);
		}

		# login with new password
		self::loginOK('root', '1234');

		# change password back without old password requirement
		$args['pass1'] = $args['pass2'] = 'admin';
		$eq($ctrl->change_password($args)[0], 0);
	}

	public function test_change_bio() {
		$eq = $this->eq();
		$ctrl = self::$ctrl;

		# not logged in
		$result = $ctrl->change_bio([]);
		$eq($result[0], Error::USER_NOT_LOGGED_IN);

		self::loginOK();

		$safe_data = $ctrl->get_safe_user_data()[1];
		$eq($safe_data['fname'], '');

		# no change
		$result = $ctrl->change_bio(['post' => []]);
		$eq($result[0], 0);

		# fname empty value
		$result = $ctrl->change_bio(['fname' => '']);

		$safe_data = $ctrl->get_safe_user_data()[1];
		$eq($safe_data['fname'], '');

		# change fname
		$result = $ctrl->change_bio(['fname' => 'The Administrator']);

		$safe_data = $ctrl->get_safe_user_data()[1];
		$eq($safe_data['site'], '');
		$eq($safe_data['fname'], 'The Administrator');

		# site too long
		$test_site = 'http://' . str_repeat('jonathan', 12) . '.co';
		$result = $ctrl->change_bio(['site' => $test_site]);
		$eq($result[0], Error::SITEURL_INVALID);

		# change site url
		$result = $ctrl->change_bio([
			'site' => 'http://www.bfinews.com']);

		$safe_data = $ctrl->get_safe_user_data()[1];
		$eq($safe_data['site'], 'http://www.bfinews.com');
		$eq($safe_data['fname'], 'The Administrator');
	}

	public function test_self_register() {
		$eq = $this->eq();
		$manage = self::$manage;

		# missing post arguments
		$eq($manage->add([], true, true)[0],
			Error::DATA_INCOMPLETE);

		$args = [
			'addname' => 'root',
			'addpass1' => 'asdf',
			'addpass2' => 'asdf',
		];

		# user exists
		$eq($manage->add($args, true, true)[0], Error::USERNAME_EXISTS);

		# success
		$args['addname'] = 'john';
		$eq($manage->add($args, true, true)[0], 0);
		### autologin, this should happen immediately prior to
		### sending anything to client
		self::loginOK('john', 'asdf');
		$udata = $manage->get_user_data();
		$eq($udata['uname'], 'john');

		$result = $manage->self_add($args, true, true);
		$eq($result[0], Error::USER_ALREADY_LOGGED_IN);

		$manage->reset();

		# using shorthand, with email required
		$args['addname'] = 'jack';
		$args['addpass1'] = 'qwer';
		# not typing password twice and no email
		unset($args['post']['addpass2']);
		$result = $manage->self_add($args, true, true);
		$eq($result[0], Error::DATA_INCOMPLETE);

		# invalid email
		$args['addpass2'] = 'qwer';
		$args['email'] = '#qwer';
		$result = $manage->self_add($args, true, true);
		$eq($result[0], Error::EMAIL_INVALID);

		# success
		$args['email'] = 'test+bed@example.org';
		$eq($manage->self_add($args, true, true)[0], 0);

		# email exists
		$args['addname'] = 'jonathan';
		$result = $manage->self_add($args, true, true);
		$eq($result[0], Error::EMAIL_EXISTS);

		# uname too long
		$args['addname'] = str_repeat('jonathan', 24);
		$eq($manage->self_add($args, true, true)[0],
			Error::USERNAME_TOO_LONG);

		# email too long
		$args['addname'] = 'jonathan';
		$args['email'] = str_repeat('jonathan', 12) . '@l.co';
		$result = $manage->self_add($args, true, true);
		$eq($result[0], Error::EMAIL_INVALID);
	}

	public function test_register() {
		$eq = $this->eq();
		$ctrl = self::$ctrl;
		$manage = self::$manage;

		# add new
		$args = [
			'addname' => 'john',
			'addpass1' => 'asdf',
			'addpass2' => 'asdf',
		];
		$eq($manage->add($args, true, true)[0], 0);

		$args = [
			'addname' => 'john',
			'addpass1' => 'asdf',
		];

		# no authn, self-registration disabled
		$eq(
			$manage->add($args, false, false)[0],
			Error::SELF_REGISTER_NOT_ALLOWED);

		### as 'john'
		self::loginOK('john', 'asdf');

		# no authz
		$result = $manage->add($args);
		$eq($result[0], Error::USER_NOT_AUTHORIZED);

		$ctrl->reset();
		$manage->reset();

		### as root, with unavailable name
		self::loginOK();

		# user exists, with addname=john
		$eq($manage->add($args)[0], Error::USERNAME_EXISTS);

		# as root, with addname=jocelyn
		$args['addname'] = 'jocelyn';
		# success, no autologin
		$eq($manage->add($args)[0], 0);

		$ctrl->reset();
		$manage->reset();

		# try to add 'jonah'
		$args['addname'] = 'jonah';
		$args['addpass1'] = '123';

		### as 'jocelyn'
		self::loginOK('jocelyn', 'asdf');

		# no authz
		$result = $manage->add(
			$args, false, false, false);
		$eq($result[0], Error::USER_NOT_AUTHORIZED);

		$ctrl->reset();
		$manage->reset();

		### use patched AuthManage ###

		### as 'john'
		self::loginOK('john', 'asdf');

		# pass authz but password doesn't check out
		$result = self::$p_manage->add($args, false, false, false);
		$eq($result[0], Error::PASSWORD_TOO_SHORT);

		# success, with correct password
		$args['addpass1'] = 'asdfgh';
		$eq(self::$p_manage->add($args, false, false, false)[0], 0);

		# name contains white space
		$args['addname'] = 'john smith';
		$eq(
			self::$p_manage->add($args, false, false, false)[0],
			Error::USERNAME_HAS_WHITESPACE);

		# name starts with plus sign
		$args['addname'] = '+jacqueline';
		$eq(
			self::$p_manage->add($args, false, false, false)[0],
			Error::USERNAME_LEADING_PLUS);

		# success, as 'john' adding 'jessica'
		$args['addname'] = 'jessica';
		$eq(
			self::$p_manage->add($args, false, false, false)[0], 0);
	}

	public function test_delete() {
		$eq = $this->eq();
		$ctrl = self::$ctrl;
		$manage = self::$manage;

		### add new
		foreach (['john', 'jocelyn', 'jonah', 'josh'] as $name) {
			$manage->add([
				'addname' => $name,
				'addpass1' => 'asdf',
				'addpass2' => 'asdf',
			], true, true);
		}

		# cannot list user when not signed in
		$args = ['uid' => '0'];
		$eq($manage->list($args)[0], Error::USER_NOT_LOGGED_IN);

		### as 'jonah'
		self::loginOK('jonah', 'asdf');

		# cannot list user when not authorized
		$args['uid'] = '0';
		$eq($manage->list($args)[0], Error::USER_NOT_AUTHORIZED);

		### sign out
		$ctrl->reset();
		$manage->reset();

		### use patched AuthManage ###

		### as 'john'
		self::loginOK('john', 'asdf');

		$sql = $ctrl::$admin::$store;

		# check number of users
		$user_count = $sql->query("
			SELECT count(uid) AS count FROM udata
		")['count'];

		# invalid page and limit on user listing will be silently
		# set to their defaults
		$args['page'] = -1e3;
		$args['limit'] = 1e3;
		$user_list = self::$p_manage->list($args)[1];
		$eq(count($user_list), $user_count);

		# authorized user can delete anyone including herself
		$jessica_uid = $sql->query("
			SELECT uid FROM udata WHERE uname=? LIMIT 1
		", ['john'])['uid'];
		$args['uid'] = $jessica_uid;
		$eq(self::$p_manage->delete($args)[0], 0);

		$ctrl->reset();
		$manage->reset();

		### use default AuthManage ###

		# as 'root'
		self::loginOK();

		# check number of users
		$user_count = $sql->query("
			SELECT count(uid) AS count FROM udata
		")['count'];

		# root user can delete anyone except herself
		$jocelyn_uid = $sql->query("
			SELECT uid FROM udata WHERE uname=? LIMIT 1
		", ['jocelyn'])['uid'];
		$args['uid'] = $jocelyn_uid;
		$eq($manage->delete($args)[0], 0);
		$eq($manage->delete(['uid' => 1])[0],
			Error::USER_NOT_AUTHORIZED);

		### logout
		$ctrl->reset();
		$manage->reset();

		### create uid arrays
		$uids = array_map(function($arr){
			return (string)$arr['uid'];
		}, $user_list);

		# no authn
		$eq($manage->delete($args)[0], Error::USER_NOT_LOGGED_IN);

		### as 'jonah'
		self::loginOK('jonah', 'asdf');

		# missing post arguments
		$eq($manage->delete([])[0], Error::DATA_INCOMPLETE);

		# with default AuthManage::authz_delete, only root can delete
		# other users
		$eq($manage->delete($args)[0], Error::USER_NOT_AUTHORIZED);

		# non-root user can self-delete
		$args['uid'] = $manage->get_user_data()['uid'];
		$eq($manage->delete($args)[0], 0);
		### logout is still allowed since it doesn't check sid
		$ctrl->logout();

		# unable to re-login because user is no longer found
		$eq($ctrl->login([
			'uname' => 'jonah',
			'upass' => 'asdfgh',
		])[0], Error::USER_NOT_FOUND);

	}

	public function test_self_register_passwordless() {
		$eq = $this->eq();
		$ctrl = self::$ctrl;
		$manage = self::$manage;

		### add new
		foreach (['john', 'jocelyn'] as $name) {
			$manage->add([
				'addname' => $name,
				'addpass1' => 'asdf',
				'addpass2' => 'asdf',
			], true, true);
		}

		$args = [];

		self::loginOK();
		$result = $manage->self_add_passwordless($args);
		$eq($result[0], Error::USER_ALREADY_LOGGED_IN);

		$ctrl->reset();
		$manage->reset();

		# empty args
		$result = $manage->self_add_passwordless($args);
		$eq($result[0], Error::DATA_INCOMPLETE);

		# no 'uservice' in args
		$args = ['uname' => '1234'];
		$result = $manage->self_add_passwordless($args);
		$eq($result[0], Error::DATA_INCOMPLETE);

		# success
		$args['uservice'] = 'github';
		$result = $manage->self_add_passwordless($args);
		$eq($result[0], 0);
		$eq($result[1]['uname'], '+1234:github');

		# use token
		$token = $result[1]['token'];
		$manage->set_token_value($token);

		# signing in success
		$result = $manage->get_safe_user_data();
		$eq($result[0], 0);
		$eq($result[1]['uname'], '+1234:github');
	}

	public function test_login_passwordless() {
		$eq = $this->eq();
		$ctrl = self::$ctrl;
		$manage = self::$manage;

		$args = [
			'uname' => '1234',
			'uservice' => 'google',
		];
		$manage->reset();
		$result = $manage->self_add_passwordless($args);
		$eq($result[0], 0);
		$eq($result[1]['uname'], '+1234:google');
		$uid = $result[1]['uid'];

		# uid shouldn't increment
		$result = $manage->self_add_passwordless($args);
		$eq($result[0], 0);
		$eq($result[1]['uname'], '+1234:google');
		$eq($result[1]['uid'], $uid);
		$token = $result[1]['token'];

		### set token
		$ctrl->set_token_value($token);
		$ctrl->get_user_data();

		# passwordless login can't change password
		$args['pass1'] = $args['pass2'] = 'blablabla';
		$eq($ctrl->change_password($args)[0], Error::USER_NOT_FOUND);

		$sql = $ctrl::$admin::$store;

		# check expiration
		$tnow = $sql->query(sprintf("
			SELECT (%s) AS now
		", $sql->stmt_fragment('datetime')))['now'];
		$dtnow = strtotime($tnow);
		$texp = $sql->query("
			SELECT expire FROM usess
			WHERE token=? ORDER BY sid DESC
			LIMIT 1
		", [$token])['expire'];
		$dtexp = strtotime($texp);

		# difference must be small, say, 2 seconds at most
		$diff = abs(
			$ctrl::$admin->get_expiration() -
			($dtexp - $dtnow)
		);
		$this->tr($diff < 1);
	}

}
