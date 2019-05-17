<?php


namespace BFITech\ZapAdmin;


use BFITech\ZapStore\SQLError;


/**
 * AdminStoreTables class.
 *
 * This class manages table installation and upgrades.
 */
class AdminStoreTables {

	/** Current table version. */
	const TABLE_VERSION = '1.0';

	private static $admin_store;
	private static $sql;
	private static $logger;

	/**
	 * Initialize object.
	 *
	 * @param AdminStore $admin_store An instance of AdminStore.
	 */
	public static function init(AdminStore $admin_store) {
		self::$admin_store = $admin_store;
		self::$sql = $admin_store->store;
		self::$logger = $admin_store->logger;
	}

	/**
	 * Deinitialize object.
	 */
	public static function deinit() {
		self::$sql = self::$logger = null;
	}

	/**
	 * Drop existing tables.
	 */
	public static function drop() {
		foreach([
			"DROP VIEW IF EXISTS v_usess",
			"DROP TABLE IF EXISTS usess",
			"DROP TABLE IF EXISTS udata",
			"DROP TABLE IF EXISTS meta",
		] as $drop) {
			// @codeCoverageIgnoreStart
			try {
				self::$sql->query_raw($drop);
			} catch(SQLError $e) {
				$msg = "Cannot drop data:" . $e->getMessage();
				self::$logger->error("Zapmin: sql error: $msg");
				throw new AdminStoreError($msg);
			}
			// @codeCoverageIgnoreEnd
		}
	}

	/**
	 * Check if tables exist.
	 *
	 * @param bool $force_create_table Recreate tables if true.
	 * @return bool True if tables exist.
	 */
	public static function exists($force_create_table=null) {
		$sql = self::$sql;
		try {
			$sql->query("SELECT 1 FROM udata LIMIT 1");
			if ($force_create_table) {
				self::drop();
				self::$logger->info("Zapmin: Recreating tables.");
				return false;
			}
			return true;
		} catch (SQLError $e) {
		}
		return false;
	}

	/**
	 * SQL statement fragments.
	 *
	 * @param int $expiration Regular session expiration duration,
	 *     in second.
	 */
	public static function fragments($expiration=7200) {
		$sql = self::$sql;
		$args = [];
		$args['index'] = $sql->stmt_fragment('index');
		$args['engine'] = $sql->stmt_fragment('engine');
		$args['dtnow'] = $sql->stmt_fragment('datetime');
		$args['expire'] = $sql->stmt_fragment(
				'datetime', ['delta' => $expiration]);
		if ($sql->get_connection_params()['dbtype'] == 'mysql')
			$args['dtnow'] = $args['expire'] = 'CURRENT_TIMESTAMP';
		return $args;
	}

	/**
	 * Install tables.
	 *
	 * @param int $expiration Regular session expiration duration,
	 *     in second.
	 * @note In case of email addresses:
	 *   - Unique and null in one column is not portable. Must check
	 *     email uniqueness manually.
	 *   - Email verification must be held separately. Table only
	 *     reserves a column for it.
	 */
	public static function install($expiration=7200) {

		$dtnow = $expire = null;
		extract(self::fragments($expiration));
		$sql = self::$sql;

		# user table

		$user_table = ("
			CREATE TABLE udata (
				uid %s,
				uname VARCHAR(64) UNIQUE,
				upass VARCHAR(64),
				usalt VARCHAR(16),
				since TIMESTAMP NOT NULL DEFAULT %s,
				email VARCHAR(64),
				email_verified INT NOT NULL DEFAULT 0,
				fname VARCHAR(128),
				site VARCHAR(128)
			) %s;
		");
		$user_table = sprintf($user_table, $index, $dtnow, $engine);
		$sql->query_raw($user_table);

		# default user

		$root_salt = self::$admin_store->generate_secret(
			'root', null, 16);
		$root_pass = self::$admin_store->hash_password(
			'root', 'admin', $root_salt);
		$sql->insert('udata', [
			'uid' => 1,
			'uname' => 'root',
			'upass' => $root_pass,
			'usalt' => $root_salt,
		]);

		# session table

		$session_table = ("
			CREATE TABLE usess (
				sid %s,
				uid INTEGER REFERENCES udata(uid) ON DELETE CASCADE,
				token VARCHAR(64),
				expire TIMESTAMP NOT NULL DEFAULT %s
			) %s;
		");
		$session_table = sprintf(
			$session_table, $index, $expire, $engine);
		$sql->query_raw($session_table);

		# session view

		$user_session_view = ("
			CREATE VIEW v_usess AS
				SELECT
					udata.*,
					usess.sid,
					usess.token,
					usess.expire
				FROM udata, usess
				WHERE
					udata.uid=usess.uid;
		");
		$sql->query_raw($user_session_view);

		# metadata

		$sql->query_raw(sprintf("
			CREATE TABLE meta (
				version VARCHAR(24) NOT NULL DEFAULT '0.0'
			);
		", $engine));
		$sql->insert('meta', [
			'version' => self::TABLE_VERSION,
		]);
	}

	/**
	 * Check if tables need upgrade.
	 */
	public static function upgrade() {
		$sql = self::$sql;

		try {
			$version = $sql->query(
				"SELECT version FROM meta LIMIT 1")['version'];
		} catch(SQLError $e) {
			return self::upgrade_tables();
		}

		if (0 <= version_compare($version, self::TABLE_VERSION))
			return self::$logger->debug(
				"Zapmin: Tables are up-to-date.");

		return self::upgrade_tables($version);
	}

	/**
	 * Upgrade tables.
	 */
	private static function upgrade_tables($from_version=null) {

		$sql = self::$sql;

		if (!$from_version) {
			$from_version = '0.0';
			$sql->query_raw("
				CREATE TABLE meta (
					version VARCHAR(24) NOT NULL DEFAULT '0.0'
				);
			");
			$sql->insert('meta', [
				'version' => self::TABLE_VERSION,
			]);
		} else {
			$sql->update('meta', [
				'version' => self::TABLE_VERSION,
			]);
		}

		self::$logger->info(sprintf(
			"Zapmin: Upgrading tables: '%s' -> '%s'.",
			$from_version, self::TABLE_VERSION));

		# other upgrade actions here ...
	}

}
