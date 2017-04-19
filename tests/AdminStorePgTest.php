<?php


require_once(__DIR__ . '/AdminStoreTest.php');

use BFITech\ZapCore\Logger;
use BFITech\ZapStore as zs;


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
			file_put_contents($dbconfig,
				json_encode($dbargs, JSON_PRETTY_PRINT));
		} else {
			$dbargs = json_decode(
				file_get_contents($dbconfig), true);
		}

		$logger = new Logger(Logger::DEBUG, $logfile);
		try {
			self::$sql = new zs\PgSQL($dbargs, $logger);
		} catch(zs\SQLError $e) {
			printf(
				"\n" .
				"ERROR: Cannot connect to pgsql test database.\n" .
				"       Please check configuration: '%s'.\n\n" .
				"CURRENT CONFIGURATION:\n\n%s\n\n",
				$dbconfig,
				json_encode($dbargs, JSON_PRETTY_PRINT));
			exit(1);
		}
		self::$adm = new AdminStore(self::$sql, 600, true, $logger);
	}

}

