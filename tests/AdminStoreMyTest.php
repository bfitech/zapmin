<?php


require_once(__DIR__ . '/AdminStoreTest.php');

use BFITech\ZapCore\Logger;
use BFITech\ZapStore as zs;


class AdminStoreMyTest extends AdminStoreTest {

	public static function setUpBeforeClass() {
		$logfile = HTDOCS . '/zapmin-test-mysql.log';
		if (file_exists($logfile))
			unlink($logfile);

		$dbconfig = HTDOCS . '/zapmin-test-mysql.json';
		if (!file_exists($dbconfig)) {
			$dbargs = [
				'dbhost' => '127.0.0.1',
				'dbname' => 'zapstore_test_db',
				'dbuser' => 'root',
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
			self::$sql = new zs\MySQL($dbargs, $logger);
		} catch(zs\SQLError $e) {
			printf(
				"\n" .
				"ERROR: Cannot connect to mysql test database.\n" .
				"       Please check configuration: '%s'.\n\n" .
				"CURRENT CONFIGURATION:\n\n%s\n\n",
				$dbconfig,
				json_encode($dbargs, JSON_PRETTY_PRINT));
			exit(1);
		}
		self::$adm = new AdminStore(self::$sql, 600, true, $logger);
	}
}

