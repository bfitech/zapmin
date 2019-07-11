<?php


namespace BFITech\ZapAdmin;



/**
 * Utils class.
 *
 * This contains common stateless utilities.
 */
class Utils {

	/**
	 * Create hashed password.
	 *
	 * @param string $uname Username.
	 * @param string $upass Password.
	 * @param string $usalt Salt.
	 *
	 * @return string Hash password.
	 */
	public static function hash_password(
		string $uname, string $upass, string $usalt
	) {
		// @codeCoverageIgnoreStart
		if (strlen($usalt) > 16)
			$usalt = substr($usalt, 0, 16);
		// @codeCoverageIgnoreEnd
		return static::generate_secret($upass . $uname, $usalt);
	}

	/**
	 * Verify plaintext password.
	 *
	 * Used by password change, registration, and derivatives
	 * like password reset, etc.
	 *
	 * @param string $pass1 First password.
	 * @param string $pass2 Second password.
	 *
	 * @return int Errno.
	 */
	public static function verify_password(
		string $pass1, string $pass2
	) {
		$pass1 = trim($pass1);
		$pass2 = trim($pass2);
		# type twice the same
		if (!hash_equals($pass1, $pass2))
			return Error::PASSWORD_NOT_SAME;
		# must be longer than 3
		if (strlen($pass1) < 4)
			return Error::PASSWORD_TOO_SHORT;
		return 0;
	}

	/**
	 * Generate salt.
	 *
	 * @param string $data Input data.
	 * @param string $key HMAC key.
	 * @param int $length Maximum length of generated salt. Normal
	 *     usage is 16 for user salt and 64 for hashed password.
	 *
	 * @return string Secret key.
	 */
	public static function generate_secret(
		string $data, string $key=null, int $length=64
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
	 *
	 * @return string|bool Filtered data, or FALSE if the filter fails.
	 */
	public static function verify_site_url(string $url) {
		$url = trim($url);
		if (!$url || strlen($url) > 64)
			return false;
		return filter_var($url, FILTER_VALIDATE_URL);
	}

	/**
	 * Verify email address.
	 *
	 * @param string $email Email address.
	 *
	 * @return string|bool Filtered data, or FALSE if the filter fails.
	 */
	public static function verify_email_address(string $email) {
		$email = trim($email);
		if (!$email || strlen($email) > 64)
			return false;
		return filter_var($email, FILTER_VALIDATE_EMAIL);
	}

}
