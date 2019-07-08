<?php

require_once __DIR__ . '/Common.php';


use PHPUnit\Framework\TestCase;

use BFITech\ZapCore\Logger;
use BFITech\ZapStore\Redis;
use BFITech\ZapStore\RedisError;
use BFITech\ZapStore\SQLite3;
use BFITech\ZapStore\SQLError;

use BFITech\ZapAdmin\Admin;
use BFITech\ZapAdmin\AuthCtrl;
use BFITech\ZapAdmin\AuthManage;
use BFITech\ZapAdmin\Error;


class Ctrl extends AuthCtrl {
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
abstract class AdminTest extends TestCase {

	protected static $sql;
	protected static $redis;
	protected static $logger;

	protected static $ctrl;
	protected static $manage;

	protected static $p_ctrl;
	protected static $p_manage;

	public static function redis_open($configfile, $logger) {
		$params = self::redis_config($configfile);
		try {
			$redis = new Redis($params, $logger);
		} catch(RedisError $e) {
			printf(
				"\n" .
				"ERROR: Cannot connect to redis server.\n" .
				"       Please check configuration: '%s'.\n\n" .
				"CURRENT CONFIGURATION:\n\n%s\n\n",
				$redisconfig,
				json_encode($redisparams, JSON_PRETTY_PRINT)
			);
			exit(1);
		}
		self::$redis = $redis;
	}

	public static function redis_config($configfile) {
		if (file_exists($configfile))
			return json_decode(
				file_get_contents($configfile), true);

		# default
		$params = [
			'REDISHOST' => '127.0.0.1',
			'REDISPORT' => 16379,
			'REDISDATABASE' => 10,
			'REDISPASSWORD' => 'xoxo',
		];

		# parse from environment
		foreach (array_keys($params) as $key) {
			$val = getenv($key);
			if (!$val)
				continue;
			$params[$key] = $val;
		}
		extract($params);

		# set to standard zapstore params
		$redisparams = [
			'redishost' => $REDISHOST,
			'redisport' => $REDISPORT,
			'redisdatabase' => $REDISDATABASE,
			'redispassword' => $REDISPASSWORD,
		];

		# save config
		file_put_contents($configfile,
			json_encode($redisparams, JSON_PRETTY_PRINT));

		return $redisparams;
	}

	public function setUp() {
		$logger = self::$logger;

		$admin = new Admin(self::$sql, self::$logger, self::$redis);
		$admin
			->config('expire', 3600)
			->config('check_tables', true);

		self::$ctrl = new AuthCtrl($admin, $logger);
		self::$manage = new AuthManage($admin, $logger);

		self::$p_ctrl = new Ctrl($admin, $logger);
		self::$p_manage = new Manage($admin, $logger);
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
		$result = self::$ctrl->login(['post' => [
			'uname' => $uname,
			'upass' => $upass,
		]]);
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
	}

	public function test_admin() {
		# only run this on one database backend
		if (get_class($this) != 'AdminSQLiteTest') {
			$this->assertTrue(true);
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
		$this->assertTrue($expire_invalid);

		$token_name_not_set = false;
		try {
			$admin->config('token_name', '')->init();
		} catch(Error $e) {
			$token_name_not_set = true;
		}
		$this->assertTrue($token_name_not_set);

		$admin = (new Admin($sql, $logger))->init();
		$admin->config('token_name', 'quux')->init();
		$this->assertEquals($admin->get_token_name(), 'zapmin');
	}

	public function test_login() {
		$ctrl = self::$ctrl;

		# invalid post data
		$args = ['post' => ['uname' => 'admin']];
		$this->assertEquals(
			$ctrl->login($args)[0], Error::DATA_INCOMPLETE);

		# incomplete post data
		$this->assertEquals(
			$ctrl->login($args)[0], Error::DATA_INCOMPLETE);

		# invalid user
		$args['post']['upass'] = '1243';
		$this->assertEquals(
			$ctrl->login($args)[0], Error::USER_NOT_FOUND);

		# invalid password
		$args['post']['uname'] = 'root';
		$this->assertEquals(
			$ctrl->login($args)[0], Error::WRONG_PASSWORD);

		# missing token
		$this->assertEquals(
			$ctrl->set_token_value(false), null);

		# success
		$args['post']['upass'] = 'admin';
		$login_data = $ctrl->login($args);
		$this->assertEquals($login_data[0], 0);
		$token = $login_data[1]['token'];
		# unlike passwordless login, this has no sid
		$this->assertEquals(isset($login_data['sid']), false);

		# simulating next load
		$ctrl->set_token_value($token);
		$ctrl->get_user_data();
		# re-login will fail
		$this->assertEquals(
			$ctrl->login($args)[0], Error::USER_ALREADY_LOGGED_IN);
	}

	private function _expiration_sequence(
		$callback=null
	) {
		$ctrl = self::$ctrl;
		$ctrl->reset();

		$login_data = $ctrl->login([
			'post' => [
				'uname' => 'root',
				'upass' => 'admin',
			],
		]);
		$this->assertEquals($login_data[0], 0);
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
		$ctrl = self::$ctrl;

		# normal login
		$this->_expiration_sequence();
		$this->assertEquals(
			$ctrl->get_safe_user_data()[0], 0);
		$this->assertEquals($ctrl->logout()[0], 0);

		$ctrl->reset();
		$this->_expiration_sequence();
		$this->assertEquals(
			$ctrl->get_safe_user_data()[0], 0);
		$this->assertEquals($ctrl->logout()[0], 0);

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
		$this->assertEquals(
			$ctrl->get_safe_user_data()[0],
			Error::USER_NOT_LOGGED_IN);
		$this->assertEquals($ctrl->logout()[0],
			Error::USER_NOT_LOGGED_IN);

		# normal login again
		$this->_expiration_sequence();
		$this->assertEquals(
			$ctrl->get_safe_user_data()[0], 0);
		$this->assertEquals($ctrl->logout()[0], 0);
	}

	public function test_logout() {
		$ctrl = self::$ctrl;
		$this->assertEquals($ctrl->logout()[0],
			Error::USER_NOT_LOGGED_IN);
		self::loginOK();
		$this->assertEquals($ctrl->logout()[0], 0);
	}


	public function test_change_password() {
		$ctrl = self::$ctrl;

		# not logged in
		$args = ['post' => ['pass1' => '123']];
		$this->assertEquals($ctrl->change_password($args)[0],
			Error::USER_NOT_LOGGED_IN);

		self::loginOK();

		# invalid data
		$this->assertEquals(
			$ctrl->change_password($args)[0], Error::DATA_INCOMPLETE);

		# incomplete data
		$args['post']['pass2'] = '1234';
		$result = $ctrl->change_password($args, true);
		$this->assertEquals($result[0], Error::DATA_INCOMPLETE);

		# wrong old password
		$args['post']['pass0'] = '1234';
		$result = $ctrl->change_password($args, true);
		$this->assertEquals($result[0], Error::OLD_PASSWORD_INVALID);

		# new passwords don't verify
		$args['post']['pass0'] = 'admin';
		$result = $ctrl->change_password($args, true);
		$this->assertEquals($result[0], Error::PASSWORD_INVALID);
		// $this->assertEquals($result[0], Error::PASSWORD_NOT_SAME);

		# new password too short
		$args['post']['pass2'] = '123';
		$result = $ctrl->change_password($args, true);
		$this->assertEquals($result[0], Error::PASSWORD_INVALID);
		$this->assertEquals($result[1], Error::PASSWORD_TOO_SHORT);

		# success
		$args['post']['pass1'] = '1234';
		$args['post']['pass2'] = '1234';
		$this->assertEquals($ctrl->change_password($args, true)[0], 0);

		# logout
		$this->assertEquals($ctrl->logout()[0], 0);

		# relogin with old password will fail
		try {
			self::loginOK();
		} catch (Exception $e) {
			$this->assertEquals($ctrl->get_user_data(), null);
		}

		# login with new password
		self::loginOK('root', '1234');

		# change password back without old password requirement
		$args['post']['pass1'] = $args['post']['pass2'] = 'admin';
		$this->assertEquals($ctrl->change_password($args)[0], 0);
	}

	public function test_change_bio() {
		$ctrl = self::$ctrl;

		# not logged in
		$result = $ctrl->change_bio([]);
		$this->assertEquals($result[0], Error::USER_NOT_LOGGED_IN);

		# begin process

		self::loginOK();

		$safe_data = $ctrl->get_safe_user_data()[1];
		$this->assertEquals($safe_data['fname'], '');

		# no change
		$result = $ctrl->change_bio(['post' => []]);
		$this->assertEquals($result[0], 0);

		# fname empty value
		$result = $ctrl->change_bio([
			'post' => ['fname' => '']]);

		$safe_data = $ctrl->get_safe_user_data()[1];
		$this->assertEquals($safe_data['fname'], '');

		# change fname
		$result = $ctrl->change_bio([
			'post' => ['fname' => 'The Administrator']]);

		$safe_data = $ctrl->get_safe_user_data()[1];
		$this->assertEquals($safe_data['site'], '');
		$this->assertEquals($safe_data['fname'], 'The Administrator');

		# site too long
		$test_site = 'http://' . str_repeat('jonathan', 12) . '.co';
		$result = $ctrl->change_bio([
			'post' => ['site' => $test_site]]);
		$this->assertEquals($result[0], Error::SITEURL_INVALID);

		# change site url
		$result = $ctrl->change_bio([
			'post' => ['site' => 'http://www.bfinews.com']]);

		$safe_data = $ctrl->get_safe_user_data()[1];
		$this->assertEquals($safe_data['site'],
			'http://www.bfinews.com');
		$this->assertEquals($safe_data['fname'], 'The Administrator');
	}

	public function test_self_register() {
		$manage = self::$manage;

		# missing post arguments
		$this->assertEquals(
			$manage->add(['post' => []], true, true)[0],
			Error::DATA_INCOMPLETE);

		$args = ['post' => [
			'addname' => 'root',
			'addpass1' => 'asdf',
			'addpass2' => 'asdf']];

		# user exists
		$this->assertEquals(
			$manage->add($args, true, true)[0],
			Error::USERNAME_EXISTS);

		# success
		$args['post']['addname'] = 'john';
		$this->assertEquals(
			$manage->add($args, true, true)[0], 0);
		# autologin, this should happen immediately prior to
		# sending anything to client
		self::loginOK('john', 'asdf');
		$udata = $manage->get_user_data();
		$this->assertEquals($udata['uname'], 'john');

		$result = $manage->self_add($args, true, true);
		$this->assertEquals($result[0], Error::USER_ALREADY_LOGGED_IN);

		$manage->reset();

		# using shorthand, with email required
		$args['post']['addname'] = 'jack';
		$args['post']['addpass1'] = 'qwer';
		# not typing password twice and no email
		unset($args['post']['addpass2']);
		$result = $manage->self_add($args, true, true);
		$this->assertEquals($result[0], Error::DATA_INCOMPLETE);

		# invalid email
		$args['post']['addpass2'] = 'qwer';
		$args['post']['email'] = '#qwer';
		$result = $manage->self_add($args, true, true);
		$this->assertEquals($result[0], Error::EMAIL_INVALID);

		# success
		$args['post']['email'] = 'test+bed@example.org';
		$this->assertEquals($manage->self_add($args, true, true)[0], 0);

		# email exists
		$args['post']['addname'] = 'jonathan';
		$result = $manage->self_add($args, true, true);
		$this->assertEquals($result[0], Error::EMAIL_EXISTS);

		# uname too long
		$args['post']['addname'] = str_repeat('jonathan', 24);
		$this->assertEquals(
			$manage->self_add($args, true, true)[0],
			Error::USERNAME_TOO_LONG);

		# email too long
		$args['post']['addname'] = 'jonathan';
		$args['post']['email'] = str_repeat('jonathan', 12) . '@l.co';
		$result = $manage->self_add($args, true, true);
		$this->assertEquals($result[0], Error::EMAIL_INVALID);
	}

	public function test_register() {
		$ctrl = self::$ctrl;
		$manage = self::$manage;

		# add new
		$args = ['post' => [
			'addname' => 'john',
			'addpass1' => 'asdf',
			'addpass2' => 'asdf']];
		$this->assertEquals($manage->add($args, true, true)[0], 0);

		$args = ['post' => [
			'addname' => 'john',
			'addpass1' => 'asdf']];

		# no authn, self-registration disabled
		$this->assertEquals(
			$manage->add($args, false, false)[0],
			Error::SELF_REGISTER_NOT_ALLOWED);

		# as 'john'
		self::loginOK('john', 'asdf');

		# no authz
		$result = $manage->add($args);
		$this->assertEquals($result[0], Error::USER_NOT_AUTHORIZED);

		$ctrl->reset();
		$manage->reset();

		# as root, with unavailable name
		self::loginOK();
		# user exists
		$this->assertEquals($manage->add($args)[0],
			Error::USERNAME_EXISTS);

		# as root, with available name
		$args['post']['addname'] = 'jocelyn';
		# success, no autologin
		$this->assertEquals($manage->add($args)[0], 0);

		$ctrl->reset();
		$manage->reset();

		# try to add 'jonah'
		$args['post']['addname'] = 'jonah';
		$args['post']['addpass1'] = '123';

		# as 'jocelyn'
		self::loginOK('jocelyn', 'asdf');

		# no authz
		$result = $manage->add(
			$args, false, false, false);
		$this->assertEquals($result[0], Error::USER_NOT_AUTHORIZED);

		$ctrl->reset();
		$manage->reset();

		# use patched Manage

		# as 'john'
		self::loginOK('john', 'asdf');

		# pass authz but password doesn't check out
		$result = self::$p_manage->add($args, false, false, false);
		$this->assertEquals($result[0], Error::PASSWORD_TOO_SHORT);

		# as 'john'
		$args['post']['addpass1'] = 'asdfgh';
		# success
		$this->assertEquals(
			self::$p_manage->add($args, false, false, false)[0], 0);
		# name contains white space
		$args['post']['addname'] = 'john smith';
		$this->assertEquals(
			self::$p_manage->add($args, false, false, false)[0],
				Error::USERNAME_HAS_WHITESPACE);
		# name starts with plus sign
		$args['post']['addname'] = '+jacqueline';
		$this->assertEquals(
			self::$p_manage->add($args, false, false, false)[0],
				Error::USERNAME_LEADING_PLUS);
		# add 'jessica'
		$args['post']['addname'] = 'jessica';
		self::$p_manage->add($args, false, false, false);

	}

	public function test_delete() {
		$ctrl = self::$ctrl;
		$manage = self::$manage;

		# add new
		foreach (['john', 'jocelyn', 'jonah', 'josh'] as $name) {
			$args = ['post' => [
				'addname' => $name,
				'addpass1' => 'asdf',
				'addpass2' => 'asdf']];
			$manage->add($args, true, true);
		}

		$args = ['post' => ['uid' => '0']];
		# cannot list user when not signed in
		$this->assertEquals(
			$manage->list($args)[0], Error::USER_NOT_LOGGED_IN);

		self::loginOK('jonah', 'asdf');

		$args['post']['uid'] = '0';
		# cannot list user when not authorized
		$this->assertEquals(
			$manage->list($args)[0], Error::USER_NOT_AUTHORIZED);

		# sign out
		$ctrl->reset();
		$manage->reset();

		# use patched Manage

		# as 'john'
		self::loginOK('john', 'asdf');

		$sql = $ctrl::$admin::$store;

		# check number of users
		$user_count = $sql->query(
			"SELECT count(uid) AS count FROM udata"
			)['count'];
		# invalid page and limit on user listing will be silently
		# set to their defaults
		$args['post']['page'] = -1e3;
		$args['post']['limit'] = 1e3;
		$user_list = self::$p_manage->list($args)[1];
		$this->assertEquals(count($user_list), $user_count);

		# authorized user can delete anyone including herself
		$jessica_uid = $sql->query(
			"SELECT uid FROM udata WHERE uname=? LIMIT 1",
			['john'])['uid'];
		$args['post']['uid'] = $jessica_uid;
		$this->assertEquals(self::$p_manage->delete($args)[0], 0);

		$ctrl->reset();
		$manage->reset();

		# as 'root' with default AuthManage
		self::loginOK();

		# check number of users
		$user_count = $sql->query(
			"SELECT count(uid) AS count FROM udata"
			)['count'];
		# invalid page and limit on user listing will be silently
		# set to their defaults
		$args['post']['page'] = -1e3;
		$args['post']['limit'] = 1e3;
		$user_list = $manage->list($args)[1];
		$this->assertEquals(count($user_list), $user_count);

		# root user can delete anyone except herself
		$jocelyn_uid = $sql->query(
			"SELECT uid FROM udata WHERE uname=? LIMIT 1",
			['jocelyn'])['uid'];
		$args['post']['uid'] = $jocelyn_uid;
		$this->assertEquals($manage->delete($args)[0], 0);

		# logout
		$ctrl->reset();
		$manage->reset();

		# create uid arrays
		$uids = array_map(function($_arr){
			return (string)$_arr['uid'];
		}, $user_list);

		# no authn
		$this->assertEquals(
			$manage->delete($args)[0], Error::USER_NOT_LOGGED_IN);

		# as 'jonah'
		self::loginOK('jonah', 'asdf');

		# missing post arguments
		$this->assertEquals(
			$manage->delete(['post' => []])[0], Error::DATA_INCOMPLETE);
		# with default authz, any user cannot delete another user
		# except root
		$this->assertEquals(
			$manage->delete($args)[0], Error::USER_NOT_AUTHORIZED);
		# but s/he can self-delete
		$args['post']['uid'] = $manage->get_user_data()['uid'];
		$this->assertEquals($manage->delete($args)[0], 0);
		# logout is still allowed since it doesn't check sid
		$ctrl->logout();
		# unable to re-login because user is no longer found
		$this->assertEquals($ctrl->login(['post' => [
			'uname' => 'jonah',
			'upass' => 'asdfgh',
		]])[0], Error::USER_NOT_FOUND);

		# use patched AdminStore
		$pm = self::$p_manage;

		return;

		# as josh
		self::loginOK('josh', 'asdf');
		# deleting jonah's ID, user no longer exists
		#$this->assertEquals(
		#	$pm->delete($args)[0], Error::USER_NOT_FOUND);
		# cannot delete 'root'
		$args['post']['uid'] = '1';
		$this->assertEquals(
			$pm->delete($args)[0], Error::USER_NOT_AUTHORIZED);
		# success, self-deleting 'josh', uid=5
		$args['post']['uid'] = '5';
		$this->assertEquals($pm->delete($args)[0], 0);

		$ctrl->reset();

		# sign in as 'josh' fails
		try {
			self::loginOK('jocelyn', '1234');
		} catch (Exception $e) {
			$this->assertNull($ctrl->get_user_data());
		}

	}

	public function test_self_register_passwordless() {
		$ctrl = self::$ctrl;
		$manage = self::$manage;

		# add new
		foreach (['john', 'jocelyn'] as $name) {
			$args = ['post' => [
				'addname' => $name,
				'addpass1' => 'asdf',
				'addpass2' => 'asdf']];
			$manage->add($args, true, true);
		}

		$args = ['post' => []];

		self::loginOK();
		$result = $manage->self_add_passwordless($args);
		$this->assertEquals($result[0], Error::USER_ALREADY_LOGGED_IN);

		$ctrl->reset();
		$manage->reset();

		# no 'service' in args
		$result = $manage->self_add_passwordless($args);
		$this->assertEquals($result[0], Error::DATA_INCOMPLETE);

		# not enough args
		$args['service'] = ['uname' => '1234'];
		$result = $manage->self_add_passwordless($args);
		$this->assertEquals($result[0], Error::DATA_INCOMPLETE);

		# success
		$args['service']['uservice'] = 'github';
		$result = $manage->self_add_passwordless($args);
		$this->assertEquals($result[0], 0);
		$this->assertEquals($result[1]['uname'], '+1234:github');

		# use token
		$token = $result[1]['token'];
		$manage->set_token_value($token);

		# signing in success
		$result = $manage->get_safe_user_data();
		$this->assertEquals($result[0], 0);
		$this->assertEquals($result[1]['uname'], '+1234:github');

	}

	public function test_login_passwordless() {
		$ctrl = self::$ctrl;
		$manage = self::$manage;

		$args = [
			'service' => [
				'uname' => '1234',
				'uservice' => 'google',
			]
		];
		$manage->reset();
		$result = $manage->self_add_passwordless($args);
		$this->assertEquals($result[0], 0);
		$this->assertEquals($result[1]['uname'], '+1234:google');
		$uid = $result[1]['uid'];

		# uid shouldn't increment
		$result = $manage->self_add_passwordless($args);
		$this->assertEquals($result[0], 0);
		$this->assertEquals($result[1]['uname'], '+1234:google');
		$this->assertEquals($result[1]['uid'], $uid);
		$token = $result[1]['token'];

		# set token
		$ctrl->set_token_value($token);
		$ctrl->get_user_data();

		# passwordless login can't change password
		$args['post']['pass1'] = $args['post']['pass2'] = 'blablabla';
		$this->assertEquals(
			$ctrl->change_password($args)[0], Error::USER_NOT_FOUND);

		$sql = $ctrl::$admin::$store;

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
		$diff = abs(
			$ctrl::$admin->get_expiration() -
			($dtexp - $dtnow)
		);
		$this->assertTrue($diff < 1);
	}

}
