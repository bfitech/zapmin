<?php


namespace BFITech\ZapAdmin;


# must move to storage
class StorageError extends \Exception {}

class AdminStore {

	private $sql = null;

	private $user_token = null;
	private $user_data = null;

	private $expiration = 3600 * 2; # seconds

	public function __construct($sql, $expiration=null, $create_table=false) {

		$this->sql = $sql;
		$sql_params = $this->sql->get_connection_params();
		if (!$sql_params)
			throw new StorageError("Database not connected.");

		if ($expiration)
			$this->expiration = $expiration;

		if ($create_table)
			$this->create_table();
	}

	public static function check_keys($array, $keys) {
		foreach ($keys as $key) {
			if (!isset($array[$key]))
				return false;
			if (is_string($array[$key])) {
				$array[$key] = trim($array[$key]);
				if ($array[$key] == '')
					return false;
			}
		}
		return $array;
	}

	public function create_table() {

		$sql = $this->sql;
		$index = $sql->stmt_fragment('index');
		$engine = $sql->stmt_fragment('engine');
		$dtnow = $sql->stmt_fragment('datetime');
		$expire = $sql->stmt_fragment(
			'datetime', ['delta' => $this->expiration]);

		$user_table = (
			"CREATE TABLE udata (" .
			"  uid %s," .
			"  uname VARCHAR(64) UNIQUE," .
			"  upass VARCHAR(128)," .
			"  usalt VARCHAR(16)," .
			"  since DATE NOT NULL DEFAULT %s," .
			"  fname VARCHAR(128)," .
			"  site VARCHAR(128)" .
			") %s;"
		);
		$user_table = sprintf($user_table, $index, $dtnow, $engine);
		if (!$sql->query_raw($user_table))
			throw new StorageError(
				"Cannot create udata table:" . $sql->errmsg);
		$root_salt = $this->generate_secret('root');
		$root_salt = substr($root_salt, 0, 16);
		$root_pass = $this->hash_password('root', 'admin', $root_salt);
		$sql->insert('udata', [
			'uid' => 1,
			'uname' => 'root',
			'upass' => $root_pass,
			'usalt' => $root_salt,
		]);

		$session_table = (
			"CREATE TABLE usess (" .
			"  sid %s," .
			"  uid INTEGER REFERENCES user(uid) ON DELETE CASCADE," .
			"  token VARCHAR(64)," .
			"  expire DATE NOT NULL DEFAULT %s" .
			") %s;"
		);
		$session_table = sprintf(
			$session_table, $index, $expire, $engine);
		if (!$sql->query_raw($session_table))
			throw new StorageError(
				"Cannot create usess table:" . $sql->errmsg);

		$user_session_view = (
			"CREATE VIEW v_usess AS" .
			"  SELECT" .
			"    udata.*," .
			"    usess.sid," .
			"    usess.token," .
			"    usess.expire" .
			"  FROM udata, usess" .
			"  WHERE" .
			"    udata.uid=usess.uid;"
		);
		if (!$sql->query_raw($user_session_view))
			throw new StorageError(
				"Cannot create v_usess view:" . $sql->errmsg);
	}

	/**
	 * Set user token from environment or HTTP variables.
	 *
	 * Token can be obtained from cookie or custom header.
	 */
	public function set_user_token($user_token=null) {
		if (!$user_token)
			return;
		$this->user_token = $user_token;
	}

	/**
	 * Populate session data.
	 *
	 * Call this early on in every HTTP request once token
	 * is available.
	 */
	public function status() {
		if ($this->user_data !== null)
			return $this->user_data;
		if ($this->user_token === null)
			return $this->user_data = [];
		$session = $this->sql->query(
			sprintf(
				"SELECT * FROM v_usess " .
				"WHERE token=? AND expire>%s " .
				"LIMIT 1",
				$this->sql->stmt_frament('datetime')
			), [$this->user_token]);
		if (!$session)
			return $this->user_data = [];
		return $this->user_data = $session;
	}

	/**
	 * Get user data excluding sensitive info.
	 */
	public function get_safe_user_data() {
		if (!$this->is_logged_in())
			return [];
		$data = $this->user_data;
		foreach (['upass', 'usalt'] as $key)
			unset($data[$key]);
		return $data;
	}

	/**
	 * Shorthand for nullifying $this->user_data.
	 *
	 * Use this post-signout or the like to clear cache.
	 */
	public function status_reset() {
		$this->user_data = null;
	}

	private function match_password($uname, $upass, $usalt) {
		$udata = $this->sql->query(
			"SELECT uid, uname, fname, site " .
				"FROM udata WHERE upass=? LIMIT 1",
			[$this->hash_password($uname, $upass, $usalt)]);
		if (!$udata)
			return false;
		return $udata;
	}

	private function hash_password($uname, $upass, $usalt) {
		if (strlen($usalt) > 16)
			$usalt = substr($usalt, 0, 16);
		return $this->generate_secret($upass . $uname, $usalt);
	}

	private function generate_secret($data, $key=null) {
		if (!$key)
			$key = (string)time();
		$bstr = $data . $key;
		# NOTE: Keep $key <= 16 bytes.
		$bstr = hash_hmac('sha256', $bstr, $key, true);
		return base64_encode($bstr);
	}

	/**
	 * Sign in.
	 */
	public function login($args) {
		if ($this->is_logged_in())
			return [1];

		if (!isset($args['post']))
			return [2];
		if (!self::check_keys($args['post'], ['uname', 'upass']))
			return [2];
		extract($args['post'], EXTR_SKIP);

		$usalt = $this->sql->query(
			"SELECT usalt FROM udata WHERE uname=? LIMIT 1",
			[$uname]);
		if (!$usalt)
			return [3];
		$usalt = $usalt['usalt'];

		$udata = $this->match_password($uname, $upass, $usalt);
		if (!$udata)
			return [4];
		$this->user_data = $udata;

		// generate token
		$token = $this->generate_secret($upass . $usalt . time(), $usalt);
		$token = str_replace(['=', '+', '/'], '', $token);
		$token = substr($token, 0, 64);

		$this->sql->insert('usess', [
			'uid'   => $udata['uid'],
			'token' => $token,
		]);

		// token must be used by the router
		return [0, [
			'uid' => $udata['uid'],
			'uname' => $udata['uname'],
			'fname' => $udata['fname'],
			'site' => $udata['site'],
			'token' => $token,
		]];
	}

	private function is_logged_in() {
		if ($this->user_data === null)
			$this->status();
		return $this->user_data;
	}

	private function close_session($sid) {
		$now = $thi->sql->query(
			sprintf(
				"SELECT %s AS now",
				$this->sql->stmt_frament('datetime')
			), [], false);
		return $this->sql->update('usess', [
			'expire' => $now['now'],
		], [
			'sid' => $sid
		]);
	}

	/**
	 * Sign out.
	 */
	public function logout() {
		if (!$this->is_logged_in())
			return [1];
		if (!$this->close_session($this->user_data['sid']))
			# session not found
			return [2];
		$this->status_reset();
		# router must set appropriate local storage, cookie, etc.
		# e.g.: setcookie('cookie_adm', '', time() - 7200, '/');
		return [0];
	}

	/**
	 * Change password.
	 *
	 * @param array $args HTTP variables.
	 * @param bool $with_old_password Whether user should
	 *     enter valid old password.
	 */
	public function change_password($args, $with_old_password=false) {
		if (!$this->is_logged_in())
			return [1];

		$keys = ['pass1', 'pass2'];
		if ($with_old_password)
			$keys[] = 'pass0';

		if (!isset($args['post']))
			return [2];
		if (!self::check_keys($keys, $args['post']))
			return [2];
		extract($args['post'], EXTR_SKIP);

		extract($this->user_data, EXTR_SKIP);

		# check old password if applicable
		if (
			$with_old_password &&
			!$this->match_password($uname, $upass, $usalt)
		) {
			return [3];
		}

		# type twice the same
		if ($pass1 != $pass2)
			return [4];

		# must be longer than 3
		if (strlen($pass1) < 4)
			return [5];

		# update
		$this->sql->update('udata', [
			'upass' => $this->hash_password($uname, $pass1, $usalt),
		], [
			'uid' => $uid,
		]);

		# success
		return [0];
	}

	/**
	 * Change user info.
	 */
	public function change_bio($args) {
		if (!$this->is_logged_in())
			return [1];

		if (!isset($args['post']))
			return [2];
		$post = $args['post'];
		$vars = [];
		foreach (['fname', 'site'] as $key) {
			if (!isset($post[$key]))
				continue;
			$val = trim($post[$key]);
			if (!$val)
				continue;
			$vars[$key] = $val;
		}

		if (!$vars)
			# no change
			return [0];

		$this->sql->update('udata', $vars, [
			'uid' => $this->user_data['uid']
		]);
		return [0];
	}

	/**
	 * Register a new user.
	 *
	 * @param bool $must_authz Only authorized user can register new
	 *     one. Falsify to allow self-registration.
	 * @param function $callback_authz A function that must return true
	 *     to allow registration to proceed. Not used if $must_authz is
	 *     false. Default to current user being root.
	 */
	public function add_user($args, $must_authz=true, $callback_authz=null) {

		# authn
		if ($must_authz) {
			# must authn
			if (!$this->is_logged_in())
				return [1];
			# authz check
			if ($callback_authz === null) {
				$callback_authz = function() {
					return $this->user_data['uid'] == 1;
				};
			}
			# must authz
			if (!$callback_authz())
				return [2];
		}

		# check vars
		if (!isset($args['post']))
			return [3];
		$keys = ['addname', 'addpass'];
		if (!self::check_keys($args['post'], $keys))
			return [3];
		extract($args['post'], EXTR_SKIP);

		# hashes generation
		$usalt = $this->generate_secret($addname . $addpass);
		$hpass = $this->hash_password($addname, $addpass, $usalt);

		# insert
		if (!$this->sql->insert('udata', [
			'uname' => $addname,
			'upass' => $hpass,
			'usalt' => $usalt,
		]))
			# user exists
			return [4];

		# success
		return [0];
	}

	/*
	 * Delete a user.
	 *
	 * @param int $uid User ID to be deleted.
	 * @param function $callback_authz Callback to determine whether
	 *     current user is allowed to delete. Defaults to root and
	 *     self-delete.
	 */
	public function delete_user($args, $callback_authz=null) {
		if (!$this->is_logged_in())
			return [1];
		$user_data = $this->user_data;

		if (!isset($args['post']))
			return [2];
		if (!self::check_keys($args['post'], ['uid']))
			return [2];
		extract($args['post'], EXTR_SKIP);

		if (!$callback_authz) {
			$callback_authz = function() {
				if ($user_data['uid'] == 1 && $uid != 1)
					return true;
				if ($user_data['uid'] == $uid)
					return true;
				return false;
			};
		}
		if (!$callback_authz())
			return [3];

		# cannot delete root
		if ($uid == 1)
			return [4];

		# kill all sessions; this should be faster
		# with raw SQL, oh well
		$sessions = $this->sql->query(
			sprintf(
				"SELECT sid FROM usess WHERE ".
					"uid=? AND expire>=%s",
				$this->sql->stmt_frament('datetime')
			), [$uid]);
		foreach ($sessions as $s)
			$this->close_session($s['sid']);

		# delete
		if (!$this->sql->delete('udata', ['uid' => $uid]))
			# user doesn't exist
			return [5];

		# in case of self-delete, router must do $this->status_reset()
		# and send location.reload or the like
		return [0];
	}

	/**
	 * List all users.
	 *
	 * @param int $page Page number.
	 * @param int $limit Limit per page.
	 */
	public function list_user($page=0, $limit=10) {

		if ($page < 0)
			$page = 0;
		$offset = $page * $limit;
		return $this->sql->query(
			"SELECT uid, uname, fname, site, since " .
			"FROM udata ORDER BY uid LIMIT ? OFFSET ?",
			[$limit, $offset]);
	}
}

