<?php


namespace BFITech\ZapAdmin;


use BFITech\ZapCore\Router;


/**
 * Default routes.
 *
 * See each method documentation for more precise control.
 *
 * ### Example:
 * @code
 * # index.php
 *
 * namespace BFITech\ZapAdmin;
 *
 * use BFITech\ZapCore\Logger;
 * use BFITech\ZapCore\Router as Core;
 * use BFITech\ZapStore\SQLite3;
 *
 * $logger = new Logger;
 *
 * $store = new SQLite3(['dbname' => '/tmp/zapmin.sq3'], $logger);
 * $admin = new Admin($store, $logger);
 *
 * $ctrl = new AuthCtrl($admin, $logger);
 * $manage = new AuthManage($admin, $logger);
 *
 * $core = new Core($logger);
 *
 * $web = new RouteDefault($core, $ctrl, $manage);
 * $web->run();
 * // $adm->route('/status', [$adm, 'route_status'], 'GET');
 *
 * # run it with something like `php -S 0.0.0.0:8000`
 * @endcode
 *
 * @SuppressWarnings(PHPMD)
 */
class RouteDefault extends Route {

	public static $core;
	public static $admin;
	public static $ctrl;
	public static $manage;

	public function __construct(
		Router $core, AuthCtrl $ctrl, AuthManage $manage
	) {
		self::$core = $core;
		self::$ctrl = $ctrl;
		self::$manage = $manage;
		self::$admin = $ctrl::$admin;

		parent::__construct($core, $ctrl);
	}

	/** `GET: /` */
	public function route_home() {
		echo '<h1>It wurks!</h1>';
	}

	/** `GET: /status` */
	public function route_status() {
		return static::$core->pj(
			static::$ctrl->get_safe_user_data(), 401);
	}

	/** `POST: /login` */
	public function route_login(array $args) {
		$ctrl = static::$ctrl;
		$retval = $ctrl->login($args);
		if ($retval[0] === 0)
			static::$core->send_cookie(
				$this->token_name, $retval[1]['token'],
				time() + $ctrl::$admin->get_expiration(), '/');
		return static::$core->pj($retval);
	}

	/** `GET|POST: /logout` */
	public function route_logout(array $args) {
		$retval = static::$ctrl->logout($args);
		if ($retval[0] === 0)
			static::$core->send_cookie(
				$this->token_name, '', time() - (3600 * 48), '/');
		return static::$core->pj($retval);
	}

	/** `POST: /chpasswd` */
	public function route_chpasswd(array $args) {
		return static::$core->pj(
			static::$ctrl->change_password($args, true));
	}

	/** `POST: /chbio` */
	public function route_chbio(array $args) {
		return static::$core->pj(static::$ctrl->change_bio($args));
	}

	/** `POST: /register` */
	public function route_register(array $args) {
		$core = static::$core;
		$retval = static::$manage->self_add($args, true, true);
		if ($retval[0] !== 0)
			# fail
			return $core->pj($retval);
		# success, autologin
		$args['post']['uname'] = $args['post']['addname'];
		$args['post']['upass'] = $args['post']['addpass1'];
		$retval = $this->ctrl->login($args);
		$core->send_cookie(
			$this->token_name, $retval[1]['token'],
			time() + $this->expiration, '/');
		return $this->core->pj($retval);
	}

	/** `POST: /useradd` */
	public function route_useradd(array $args) {
		return static::$core->pj(
			static::$manage->add($args, false, true, true), 403);
	}

	/** `POST: /userdel` */
	public function route_userdel(array $args) {
		return static::$core->pj($this->manage->delete($args), 403);
	}

	/** `POST: /userlist` */
	public function route_userlist(array $args) {
		return static::$core->pj($this->manage->list($args), 403);
	}

	/**
	 * `GET|POST: /byway`
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
		$core = static::$core;
		### start mock
		if (isset($args['post']['service']))
			$args['service'] = $args['post']['service'];
		### end mock
		$retval = static::$manage->self_add_passwordless($args);
		if ($retval[0] !== 0)
			return $core->pj($retval, 403);
		# alway autologin on success
		$token = $retval[1]['token'];
		static::$ctrl->set_token_value($token);
		$core->send_cookie(
			$this->token_name, $token,
			time() + static::$admin->get_expiration(), '/'
		);
		return $core->pj($retval);
	}

}
