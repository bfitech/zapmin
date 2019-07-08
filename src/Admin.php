<?php declare(strict_types=1);


namespace BFITech\ZapAdmin;


use BFITech\ZapCore\Common;
use BFITech\ZapCore\Logger;
use BFITech\ZapCore\Router;
use BFITech\ZapStore\SQL;
use BFITech\ZapStore\SQLError;
use BFITech\ZapStore\RedisConn;
use BFITech\ZapStore\RedisError;


/**
 * Admin class.
 *
 * This class takes care of preparing administerial things prior to
 * authenticating user. Use Admin::config to fill out necessary
 * properties and then call Admin::init to ensure tables exist.
 */
class Admin {

	/** SQL instance. */
	public static $store;
	/** Logging service. */
	public static $logger;
	/** Redis instance for session caching. No caching if not set. */
	public static $redis;

	private $expiration = 7200;
	private $token_name = 'zapmin';
	private $check_tables = false;

	private $initialized = false;

	/**
	 * Constructor.
	 *
	 * @param SQL $store SQL instance.
	 * @param Logger $logger Logger instance.
	 * @param RedisConn $redis RedisConn instance.
	 */
	public function __construct(
		SQL $store, Logger $logger=null, RedisConn $redis=null
	) {
		# database
		self::$store = $store;
		# logger
		self::$logger = $logger ?? new Logger;
		# redis
		self::$redis = $redis;
	}

	/**
	 * Configure.
	 *
	 * Available configurables:
	 *   - (int)expiration: Regular session expiration, in seconds.
	 *   - (string)token_name: Session token name.
	 *   - (bool)check_tables: Check table existence. To prevent this
	 *     check on every call, save previous state, e.g. on config,
	 *     and run only when necessary.
	 */
	public function config(string $key, string $val=null) {
		if ($this->initialized)
			return $this;
		switch ($key) {
			case 'expiration':
				$this->$key = intval($val);
				break;
			case 'token_name':
				$this->token_name = rawurlencode($val);
				break;
			case 'check_tables':
				$this->check_tables = (bool)$val;
				break;
		}
		return $this;
	}

	private function fatal($errno, $message) {
		self::$logger->error("Zapmin: $message.");
		throw new Error($errno, $message);
	}

	/**
	 * Initialize properties, tables, etc.
	 */
	public function init() {
		if ($this->initialized)
			return $this;

		if (!$this->token_name)
			$this->fatal(
				Error::ADM_TOKEN_NOT_SET, "Token name not set.");

		$exp = $this->expiration;
		if (!$exp || $exp < 600)
			$this->fatal(
				Error::ADM_EXPIRATION_INVALID,
				"Invalid expiration value.");

		if ($this->check_tables)
			new Tables($this);

		if (!self::$redis)
			self::$logger->warning(
				"Zapmin: Redis connection not set. Cache disabled.");

		$this->initialized = true;
		return $this;
	}

	/**
	 * Match password and return user data on success.
	 *
	 * @param string $uname Username.
	 * @param string $upass User plain text password.
	 * @param string $usalt User salt.
	 * @return array|bool False on failure, user data on success. User
	 *     data elements are subset of those returned by
	 *     AdminStore::adm_get_safe_user_data.
	 */
	final public function match_password(
		string $uname, string $upass, string $usalt
	) {
		$udata = self::$store->query(
			"SELECT uid, uname " .
				"FROM udata WHERE upass=? LIMIT 1",
			[Utils::hash_password($uname, $upass, $usalt)]);
		if (!$udata)
			return false;
		return $udata;
	}

	/**
	 * Read session data from cache.
	 *
	 * @param string $token_value Session token value.
	 */
	final public function cache_read(string $token_value) {
		if (!self::$redis)
			return null;

		$key = sprintf('%s:%s', $this->token_name, $token_value);
		$data = self::$redis->get($key);
		if ($data === false)
			# key not fund
			return null;
		$data = @json_decode($data, true);
		if (!$data)
			# cached data is broken
			$data = ['uid' => -2];
		self::$logger->debug(sprintf(
			"Zapmin: session read from cache: '%s' <- '%s'.",
			$token_value, json_encode($data)));
		return $data;
	}

	/**
	 * Write session to cache.
	 *
	 * @param string $token_value Token value.
	 * @param array $data User data.
	 */
	final public function cache_write(
		string $token_value, array $data
	) {
		if (!self::$redis)
			return null;
		$redis = self::$redis;

		$key = sprintf('%s:%s', $this->token_name, $token_value);
		$jdata = json_encode($data);
		$redis->set($key, $jdata);
		$redis->expire($key, $this->expiration);
		self::$logger->debug(sprintf(
			"Zapmin: session written to cache: '%s' <- '%s'.",
			$token_value, $jdata));
	}

	/**
	 * Remove session cache.
	 *
	 * @param string $token_value Token value.
	 */
	final public function cache_del(string $token_value) {
		if (!self::$redis)
			return null;

		$key = sprintf('%s:%s', $this->token_name, $token_value);
		self::$redis->del($key);
		self::$logger->debug(sprintf(
			"Zapmin: session removed from cache: '%s'.", $token_value));
	}

	/* setters */

	/**
	 *
	 */
	final public function set_expiration(int $exp) {
		if ($exp < 600)
			$this->expiration = 600;
	}

	/* getters */

	/**
	 * Get expiration. In seconds.
	 */
	final public function get_expiration() {
		return $this->expiration;
	}

	/**
	 * Get token name.
	 */
	final public function get_token_name() {
		return $this->token_name;
	}

}
