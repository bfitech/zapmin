<?php declare(strict_types=1);


namespace BFITech\ZapAdmin;


use BFITech\ZapCore\Router;


/**
 * Default routing implementation.
 *
 * See each underlying method documentation for more precise control.
 * By convention, routing method is always prefixed `route_*`.
 *
 * @see WebDefault for sample dispatcher.
 *
 * @if TRUE
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @endif
 */
class RouteDefault extends Route {

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
		return static::$core->pj(
			self::$ctrl->get_safe_user_data(), 401);
	}

	/**
	 * `POST: /login`
	 *
	 * Sample implementation of signing in. If source of authentication
	 * token is cookie, don't forget to send the cookie on success.
	 **/
	public function route_login(array $args) {
		$ctrl = self::$ctrl;
		$retval = $ctrl->login($args['post']);
		if ($retval[0] === 0)
			self::$core->send_cookie(
				$this->token_name, $retval[1]['token'],
				time() + $ctrl::$admin->get_expiration(), '/');
		return self::$core->pj($retval);
	}

	/**
	 * `GET|POST: /logout`
	 *
	 * Sample implementation of signing out. If source of authentication
	 * token is cookie, don't forget to destroy the cookie on success.
	 **/
	public function route_logout() {
		$retval = self::$ctrl->logout();
		if ($retval[0] === 0)
			self::$core->send_cookie(
				$this->token_name, '', time() - (3600 * 48), '/');
		return self::$core->pj($retval);
	}

	/**
	 *
	 * `POST: /chpasswd`
	 *
	 * Sample implementation of changing password.
	 **/
	public function route_chpasswd(array $args) {
		return self::$core->pj(
			self::$ctrl->change_password($args['post'], true));
	}

	/**
	 * `POST: /chbio`
	 *
	 * Sample implementation of changing bio.
	 **/
	public function route_chbio(array $args) {
		return self::$core->pj(self::$ctrl->change_bio($args['post']));
	}

	/**
	 * `POST: /register`
	 *
	 * Sample implementation of user registration.
	 **/
	public function route_register(array $args) {
		$core = self::$core;
		$retval = self::$manage->self_add($args['post'], true, true);
		if ($retval[0] !== 0)
			# fail
			return $core->pj($retval);
		# success, autologin
		$args['post']['uname'] = $args['post']['addname'];
		$args['post']['upass'] = $args['post']['addpass1'];
		$retval = self::$ctrl->login($args['post']);
		$core->send_cookie(
			$this->token_name, $retval[1]['token'],
			time() + $this->expiration, '/');
		return $core->pj($retval);
	}

	/**
	 *
	 * `POST: /useradd`
	 *
	 * Sample implementation of user addition.
	 **/
	public function route_useradd(array $args) {
		return self::$core->pj(
			self::$manage->add($args['post'], false, true, true), 403);
	}

	/**
	 *
	 * `DELETE: /userdel`
	 *
	 * Sample implementation of user deletion.
	 **/
	public function route_userdel(array $args) {
		return self::$core->pj(
			self::$manage->delete($args['post']), 403);
	}

	/**
	 * `GET: /userlist`
	 *
	 * Sample implementation of user listing.
	 **/
	public function route_userlist(array $args) {
		return self::$core->pj(
			self::$manage->list($args['get']), 403);
	}

	/**
	 * `GET|POST: /byway`
	 *
	 * Sample implementation of byway routing.
	 *
	 * @note
	 * - This is a mock method. Real method must manipulate `$args`
	 *   into containing `service` key that is not sent by client,
	 *   but by 3rd-party.
	 * - Since version 2, there's no longer difference of expiration
	 *   between regular and byway. To set different values of
	 *   expiration, use separate instance of Admin.
	 */
	public function route_byway(array $args) {
		$core = self::$core;
		$retval = self::$manage->self_add_passwordless($args['post']);
		if ($retval[0] !== 0)
			return $core->pj($retval, 403);
		# alway autologin on success
		$token = $retval[1]['token'];
		self::$ctrl->set_token_value($token);
		$core->send_cookie(
			$this->token_name, $token,
			time() + self::$admin->get_expiration(), '/'
		);
		return $core->pj($retval);
	}

}
