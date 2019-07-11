<?php declare(strict_types=1);


namespace BFITech\ZapAdmin;


use BFITech\ZapCore\Logger;


/**
 * Auth class.
 */
abstract class Auth {

	/** Admin class instance. */
	public static $admin;
	/** Logger instance. */
	public static $logger;

	/** In-memory user data cache. */
	private $user_data;
	/** Token value normally set via HTTP header or cookie. */
	private $token_value;
	/** Mark if authentication has been performed. */
	private $authed = false;

	/**
	 * Constructor.
	 *
	 * @param Admin $admin Admin instance.
	 * @param Logger $logger Logger instance.
	 */
	public function __construct(
		Admin $admin, Logger $logger=null
	) {
		self::$admin = $admin->init();
		self::$logger = $logger ?? $admin::$logger;
	}

	/**
	 * Set user token value.
	 */
	public function set_token_value(string $val=null) {
		$this->token_value = $val;
	}

	/**
	 * Get user data.
	 *
	 * @return array User data from session.
	 */
	final public function get_user_data() {
		if ($this->token_value === null) {
			if ($this->authed)
				return null;
			$this->authed = true;
		}
		if ($this->user_data !== null)
			return $this->user_data;

		$token = $this->token_value;
		if (!$token)
			return null;

		# cache validation
		$cached = self::$admin->cache_read($token);
		if ($cached !== null) {
			if ($cached['uid'] == -1)
				# cached invalid session
				return null;
			# cached valid session
			return $this->user_data = $cached;
		}

		$admin = self::$admin;
		$sql = $admin::$store;

		$expire = $sql->stmt_fragment('datetime');
		$session = $sql->query(sprintf("
			SELECT * FROM v_usess
			WHERE token=? AND expire>%s
			LIMIT 1
		", $expire), [$token]);
		if (!$session) {
			# session not found or expired
			$this->reset();
			# cache invalid session for 10 minutes
			$admin->cache_write(
				$token, ['uid' => -1], null, 600);
			return $this->user_data;
		}

		$admin->cache_write($token, $session, $session['expire']);
		return $this->user_data = $session;
	}

	/**
	 * Get user data excluding sensitive info.
	 *
	 * @return array User data.
	 */
	final public function get_safe_user_data() {
		if (!$this->is_logged_in())
			return [Error::USER_NOT_LOGGED_IN];
		$data = $this->user_data;
		foreach (['upass', 'usalt', 'sid', 'token', 'expire'] as $key)
			unset($data[$key]);
		return [0, $data];
	}

	/**
	 * Close session record in the database.
	 *
	 * @param int $sid Session ID.
	 */
	final public function close_session(int $sid) {
		$sql = self::$admin::$store;
		$now = $sql->stmt_fragment('datetime');
		$sql->query_raw(sprintf("
			UPDATE usess SET expire=(%s) WHERE sid=%d
		", $now, $sid));
		self::$admin->cache_del($this->token_value);
	}

	/**
	 * Check if current user is signed in.
	 *
	 * @return array User data.
	 */
	final public function is_logged_in() {
		if ($this->user_data === null)
			$this->get_user_data();
		return $this->user_data;
	}

	/**
	 * Shorthand for nullifying session data.
	 */
	final public function reset() {
		$this->token_value = null;
		$this->user_data = null;
		$this->authed = false;
	}

}
