<?php


namespace BFITech\ZapAdmin;


/**
 * Default routes.
 *
 * See each method documentation for more precise control.
 *
 * ### Example:
 * @code
 * # index.php
 *
 * use BFITech\ZapStore\SQLite3;
 * use BFITech\ZapAdmin\AdminRouteDefault;
 *
 * $store = new SQLite3(['dbname' => '/tmp/zapmin.sq3']);
 * $adm = new AdminRouteDefault($store);
 * $adm->route('/status', [$adm, 'route_status'], 'GET');
 *
 * # run it with something like `php -S 0.0.0.0:8000`
 * @endcode
 */
class AdminRouteDefault extends AdminRoute {

	/** `GET: /` */
	public function route_home($args) {
		echo '<h1>It wurks!</h1>';
	}

	/** `GET: /status` */
	public function route_status($args) {
		return $this->core->pj($this->adm_get_safe_user_data());
	}

	/** `POST: /login` */
	public function route_login($args) {
		$retval = $this->adm_login($args);
		if ($retval[0] === 0)
			$this->core->send_cookie(
				$this->adm_get_token_name(), $retval[1]['token'],
				time() + $this->adm_get_expiration(), '/');
		return $this->core->pj($retval);
	}

	/** `GET|POST: /logout` */
	public function route_logout($args) {
		$retval = $this->adm_logout($args);
		if ($retval[0] === 0)
			$this->core->send_cookie(
				$this->adm_get_token_name(), '',
				time() - (3600 * 48), '/');
		return $this->core->pj($retval);
	}

	/** `POST: /chpasswd` */
	public function route_chpasswd($args) {
		return $this->core->pj($this->adm_change_password($args, true));
	}

	/** `POST: /chbio` */
	public function route_chbio($args) {
		return $this->core->pj($this->adm_change_bio($args));
	}

	/** `POST: /register` */
	public function route_register($args) {
		$retval = $this->adm_self_add_user($args, true, true);
		if ($retval[0] !== 0)
			# fail
			return $this->core->pj($retval);
		# success, autologin
		$args['post']['uname'] = $args['post']['addname'];
		$args['post']['upass'] = $args['post']['addpass1'];
		$retval = $this->adm_login($args);
		$this->core->send_cookie(
			$this->adm_get_token_name(), $retval[1]['token'],
			time() + $this->adm_get_expiration(), '/');
		return $this->core->pj($retval);
	}

	/** `POST: /useradd` */
	public function route_useradd($args) {
		return $this->core->pj(
			$this->adm_add_user($args, false, true, true), 403);
	}

	/** `POST: /userdel` */
	public function route_userdel($args) {
		return $this->core->pj($this->adm_delete_user($args), 403);
	}

	/** `POST: /userlist` */
	public function route_userlist($args) {
		return $this->core->pj($this->adm_list_user($args), 403);
	}

	/**
	 * `GET|POST: /byway`
	 *
	 * @note
	 *     This is a mock method. Real method must manipulate `$args`
	 *     into containing `service` key that is not sent by client,
	 *     but by 3rd-party.
	 */
	public function route_byway($args) {
		### start mock
		if (isset($args['post']['service']))
			$args['service'] = $args['post']['service'];
		### end mock
		$retval = $this->adm_self_add_user_passwordless($args);
		if ($retval[0] !== 0)
			return $this->core->pj($retval, 403);
		if (!isset($retval[1]) || !isset($retval[1]['token']))
			return $this->core->pj($retval, 403);
		# alway autologin on success
		$token = $retval[1]['token'];
		$this->adm_set_user_token($token);
		$this->core->send_cookie(
			$this->adm_get_token_name(), $token,
			time() + $this->adm_get_byway_expiration(), '/'
		);
		return $this->core->pj($retval);
	}
}

