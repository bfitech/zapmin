<?php

require_once __DIR__ . '/AuthCommon.php';


use BFITech\ZapCore\Logger;
use BFITech\ZapStore\SQLite3;


class AuthSQLite3Test extends AuthCommon {

	public static function setUpBeforeClass() {
		$logfile = testdir() . '/zapmin-sqlite3.log';
		if (file_exists($logfile))
			unlink($logfile);
		self::$logger = $logger = new Logger(Logger::DEBUG, $logfile);
		self::$sql = new SQLite3([
			'dbname' => ':memory:'
		], $logger);

		$configfile = testdir() . '/zapmin-redis.json';
		self::redis_open($configfile, $logger);
	}

	public function test_upgrade_tables() {
		$this->markTestIncomplete('Reworking ...');

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
		$this->ae($adm->get_table_version(),
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

}
