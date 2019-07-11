<?php declare(strict_types=1);


namespace BFITech\ZapAdmin;


use BFITech\ZapCore\Common;
use BFITech\ZapStore\SQLError;


/**
 * AuthCtrl class.
 */
class AuthCtrl extends Auth {

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
	final public function login(array $args) {
		$logger = self::$logger;

		if ($this->is_logged_in())
			return [Error::USER_ALREADY_LOGGED_IN];

		if (!$args)
			return [Error::DATA_INCOMPLETE];

		if (!Common::check_idict($args, ['uname', 'upass']))
			return [Error::DATA_INCOMPLETE];
		extract($args);

		$sql = self::$admin::$store;

		$usalt = $sql->query(
			"SELECT usalt FROM udata WHERE uname=? LIMIT 1",
			[$uname]);
		if (!$usalt)
			# user not found
			return [Error::USER_NOT_FOUND];
		$usalt = $usalt['usalt'];

		$udata = self::$admin->match_password($uname, $upass, $usalt);
		if (!$udata) {
			# wrong password
			$logger->warning(
				"Zapmin: login: wrong password: '$uname'.");
			return [Error::WRONG_PASSWORD];
		}

		// generate token
		$token = Utils::generate_secret(
			$upass . $usalt . time(), $usalt);

		$sid = $sql->insert('usess', [
			'uid'   => $udata['uid'],
			'token' => $token,
		], 'sid');
		if ($sql->get_dbtype() == 'mysql') {
			// mysql has no parametrized default values, and can't
			// invoke trigger on currently inserted table
			$expire_at = $sql->stmt_fragment('datetime', [
				'delta' => self::$admin->get_expiration()]);
			$sql->query_raw(sprintf(
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
	 *
	 * @return array Errno.
	 */
	final public function logout() {
		if (!$this->is_logged_in())
			return [Error::USER_NOT_LOGGED_IN];

		# this just close sessions with current sid, whether it exists
		# or not, including the case of account self-deletion
		$this->close_session(intval($this->get_user_data()['sid']));

		# reset status
		$this->reset();

		# router must set appropriate cookie

		self::$logger->info(sprintf(
			"Zapmin: logout: OK: '%s'.",
			$this->get_user_data()['uname']
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
	 *
	 * @return array Errno.
	 */
	public function change_password(
		array $args, bool $with_old_password=null
	) {
		$uid = $pass0 = $pass1 = $pass2 = null;
		if (!$this->is_logged_in())
			return [Error::USER_NOT_LOGGED_IN];
		extract($this->get_user_data());

		$log = self::$logger;

		if (!$usalt)
			# passwordless has no salt
			return [Error::USER_NOT_FOUND];

		$keys = ['pass1', 'pass2'];
		if ($with_old_password)
			$keys[] = 'pass0';

		$args = Common::check_idict($args, $keys);
		if (!$args)
			return [Error::DATA_INCOMPLETE];
		extract($args);

		# check old password if applicable
		if (
			$with_old_password &&
			!self::$admin->match_password($uname, $pass0, $usalt)
		) {
			$log->warning(
				"Zapmin: chpasswd: old passwd invalid: '$uname'.");
			return [Error::OLD_PASSWORD_INVALID];
		}

		$verify_password = Utils::verify_password($pass1, $pass2);
		if ($verify_password !== 0) {
			$log->warning(
				"Zapmin: chpasswd: new passwd invalid: '$uname'.");
			return [Error::PASSWORD_INVALID,
				$verify_password];
		}

		# update
		self::$admin::$store->update('udata', [
			'upass' => Utils::hash_password($uname, $pass1, $usalt),
		], [
			'uid' => $uid,
		]);

		# success
		$log->info("Zapmin: chpasswd: OK: '$uname'.");
		return [0];
	}

	/**
	 * Change user info.
	 *
	 * @param array $args Dict with keys: `fname`, `site`.
	 *
	 * @return array Errno.
	 */
	public function change_bio(array $args) {
		if (!$this->is_logged_in())
			return [Error::USER_NOT_LOGGED_IN];

		$vars = [];
		foreach (['fname', 'site'] as $key) {
			if (!isset($args[$key]))
				continue;
			$val = trim($args[$key]);
			if (!$val)
				continue;
			$vars[$key] = $val;
		}
		if (!$vars)
			# no change
			return [0];
		$site = '';
		extract($vars);

		$log = self::$logger;

		# verify site url value
		if ($site && !Utils::verify_site_url($site)) {
			$log->warning(
				"Zapmin: chbio: site URL invalid: '$site'.");
			return [Error::SITEURL_INVALID];
		}

		# update database
		$udata = $this->get_user_data();
		$token = $udata['token'];
		self::$admin::$store->update(
			'udata', $vars, ['uid' => $udata['uid']]);

		# empty redis cache; let it lazy-reloaded by get_user_data
		self::$admin->cache_del($token);

		# reset user data but not user token
		$this->reset();
		$this->set_token_value($token);
		$udata = $this->get_user_data();

		# ok
		$log->info("Zapmin: chbio: OK: '${udata['uname']}.");
		return [0];
	}

}
