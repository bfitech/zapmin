<?php


namespace BFITech\ZapAdmin;


use BFITech\ZapCore\Common;
use BFITech\ZapCore\Logger;
use BFITech\ZapCore\Router;
use BFITech\ZapStore\SQL;
use BFITech\ZapStore\SQLError;
use BFITech\ZapStore\RedisConn;
use BFITech\ZapStore\RedisError;


/**
 * AdminStoreInit class.
 *
 * This class takes care of preparing things before exposing
 * public methods to router. Protected methods are prefixed
 * with `store_*` for clarity.
 */
abstract class AdminStoreInit extends AdminStoreCommon {

	/** SQL instance. */
	public $store;
	/** Redis instance for session caching. */
	public $redis;
	/** Logging service. */
	public $logger;
	/** Router instance. */
	public $core;

	/** Regular session expiration. */
	protected $expiration = 7200;
	/** Byway session expiration. */
	protected $byway_expiration = 604800;

	/** User session token. */
	protected $user_token = null;
	/** User data. */
	protected $user_data = null;
	/** Session token name, used by router. */
	protected $token_name = 'zapmin';

	private $force_create_table = false;
	private $initialized = false;

	/**
	 * Constructor.
	 *
	 * @param SQL $store SQL instance.
	 * @param Logger $logger Logger instance.
	 * @param RedisConn $redis RedisConn instance.
	 * @param Router $core Router instance. Not used in this class but
	 *     declared here for cleaner construct.
	 */
	public function __construct(
		SQL $store, Logger $logger=null, RedisConn $redis=null,
		Router $core=null
	) {
		$this->logger = $logger ? $logger : new Logger();

		# database
		$this->store = $store;
		$this->dbtype = $store->get_connection_params()['dbtype'];

		# redis
		$this->redis = $redis;

		# router
		$this->core = $core;
	}

	/**
	 * Configure.
	 *
	 * Available configurables:
	 *   - (int)expiration: Regular session expiration, in seconds.
	 *   - (int)byway_expiration: Byway session expiration, in seconds.
	 *   - (string)token_name: Session token name.
	 *   - (bool)force_create_table: Force recreate table on start,
	 *     even if tables already exist.
	 */
	public function config($key, $val) {
		if ($this->initialized)
			return $this;
		switch ($key) {
			case 'expiration':
			case 'byway_expiration':
				$this->$key = $this->store_check_expiration($val);
				break;
			case 'token_name':
				$this->token_name = rawurlencode($val);
				break;
			case 'force_create_table':
				$this->force_create_table = (bool)$val;
				break;
		}
		return $this;
	}

	/**
	 * Initialize properties, tables, etc.
	 */
	public function init() {
		if ($this->initialized)
			return $this;
		$this->initialized = true;
		$this->check_tables();
		return $this;
	}

	/**
	 * Restore properties to default.
	 */
	public function deinit() {
		if (!$this->initialized)
			return $this;

		$this->user_token = null;
		$this->user_data = null;

		$this->expiration = 3600 * 2;
		$this->byway_expiration = 3600 * 24 * 7;

		$this->initialized = false;

		return $this;
	}

	/**
	 * Verify expiration.
	 *
	 * Do not allow session that's too short. That would annoy users.
	 *
	 * @param int $expiration Session expiration, in seconds. This can
	 *     be used for standard or byway session.
	 */
	protected function store_check_expiration($expiration=null) {
		if (!$expiration)
			return 3600 * 2;
		$expiration = (int)$expiration;
		if ($expiration < 600)
			$expiration = 600;
		return $expiration;
	}


	/**
	 * Check tables.
	 *
	 * Check table existance and upgradability. If
	 * $this->force_create_table is true, old tables will be dropped
	 * and old data discarded.
	 */
	private function check_tables() {
		$tab = new AdminStoreTables();
		$tab::init($this);
		$force = $this->force_create_table;
		if ($tab::exists($force)) {
			if (!$force)
				$tab::upgrade();
			$tab::deinit();
			return;
		}
		$tab::install($this->expiration);
		$tab::deinit();
	}

	/**
	 * Get current table version.
	 */
	public function get_table_version() {
		return $this->store->query(
			"SELECT version FROM meta LIMIT 1"
		)['version'];
	}

	/**
	 * Shorthand for nullifying session data.
	 */
	protected function store_reset_status() {
		$this->user_data = null;
		$this->user_token = null;
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
	protected function store_match_password($uname, $upass, $usalt) {
		$udata = $this->store->query(
			"SELECT uid, uname " .
				"FROM udata WHERE upass=? LIMIT 1",
			[self::hash_password($uname, $upass, $usalt)]);
		if (!$udata)
			return false;
		return $udata;
	}

	/**
	 * Read session data from cache.
	 *
	 * @param string $token Session token.
	 */
	protected function store_redis_cache_read($token) {
		if (!$this->redis)
			return null;
		$key = sprintf('%s:%s', $this->token_name, $token);
		$data = $this->redis->get($key);
		if ($data === false)
			# key not fund
			return null;
		$data = @json_decode($data, true);
		if (!$data)
			# cached data is broken
			$data = ['uid' => -2];
		$this->logger->debug(sprintf(
			"Zapmin: session read from cache: '%s' <- '%s'.",
			$token, json_encode($data)));
		return $data;
	}

	/**
	 * Write session to cache.
	 *
	 * @param string $token Session token.
	 * @param array $data User data.
	 * @param string $expire_at Redis expireat parameter, in UTC
	 *     datetime.
	 * @param int $expire Redis expire parameter, in seconds. If this
	 *     is set, $expire_at is ignored.
	 */
	protected function store_redis_cache_write(
		$token, $data, $expire_at, $expire=null
	) {
		if (!$this->redis)
			return;

		$key = sprintf('%s:%s', $this->token_name, $token);
		$this->redis->set($key, json_encode($data));

		if ($expire !== null) {
			$this->redis->expire($key, $expire);
		} else {
			$expire_at = str_replace(' ', 'T', $expire_at) . 'Z';
			$expire_at = \DateTime::createFromFormat(
				\DateTime::ATOM, $expire_at)->format("U");
			$this->redis->expireat($key, $expire_at);
		}

		$this->logger->debug(sprintf(
			"Zapmin: session written to cache: '%s' <- '%s'.",
			$token, json_encode($data)));
	}

	/**
	 * Remove session cache.
	 *
	 * @param string $token Session token.
	 */
	protected function store_redis_cache_del($token) {
		if (!$this->redis)
			return;
		$key = sprintf('%s:%s', $this->token_name, $token);
		$this->redis->del($key);
		$this->logger->debug(sprintf(
			"Zapmin: session removed from cache: '%s'.", $token));
	}

	/**
	 * Populate session data.
	 */
	protected function store_get_user_status() {
		$this->init();
		if ($this->user_token === null)
			return null;
		if ($this->user_data !== null)
			return $this->user_data;

		$token = $this->user_token;

		# cache validation
		$cached = $this->store_redis_cache_read($token);
		if ($cached !== null) {
			if ($cached['uid'] == -1)
				# cached invalid session
				return null;
			# cached valid session
			return $this->user_data = $cached;
		}

		$expire = $this->store->stmt_fragment('datetime');
		$session = $this->store->query(
			sprintf(
				"SELECT * FROM v_usess " .
				"WHERE token=? AND expire>%s " .
				"LIMIT 1",
				$expire
			), [$token]);
		if (!$session) {
			# session not found or expired
			$this->store_reset_status();
			# cache invalid session for 10 minutes
			$this->store_redis_cache_write($token,
				['uid' => -1], null, 600);
			return $this->user_data;
		}

		if (!$this->redis)
			return $this->user_data = $session;

		$this->store_redis_cache_write($token, $session,
			$session['expire']);

		return $this->user_data = $session;
	}

	/**
	 * Check if current user is signed in.
	 */
	protected function store_is_logged_in() {
		$this->init();
		if ($this->user_data === null)
			$this->adm_status();
		return $this->user_data;
	}

	/**
	 * Close session record in the database.
	 *
	 * @param int $sid Session ID.
	 */
	protected function store_close_session($sid) {
		$now = $this->store->stmt_fragment('datetime');
		$this->store->query_raw(sprintf(
			"UPDATE usess SET expire=(%s) WHERE sid='%s'",
			$now, $sid));
		$this->store_redis_cache_del($this->user_token);
	}

}

