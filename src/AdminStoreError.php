<?php


namespace BFITech\ZapAdmin;


/**
 * Error Class
 */
class AdminStoreError extends \Exception {

	/** Password Invalid. */
	const PASSWORD_INVALID = 0x0200;
	/** Password confirmation not equal with password. */
	const PASSWORD_NOT_SAME = 0x0201;
	/** Password too short. */
	const PASSWORD_TOO_SHORT = 0x0202;
	/** Old password invalid. */
	const OLD_PASSWORD_INVALID = 0x0203;
	/** Wrong password. */
	const WRONG_PASSWORD = 0x0204;

	/** User not signed in. */
	const USER_NOT_LOGGED_IN = 0x03;
	/** User already signed in. */
	const USER_ALREADY_LOGGED_IN = 0x0301;
	/** User not found. */
	const USER_NOT_FOUND = 0x0302;
	/** User not authorized. */
	const USER_NOT_AUTHORIZED = 0x0305;

	/** Self-registration not allowed. */
	const SELF_REGISTER_NOT_ALLOWED = 0x04;

	/** Incomplete data, mostly from POST request. */
	const DATA_INCOMPLETE = 0x0501;
	/** Invalid site URL. */
	const SITEURL_INVALID = 0x0502;
	/** Invalid name: too long. */
	const USERNAME_TOO_LONG = 0x0503;
	/** Invalid name: contains whitespace. */
	const USERNAME_HAS_WHITESPACE = 0x0504;
	/** Invalid name: leading '+' reserved for passwordless account. */
	const USERNAME_LEADING_PLUS = 0x0505;
	/** Invalid email address. */
	const EMAIL_INVALID = 0x0506;
	/** Email already exists. */
	const EMAIL_EXISTS = 0x0507;
	/** User already exists. */
	const USERNAME_EXISTS = 0x0508;
}
