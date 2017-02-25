<?php


namespace BFITech\ZapAdmin;

use BFITech\ZapCore as zc;
use BFITech\ZapStore as zs;


class AdminStoreError extends \Exception {}

class AdminStore {

	private $sql = null;

	private $user_token = null;
	private $user_data = null;

	private $expiration = 3600 * 2;

	/**
	 * Constructor.
	 *
	 * @param object $sql An instance of ZapStore\\SQL.
	 * @param int $expiration Session expiration interval in seconds.
	 *     Defaults to 2 hours.
	 * @param bool $force_create_table Whether tables are to be created
	 *     regardless current installation.
	 */
	public function __construct(
		$sql, $expiration=null, $force_create_table=false
	) {

		$this->sql = $sql;
		$sql_params = $this->sql->get_connection_params();
		if (!$sql_params)
			throw new AdminStoreError("Database not connected.");
		if ($sql_params['dbtype'] == 'mysql')
			# @fixme: MySQL needs extra effort due to it unable to
			# use parameterized defaults. Let's skip it for now.
			throw new AdminStoreError("Database not supported.");

		if ($expiration) {
			$expiration = (int)$expiration;
			if ($expiration < 600)
				$expiration = 600;
			$this->expiration = $expiration;
		}

		$this->check_tables($force_create_table);
	}

	/**
	 * Check if tables exist.
	 *
	 * @param bool $force_create_table When set to true, new table
	 *     will be created despite the old one.
	 */
	private function check_tables($force_create_table=false) {

		$sql = $this->sql;

		try {
			# check if table is already there
			$test = $sql->query("SELECT 1 FROM udata LIMIT 1");
			if (!$force_create_table)
				return;
		} catch (zs\SQLError $e) {}

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
			try {
				$sql->query_raw($drop);
			} catch(zs\SQLError $e) {
				throw new AdminStoreError(
					"Cannot drop data:" . $e->getMessage());
			}
		}

		# @note
		# - Unique and null in one column is not portable.
		#   Must check email uniqueness manually.
		# - Email verification must be held separately.
		#   Table only reserves a column for it.
		$user_table = (
			"CREATE TABLE udata (" .
			"  uid %s," .
			"  uname VARCHAR(64) UNIQUE," .
			"  upass VARCHAR(64)," .
			"  usalt VARCHAR(16)," .
			"  since DATE NOT NULL DEFAULT %s," .
			"  email VARCHAR(64)," .
			"  email_verified INT NOT NULL DEFAULT 0," .
			"  fname VARCHAR(128)," .
			"  site VARCHAR(128)" .
			") %s;"
		);
		$user_table = sprintf($user_table, $index, $dtnow, $engine);
		try {
			$sql->query_raw($user_table);
		} catch(zs\SQLError $e) {
			throw new AdminStoreError(
				"Cannot create udata table:" . $e->getMessage());
		}
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
		try {
			$sql->query_raw($session_table);
		} catch(zs\SQLError $e) {
			throw new AdminStoreError(
				"Cannot create usess table:" . $e->getMessage());
		}

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
		try {
			$sql->query_raw($user_session_view);
		} catch(zs\SQLError $e) {
			throw new AdminStoreError(
				"Cannot create v_usess view:" . $e->getMessage());
		}
	}

	/**
	 * Set user token.
	 *
	 * Token can be obtained from cookie or custom header.
	 */
	public function adm_set_user_token($user_token=null) {
		if (!$user_token)
			return;
		$this->user_token = $user_token;
	}

	/**
	 * Get expiration interval.
	 *
	 * Useful for client-side manipulation such as sending cookies.
	 */
	public function adm_get_expiration() {
		return $this->expiration;
	}

	/**
	 * Populate session data.
	 *
	 * Call this early on in every HTTP request once token
	 * is available or use its shorthand is_logged_in().
	 */
	public function adm_status() {
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
	 *
	 * @param array $args Unused. Keep it here to keep callback
	 *     pattern consistent.
	 */
	public function adm_get_safe_user_data($args=null) {
		if (!$this->is_logged_in())
			return [1];
		$data = $this->user_data;
		foreach (['upass', 'usalt', 'sid', 'token', 'expire'] as $key)
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

	/**
	 * Match password and return user data on success.
	 *
	 * @param string $uname Username.
	 * @param string $upass User plain text password.
	 * @param string $usalt User salt.
	 * @return array|bool False on failure, user data on
	 *     success. User data elements are just subset of
	 *     those returned by get_safe_user_data().
	 */
	private function match_password($uname, $upass, $usalt) {
		$udata = $this->sql->query(
			"SELECT uid, uname " .
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
	 * Verify email address.
	 *
	 * See: https://archive.fo/W1X0O
	 */
	public static function verify_email_address($email) {
		$email = trim($email);
		if (!$email || strlen($email) > 64)
			return false;
		$pat = '/^(([^<>()[\]\.,;:\s@\"]+(\.[^<>()[\]\.,;:\s@\"]+)*)|(\".+\"))' .
			   '@' .
			   '(([^<>()[\]\.,;:\s@\"]+\.)+[^<>()[\]\.,;:\s@\"]{2,})$/i';
		return preg_match($pat, $email);
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
	 * @param array $args Post data with keys: 'uname', 'upass'.
	 */
	public function adm_login($args) {
		if ($this->is_logged_in())
			return [1];

		if (!isset($args['post']))
			return [2];
		if (!zc\Common::check_dict($args['post'], ['uname', 'upass']))
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

		// token must be used by the router; this is a subset
		// of return value of get_safe_user_data() so it needs
		// a re-request after signing in
		return [0, [
			'uid' => $udata['uid'],
			'uname' => $udata['uname'],
			'token' => $token,
		]];
	}

	private function is_logged_in() {
		if ($this->user_data === null)
			$this->adm_status();
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
	 *
	 * @param array $args Unused. Retained for callback pattern consistency.
	 * @note Using _GET is enough for this operation.
	 */
	public function adm_logout($args=null) {
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
	 * @param array $args Post data with keys: 'pass1', 'pass2' and
	 *     optionally 'pass0' if $with_old_password is set to true.
	 * @param bool $with_old_password Whether user should
	 *     enter valid old password.
	 */
	public function adm_change_password($args, $with_old_password=false) {
		if (!$this->is_logged_in())
			return [1];

		extract($this->user_data, EXTR_SKIP);

		if (!$usalt)
			# passwordless has no salt
			return [2];

		$keys = ['pass1', 'pass2'];
		if ($with_old_password)
			$keys[] = 'pass0';

		if (!isset($args['post']))
			return [3];
		$post = zc\Common::check_dict($args['post'], $keys);
		if (!$post)
			return [4];
		extract($post, EXTR_SKIP);


		# check old password if applicable
		if (
			$with_old_password &&
			!$this->match_password($uname, $pass0, $usalt)
		) {
			return [5];
		}

		$verify_password = $this->verify_password($pass1, $pass2);
		if ($verify_password !== 0)
			return [6, $verify_password];

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
	 *
	 * @param array $args Post data with keys: 'fname', 'site'.
	 * @todo Change email, although this is more complicated if
	 *     we also need to verify the email.
	 */
	public function adm_change_bio($args) {
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
		$this->adm_status();

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
	 * @param bool $email_required Whether email address is mandatory.
	 * @param function $callback_authz A function that takes one parameter
	 *     $callback_param to allow registration to proceed. Default to
	 *     current user being root.
	 * @param array $callback_param Parameter to pass to $callback_authz.
	 */
	public function adm_add_user(
		$args, $pass_twice=false, $allow_self_register=false,
		$email_required=false, $callback_authz=null, $callback_param=null
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
		if ($email_required)
			$keys[] = 'email';
		$post = zc\Common::check_dict($args['post'], $keys);
		if (!$post)
			return [3, 1];
		extract($post, EXTR_SKIP);

		# check name, allow unicode but not whitespace
		if (strlen($addname) > 64)
			# max 64 chars
			return [4, 0];
		foreach([" ", "\n", "\r", "\t"] as $white) {
			# never allow whitespace in the middle
			if (strpos($addname, $white) !== false)
				return [4, 1];
		}
		if ($addname[0] == '+')
			# leading '+' is reserved for passwordless accounts
			return [4, 2];

		if ($email_required) {
			if (!self::verify_email_address($email))
				return [5, 0];
			if ($this->sql->query(
				"SELECT uid FROM udata WHERE email=? LIMIT 1",
				[$email])
			)
				return [5, 1];
		}

		if (!$pass_twice)
			$addpass2 = $addpass1;
		$verify_password = $this->verify_password($addpass1, $addpass2);
		if ($verify_password !== 0)
			return [6, $verify_password];

		# hashes generation
		$usalt = $this->generate_secret($addname . $addpass1, null, 16);
		$hpass = $this->hash_password($addname, $addpass1, $usalt);

		# insert
		$udata = [
			'uname' => $addname,
			'upass' => $hpass,
			'usalt' => $usalt,
		];
		if ($email_required)
			$udata['email'] = $email;
		try {
			$this->sql->insert('udata', $udata, 'uid');
		} catch(zs\SQLError $e) {
			# user exists
			return [7];
		}

		# success
		return [0];
	}

	/**
	 * Self-register.
	 *
	 * @note This is just a special case of add_user() with additional
	 * condition: user must not be authenticated.
	 *
	 * @param array $args Post data with keys: 'addname', 'addpass1',
	 *     and optional 'addpass2' unless $pass_twice is set to true.
	 * @param bool $pass_twice Whether password must be entered twice.
	 * @param bool $email_required Whether an email address must be
	 *     provided.
	 */
	public function adm_self_add_user(
		$args, $pass_twice=false, $email_required=false
	) {
		if ($this->is_logged_in())
			return [1];
		return $this->adm_add_user(
			$args, $pass_twice, true, $email_required);
	}

	/**
	 * Passwordless self-registration.
	 *
	 * This doesn't differ sign in and sign up.
	 *
	 * @note
	 * - Use this with caution, e.g. with proper authentication
	 *   via OAuth* or the like.
	 * - Unlike add user with password, this also returns 'sid' to
	 *   associate session.sid with another column in different table.
	 * @param array $args Array with key 'service' containing another
	 *     array with keys: 'uname' and 'uservice'. This can be added
	 *     to $args parameter of route handlers.
	 */
	public function adm_self_add_user_passwordless($args) {
		if ($this->is_logged_in())
			return [1];

		# check vars
		if (!isset($args['service']))
			return [2, 0];
		$service = zc\Common::check_dict($args['service'], ['uname', 'uservice']);
		if (!$service)
			return [2, 1];
		extract($service, EXTR_SKIP);

		$dbuname = '+' . $uname . ':' . $uservice;
		$check = $this->sql->query(
			"SELECT uid FROM udata WHERE uname=? LIMIT 1",
			[$dbuname]);
		if (!$check) {
			$uid = $this->sql->insert("udata", [
				'uname' => $dbuname,
			], 'uid');
		} else {
			$uid = $check['uid'];
		}

		# token generation is a little different
		$token = $this->generate_secret(
			$dbuname . time(), $uname);

		# expiration is much longer, 7 days
		$expire = 3600 * 24 * 7;
		$date_expire = $this->sql->query(
			sprintf(
				"SELECT %s AS date_expire",
				$this->sql->stmt_fragment(
					'datetime', ['delta' => $expire])
				)
			)['date_expire'];

		# insert
		$sid = $this->sql->insert('usess', [
			'uid'    => $uid,
			'token'  => $token,
			'expire' => $date_expire,
		], 'sid');

		# use token for next request
		return [0, [
			'uid' => $uid,
			'uname' => $dbuname,
			'token' => $token,
			'sid' => $sid,
		]];
	}

	/**
	 * Delete a user.
	 *
	 * @param array $args Post data with keys: 'uid'.
	 * @param function $callback_authz A function that takes one parameter
	 *     $callback_args to allow deletion to proceed. Default to current
	 *     user being root, or non-root self-deletion.
	 * @param array $callback_param Parameter to pass to $callback_authz.
	 */
	public function adm_delete_user(
		$args, $callback_authz=null, $callback_param=null
	) {
		if (!$this->is_logged_in())
			return [1];
		$user_data = $this->user_data;

		if (!isset($args['post']))
			return [2];
		if (!zc\Common::check_dict($args['post'], ['uid']))
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
			# user does not exist
			return [5];

		# delete user data and its related session history via
		# foreign key constraint
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
	public function adm_list_user(
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

