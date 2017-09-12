<?php


require_once(__DIR__ . '/AdminStoreTest.php');

use BFITech\ZapCore\Logger;
use BFITech\ZapStore as zs;


class AdminStoreMyTest extends AdminStoreTest {

	private static function prepare_config($dbconfig) {
		if (file_exists($dbconfig))
			return json_decode(
				file_get_contents($dbconfig), true);

		# default
		$params = [
			'MYSQL_HOST' => '127.0.0.1',
			'MYSQL_PORT' => '3306',
			'MYSQL_USER' => 'zapstore_test',
			'MYSQL_PASSWORD' => 'admin',
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
		$logfile = __TESTDIR__ . '/zapmin-mysql.log';
		if (file_exists($logfile))
			unlink($logfile);

		$logger = new Logger(Logger::DEBUG, $logfile);
		$dbconfig = __TESTDIR__ . '/zapmin-mysql.json';
		$dbparams = self::prepare_config($dbconfig);
		try {
			self::$sql = new zs\MySQL($dbparams, $logger);
		} catch(zs\SQLError $e) {
			printf(
				"\n" .
				"ERROR: Cannot connect to mysql test database.\n" .
				"       Please check configuration: '%s'.\n\n" .
				"CURRENT CONFIGURATION:\n\n%s\n\n",
				$dbconfig, json_encode($dbparams, JSON_PRETTY_PRINT)
			);
			exit(1);
		}
		self::$adm = (new AdminStore(self::$sql, $logger))
			->config('expiration', 600)
			->config('force_create_table', true);
	}

}
