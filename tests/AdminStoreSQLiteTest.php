<?php

require_once(__DIR__ . '/AdminStoreWrapper.php');

use BFITech\ZapCore\Logger;
use BFITech\ZapStore\SQLite3;
use BFITech\ZapStore\Redis;
use BFITech\ZapAdmin as za;
use BFITech\ZapAdmin\AdminStoreError as Err;


class AdminStoreSQLiteTest extends AdminStoreWrapper {

	public static function setUpBeforeClass() {
		$logfile = testdir() . '/zapmin-sqlite3.log';
		if (file_exists($logfile))
			unlink($logfile);
		$logger = new Logger(Logger::DEBUG, $logfile);
		self::$sql = new SQLite3([
			'dbname' => ':memory:'
		], $logger);
		self::$adm = (new AdminStore(self::$sql))
			->config('expiration', 600);

		# redis-specific
		$redisconf = testdir() . '/zapmin-redis.json';
		$redisparams = self::prepare_redis_config($redisconf);
		try {
			$redis = new Redis($redisparams, $logger);
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

	private static function prepare_redis_config($configfile) {
		if (file_exists($configfile))
			return json_decode(
				file_get_contents($configfile), true);

		# default
		$params = [
			'REDISHOST' => '127.0.0.1',
			'REDISPORT' => 6379,
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

	public function test_constructor() {
		$logfile = testdir() . '/zapmin-constructor.log';
		if (file_exists($logfile))
			unlink($logfile);
		$logger = new Logger(Logger::ERROR, $logfile);
		$dbfile = testdir() . '/zapmin-constructor.sq3';
		$sql = new SQLite3([
			'dbname' => $dbfile,
		], $logger);

		# default expiration with forced table overwrite
		$adm = (new AdminStore($sql, $logger))
			->config('expiration', null)
			->config('force_create_table', true);
		$this->assertEquals($adm->adm_get_expiration(), 3600 * 2);

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
		} catch(za\AdminStoreError $e) {
		}

		unlink($dbfile);
	}

	public function test_redis_cache() {
		$logfile = testdir() . '/zapmin-redis.log';
		$dbfile = testdir() . '/zapmit-redis.sq3';
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

	public function test_upgrade_tables() {
		$logfile = testdir() . '/zapmin-table-update.log';
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
		$logfile = testdir() . '/zapmin-change-bio.log';
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

}