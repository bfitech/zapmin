<?php


namespace BFITech\ZapAdmin;


use BFITech\ZapStore\SQLError;


/**
 * Tables class.
 *
 * This class manages table installation and upgrades.
 */
class Tables {

	/** Current table version. */
	const TABLE_VERSION = '1.0';

	/** Admin instance. */
	public static $admin;

	/**
	 * Initialize object.
	 *
	 * @param Admin $admin An instance of Admin.
	 */
	public function __construct(Admin $admin) {
		self::$admin = $admin;
		$this->exists();
	}

	/**
	 * Check if tables exist and perform possible upgrade for existing
	 * tables.
	 */
	public function exists() {
		try {
			self::$admin::$store->query("SELECT 1 FROM udata LIMIT 1");
			$this->upgrade();
		} catch (SQLError $e) {
			$this->install();
		}
	}

	/**
	 * SQL statement fragments.
	 *
	 * @param int $expiration Regular session expiration duration,
	 *     in second.
	 *
	 * @return array SQL statement fragments.
	 */
	private function fragments() {
		$sql = self::$admin::$store;
		$args = [];
		$args['index'] = $sql->stmt_fragment('index');
		$args['engine'] = $sql->stmt_fragment('engine');
		$args['dtnow'] = $sql->stmt_fragment('datetime');
		$args['expire'] = $sql->stmt_fragment('datetime',
			['delta' => self::$admin->get_expiration()]);
		if ($sql->get_connection_params()['dbtype'] == 'mysql')
			$args['dtnow'] = $args['expire'] = 'CURRENT_TIMESTAMP';
		return $args;
	}

	/**
	 * Install tables.
	 *
	 * @note In case of email addresses:
	 *   - Unique and null in one column is not portable. Must check
	 *     email uniqueness manually.
	 *   - Email verification must be held separately. Table only
	 *     reserves a column for it.
	 */
	private function install() {

		$sql = self::$admin::$store;

		$dtnow = $expire = $engine = $index = null;
		extract($this->fragments(self::$admin->get_expiration()));

		# user table

		$user_table = sprintf("
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
		", $index, $dtnow, $engine);
		$sql->query_raw($user_table);

		# default user

		$root_salt = Utils::generate_secret('root', null, 16);
		$root_pass = Utils::hash_password('root', 'admin', $root_salt);
		$sql->insert('udata', [
			'uid' => 1,
			'uname' => 'root',
			'upass' => $root_pass,
			'usalt' => $root_salt,
		]);

		# session table

		$session_table = sprintf("
			CREATE TABLE usess (
				sid %s,
				uid INTEGER REFERENCES udata(uid) ON DELETE CASCADE,
				token VARCHAR(64),
				expire TIMESTAMP NOT NULL DEFAULT %s
			) %s;
		", $index, $expire, $engine);
		$sql->query_raw($session_table);

		# session view

		$sql->query_raw("
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
	 *
	 * @return string Table version.
	 */
	private function upgrade() {

		$version = '0.0';
		try {
			$version = self::$admin::$store->query(
				"SELECT version FROM meta LIMIT 1")['version'];
		} catch(SQLError $e) {
			return self::upgrade_tables($version);
		}

		if (0 <= version_compare($version, self::TABLE_VERSION))
			return self::$admin::$logger->debug(
				"Zapmin: Tables are up-to-date.");

		// @codeCoverageIgnoreStart
		# no use cases just yet
		return self::upgrade_tables($version);
		// @codeCoverageIgnoreEnd
	}

	/**
	 * Upgrade tables.
	 *
	 * @return string Table version.
	 */
	private function upgrade_tables(string $from_version) {
		switch ($from_version) {
			case '0.0':
				$this->upgrade_tables($this->upgrade_0_0());
			# more cases here until reaching latest version
		}
		return self::TABLE_VERSION;
	}

	/**
	 * From 0.0 to 1.0.
	 *
	 * @return string Table version.
	 */
	private function upgrade_0_0() {
		$sql = self::$admin::$store;
		$sql->query_raw("
			CREATE TABLE meta (
				version VARCHAR(24) NOT NULL DEFAULT '0.0'
			)
		");
		$sql->insert('meta', [
			'version' => self::TABLE_VERSION,
		]);
		self::$admin::$logger->info(sprintf(
			"Zapmin: Upgrading tables: '0.0' -> '%s'.",
			self::TABLE_VERSION));
		return self::TABLE_VERSION;
	}

}
