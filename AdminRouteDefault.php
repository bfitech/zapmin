<?php


namespace BFITech\ZapAdmin;


/**
 * Default routes.
 *
 * @example
 *
 * # index.php
 * use BFITech\ZapAdmin as za;
 * $route = new za\AdminRouteDefault(null, null, [
 *     'dbtype' => 'sqlite3',
 *     'dbname' => '/tmp/zapmin.sq3',
 * ]);
 * $route->process_routes();
 *
 * # run it with `php -S 0.0.0.0:8000`
 */
class AdminRouteDefault extends AdminRoute {

	/**
	 * Constructor.
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
		$this->add_route('/logout',   [$this, '_logout'], 'POST');
		$this->add_route('/chpasswd', [$this, '_chpasswd'], 'POST');
		$this->add_route('/chbio',    [$this, '_chbio'], 'POST');
		$this->add_route('/register', [$this, '_register'], 'POST');
		$this->add_route('/useradd',  [$this, '_useradd'], 'POST');
		$this->add_route('/userdel',  [$this, '_userdel'], 'POST');
		$this->add_route('/userlist', [$this, '_userlist'], 'POST');
	}

	/**
	 * Wrapper for JSON response header and body.
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

	protected function _home($args) {
		echo '<h1>It wurks!</h1>';
	}

	protected function _status($args) {
		return $this->_json($this->get_safe_user_data());
	}

	protected function _login($args) {
		$retval = $this->login($args);
		if ($retval[0] === 0)
			setcookie('adm', $retval[1]['token'], 7200, '/');
		return $this->_json($retval);
	}

	protected function _logout($args) {
		return $this->_json($this->logout($args));
	}

	protected function _chpasswd($args) {
		return $this->_json($this->change_password($args));
	}

	protected function _chbio($args) {
		return $this->_json($this->change_bio($args));
	}

	protected function _register($args) {
		$retval = $this->self_add_user($args);
		if ($retval[0] !== 0)
			# fail
			return $this->_json($retval);
		# success, autologin
		$args['post']['uname'] = $args['post']['addname'];
		$args['post']['upass'] = $args['post']['addpass1'];
		$retval = $this->login($args);
		setcookie('adm', $retval[1]['token'], 7200, '/');
		return $this->_json($retval);
	}

	protected function _useradd($args) {
		return $this->_json(
			$this->add_user($args, false, false));
	}

	protected function _userdel($args) {
		return $this->_json(
			$this->delete_user($args));
	}

	protected function _userlist($args) {
		return $this->_json(
			$this->list_user($args));
	}
}

