<?php


namespace BFITech\ZapAdmin;


use BFITech\ZapCore as zc;
use BFITech\ZapStore as zs;


/**
 * AdminRoute class.
 */
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

	private $prefix = null;
	private $token_name = null;
	private $token_value = null;

	/**
	 * Constructor.
	 *
	 * To use a patched core, use $core_instance parameter.
	 *
	 * @param string $home_or_kwargs Core home or kwargs.
	 * @param string $host Core host.
	 * @param string $shutdown Core shutdown function switch.
	 * @param array $dbargs Database connection parameter.
	 * @param int $expiration Expiration interval.
	 * @param bool $force_create_table Whether overwriting tables is allowed.
	 * @param string $token_name Name of authorization token. Defaults
	 *     to 'zapmin'.
	 * @param string $route_prefix Route prefix.
	 * @param object $core_instance Use this core instance instead of
	 *     instantiating a new one.
	 * @param object $store_instance Use this store instance instead of
	 *     instantiating a new one.
	 *
	 * ### Example:
	 * @code
	 * # index.php
	 *
	 * use BFITech\ZapAdmin as za;
	 * $adm = new za\AdminRoute([
	 *   'dbargs' => [
	 *     'dbtype' => 'sqlite3',
	 *     'dbname' => '/tmp/zapmin.sq3',
	 *   ]
	 * ]);
	 * $adm->route('/status', [$adm, 'route_status'], 'GET');
	 *
	 * # run it with `php -S 0.0.0.0:8000`
	 * @endcode
	 */
	public function __construct(
		$home_or_kwargs=null, $host=null, $shutdown=true,
		$dbargs=[], $expiration=null, $force_create_table=false,
		$token_name=null, $route_prefix=null,
		$core_instance=null, $store_instance=null
	) {
		if (is_array($home_or_kwargs)) {
			extract(zc\Common::extract_kwargs($home_or_kwargs, [
				'home' => null,
				'host' => null,
				'shutdown' => true,
				'dbargs' => [],
				'expiration' => null,
				'force_create_table' => false,
				'token_name' => null,
				'route_prefix' => null,
				'core_instance' => null,
				'store_instance' => null,
			]));
		} else {
			$home = $home_or_kwargs;
		}

		# no null-coalesce operator
		if ($core_instance)
			self::$core = $core_instance;
		else
			self::$core = new zc\Router($home, $host, $shutdown);

		if ($store_instance) {
			self::$store = $store_instance;
		} else {
			self::$store = new zs\SQL($dbargs);
			self::$store->open();
		}

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
	 * Useful for e.g. setting cookie name or HTTP request header
	 * on the client side.
	 *
	 * @note This doesn't belong in AdminStore which only cares
	 *     about token value.
	 */
	public function adm_get_token_name() {
		return $this->token_name;
	}

	/**
	 * Standard wrapper for self::$core->route().
	 *
	 * @param string $path Route path.
	 * @param function $callback Route handler.
	 * @param string|array $method Request method.
	 */
	public function route($path, $callback, $method='GET') {
		if ($this->prefix)
			$path = $this->prefix . $path;
		self::$core->route($path, function($args) use($callback){
			# set token if available
			if (isset($args['cookie'][$this->token_name])) {
				$this->adm_set_user_token(
					$args['cookie'][$this->token_name]);
			} elseif (isset($args['header']['authorization'])) {
				$auth = explode(' ', $args['header']['authorization']);
				if ($auth[0] == $this->token_name) {
					$this->adm_set_user_token($auth[1]);
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
		return self::$core->pj($this->adm_get_safe_user_data());
	}

	/** `POST: /login` */
	public function route_login($args) {
		$retval = $this->adm_login($args);
		if ($retval[0] === 0)
			setcookie(
				$this->adm_get_token_name(), $retval[1]['token'],
				time() + $this->adm_get_expiration(), '/');
		return self::$core->pj($retval);
	}

	/** `GET|POST: /logout` */
	public function route_logout($args) {
		$retval = $this->adm_logout($args);
		if ($retval[0] === 0)
			setcookie(
				$this->adm_get_token_name(), '',
				time() - (3600 * 48), '/');
		return self::$core->pj($retval);
	}

	/** `POST: /chpasswd` */
	public function route_chpasswd($args) {
		return self::$core->pj($this->adm_change_password($args, true));
	}

	/** `POST: /chbio` */
	public function route_chbio($args) {
		return self::$core->pj($this->adm_change_bio($args));
	}

	/** `POST: /register` */
	public function route_register($args) {
		$retval = $this->adm_self_add_user($args, true, true);
		if ($retval[0] !== 0)
			# fail
			return self::$core->pj($retval);
		# success, autologin
		$args['post']['uname'] = $args['post']['addname'];
		$args['post']['upass'] = $args['post']['addpass1'];
		$retval = $this->adm_login($args);
		setcookie(
			$this->adm_get_token_name(), $retval[1]['token'],
			time() + $this->adm_get_expiration(), '/');
		return self::$core->pj($retval);
	}

	/** `POST: /useradd` */
	public function route_useradd($args) {
		return self::$core->pj(
			$this->adm_add_user($args, false, true, true), 403);
	}

	/** `POST: /userdel` */
	public function route_userdel($args) {
		return self::$core->pj($this->adm_delete_user($args), 403);
	}

	/** `POST: /userlist` */
	public function route_userlist($args) {
		return self::$core->pj($this->adm_list_user($args), 403);
	}

	/**
	 * `GET|POST: /byway`
	 *
	 * @note
	 * - This is a mock method. Real method must manipulate `$args`
	 *   into containing `service` key that is not sent by client,
	 *   but by 3rd-party.
	 * - Move hardcoded expiration to an attribute retriavable by
	 *   a getter like adm_get_expiration().
	 */
	public function route_byway($args) {
		### start mock
		if (isset($args['post']['service']))
			$args['service'] = $args['post']['service'];
		### end mock
		$retval = $this->adm_self_add_user_passwordless($args);
		if ($retval[0] !== 0)
			return self::$core->pj($retval, 403);
		if (!isset($retval[1]) || !isset($retval[1]['token']))
			return self::$core->pj($retval, 403);
		# alway autologin on success
		$token = $retval[1]['token'];
		$this->adm_set_user_token($token);
		setcookie(
			$this->adm_get_token_name(), $token,
			time() + (3600 * 24 * 7), '/');
		return self::$core->pj($retval);
	}
}

