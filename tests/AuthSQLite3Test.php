<?php

require_once __DIR__ . '/AuthCommon.php';


use BFITech\ZapCore\Logger;
use BFITech\ZapStore\SQLite3;
use BFITech\ZapAdmin\Admin;
use BFITech\ZapAdmin\Tables;


class AuthSQLite3Test extends AuthCommon {

	public static function setUpBeforeClass() {
		self::open_connections('sqlite3');
	}

	public function test_upgrade_0_0() {
		$eq = self::eq();

		$logfile = self::tdir(__FILE__) . '/zapmin-table-update.log';
		if (file_exists($logfile))
			unlink($logfile);

		$log = new Logger(Logger::DEBUG, $logfile);
		$sql = new SQLite3(['dbname' => ':memory:'], $log);
		$admin = (new Admin($sql, $log))
			->config('expire', 3600)
			->config('token_name', 'bar')
			->config('check_tables', true)
			->init();

		### dummy drop, sqlite3 does not support DROP * CASCADE
		foreach (['meta', 'udata', 'usess'] as $table)
			$sql->query_raw("DROP TABLE $table");
		$sql->query_raw("DROP VIEW v_usess");
		new Tables($admin);
		# tables are recreated
		$eq($sql->query("SELECT version FROM meta LIMIT 1")['version'],
			Tables::TABLE_VERSION);

		### table upgrading from no version
		$sql->query_raw("DROP TABLE IF EXISTS meta");
		new Tables($admin);
		# table 'meta' is recreated
		$eq($sql->query("SELECT version FROM meta LIMIT 1")['version'],
			Tables::TABLE_VERSION);

		### table upgrading from 0.1
		new Tables($admin);
		# no table updates
		$this->assertNotFalse(strpos(
			file_get_contents($logfile), "Tables are up-to-date."));
	}

}
