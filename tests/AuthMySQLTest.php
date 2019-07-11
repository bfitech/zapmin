<?php

require_once(__DIR__ . '/AuthCommon.php');


use BFITech\ZapCore\Logger;
use BFITech\ZapStore\MySQL;
use BFITech\ZapStore\SQLError;


class AuthMySQLTest extends AuthCommon {

	private static function mysql_config($dbconfig) {
		if (file_exists($dbconfig))
			return json_decode(
				file_get_contents($dbconfig), true);

		# default
		$params = [
			'MYSQL_HOST' => '127.0.0.1',
			'MYSQL_PORT' => '3306',
			'MYSQL_USER' => 'root',
			'MYSQL_PASSWORD' => '',
			'MYSQL_DATABASE' => 'zapstore_test_db',
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
		$dbparams = [
			'dbhost' => $MYSQL_HOST,
			'dbport' => $MYSQL_PORT,
			'dbuser' => $MYSQL_USER,
			'dbpass' => $MYSQL_PASSWORD,
			'dbname' => $MYSQL_DATABASE,
		];

		# save config
		file_put_contents($dbconfig,
			json_encode($dbparams, JSON_PRETTY_PRINT));

		return $dbparams;
	}

	public static function setUpBeforeClass() {
		$logfile = testdir() . '/zapmin-mysql.log';
		if (file_exists($logfile))
			unlink($logfile);

		self::$logger = $logger = new Logger(Logger::DEBUG, $logfile);
		$configfile = testdir() . '/zapmin-mysql.json';
		$params = self::mysql_config($configfile);
		try {
			self::$sql = new MySQL($params, $logger);
		} catch(SQLError $e) {
			printf(
				"\n" .
				"ERROR: Cannot connect to mysql test database.\n" .
				"       Please check configuration: '%s'.\n\n" .
				"CURRENT CONFIGURATION:\n\n%s\n\n",
				$configfile, json_encode($params, JSON_PRETTY_PRINT)
			);
			exit(1);
		}
		try {
			# mysql doesn't cascade on views propperly
			self::$sql->query_raw("DROP VIEW v_usess");
			foreach (['meta', 'usess', 'udata'] as $table)
				self::$sql->query_raw("DROP TABLE $table CASCADE");
		} catch (SQLError $e) {
		}

		$configfile = testdir() . '/zapmin-redis.json';
		self::redis_open($configfile, $logger);
	}

}