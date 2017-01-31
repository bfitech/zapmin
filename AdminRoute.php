<?php


namespace BFITech\ZapAdmin;


use BFITech\ZapCore as zc;
use BFITech\ZapStore as zs;


class AdminRoute extends AdminStore {

	/**
	 * Core instance. Subclasses are expected to collect HTTP variables
	 * with this.
	 */
	public static $core = null;
	/**
	 * Storage instance. Subclasses are expected to manipulate tables
	 * with this.
	 */
	public static $store = null;

	/** Path prefix. */
	protected $prefix = null;
	/** Collection of routes. */
	protected $routes = [];

	/** Marker whether routes have been processed. */
	protected $routes_processed = false;

	private $token_name = null;
	private $token_value = null;

	/**
	 * Constructor.
	 *
	 * @param string $home Core home.
	 * @param string $host Core host.
	 * @param array $dbargs Database connection parameter.
	 * @param int $expiration Expiration interval.
	 * @param bool $force_create_table Whether overwriting tables is allowed.
	 * @param string $token_name Name of authorization token. Defaults
	 *     to 'zapmin'.
	 * @param string $route_prefix Route prefix.
	 *
	 * ## Example:
	 * ~~~~.php
	 *
	 * # index.php
	 *
	 * use BFITech\ZapAdmin as za;
	 * $adm = new za\AdminRoute(null, null, [
	 *     'dbtype' => 'sqlite3',
	 *     'dbname' => '/tmp/zapmin.sq3',
	 * ]);
	 * $adm->route('/status', [$adm, 'route_status'], 'GET');
	 *
	 * # run it with `php -S 0.0.0.0:8000`
	 */
	public function __construct(
		$home=null, $host=null,
		$dbargs=[], $expiration=null, $force_create_table=false,
		$token_name=null, $route_prefix=null
	) {
		self::$core = new zc\Router($home, $host);
		self::$store = new zs\SQL($dbargs);
		self::$store->open();
		parent::__construct(self::$store, $expiration, $force_create_table);

		if (!$token_name)
			$token_name = 'zapmin';
		$this->token_name = $token_name;
		$this->prefix = $this->verify_prefix($route_prefix);
	}

	private function verify_prefix($prefix) {
		if (!$prefix)
			return null;
		$prefix = trim($prefix, '/');
		if (!$prefix)
			return null;
		return '/' . $prefix;
	}

	/**
	 * Safely retrieve authentication token name.
	 *
	 * Useful for the client for e.g. setting cookie name or HTTP
	 * request header.
	 */
	public function get_token_name() {
		return $this->token_name;
	}

	/**
	 * Wrap self::$core->route().
	 */
	public function route($path, $callback, $method='GET') {
		self::$core->route($path, function($args) use($callback){
			# set token if available
			if (isset($args['cookie'][$this->token_name])) {
				$this->set_user_token(
					$args['cookie'][$this->token_name]);
			} elseif (isset($args['header']['authorization'])) {
				$auth = explode(' ', $args['header']['authorization']);
				if ($auth[0] == $this->token_name) {
					$this->set_user_token($auth[1]);
				}
			}
			# execute calback
			$callback($args);
		}, $method);
	}

	# Default route handlers. See each method documentation for more
	# precise control.

	/** `GET: /` */
	public function route_home($args) {
		echo '<h1>It wurks!</h1>';
	}

	/** `GET: /status` */
	public function route_status($args) {
		return self::$core->pj($this->get_safe_user_data());
	}

	/** `POST: /login` */
	public function route_login($args) {
		$retval = $this->login($args);
		if ($retval[0] === 0)
			setcookie(
				$this->get_token_name(), $retval[1]['token'],
				time() + $this->get_expiration(), '/');
		return self::$core->pj($retval);
	}

	/** `GET|POST: /logout` */
	public function route_logout($args) {
		$retval = $this->logout($args);
		if ($retval[0] === 0)
			setcookie(
				$this->get_token_name(), '',
				time() - (3600 * 48), '/');
		return self::$core->pj($retval);
	}

	/** `POST: /chpasswd` */
	public function route_chpasswd($args) {
		return self::$core->pj($this->change_password($args, true));
	}

	/** `POST: /chbio` */
	public function route_chbio($args) {
		return self::$core->pj($this->change_bio($args));
	}

	/** `POST: /register` */
	public function route_register($args) {
		$retval = $this->self_add_user($args, true, true);
		if ($retval[0] !== 0)
			# fail
			return self::$core->pj($retval);
		# success, autologin
		$args['post']['uname'] = $args['post']['addname'];
		$args['post']['upass'] = $args['post']['addpass1'];
		$retval = $this->login($args);
		setcookie(
			$this->get_token_name(), $retval[1]['token'],
			time() + $this->get_expiration(), '/');
		return self::$core->pj($retval);
	}

	/** `POST: /useradd` */
	public function route_useradd($args) {
		return self::$core->pj(
			$this->add_user($args, false, true, true), 403);
	}

	/** `POST: /userdel` */
	public function route_userdel($args) {
		return self::$core->pj($this->delete_user($args), 403);
	}

	/** `POST: /userlist` */
	public function route_userlist($args) {
		return self::$core->pj($this->list_user($args), 403);
	}

	/**
	 * `GET|POST: /byway`
	 *
	 * @todo
	 * - This is a mock method. Real method must manipulate $args
	 *   into containing 'service' key that is not sent by client,
	 *   but by 3rd-party.
	 * - Move hardcoded expiration to an attribute retriavable by
	 *   a getter like get_expiration().
	 */
	public function route_byway($args) {
		### start mock
		if (isset($args['post']['service']))
			$args['service'] = $args['post']['service'];
		### end mock
		$retval = $this->self_add_user_passwordless($args);
		if ($retval[0] !== 0)
			return self::$core->pj($retval, 403);
		if (!isset($retval[1]) || !isset($retval[1]['token']))
			return self::$core->pj($retval, 403);
		# alway autologin on success
		$token = $retval[1]['token'];
		$this->set_user_token($token);
		setcookie(
			$this->get_token_name(), $token,
			time() + (3600 * 24 * 7), '/');
		return self::$core->pj($retval);
	}
}

