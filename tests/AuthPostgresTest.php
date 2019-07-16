<?php

require_once(__DIR__ . '/AuthCommon.php');


class AuthPostgresTest extends AuthCommon {

	public static function setUpBeforeClass() {
		self::open_connections('mysql');
	}

}
