<?php declare(strict_types=1);


namespace BFITech\ZapAdmin;


use BFITech\ZapCore\Common;
use BFITech\ZapStore\SQLError;

/**
 * AuthManage class.
 */
class AuthManage extends Auth {

	/**
	 * Default method to decide if adding new user is allowed.
	 *
	 * This succeeds if current user is root.
	 */
	public function authz_add() {
		return $this->get_user_data()['uid'] == 1;
	}

	/**
	 * Verify username of new user.
	 */
	private function _add_verify_name(string $addname) {
		$log = self::$logger;

		# check name, allow multi-byte chars but not whitespace
		if (strlen($addname) > 64) {
			# max 64 chars
			$log->warning(
				"Zapmin: usradd: name invalid: '$addname'.");
			return Error::USERNAME_TOO_LONG;
		}
		foreach([" ", "\n", "\r", "\t"] as $white) {
			# never allow whitespace in the middle
			if (strpos($addname, $white) !== false) {
				$log->warning(
					"Zapmin: usradd: name invalid: '$addname'.");
				return Error::USERNAME_HAS_WHITESPACE;
			}
		}
		if ($addname[0] == '+') {
			# leading '+' is reserved for passwordless accounts
			$log->warning(
				"Zapmin: usradd: name invalid: '$addname'.");
			return Error::USERNAME_LEADING_PLUS;
		}

		return 0;
	}

	/**
	 * Verify email of new user.
	 */
	private function _add_verify_email(
		string $email, string $addname
	) {
		$log = self::$logger;

		if (!Utils::verify_email_address($email)) {
			$log->warning(sprintf(
				"Zapmin: usradd: email invalid: '%s' <- '%s'.",
				$addname, $email));
			return Error::EMAIL_INVALID;
		}

		if (self::$admin::$store->query(
			"SELECT uid FROM udata WHERE email=? LIMIT 1",
			[$email])
		) {
			$log->warning(sprintf(
				"Zapmin: usradd: email exists: '%s' <- '%s'.",
				$addname, $email));
			return Error::EMAIL_EXISTS;
		}

		return 0;
	}

	/**
	 * Register a new user.
	 *
	 * @param array $args Dict with keys: `addname`, `addpass1`, and
	 *     optional `addpass2` unless `$pass_twice` is set to true.
	 * @param bool $pass_twice Whether password must be entered twice.
	 * @param bool $allow_self_register Whether self-registration is
	 *     allowed.
	 * @param bool $email_required Whether email address is mandatory.
	 *
	 * @if TRUE
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @endif
	 */
	final public function add(
		array $args, bool $pass_twice=false,
		bool $allow_self_register=false, bool $email_required=false
	) {
		$log = self::$logger;

		# check permission
		if ($this->is_logged_in()) {
			if (!$this->authz_add())
				return [Error::USER_NOT_AUTHORIZED];
		} elseif (!$allow_self_register) {
			# self-registration not allowed
			return [Error::SELF_REGISTER_NOT_ALLOWED];
		}

		# check vars
		$keys = ['addname', 'addpass1'];
		if ($pass_twice)
			$keys[] = 'addpass2';
		if ($email_required)
			$keys[] = 'email';

		$args = Common::check_idict($args, $keys);
		if (!$args)
			return [Error::DATA_INCOMPLETE];
		$addname = $addpass1 = $addpass2 = $email = null;
		extract($args);

		$udata = [];

		# verify new usename
		if (0 !== $ret = $this->_add_verify_name($addname))
			return [$ret];
		$udata['uname'] = $addname;

		# verify email address
		if ($email_required) {
			if (0 !== $ret = $this->_add_verify_email(
				$email, $addname)
			)
				return [$ret];
			$udata['email'] = $email;
		}

		# if password is only required once
		if (!$pass_twice)
			$addpass2 = $addpass1;

		# verify password
		if (0 !== $ret = Utils::verify_password($addpass1, $addpass2)) {
			$log->warning(
				"Zapmin: usradd: passwd invalid: '$addname'.");
			return [$ret];
		}

		# generate hashes
		$udata['usalt'] = Utils::generate_secret(
			$addname . $addpass1, null, 16);
		$udata['upass'] = Utils::hash_password(
			$addname, $addpass1, $udata['usalt']);

		# insert
		try {
			$uid = self::$admin::$store->insert('udata', $udata, 'uid');
		} catch(SQLError $e) {
			# user exists
			$log->info("Zapmin: usradd: user exists: '$addname'.");
			return [Error::USERNAME_EXISTS];
		}

		# success
		$log->info("Zapmin: usradd: OK: $uid:'$addname'.");
		return [0];
	}

	/**
	 * Self-register.
	 *
	 * @param array $args Dict with keys: `addname`, `addpass1`,
	 *     and optional `addpass2` unless `$pass_twice` is set to true.
	 * @param bool $pass_twice Whether password must be entered twice.
	 * @param bool $email_required Whether an email address must be
	 *     provided.
	 *
	 * @note This is just a special case of add() with
	 *     additional condition: user must not be authenticated.
	 */
	final public function self_add(
		array $args, bool $pass_twice=false, bool $email_required=false
	) {
		if ($this->is_logged_in())
			return [Error::USER_ALREADY_LOGGED_IN];
		return $this->add(
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
	 * @param array $args Dict with keys: `uname`, `uservice`. Ensure
	 *     a combination of `uname` and `uservice` is unique for each
	 *     user.
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
	final public function self_add_passwordless(array $args) {
		if ($this->is_logged_in())
			return [Error::USER_ALREADY_LOGGED_IN];

		# check vars
		$service = Common::check_idict($args, ['uname', 'uservice']);
		if (!$service)
			return [Error::DATA_INCOMPLETE];
		$uname = $uservice = null;
		extract($service);

		$sql = self::$admin::$store;

		$dbuname = sprintf('+%s:%s', $uname, $uservice);
		$check = $sql->query("
			SELECT uid FROM udata WHERE uname=?
		", [$dbuname]);

		$uid = $check
			? $check['uid']
			: $sql->insert("udata", ['uname' => $dbuname], 'uid');

		# token generation is a little different
		$token = Utils::generate_secret($dbuname . uniqid(), $uname);

		# get expiration time from sql engine
		$date_expire = $sql->query(
			sprintf(
				"SELECT %s AS date_expire",
				$sql->stmt_fragment(
					'datetime',
					['delta' => self::$admin->get_expiration()])
				)
			)['date_expire'];

		# insert
		$sid = $sql->insert('usess', [
			'uid' => $uid,
			'token' => $token,
			'expire' => $date_expire,
		], 'sid');

		# use token for next request
		self::$logger->info("Zapmin: usradd: OK: $uid:'$dbuname'.");
		return [0, [
			'uid' => $uid,
			'uname' => $dbuname,
			'token' => $token,
			'sid' => $sid,
		]];
	}

	/**
	 * Default method to decide if user deletion is allowed.
	 *
	 * This succeeds if current user is root, or if it's a case of
	 * self-deletion for non-root user.
	 *
	 * @param int $uid User ID to delete.
	 */
	public function authz_delete(int $uid) {
		$udata = $this->get_user_data();
		if ($udata['uid'] == 1 && $uid != 1)
			return true;
		if ($udata['uid'] == $uid)
			return true;
		return false;
	}

	/**
	 * Delete a user.
	 *
	 * @param array $args Dict with keys: `uid`.
	 */
	final public function delete(array $args) {
		$log = $this::$logger;
		$sql = self::$admin::$store;

		if (!$this->is_logged_in())
			return [Error::USER_NOT_LOGGED_IN];

		if (!Common::check_idict($args, ['uid']))
			return [Error::DATA_INCOMPLETE];
		$uid = 0;
		extract($args);

		if (!$this->authz_delete(intval($uid)))
			return [Error::USER_NOT_AUTHORIZED];

		# cannot delete root
		if ($uid == 1)
			return [Error::USER_NOT_AUTHORIZED];

		if (!$sql->query(
			"SELECT uid FROM udata WHERE uid=? LIMIT 1",
			[$uid])
		) {
			# user does not exist
			$log->warning("Zapmin: usrdel: not found: uid=$uid.");
			return [Error::USER_NOT_FOUND];
		}

		# delete user data and its related session history via
		# foreign key constraint
		$sql->delete('udata', ['uid' => $uid]);

		# in case of self-delete, router must send redirect header
		# or location.reload from the client side
		$log->info("Zapmin: usrdel successful: uid=$uid.");
		return [0];
	}

	/**
	 * Default method to decide if user listing is allowed.
	 *
	 * This succeeds if current user is root.
	 */
	public function authz_list() {
		return $this->authz_add();
	}

	/**
	 * List all users.
	 *
	 * @param array $args Dict with keys: `page`, `limit`, `order`
	 *     where `order` is `ASC` or `DESC`.
	 */
	final public function list(array $args) {
		if (!$this->is_logged_in())
			return [Error::USER_NOT_LOGGED_IN];

		if (!$this->authz_list())
			return [Error::USER_NOT_AUTHORIZED];

		$page = $limit = 0;
		$order = '';
		extract($args);

		$page = intval($page);
		if ($page < 0)
			$page = 0;

		$limit = intval($limit);
		if ($limit <= 0 || $limit >= 40)
			$limit = 10;

		$offset = $page * $limit;

		if (!in_array($order, ['ASC', 'DESC']))
			$order = '';

		// @note MySQL doesn't support '?' placeholder for limit and
		// offset.
		$stmt = sprintf("
			SELECT uid, uname, fname, site, since
			FROM udata ORDER BY uid %s LIMIT %s OFFSET %s
		", $order, $limit, $offset);
		return [0, self::$admin::$store->query($stmt, [], true)];
	}

}
