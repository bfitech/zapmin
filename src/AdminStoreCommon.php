<?php


namespace BFITech\ZapAdmin;



/**
 * AdminStoreCommon class.
 *
 * This contains common stateless utilities.
 */
class AdminStoreCommon {

	/**
	 * Create hashed password.
	 *
	 * @param string $uname Username.
	 * @param string $upass Password.
	 * @param string $usalt Salt.
	 */
	public static function hash_password($uname, $upass, $usalt) {
		// @codeCoverageIgnoreStart
		if (strlen($usalt) > 16)
			$usalt = substr($usalt, 0, 16);
		// @codeCoverageIgnoreEnd
		return self::generate_secret($upass . $uname, $usalt);
	}

	/**
	 * Verify plaintext password.
	 *
	 * Used by password change, registration, and derivatives
	 * like password reset, etc.
	 *
	 * @param string $pass1 First password.
	 * @param string $pass2 Second password.
	 */
	public static function verify_password($pass1, $pass2) {
		$pass1 = trim($pass1);
		$pass2 = trim($pass2);
		# type twice the same
		if ($pass1 != $pass2)
			return AdminStoreError::PASSWORD_NOT_SAME;
		# must be longer than 3
		if (strlen($pass1) < 4)
			return AdminStoreError::PASSWORD_TOO_SHORT;
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
	public static function generate_secret(
		$data, $key=null, $length=64
	) {
		if (!$key)
			$key = uniqid() . (string)mt_rand();
		$bstr = $data . $key;
		$bstr = hash_hmac('sha256', $bstr, $key, true);
		$bstr = base64_encode($bstr);
		$bstr = str_replace(['/', '+', '='], '', $bstr);
		return substr($bstr, 0, $length);
	}

	/**
	 * Verify site url
	 *
	 * @param string $url Site URL.
	 */
	public static function verify_site_url($url) {
		$url = trim($url);
		if (!$url || strlen($url) > 64)
			return false;
		return filter_var($url, FILTER_VALIDATE_URL);
	}

	/**
	 * Verify email address.
	 *
	 * @param string $email Email address.
	 */
	public static function verify_email_address($email) {
		$email = trim($email);
		if (!$email || strlen($email) > 64)
			return false;
		return filter_var($email, FILTER_VALIDATE_EMAIL);
	}

}
