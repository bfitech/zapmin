<?php


namespace BFITech\ZapAdmin;


/**
 * AdminStorePrepare class.
 *
 * This takes care of getting and setting attributes used to load
 * current user login status based on CGI variables and database
 * backends.
 *
 * For clarity, router-exposed public methods must be prefixed with
 * `adm_*` in this class and its subclasses.
 */
abstract class AdminStorePrepare extends AdminStoreInit {

	/**
	 * Set user token.
	 *
	 * Token can be obtained from cookie or custom header.
	 */
	public function adm_set_user_token(string $user_token=null) {
		$this->init();
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
	public function adm_set_byway_expiration(int $expiration) {
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
	 * Retrieve session token name.
	 *
	 * Useful for e.g. setting cookie name or HTTP request header
	 * on the client side.
	 */
	public function adm_get_token_name() {
		return $this->token_name;
	}

	/**
	 * Set sesion token name.
	 *
	 * @param string $token_name Session token name.
	 */
	public function adm_set_token_name(string $token_name) {
		$this->token_name = $token_name;
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

}
