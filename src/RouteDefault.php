<?php declare(strict_types=1);


namespace BFITech\ZapAdmin;


use BFITech\ZapCore\Router;


/**
 * Default routing implementation.
 *
 * See each underlying method documentation of AuthCtrl and AuthManage
 * for more precise control. By convention, routing method is always
 * prefixed `route_*`. Change request method as you see fit on your
 * own implementation, e.g. `DELETE` for RouteDefault::route_userdel.
 *
 * @see WebDefault for sample dispatcher.
 *
 * @if TRUE
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @endif
 */
class RouteDefault extends RouteAdmin {

	/** Router instance. */
	public static $core;
	/** Admin instance. */
	public static $admin;
	/** AuthCtrl instance. */
	public static $ctrl;
	/** AuthManage instance. */
	public static $manage;

	/**
	 * Constructor.
	 *
	 * @param Router $core Router instance.
	 * @param AuthCtrl $ctrl AuthCtrl instance.
	 * @param AuthManage $manage AuthManage instance.
	 */
	public function __construct(
		Router $core, AuthCtrl $ctrl, AuthManage $manage
	) {
		self::$core = $core;
		self::$ctrl = $ctrl;
		self::$manage = $manage;
		self::$admin = $ctrl::$admin;

		parent::__construct($core, $ctrl, $manage);
	}

	/**
	 * `GET: /`
	 *
	 * Sample homepage.
	 **/
	public function route_home() {
		echo '<h1>It wurks!</h1>';
	}

	/**
	 * `GET: /status`
	 *
	 * Sample implementation of user status.
	 **/
	public function route_status() {
		return self::$core::pj(self::$ctrl->get_safe_user_data(), 401);
	}

	/**
	 * `POST: /login`
	 *
	 * Sample implementation of signing in. If source of authentication
	 * token is cookie, don't forget to send the cookie on success.
	 **/
	public function route_login(array $args) {
		$core = self::$core;
		$ctrl = self::$ctrl;
		$retval = $ctrl->login($args['post']);
		if ($retval[0] === 0) {
			$token = $retval[1]['token'];
			$expires = time() + $ctrl::$admin->get_expiration();
			$core::send_cookie_with_opts($this->token_name, $token, [
				'path' => '/',
				'expires' => $expires,
				'httponly' => true,
				'samesite' => 'Lax',
			]);
		}
		return $core::pj($retval);
	}

	/**
	 * `GET|POST: /logout`
	 *
	 * Sample implementation of signing out. If source of authentication
	 * token is cookie, don't forget to destroy the cookie on success.
	 **/
	public function route_logout() {
		$core = self::$core;
		$retval = self::$ctrl->logout();
		if ($retval[0] === 0) {
			$core::send_cookie_with_opts($this->token_name, '', [
				'path' => '/',
				'expires' => 1,
				'httponly' => true,
				'samesite' => 'Lax',
			]);
		}
		return $core::pj($retval);
	}

	/**
	 *
	 * `POST: /chpasswd`
	 *
	 * Sample implementation of changing password.
	 **/
	public function route_chpasswd(array $args) {
		return self::$core::pj(
			self::$ctrl->change_password($args['post'], true));
	}

	/**
	 * `POST: /chbio`
	 *
	 * Sample implementation of changing bio.
	 **/
	public function route_chbio(array $args) {
		return self::$core::pj(self::$ctrl->change_bio($args['post']));
	}

	/**
	 * `POST: /register`
	 *
	 * Sample implementation of user registration.
	 **/
	public function route_register(array $args) {
		$core = self::$core;
		$post = $args['post'];
		$retval = self::$manage->self_add($post, true, true);

		# fail
		if ($retval[0] !== 0)
			return $core::pj($retval);

		# success, autologin
		$post['uname'] = $post['addname'];
		$post['upass'] = $post['addpass1'];
		$retval = self::$ctrl->login($post);
		$token = $retval[1]['token'];
		$core::send_cookie_with_opts($this->token_name, $token, [
			'path' => '/',
			'expires' => time() + $this->expiration,
			'httponly' => true,
			'samesite' => 'Lax',
		]);
		return $core::pj($retval);
	}

	/**
	 *
	 * `POST: /useradd`
	 *
	 * Sample implementation of user addition.
	 **/
	public function route_useradd(array $args) {
		if (!self::$ctrl->get_user_data())
			return self::$core::pj([Error::USER_NOT_LOGGED_IN], 401);
		return self::$core::pj(
			self::$manage->add($args['post'], false, true, true), 403);
	}

	/**
	 *
	 * `POST: /userdel`
	 *
	 * Sample implementation of user deletion.
	 **/
	public function route_userdel(array $args) {
		if (!self::$ctrl->get_user_data())
			return self::$core::pj([Error::USER_NOT_LOGGED_IN], 401);
		return self::$core::pj(
			self::$manage->delete($args['post']), 403);
	}

	/**
	 * `GET: /userlist`
	 *
	 * Sample implementation of user listing.
	 **/
	public function route_userlist(array $args) {
		if (!self::$ctrl->get_user_data())
			return self::$core::pj([Error::USER_NOT_LOGGED_IN], 401);
		return self::$core::pj(
			self::$manage->list($args['get']), 403);
	}

	/**
	 * `POST: /byway`
	 *
	 * Sample implementation of byway routing.
	 *
	 * @note
	 * - This is a mock method. Real method must manipulate `$args`
	 *   into containing `uname` and `uservice` keys that is not
	 *   actually sent by client, but by a middleware.
	 * - Since version 2, there's no longer difference of expiration
	 *   between regular and byway. To set different values of
	 *   expiration, use separate instance of Admin.
	 */
	public function route_byway(array $args) {
		$core = self::$core;
		$retval = self::$manage->self_add_passwordless($args['post']);
		if ($retval[0] !== 0)
			return $core::pj($retval, 403);
		# alway autologin on success
		$token = $retval[1]['token'];
		self::$ctrl->set_token_value($token);
		$expires = time() + self::$admin->get_expiration();
		$core::send_cookie_with_opts($this->token_name, $token, [
			'path' => '/',
			'expires' => $expires,
			'httponly' => true,
			'samesite' => 'Lax',
		]);
		return $core::pj($retval);
	}

}
