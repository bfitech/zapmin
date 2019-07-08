<?php


require_once(__DIR__ . '/AdminTest.php');


use BFITech\ZapCore\Logger;
use BFITech\ZapStore\PgSQL;
use BFITech\ZapStore\SQLError;


class AdminPgTest extends AdminTest {

	private static function postgres_config($dbconfig) {
		if (file_exists($dbconfig))
			return json_decode(
				file_get_contents($dbconfig), true);

		# default
		$params = [
			'POSTGRES_HOST' => 'localhost',
			'POSTGRES_PORT' => '5432',
			'POSTGRES_USER' => 'postgres',
			'POSTGRES_PASSWORD' => '',
			'POSTGRES_DB' => 'zapstore_test_db',
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
			'dbhost' => $POSTGRES_HOST,
			'dbport' => $POSTGRES_PORT,
			'dbuser' => $POSTGRES_USER,
			'dbpass' => $POSTGRES_PASSWORD,
			'dbname' => $POSTGRES_DB,
		];

		# save config
		file_put_contents($dbconfig,
			json_encode($dbparams, JSON_PRETTY_PRINT));

		return $dbparams;
	}

	public static function setUpBeforeClass() {
		$logfile = testdir() . '/zapmin-pgsql.log';
		if (file_exists($logfile))
			unlink($logfile);

		self::$logger = $logger = new Logger(Logger::DEBUG, $logfile);
		$dbconfig = testdir() . '/zapmin-pgsql.json';
		$dbparams = self::postgres_config($dbconfig);
		try {
			self::$sql = new PgSQL($dbparams, $logger);
		} catch(SQLError $e) {
			printf(
				"\n" .
				"ERROR: Cannot connect to pgsql test database.\n" .
				"       Please check configuration: '%s'.\n\n" .
				"CURRENT CONFIGURATION:\n\n%s\n\n",
				$dbconfig, json_encode($dbparams, JSON_PRETTY_PRINT)
			);
			exit(1);
		}
		try {
			foreach (['meta', 'usess', 'udata'] as $table)
				self::$sql->query_raw("DROP TABLE $table CASCADE");
		} catch (SQLError $e) {
		}

		$configfile = testdir() . '/zapmin-redis.json';
		self::redis_open($configfile, $logger);
	}

}
