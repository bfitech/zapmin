<?php


namespace BFITech\ZapAdmin;


use BFITech\ZapCore\Common;
use BFITech\ZapStore\SQLError;


/**
 * AdminStore class.
 *
 * Router-exposed public methods must be prefixed with `adm_*` in this
 * class or in its subclasses for clarity.
 */
abstract class AdminStore extends AdminStoreInit {

	/**
	 * Set user token.
	 *
	 * Token can be obtained from cookie or custom header.
	 */
	public function adm_set_user_token($user_token=null) {
		$this->init();
		if (!$user_token)
			return;
		$this->user_token = $user_token;
	}

	/**
	 * Getter for expiration.
	 *
	 * Useful for client-side manipulation such as sending cookies.
	 */
	public function adm_get_expiration() {
		$this->init();
		return $this->expiration;
	}

	/**
	 * Setter for byway expiration.
	 *
	 * Byway expiration is typically much longer than the standard
	 * one. It also must be easily altered, allowing a subclass to
	 * finetune the session specific to each 3rd-party provider it
	 * authenticates against.
	 *
	 * @param int $expiration Byway expiration, in seconds.
	 */
	public function adm_set_byway_expiration($expiration) {
		$this->init();
		$this->byway_expiration = $this->store_check_expiration(
			$expiration);
	}

	/**
	 * Getter for byway expiration.
	 */
	public function adm_get_byway_expiration() {
		$this->init();
		return $this->byway_expiration;
	}

	/**
	 * Safely retrieve authentication token name.
	 *
	 * Useful for e.g. setting cookie name or HTTP request header
	 * on the client side.
	 */
	public function adm_get_token_name() {
		return $this->token_name;
	}

	/**
	 * Get user login status.
	 *
	 * Call this early on in every HTTP request once session token
	 * is available.
	 */
	public function adm_status() {
		return $this->store_get_user_status();
	}

	/**
	 * Get user data excluding sensitive info.
	 */
	public function adm_get_safe_user_data() {
		if (!$this->store_is_logged_in())
			return [AdminStoreError::USER_NOT_LOGGED_IN];
		$data = $this->user_data;
		foreach (['upass', 'usalt', 'sid', 'token', 'expire'] as $key)
			unset($data[$key]);
		return [0, $data];
	}

	/**
	 * Sign in.
	 *
	 * @param array $args Dict with keys: `uname`, `upass`.
	 * @return array An array of the form:
	 *     @code
	 *     (array)[
	 *       (int errno),
	 *       (dict){
	 *         'uid': (int uid),
	 *         'uname': (string uname),
	 *         'token': (string token)
	 *       }
	 *     ]
	 *     @endcode
	 */
	public function adm_login($args) {
		$this->init();
		$logger = $this->logger;

		if ($this->store_is_logged_in())
			return [AdminStoreError::USER_ALREADY_LOGGED_IN];

		if (!isset($args['post']))
			return [AdminStoreError::DATA_INCOMPLETE];
		if (!Common::check_idict($args['post'], ['uname', 'upass']))
			return [AdminStoreError::DATA_INCOMPLETE];
		extract($args['post']);

		$usalt = $this->store->query(
			"SELECT usalt FROM udata WHERE uname=? LIMIT 1",
			[$uname]);
		if (!$usalt)
			# user not found
			return [AdminStoreError::USER_NOT_FOUND];
		$usalt = $usalt['usalt'];

		$udata = $this->store_match_password($uname, $upass, $usalt);
		if (!$udata) {
			# wrong password
			$logger->warning("Zapmin: login: wrong password: '$uname'.");
			return [AdminStoreError::WRONG_PASSWORD];
		}

		// generate token
		$token = $this->generate_secret(
			$upass . $usalt . time(), $usalt);

		$sid = $this->store->insert('usess', [
			'uid'   => $udata['uid'],
			'token' => $token,
		], 'sid');
		if ($this->dbtype == 'mysql') {
			// mysql has no parametrized default values, and can't
			// invoke trigger on currently inserted table
			$expire_at = $this->store->stmt_fragment('datetime', [
				'delta' => $this->expiration,
			]);
			$this->store->query_raw(sprintf(
				"UPDATE usess SET expire=(%s) WHERE sid='%s'",
				$expire_at, $sid));
		}

		// token must be used by the router; this is a subset
		// of return value of get_safe_user_data() so it needs
		// a re-request after signing in
		$logger->info("Zapmin: login: OK: '$uname'.");
		return [0, [
			'uid' => $udata['uid'],
			'uname' => $udata['uname'],
			'token' => $token,
		]];
	}

	/**
	 * Sign out.
	 *
	 * @note Using _GET is enough for this operation.
	 */
	public function adm_logout() {
		if (!$this->store_is_logged_in())
			return [AdminStoreError::USER_NOT_LOGGED_IN];
		# this just close sessions with current sid, whether
		# it exists or not, possibly deleted by account
		# self-delete
		$this->store_close_session($this->user_data['sid']);
		# reset status
		$this->store_reset_status();
		# router must set appropriate cookie, e.g.:
		# setcookie('cookie_adm', '', time() - 7200, '/');
		$this->logger->info(sprintf(
			"Zapmin: logout: OK: '%s'.",
			$this->user_data['uname']
		));
		return [0];
	}

	/**
	 * Change password.
	 *
	 * @param array $args Dict of the form:
	 *     @code
	 *     (dict){
	 *       'pass1': (string pass1),
	 *       'pass2': (string pass2),
	 *       'pass0': (string:optional pass0)
	 *     }
	 *     @endcode
	 *     where `pass0` is processed only if $with_old_password is set
	 *     to true.
	 * @param bool $with_old_password Whether user should provide valid
	 *     old password.
	 */
	public function adm_change_password(
		$args, $with_old_password=null
	) {
		if (!$this->store_is_logged_in())
			return [AdminStoreError::USER_NOT_LOGGED_IN];

		extract($this->user_data);

		$logger = $this->logger;

		if (!$usalt)
			# passwordless has no salt
			return [AdminStoreError::USER_NOT_FOUND];

		$keys = ['pass1', 'pass2'];
		if ($with_old_password)
			$keys[] = 'pass0';

		if (!isset($args['post']))
			return [AdminStoreError::DATA_INCOMPLETE];
		$post = Common::check_idict($args['post'], $keys);
		if (!$post)
			return [AdminStoreError::DATA_INCOMPLETE];
		extract($post);

		# check old password if applicable
		if (
			$with_old_password &&
			!$this->store_match_password($uname, $pass0, $usalt)
		) {
			$logger->warning(
				"Zapmin: chpasswd: old passwd invalid: '$uname'.");
			return [AdminStoreError::OLD_PASSWORD_INVALID];
		}

		$verify_password = $this->verify_password($pass1, $pass2);
		if ($verify_password !== 0) {
			$logger->warning(
				"Zapmin: chpasswd: new passwd invalid: '$uname'.");
			return [AdminStoreError::PASSWORD_INVALID,
				$verify_password];
		}

		# update
		$this->store->update('udata', [
			'upass' => $this->hash_password($uname, $pass1, $usalt),
		], [
			'uid' => $uid,
		]);

		# success
		$logger->info("Zapmin: chpasswd: OK: '$uname'.");
		return [0];
	}

	/**
	 * Change user info.
	 *
	 * @param array $args Dict with keys: `fname`, `site`.
	 */
	public function adm_change_bio($args) {
		if (!$this->store_is_logged_in())
			return [AdminStoreError::USER_NOT_LOGGED_IN];

		if (!isset($args['post']))
			return [AdminStoreError::DATA_INCOMPLETE];
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

		extract($vars);

		# verify site url value
		if (isset($site) && !self::verify_site_url($site)) {
			$this->logger->warning(
				"Zapmin: chbio: site URL invalid: '$site'.");
			return [AdminStoreError::SITEURL_INVALID];
		}

		$this->store->update('udata', $vars, [
			'uid' => $this->user_data['uid']
		]);

		# reset user data but not user token
		$this->user_data = null;
		$this->adm_status();

		# ok
		$this->logger->info(sprintf(
			"Zapmin: chbio: OK: '%s'.",
			$this->user_data['uname']
		));
		return [0];
	}

	/**
	 * Register a new user.
	 *
	 * @param array $args Dict with keys: `addname`, `addpass1`,
	 *     and optional `addpass2` unless `$pass_twice` is set to true.
	 * @param bool $pass_twice Whether password must be entered twice.
	 * @param bool $allow_self_register Whether self-registration is
	 *     allowed.
	 * @param bool $email_required Whether email address is mandatory.
	 * @param function $callback_authz A function that takes one
	 *     parameter $callback_param to allow registration to proceed.
	 *     Defaults to current user being root.
	 * @param array $callback_param Parameter to pass to
	 *     `$callback_authz`.
	 */
	public function adm_add_user(
		$args, $pass_twice=null, $allow_self_register=null,
		$email_required=null, $callback_authz=null,
		$callback_param=null
	) {
		$this->init();
		$logger = $this->logger;

		if ($this->store_is_logged_in()) {
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
				return [AdminStoreError::USER_NOT_AUTHORIZED];
		} elseif (!$allow_self_register) {
			# self-registration not allowed
			return [AdminStoreError::SELF_REGISTER_NOT_ALLOWED];
		}

		# check vars
		if (!isset($args['post']))
			return [AdminStoreError::DATA_INCOMPLETE];
		$keys = ['addname', 'addpass1'];
		if ($pass_twice)
			$keys[] = 'addpass2';
		if ($email_required)
			$keys[] = 'email';
		$post = Common::check_idict($args['post'], $keys);
		if (!$post)
			return [AdminStoreError::DATA_INCOMPLETE];
		extract($post);

		# check name, allow unicode but not whitespace
		if (strlen($addname) > 64) {
			# max 64 chars
			$logger->warning(
				"Zapmin: usradd: name invalid: '$addname'.");
			return [AdminStoreError::USERNAME_TOO_LONG];
		}
		foreach([" ", "\n", "\r", "\t"] as $white) {
			# never allow whitespace in the middle
			if (strpos($addname, $white) !== false) {
				$logger->warning(
					"Zapmin: usradd: name invalid: '$addname'.");
				return [AdminStoreError::USERNAME_HAS_WHITESPACE];
			}
		}
		if ($addname[0] == '+') {
			# leading '+' is reserved for passwordless accounts
			$logger->warning(
				"Zapmin: usradd: name invalid: '$addname'.");
			return [AdminStoreError::USERNAME_LEADING_PLUS];
		}

		if ($email_required) {
			if (!self::verify_email_address($email)) {
				$logger->warning(sprintf(
					"Zapmin: usradd: email invalid: '%s' <- '%s'.",
					$addname, $email));
				return [AdminStoreError::EMAIL_INVALID];
			}
			if ($this->store->query(
				"SELECT uid FROM udata WHERE email=? LIMIT 1",
				[$email])
			) {
				$logger->warning(sprintf(
					"Zapmin: usradd: email exists: '%s' <- '%s'.",
					$addname, $email));
				return [AdminStoreError::EMAIL_EXISTS];
			}
		}

		if (!$pass_twice)
			$addpass2 = $addpass1;
		$verify_password = $this->verify_password($addpass1, $addpass2);
		if ($verify_password !== 0) {
			$logger->warning(
				"Zapmin: usradd: passwd invalid: '$addname'.");
			return [AdminStoreError::PASSWORD_INVALID, $verify_password];
		}

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
			$this->store->insert('udata', $udata, 'uid');
		} catch(SQLError $e) {
			# user exists
			$logger->info("Zapmin: usradd: user exists: '$addname'.");
			return [AdminStoreError::USERNAME_EXISTS];
		}

		# success
		$logger->info("Zapmin: usradd: OK: '$addname'.");
		return [0];
	}

	/**
	 * Self-register.
	 *
	 * @note This is just a special case of adm_add_user() with
	 *     additional condition: user must not be authenticated.
	 *
	 * @param array $args Dict with keys: `addname`, `addpass1`,
	 *     and optional `addpass2` unless `$pass_twice` is set to true.
	 * @param bool $pass_twice Whether password must be entered twice.
	 * @param bool $email_required Whether an email address must be
	 *     provided.
	 */
	public function adm_self_add_user(
		$args, $pass_twice=null, $email_required=null
	) {
		if ($this->store_is_logged_in())
			return [AdminStoreError::USER_ALREADY_LOGGED_IN];
		return $this->adm_add_user(
			$args, $pass_twice, true, $email_required);
	}

	/**
	 * Passwordless self-registration.
	 *
	 * This byway registration doesn't differ sign in and sign up.
	 * Use this with caution, e.g. with proper authentication via
	 * OAuth*, SMTP or the like. Unlike add user with password, this
	 * also returns `sid` to associate `session.sid` with a column
	 * on different table.
	 *
	 * @param array $args Dict of the form:
	 *     @code
	 *     (dict){
	 *       'service': (dict){
	 *         'uname': (string uname),
	 *         'uservice': (string uservice)
	 *       }
	 *     }
	 *     @endcode
	 *     This can be added to `$args` parameter of route callback.
	 *
	 * @return array An array of the form:
	 *     @code
	 *     (array)[
	 *       (int errno),
	 *       (dict){
	 *         'uid': (int uid),
	 *         'uname': (string uname),
	 *         'token': (string token)
	 *         'sid': (int sid),
	 *       }
	 *     ]
	 *     @endcode
	 */
	public function adm_self_add_user_passwordless($args) {
		if ($this->store_is_logged_in())
			return [AdminStoreError::USER_ALREADY_LOGGED_IN];

		# check vars
		if (!isset($args['service']))
			return [AdminStoreError::DATA_INCOMPLETE];
		$service = Common::check_idict($args['service'],
			['uname', 'uservice']);
		if (!$service)
			return [AdminStoreError::DATA_INCOMPLETE];
		extract($service);

		$dbuname = '+' . $uname . ':' . $uservice;
		$check = $this->store->query(
			"SELECT uid FROM udata WHERE uname=? LIMIT 1",
			[$dbuname]);
		if ($check) {
			$uid = $check['uid'];
		} else {
			$uid = $this->store->insert("udata", [
				'uname' => $dbuname,
			], 'uid');
		}

		# token generation is a little different
		$token = $this->generate_secret(
			$dbuname . time(), $uname);

		# explicitly use byway expiration, default column value
		# is strictly for standard expiration
		$date_expire = $this->store->query(
			sprintf(
				"SELECT %s AS date_expire",
				$this->store->stmt_fragment(
					'datetime',
					['delta' => $this->byway_expiration])
				)
			)['date_expire'];

		# insert
		$sid = $this->store->insert('usess', [
			'uid' => $uid,
			'token' => $token,
			'expire' => $date_expire,
		], 'sid');

		# use token for next request
		$this->logger->info("Zapmin: usradd: OK : '$dbuname'.");
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
	 * @param array $args Dict with key: `uid`.
	 * @param function $callback_authz A function that takes one
	 *     parameter `$callback_args` to allow deletion to proceed.
	 *     Defaults to current user being root, or self-deletion for
	 *     non-root user.
	 * @param array $callback_param Parameter to pass to
	 *     `$callback_authz`.
	 */
	public function adm_delete_user(
		$args, $callback_authz=null, $callback_param=null
	) {
		if (!$this->store_is_logged_in())
			return [AdminStoreError::USER_NOT_LOGGED_IN];
		$user_data = $this->user_data;

		if (!isset($args['post']))
			return [AdminStoreError::DATA_INCOMPLETE];
		if (!Common::check_idict($args['post'], ['uid']))
			return [AdminStoreError::DATA_INCOMPLETE];
		extract($args['post']);

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
			return [AdminStoreError::USER_NOT_AUTHORIZED];

		# cannot delete root
		if ($uid == 1)
			return [AdminStoreError::USER_NOT_AUTHORIZED];

		if (!$this->store->query(
			"SELECT uid FROM udata WHERE uid=? LIMIT 1",
			[$uid])
		) {
			# user does not exist
			$this->logger->warning(sprintf(
				"Zapmin: usrdel: not found: uid=%s.",
				$uid
			));
			return [AdminStoreError::USER_NOT_FOUND];
		}

		# delete user data and its related session history via
		# foreign key constraint
		$this->store->delete('udata', ['uid' => $uid]);

		# in case of self-delete, router must send redirect header
		# or location.reload from the client side
		$this->logger->info(sprintf(
			"Zapmin: usrdel successful: uid=%s.",
			$uid
		));
		return [0];
	}

	/**
	 * List all users.
	 *
	 * @param array $args Dict with keys: `page`, `limit`, `order`
	 *     where `order` is `ASC` or `DESC`.
	 * @param function $callback_authz A function that takes one
	 *     parameter `$callback_param` to decide if listing is
	 *     allowed. Defaults to current user being root.
	 * @param array $callback_param Parameter to pass to
	 *     `$callback_authz`.
	 */
	public function adm_list_user(
		$args, $callback_authz=null, $callback_param=null
	) {
		$this->init();
		if (!$callback_authz) {
			$this->store_is_logged_in();
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
			return [AdminStoreError::USER_NOT_AUTHORIZED];

		extract($args['post']);

		$page = isset($page) ? (int)$page : 0;
		if ($page < 0)
			$page = 0;

		$limit = isset($limit) ? (int)$limit : 10;
		if ($limit <= 0 || $limit >= 40)
			$limit = 10;

		$offset = $page * $limit;

		if (!isset($order) || !in_array($order, ['ASC', 'DESC']))
			$order = '';

		// @note MySQL doesn't support '?' placeholder for limit and
		// offset.
		$stmt = sprintf(
			"SELECT uid, uname, fname, site, since " .
			"FROM udata ORDER BY uid %s LIMIT %s OFFSET %s",
		$order, $limit, $offset);

		return [0, $this->store->query($stmt, [], true)];
	}
}

