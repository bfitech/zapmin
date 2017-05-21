<?php


namespace BFITech\ZapAdmin;


/**
 * Error Class
 */
class AdminStoreError extends \Exception {

	/** Cannot delete root. */
	const CANNOT_DELETE_ROOT = 0x0100;

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

	/** Users not signed in. */
	const USERS_NOT_LOGGED_IN = 0x03;
	/** Users already signed in. */
	const USERS_ALREADY_LOGGED_IN = 0x0301;
	/** Users not found. */
	const USERS_NOT_FOUND = 0x0302;
	/** Users not authorized. */
	const USERS_NOT_AUTHORIZED = 0x0305;

	/** Self-registration not allowed. */
	const SELF_REGISTER_NOT_ALLOWED = 0x04;

	/** Missing arguments post. */
	const MISSING_POST_ARGS = 0x05;
	/** Missing arguments service. */
	const MISSING_SERVICE_ARGS = 0x0500;
	/** Missing dict from post args. */
	const MISSING_DICT = 0x0501;
	/** Invalid site URL. */
	const INVALID_SITE_URL = 0x0502;
	/** Invalid name: too long. */
	const NAME_TOO_LONG = 0x0503;
	/** Invalid name: contain whitespace. */
	const NAME_CONTAIN_WHITESPACE = 0x0504;
	/** Invalid name: leading '+' reserved for passwordless account. */
	const NAME_LEADING_PLUS = 0x0505;
	/** Invalid email address. */
	const INVALID_EMAIL = 0x0506;
	/** Email already exists. */
	const EMAIL_EXISTS = 0x0507;
	/** User already exists. */
	const USERS_EXISTS = 0x0508;
}

