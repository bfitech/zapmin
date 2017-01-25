<?php


namespace BFITech\ZapAdmin;


/**
 * Default routes.
 */
class AdminRouteDefault extends AdminRoute {

	/**
	 * Constructor.
	 *
	 * @see AdminRoute
	 *
	 * ## Example:
	 * ~~~~.php
	 *
	 * # index.php
	 *
	 * use BFITech\ZapAdmin as za;
	 * $route = new za\AdminRouteDefault(null, null, [
	 *     'dbtype' => 'sqlite3',
	 *     'dbname' => '/tmp/zapmin.sq3',
	 * ]);
	 * $route->process_routes();
	 *
	 * # run it with `php -S 0.0.0.0:8000`
	 *
	 * ~~~~
	 */
	public function __construct(
		$home=null, $host=null,
		$dbargs=[], $expiration=null, $create_table=false,
		$token_name=null, $route_prefix=null
	) {
		parent::__construct($home, $host,
			$dbargs, $expiration, $create_table,
			$token_name, $route_prefix);
		$this->set_default_routes();
	}

	/**
	 * Set default routes.
	 */
	protected function set_default_routes() {
		$this->add_route('/',         [$this, '_home'], 'GET');
		$this->add_route('/status',   [$this, '_status'], 'GET');
		$this->add_route('/login',    [$this, '_login'], 'POST');
		$this->add_route('/logout',   [$this, '_logout'], ['GET', 'POST']);
		$this->add_route('/chpasswd', [$this, '_chpasswd'], 'POST');
		$this->add_route('/chbio',    [$this, '_chbio'], 'POST');
		$this->add_route('/register', [$this, '_register'], 'POST');
		$this->add_route('/useradd',  [$this, '_useradd'], 'POST');
		$this->add_route('/userdel',  [$this, '_userdel'], 'POST');
		$this->add_route('/userlist', [$this, '_userlist'], 'POST');
		$this->add_route('/byway',    [$this, '_byway'], ['GET', 'POST']);
	}

	/**
	 * Wrapper for JSON response header and body.
	 *
	 * @param array $retval Return value of a method call.
	 * @param int $forbidden_code If $retval[0]==0, HTTP code is 200.
	 *     Otherwise it defaults to 401 which we can override with
	 *     this parameter, e.g. 403.
	 */
	protected function _json($retval, $forbidden_code=null) {
		if (count($retval) < 2)
			$retval[] = [];
		$http_code = 200;
		if ($retval[0] !== 0) {
			$http_code = 401;
			if ($forbidden_code)
				$http_code = $forbidden_code;
		}
		self::$core->print_json($retval[0], $retval[1], $http_code);
	}

	# default handlers

	/** `GET: /` */
	protected function _home($args) {
		echo '<h1>It wurks!</h1>';
	}

	/** `GET: /status` */
	protected function _status($args) {
		return $this->_json($this->get_safe_user_data());
	}

	/** `POST: /login` */
	protected function _login($args) {
		$retval = $this->login($args);
		if ($retval[0] === 0)
			setcookie(
				$this->get_token_name(), $retval[1]['token'],
				time() + $this->get_expiration(), '/');
		return $this->_json($retval);
	}

	/** `GET|POST: /logout` */
	protected function _logout($args) {
		$retval = $this->logout($args);
		if ($retval[0] === 0)
			setcookie(
				$this->get_token_name(), '',
				time() - (3600 * 48), '/');
		return $this->_json($retval);
	}

	/** `POST: /chpasswd` */
	protected function _chpasswd($args) {
		return $this->_json($this->change_password($args, true));
	}

	/** `POST: /chbio` */
	protected function _chbio($args) {
		return $this->_json($this->change_bio($args));
	}

	/** `POST: /register` */
	protected function _register($args) {
		$retval = $this->self_add_user($args, true, true);
		if ($retval[0] !== 0)
			# fail
			return $this->_json($retval);
		# success, autologin
		$args['post']['uname'] = $args['post']['addname'];
		$args['post']['upass'] = $args['post']['addpass1'];
		$retval = $this->login($args);
		setcookie(
			$this->get_token_name(), $retval[1]['token'],
			time() + $this->get_expiration(), '/');
		return $this->_json($retval);
	}

	/** `POST: /useradd` */
	protected function _useradd($args) {
		return $this->_json(
			$this->add_user($args, false, true, true), 403);
	}

	/** `POST: /userdel` */
	protected function _userdel($args) {
		return $this->_json(
			$this->delete_user($args), 403);
	}

	/** `POST: /userlist` */
	protected function _userlist($args) {
		return $this->_json(
			$this->list_user($args), 403);
	}

	/**
	 * `GET|POST: /byway`
	 * @todo
	 * - This is a mock method. Real method must manipulate $args
	 *   into containing 'service' key that is not sent by client,
	 *   but by 3rd-party instead.
	 * - Move hardcoded expiration to an attribute retriavable by
	 *   a getter like get_expiration().
	 */
	protected function _byway($args) {
		### start mock
		if (isset($args['post']['service']))
			$args['service'] = $args['post']['service'];
		### end mock
		$retval = $this->self_add_user_passwordless($args);
		if ($retval[0] !== 0)
			return $this->_json($retval, 403);
		if (!isset($retval[1]) || !isset($retval[1]['token']))
			return $this->_json($retval, 403);
		# alway autologin on success
		$token = $retval[1]['token'];
		$this->set_user_token($token);
		setcookie(
			$this->get_token_name(), $token,
			time() + (3600 * 24 * 7), '/');
		return $this->_json($retval);
	}
}

