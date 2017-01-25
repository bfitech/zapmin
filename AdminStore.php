<?php


namespace BFITech\ZapAdmin;


# must move to storage
class StorageError extends \Exception {}

class AdminStore {

	private $sql = null;

	private $user_token = null;
	private $user_data = null;

	private $expiration = 3600 * 2; # seconds

	public function __construct(
		$sql, $expiration=null, $force_create_table=false
	) {

		$this->sql = $sql;
		$sql_params = $this->sql->get_connection_params();
		if (!$sql_params)
			throw new StorageError("Database not connected.");

		if ($expiration)
			$this->expiration = $expiration;

		$this->check_tables($force_create_table);
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

	public function check_tables($force_create_table=false) {

		$sql = $this->sql;

		# Test if tables exist. There's no generic way to check
		# it across databases. Just use 'udata' since it must
		# never be empty. Also, every request calls this. Whatevs.
		try {
			$test = $sql->query("SELECT uid FROM udata LIMIT 1");
			if (!$force_create_table)
				return;
		} catch (\PDOException $e) {}

		$index = $sql->stmt_fragment('index');
		$engine = $sql->stmt_fragment('engine');
		$dtnow = $sql->stmt_fragment('datetime');
		$expire = $sql->stmt_fragment(
			'datetime', ['delta' => $this->expiration]);

		foreach([
			"DROP VIEW IF EXISTS v_usess;",
			"DROP TABLE IF EXISTS usess;",
			"DROP TABLE IF EXISTS udata;",
		] as $drop) {
			if (!$sql->query_raw($drop))
				throw new StorageError(
					"Cannot drop data:" . $sql->errmsg);
		}

		$user_table = (
			"CREATE TABLE udata (" .
			"  uid %s," .
			"  uname VARCHAR(64) UNIQUE," .
			"  upass VARCHAR(64)," .
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
		$root_salt = $this->generate_secret('root', null, 16);
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
			"  uid INTEGER REFERENCES udata(uid) ON DELETE CASCADE," .
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
		if ($this->user_token === null)
			return null;
		if ($this->user_data !== null)
			return $this->user_data;
		$session = $this->sql->query(
			sprintf(
				"SELECT * FROM v_usess " .
				"WHERE token=? AND expire>%s " .
				"LIMIT 1",
				$this->sql->stmt_fragment('datetime')
			), [$this->user_token]);
		if (!$session) {
			# session not found or expired
			$this->status_reset();
			return $this->user_data;
		}
		return $this->user_data = $session;
	}

	/**
	 * Get user data excluding sensitive info.
	 */
	public function get_safe_user_data() {
		if (!$this->is_logged_in())
			return [1];
		$data = $this->user_data;
		foreach (['upass', 'usalt'] as $key)
			unset($data[$key]);
		return [0, $data];
	}

	/**
	 * Shorthand for nullifying session data.
	 */
	private function status_reset() {
		$this->user_data = null;
		$this->user_token = null;
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

	/**
	 * Verify plaintext password.
	 *
	 * Used by password change, registration, and derivatives
	 * like password reset, etc.
	 */
	private function verify_password($pass1, $pass2) {
		$pass1 = trim($pass1);
		$pass2 = trim($pass2);
		# type twice the same
		if ($pass1 != $pass2)
			return 1;
		# must be longer than 3
		if (strlen($pass1) < 4)
			return 2;
		return 0;
	}

	/**
	 * Generate salt.
	 *
	 * @param string $data Input data.
	 * @param string $key HMAC key.
	 * @param int $length Maximum length of generated salt. Normal
	 *     usage is 16 for user salt and 64 for hashed password.
	 */
	private function generate_secret($data, $key=null, $length=64) {
		if (!$key)
			$key = dechex(time() + mt_rand());
		$bstr = $data . $key;
		$bstr = hash_hmac('sha256', $bstr, $key, true);
		$bstr = base64_encode($bstr);
		$bstr = str_replace(['/', '+', '='], '', $bstr);
		return substr($bstr, 0, $length);
	}

	/**
	 * Sign in.
	 *
	 * @param array $arg Post data with keys: 'uname', 'upass'.
	 */
	public function login($args) {
		if ($this->is_logged_in())
			return [1];

		if (!isset($args['post']))
			return [2];
		if (!self::check_keys($args['post'], ['uname', 'upass']))
			return [3];
		extract($args['post'], EXTR_SKIP);

		$usalt = $this->sql->query(
			"SELECT usalt FROM udata WHERE uname=? LIMIT 1",
			[$uname]);
		if (!$usalt)
			# user not found
			return [4];
		$usalt = $usalt['usalt'];

		$udata = $this->match_password($uname, $upass, $usalt);
		if (!$udata)
			# wrong password
			return [5];

		// generate token
		$token = $this->generate_secret(
			$upass . $usalt . time(), $usalt);

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
		$now = $this->sql->query(
			sprintf(
				"SELECT %s AS now",
				$this->sql->stmt_fragment('datetime')
			), [], false);
		$this->sql->update('usess', [
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
		# this just close sessions with current sid, whether
		# it exists or not, possibly deleted by account
		# self-delete
		$this->close_session($this->user_data['sid']);
		# reset status
		$this->status_reset();
		# router must set appropriate cookie, e.g.:
		# setcookie('cookie_adm', '', time() - 7200, '/');
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
		$post = self::check_keys($args['post'], $keys);
		if (!$post)
			return [2];
		extract($post, EXTR_SKIP);

		extract($this->user_data, EXTR_SKIP);

		# check old password if applicable
		if (
			$with_old_password &&
			!$this->match_password($uname, $pass0, $usalt)
		) {
			return [3];
		}

		$verify_password = $this->verify_password($pass1, $pass2);
		if ($verify_password !== 0)
			return [4, $verify_password];

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

		# reset user data but not user token
		$this->user_data = null;
		$this->status();

		# ok
		return [0];
	}

	/**
	 * Register a new user.
	 *
	 * @param array $args Post data with keys: 'addname', 'addpass1',
	 *     and optional 'addpass2' unless $pass_twice is set to true.
	 * @param bool $pass_twice Whether password must be entered twice.
	 * @param bool $allow_self_register Whether self-registration is
	 *     allowed.
	 * @param function $callback_authz A function that takes one parameter
	 *     $callback_param to allow registration to proceed. Default to
	 *     current user being root.
	 * @param array $callback_param Parameter to pass to $callback_authz.
	 */
	public function add_user(
		$args, $pass_twice=false, $allow_self_register=false,
		$callback_authz=null, $callback_param=null
	) {
		if ($this->is_logged_in()) {
			if (!$callback_authz) {
				# default callback
				$callback_authz = function($param) {
					# allow uid=1 only
					if ($param['uid'] == 1)
						return 0;
					return 1;
				};
				$callback_param = [
					'uid' => $this->user_data['uid']];
			}
			$ret = $callback_authz($callback_param);
			if ($ret !== 0)
				return [1, $ret];
		} else {
			# not signed in
			if (!$allow_self_register)
				# self-registration not allowed
				return [2];
			# NOTE: Other constraint such as captcha happens outside
			# of this class.
		}

		# check vars
		if (!isset($args['post']))
			return [3, 0];
		$keys = ['addname', 'addpass1'];
		if ($pass_twice)
			$keys[] = 'addpass2';
		$post = self::check_keys($args['post'], $keys);
		if (!$post)
			return [3, 1];
		extract($post, EXTR_SKIP);

		if (!$pass_twice)
			$addpass2 = $addpass1;
		$verify_password = $this->verify_password($addpass1, $addpass2);
		if ($verify_password !== 0)
			return [4, $verify_password];

		# hashes generation
		$usalt = $this->generate_secret($addname . $addpass1, null, 16);
		$hpass = $this->hash_password($addname, $addpass1, $usalt);

		# insert
		if (!$this->sql->insert('udata', [
			'uname' => $addname,
			'upass' => $hpass,
			'usalt' => $usalt,
		], 'uid')) {
			# user exists
			# FIXME: Relying on PDO exception seems fishy.
			return [6];
		}

		# success
		return [0];
	}

	/**
	 * Self-register.
	 *
	 * This is just a special case of add_user() with additional
	 * condition: user must not be authenticated.
	 *
	 * @param array $args Post data with keys: 'addname', 'addpass1',
	 *     and optional 'addpass2' unless $pass_twice is set to true.
	 * @param bool $pass_twice Whether password must be entered twice.
	 */
	public function self_add_user($args, $pass_twice=false) {
		if ($this->is_logged_in())
			return [1];
		return $this->add_user($args, $pass_twice, true);
	}

	/*
	 * Delete a user.
	 *
	 * @param array $args Post data with keys: 'uid'.
	 * @param int $uid User ID to be deleted.
	 * @param function $callback_authz Callback to determine whether
	 *     current user is allowed to delete. Defaults to root and
	 *     self-delete.
	 * @param function $callback_authz A function that takes one parameter
	 *     $callback_args to allow deletion to proceed. Default to current
	 *     user being root, or non-root self-deletion.
	 * @param array $callback_param Parameter to pass to $callback_authz.
	 */
	public function delete_user(
		$args, $callback_authz=null, $callback_param=null
	) {
		if (!$this->is_logged_in())
			return [1];
		$user_data = $this->user_data;

		if (!isset($args['post']))
			return [2];
		if (!self::check_keys($args['post'], ['uid']))
			return [2];
		extract($args['post'], EXTR_SKIP);

		if (!$callback_authz) {
			# default callback
			$callback_authz = function($param) {
				if ($param['own_uid'] == 1 && $param['del_uid'] != 1)
					return 0;
				if ($param['own_uid'] == $param['del_uid'])
					return 0;
				return 1;
			};
			$callback_param = [
				'own_uid' => $user_data['uid'],
				'del_uid' => $uid,
			];
		}
		$ret = $callback_authz($callback_param);
		if ($ret !== 0)
			return [3, $ret];

		# cannot delete root
		if ($uid == 1)
			return [4];

		if (!$this->sql->query(
			"SELECT uid FROM udata WHERE uid=? LIMIT 1",
			[$uid]))
			# user doesn't exist
			return [5];

		# kill all sessions; this should be faster
		# with raw SQL, oh well
		$sessions = $this->sql->query(
			sprintf(
				"SELECT sid FROM usess WHERE ".
					"uid=? AND expire>=%s",
				$this->sql->stmt_fragment('datetime')
			), [$uid], true);
		foreach ($sessions as $s)
			$this->close_session($s['sid']);

		# delete user data
		$this->sql->delete('udata', ['uid' => $uid]);

		# in case of self-delete, router must send redirect header
		# or location.reload from the client side
		return [0];
	}

	/**
	 * List all users.
	 *
	 * @param array $args Post data with keys: 'page', 'limit', 'order'
	 *     where 'order' is 'ASC' or 'DESC'.
	 * @param function $callback_authz A function that takes one parameter
	 *     $callback_args to allow listing. Default to current user being root.
	 * @param array $callback_param Parameter to pass to $callback_authz.
	 */
	public function list_user(
		$args, $callback_authz=null, $callback_param=null
	) {
		if (!$callback_authz) {
			$this->is_logged_in();
			$callback_authz = function($param) {
				# allow uid=1 only
				if ($param['uid'] == 1)
					return 0;
				return 1;
			};
			$callback_param = [
				'uid' => $this->user_data['uid']];
		}
		$ret = $callback_authz($callback_param);
		if ($ret !== 0)
			return [1, $ret];

		extract($args['post'], EXTR_SKIP);

		$page = isset($page) ? (int)$page : 0;
		if ($page < 0)
			$page = 0;

		$limit = 10;
		$limit = isset($limit) ? (int)$limit : 10;
		if ($limit <= 0 || $limit >= 40)
			$limit = 10;

		$offset = $page * $limit;

		if (!isset($order) || !in_array($order, ['ASC', 'DESC']))
			$order = '';

		$sql = sprintf(
			"SELECT uid, uname, fname, site, since " .
			"FROM udata ORDER BY uid %s LIMIT ? OFFSET ?",
			$order);

		return [0, $this->sql->query($sql, [$limit, $offset], true)];
	}
}

