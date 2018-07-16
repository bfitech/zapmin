<?php


namespace BFITech\ZapAdmin;


/**
 * AdminRoute class.
 *
 * This is a thin layer than glues router and storage together.
 * Subclassess extend this instead of abstract AdminStore.
 *
 * @see AdminRouteDefault for limited example.
 */
class AdminRoute extends AdminStore {

	/**
	 * Standard wrapper for Router::route.
	 *
	 * @param string $path Router path.
	 * @param callable $callback Router callback.
	 * @param string|array $method Router request method.
	 * @param bool $is_raw If true, accept raw data instead of parsed
	 */
	public function route(
		$path, $callback, $method='GET', $is_raw=null
	) {
		$this->core->route($path, function($args) use($callback){
			# set token if available
			if (isset($args['cookie'][$this->token_name])) {
				# via cookie
				$this->adm_set_user_token(
					$args['cookie'][$this->token_name]);
			} elseif (isset($args['header']['authorization'])) {
				# via request header
				$auth = explode(' ', $args['header']['authorization']);
				if ($auth[0] == $this->token_name) {
					$this->adm_set_user_token($auth[1]);
				}
			}
			# execute calback
			$callback($args);
		}, $method, $is_raw);
	}

}
