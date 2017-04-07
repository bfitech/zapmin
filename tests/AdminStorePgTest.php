<?php


require(__DIR__ . '/AdminStoreTest.php');

use BFITech\ZapCore as zc;
use BFITech\ZapStore as zs;
use BFITech\ZapAdmin as za;


class AdminStorePgTest extends AdminStoreTest {

	public static function setUpBeforeClass() {
		$logfile = HTDOCS . '/zapmin-test-pgsql.log';
		if (file_exists($logfile))
			unlink($logfile);

		$dbconfig = HTDOCS . '/zapmin-test-pgsql.json';
		if (!file_exists($dbconfig)) {
			$dbargs = [
				'dbhost' => 'localhost',
				'dbname' => 'zapstore_test_db',
				'dbuser' => 'postgres',
				'dbpass' => '',
			];
			file_put_contents($dbconfig, json_encode($dbargs, JSON_PRETTY_PRINT));
		} else {
			$dbargs = json_decode(file_get_contents($dbconfig), true);
		}

		$logger = new zc\Logger(zc\Logger::DEBUG, $logfile);
		try {
			self::$sql = new zs\PgSQL($dbargs, $logger);
		} catch(\Exception $e) {
			printf(
				"\n" .
				"ERROR: Cannot connect to pgsql test database.\n" .
				"       Please check configuration: '%s'.\n\n" .
				"CURRENT CONFIGURATION:\n\n%s\n\n",
				$dbconfig,
				json_encode($dbargs, JSON_PRETTY_PRINT));
			exit(1);
		}
		self::$store = new za\AdminStore(
			self::$sql, 600, true, $logger);
	}

}

