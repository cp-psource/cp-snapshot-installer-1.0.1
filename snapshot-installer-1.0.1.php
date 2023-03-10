<?php

/**
 * Snapshot Recovery installer
 * Version: 1.0.1
 * Build: 2023-03-10
 *
 * Copyright 2009-2023 Incsub (https://n3rds.work)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License (Version 2 - GPLv2) as published by
 * the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/



// Source: build/_php_deps.php

// Source: src/lib/class_si_controller.php


abstract class Si_Controller {

	abstract public function run ();

	protected $_request;
	protected $_env;
	protected $_view_class;

	private $_view;

	public function __construct () {
		$this->_request = Si_Model_Request::load(Si_Model_Request::REQUEST_GET);
		$this->_env = new Si_Model_Env;
	}

	/**
	 * Sets view class to use for this controller instance
	 *
	 * @param string $type View class type
	 */
	public function set_view_class ($type) {
		$this->_view_class = $type;
	}

	/**
	 * Gets view instance
	 *
	 * @return object An appropriate Si_View_Page instance
	 */
	public function get_view () {
		if (!empty($this->_view)) return $this->_view;

		$cls = 'Si_View_Page_' . ucfirst($this->_view_class);
		if (!class_exists($cls)) {
			$cls = 'Si_View_Page_Error';
		}
		$this->_view = new $cls;

		$this->_view->set_state($this->_view_class);

		return $this->_view;
	}

	/**
	 * Re-routes (redirects) according to the request query vars
	 */
	public function reroute () {
		$url = self::get_base_url();
		$query = $this->_request->to_query();
		header("Location: {$url}{$query}");
		die;
	}

	/**
	 * Gets the absolute current URL
	 *
	 * @return string
	 */
	public static function get_base_url () {
		$protocol = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on'
			? 'https'
			: 'http'
		;
		$host = !empty($_SERVER['HTTP_HOST'])
			? rtrim($_SERVER['HTTP_HOST'], '/')
			: ''
		;
		$uri = !empty($_SERVER['PHP_SELF'])
			? ltrim($_SERVER['PHP_SELF'], '/')
			: ''
		;
		return "{$protocol}://{$host}/{$uri}";
	}

	/**
	 * Gets full URL to relative path
	 *
	 * @param string $relative_path Relative path
	 *
	 * @return string
	 */
	public static function get_url ($relative_path) {
		$target = rtrim(dirname(self::get_base_url()), '/');
		if (preg_match('/\/(src|build)/', $target)) {
			$target = rtrim(dirname($target), '/');
		}
		return $target . '/' . ltrim($relative_path, '/');
	}
}



// Source: src/lib/class_si_model.php


abstract class Si_Model {

	/**
	 * Deep-trims value
	 *
	 * @param mixed $value Value to deep-trim
	 *
	 * @return mixed
	 */
	public function deep_trim ($value) {
		if (!is_array($value)) {
			if (is_numeric($value) && !strstr($value, '.')) $value = (int)$value;
			else $value = trim($value);

			return $value;
		}
		foreach ($value as $key => $val) {
			$value[$key] = $this->deep_trim($val);
		}
		return $value;
	}
}



// Source: src/lib/class_si_view.php


abstract class Si_View {

	abstract public function out ($params=array());

	private $_state;

	public function get_state () { return $this->_state; }
	public function set_state ($state) { return !!$this->_state = $state; }

	/**
	 * Quick, trimmed down string convention replacer
	 *
	 * @param string $str String to process
	 *
	 * @return string
	 */
	public function quickdown ($str) {
		$str = preg_replace('/```([^`]+)```/', '<pre><code>\\1</code></pre>', $str);
		$str = preg_replace('/`([^`]+)`/', '<code>\\1</code>', $str);
		return $str;
	}
}



// Source: src/lib/Controller/class_si_controller_check.php


class Si_Controller_Check extends Si_Controller {

	public function run () {
		$archive = $this->_env->get(Si_Model_Env::ARCHIVE);
		$archive_valid = Si_Model_Archive::load($archive)->check();
		$checks = array(
			'PhpVersion' => array(
				'test' => version_compare(PHP_VERSION, '5.2') >= 0,
				'value' => PHP_VERSION
			),
			'OpenBasedir' => array(
				'test' => !ini_get('open_basedir'),
			),
			'MaxExecTime' => array(
				'test' => 0 === (int)ini_get('max_execution_time') || (int)ini_get('max_execution_time') >= 150,
				'value' => (int)ini_get('max_execution_time'),
			),
			'Mysqli' => array(
				'test' => (bool)function_exists('mysqli_connect'),
			),
			'Zip' => array(
				'test' => class_exists('ZipArchive')
			),
			'Archive' => array(
				'test' => !empty($archive),
			),
			'ArchiveValid' => array(
				'test' => (true === $archive_valid),
				'value' => $archive_valid,
			),
		);

		$all_good = true;
		foreach ($checks as $check) {
			if (!empty($check['test'])) continue;
			$all_good = false;
			break;
		}
		if (!!$this->_request->get('preview')) $all_good = false; // Don't fast-forward in preview

		if ($all_good) {
			// We're good - just push forward
			$this->_request->set('state', 'configuration');
			$this->reroute();
		} else {
			// We have errors - render page
			$this->get_view()->out(array(
				'checks' => $checks,
			));
		}

	}
}



// Source: src/lib/Controller/class_si_controller_cleanup.php


class Si_Controller_Cleanup extends Si_Controller {

	public function run () {
		$temp = Si_Model_Fs::temp($this->_env->get(Si_Model_Env::TEMP_DIR));
		$temp_status = $temp->exists()
			? $temp->rmdir()
			: false
		;

		$source_cleanup = $self_cleanup = false;
		$source_path = $self_path = false;
		$destination = Si_Model_Fs::path($this->_env->get(Si_Model_Env::TARGET));
		if ($destination->exists()) {
			$source_path = $this->_env->get(Si_Model_Env::ARCHIVE);
			$archive = $destination->relative($source_path, true);
			if (!empty($archive)) $source_cleanup = $destination->rm($archive);

			$self_path = Si_Model_Fs::trailing(getcwd()) . basename($_SERVER['PHP_SELF']);
			$script = basename($self_path);
			if ($destination->exists($script)) $self_cleanup = $destination->rm($script);
			if ($destination->exists(Si_Helper_Log::FILENAME)) {
				$destination->rm(Si_Helper_Log::FILENAME);
			}
		}

		$this->get_view()->out(array(
			'status' => $temp_status && $source_cleanup && $self_cleanup,
			'temp_status' => $temp_status,
			'temp_path' => $temp->get_root(),
			'source_status' => $source_cleanup,
			'source_path' => $source_path,
			'self_status' => $self_cleanup,
			'self_path' => $self_path,
			'next_url' => $this->_request->get_query('state', ''),
			'this_url' => $this->_request->to_query(),
			'view_url' => $this->_env->get(Si_Model_Env::TARGET_URL),
		));
	}
}



// Source: src/lib/Controller/class_si_controller_configuration.php


class Si_Controller_Configuration extends Si_Controller {

	public function run () {

		if (!empty($_POST)) {
			if (!empty($_POST['name'])) $this->_env->set('_dbname', $_POST['name']);
			else $this->_env->drop('_dbname');

			if (!empty($_POST['user'])) $this->_env->set('_dbuser', $_POST['user']);
			else $this->_env->drop('_dbuser');

			if (!empty($_POST['password'])) $this->_env->set('_dbpassword', $_POST['password']);
			else $this->_env->drop('_dbpassword');

			if (!empty($_POST['host']) || !empty($_POST['port'])) {
				$host = !empty($_POST['host']) ? $_POST['host'] : false;

				if (!empty($host)) {
					$port = !empty($_POST['port']) ? $_POST['port'] : false;
					if (!empty($port) && is_numeric($port)) {
						$host .= ':' . (int)$port;
					}
				}
				$this->_env->set('_dbhost', $host);
			} else $this->_env->drop('_dbhost');

			if (!empty($_POST['site-url'])) $this->_env->set(Si_Model_Env::TARGET_URL, $_POST['site-url']);
			else $this->_env->drop(Si_Model_Env::TARGET_URL);

			$this->reroute();
		}

		$archive = Si_Model_Archive::load($this->_env->get(Si_Model_Env::ARCHIVE));
		$source = Si_Model_Fs::temp($this->_env->get(Si_Model_Env::TEMP_DIR));

		$archive->extract_specific($source, array(
			'www/wp-config.php',
			Si_Model_Manifest::FILE_NAME,    // Snapshot v3
		));

		$manifest = Si_Model_Manifest::load($source);
		if ($manifest->has_file()) {
			// Snapshot v3
			$version = $manifest->get('SNAPSHOT_VERSION', '0.0');
			$files = $manifest->get_sources('fileset');
			$tables = $manifest->get_sources('tableset');

			$manifest_status = true
				&& version_compare($version, '3.0-alpha-1', 'ge')
				&& in_array('full', $files)
				&& !empty($tables)
			;
		} else {
			// Snapshot v4
			$manifest_status = true;
		}

		$source->chroot('www');
		$config = Si_Model_Wpconfig::load($source);

		$manifest_status = true;

		$config_status = true
			&& !!$config->has_file()
			&& !!strlen($config->get('DB_NAME', ''))
			&& !!strlen($config->get('DB_USER', ''))
			&& !!strlen($config->get('DB_HOST', ''))
			&& !!strlen($config->get('$table_prefix', ''))
		;

		// Now, try to connect
		$database_status = false;
		$db_connection_errno = false;
		$db_empty = false;
		if ($config_status) {
			$db = Si_Model_Database::from_config($config);
			$database_status = $db->connect();
			$db_connection_errno = $db->get_connection_error_code();
			$db_empty = $db->is_db_empty();
		}

		$destination = Si_Model_Fs::path($this->_env->get(Si_Model_Env::TARGET));
		$database = array(
			'name' => $this->_env->get('_dbname', $config->get('DB_NAME', '')),
			'user' => $this->_env->get('_dbuser', $config->get('DB_USER', '')),
			'password' => $this->_env->get('_dbpassword', $config->get('DB_PASSWORD', '')),
			'host' => $this->_env->get('_dbhost', $config->get('DB_HOST', '')),
			'table_prefix' => $this->_env->get('_dbtable_prefix', $config->get('$table_prefix', '')),
		);

		return $this->get_view()->out(array(
			'status' => $manifest_status && $config_status && $database_status,
			'manifest_status' => $manifest_status,
			'has_manifest_file' => true,    // Snapshot v3/v4
			'deployment_directory' => $destination->get_root(),
			'site_url' => $this->_env->get(Si_Model_Env::TARGET_URL),
			'can_override' => $this->_env->can_override(),
			'config_status' => $config_status,
			'has_config_file' => $config->has_file(),
			'database_status' => $database_status,
			'db_connection_errno' => $db_connection_errno,
			'db_empty' => $db_empty,
			'config' => $config,
			'database' => $database,
			'next_url' => $this->_request->get_query('state', 'extract'),
			'this_url' => $this->_request->to_query(),
			'cleanup_url' => $this->_request->get_query('state', 'cleanup'),
		));
	}
}



// Source: src/lib/Controller/class_si_controller_done.php


class Si_Controller_Done extends Si_Controller {

	public function run () {
		$this->get_view()->out(array(
			'status' => true,
			'cleanup_url' => $this->_request->get_query('state', 'cleanup'),
			'view_url' => $this->_env->get(Si_Model_Env::TARGET_URL),
		));
	}
}



// Source: src/lib/Controller/class_si_controller_extract.php


class Si_Controller_Extract extends Si_Controller {

	public function run () {
		$archive = Si_Model_Archive::load($this->_env->get(Si_Model_Env::ARCHIVE));
		$destination = Si_Model_Fs::temp($this->_env->get(Si_Model_Env::TEMP_DIR));

		if ($destination->exists()) {
			$all = count($destination->ls());
			// Clean up manifest and config first
			if ($all > 0 && $all < 3) {
				$path = $destination->resolve('www/wp-config.php');
				if ($path && file_exists($path)) {
					$destination->rmdir();
				}

				$path = $destination->resolve(Si_Model_Manifest::FILE_NAME);
				if ($path && file_exists($path)) unlink($path);
			}
		}

		if (!$destination->exists() || $destination->is_empty()) {
			$this->_extract($archive, $destination);
		} else {
			$this->_request->set('state', 'files');
			$this->reroute();
		}

	}

	private function _extract ($archive, $destination) {
		$status = $archive->extract_to($destination);
		return $this->get_view()->out(array(
			'status' => $status,
			'progress' => array(
				'percentage' => 5,
				'action' => 'Extracting package',
			),
			'next_url' => $this->_request->to_query(), // Auto-reload to this, and let controller take over
			'cleanup_url' => $this->_request->get_query('state', 'cleanup'),
		));
	}

}



// Source: src/lib/Controller/class_si_controller_files.php


class Si_Controller_Files extends Si_Controller {

	public function run () {
		$status = true;

		$target = Si_Model_Fs::temp($this->_env->get(Si_Model_Env::TEMP_DIR));
		if (!$target->exists() || !$target->chroot('www')) {
			$status = false;
		}
		$destination = Si_Model_Fs::path($this->_env->get(Si_Model_Env::TARGET));

		$chunk = $this->_request->get('chunk');
		$chunk = !empty($chunk) && is_numeric($chunk)
			? (int)$chunk
			: 0
		;
		$chunk_size = $this->_get_chunk_size();
		$offset = $chunk * $chunk_size;

		if ($status) $status = $destination->exists();
		$all_files = $files = array();

		if ($status) {
			$all_files = $target->ls();
			$files = array_slice($all_files, $chunk * $chunk_size, $chunk_size);
			if (empty($files) && empty($all_files)) $status = false;
		}

		$processed = array();

		if ($status && !empty($files)) {
			$tmp = false;
			foreach ($files as $path) {
				$source = $target->relative($path);
				$tmp = $destination->cpr($target, $source);
				if (!$tmp) {
					$status = false;
					break;
				}
				$processed[] = $source;
			}
		}

		$next_url = $offset >= count($all_files)
			? $this->_request->get_clean_query('state', 'tables', true)
			: $this->_request->get_query('chunk', $chunk+1)
		;

		$current = count($processed) * (!empty($chunk) ? $chunk : 1);
		$percentage = (($current * 45) / count($all_files));

		if ($percentage < 1 && $chunk > 1) $percentage = 45; // Normalize last step refresh

		$percentage += 5; // Extract (5%) being the previous step

		$this->get_view()->out(array(
			'status' => $status,
			'progress' => array(
				'percentage' => $percentage,
				'action' => 'Copying files',
			),
			'next_url' => $next_url,
			'cleanup_url' => $this->_request->get_query('state', 'cleanup'),
		));

	}

	private function _get_chunk_size () {
		return 250;
	}
}



// Source: src/lib/Controller/class_si_controller_finalize.php


class Si_Controller_Finalize extends Si_Controller {

	public function run () {
		$target = Si_Model_Fs::temp($this->_env->get(Si_Model_Env::TEMP_DIR));
		if (!$target->exists()) {
			$status = false;
		}

		if ($this->_env->can_override()) {
			$this->_update_htaccess();
			$this->_update_config();
		}

		$this->_update_target();

		$this->get_view()->out(array(
			'status' => true,//$target->rmdir(),
			'progress' => array(
				'percentage' => 95,
				'action' => 'Finalizing installation'
			),
			'next_url' => $this->_request->get_query('state', 'done'),
			'this_url' => $this->_request->to_query(),
		));
	}

	private function _update_htaccess () {
		$destination = Si_Model_Fs::path($this->_env->get(Si_Model_Env::TARGET));
		$htaccess = Si_Model_Htaccess::load($destination);
		$htaccess_changed = false;

		$base = $htaccess->get(Si_Model_Htaccess::REWRITE_BASE);
		$site_url = $this->_env->get(Si_Model_Env::TARGET_URL);
		$site_path = Si_Model_Fs::trailing(parse_url($site_url, PHP_URL_PATH));

		if ($base !== $site_path) {
			$htaccess->update_raw_base($site_path);
			$htaccess_changed = true;
		}

		if ($htaccess_changed) $htaccess->write();
	}

	private function _update_config () {
		$destination = Si_Model_Fs::path($this->_env->get(Si_Model_Env::TARGET));
		$config = Si_Model_Wpconfig::load($destination);
		$config_changed = false;

		$_dbhost = $this->_env->get('_dbhost');
		if (!empty($_dbhost) && $_dbhost !== $config->get('DB_HOST')) {
			$config->update_raw('DB_HOST', $_dbhost);
			$config_changed = true;
		}

		$_dbuser = $this->_env->get('_dbuser');
		if (!empty($_dbuser) && $_dbuser !== $config->get('DB_USER')) {
			$config->update_raw('DB_USER', $_dbuser);
			$config_changed = true;
		}

		$_dbpassword = $this->_env->get('_dbpassword');
		if (!empty($_dbpassword) && $_dbpassword !== $config->get('DB_PASSWORD')) {
			$config->update_raw('DB_PASSWORD', $_dbpassword);
			$config_changed = true;
		}

		$_dbname = $this->_env->get('_dbname');
		if (!empty($_dbname) && $_dbname !== $config->get('DB_NAME')) {
			$config->update_raw('DB_NAME', $_dbname);
			$config_changed = true;
		}

		if ($config_changed) $config->write();
	}

	private function _update_target () {
		$destination = Si_Model_Fs::path($this->_env->get(Si_Model_Env::TARGET));
		$config = Si_Model_Wpconfig::load($destination);
		$db = Si_Model_Database::from_config($config);

		$site_url = $this->_env->get(Si_Model_Env::TARGET_URL);
		$pfx = $db->get_prefix();
		$db->query("UPDATE {$pfx}options SET option_value='{$site_url}' WHERE option_name='siteurl' LIMIT 1");
		$db->query("UPDATE {$pfx}options SET option_value='{$site_url}' WHERE option_name='home' LIMIT 1");

		// Delete Snapshot v4 running backup info
		$db->query("DELETE FROM {$pfx}options WHERE option_name='snapshot_running_backup'");
		$db->query("DELETE FROM {$pfx}options WHERE option_name='snapshot_running_backup_status'");
		$db->query("DELETE FROM {$pfx}sitemeta WHERE meta_key='snapshot_running_backup'");
		$db->query("DELETE FROM {$pfx}sitemeta WHERE meta_key='snapshot_running_backup_status'");

/*
		// Do the FS hardening step
		$files = $destination->ls();
		$pattern = '/' . preg_quote('/wp-content/', '/') . '/';
		foreach ($files as $file) {
			$perms = preg_match($pattern, $file)
				? (is_dir($file) ? 0766 : 0666)
				: (is_dir($file) ? 0755 : 0644)
			;
			@chmod($file, $perms);
		}
*/
	}
}



// Source: src/lib/Controller/class_si_controller_install.php


class Si_Controller_Install extends Si_Controller {

	const STEP_CHECK = 0;
	const STEP_CONFIG = 1;
	const STEP_DEPLOY = 2;

	public function run () {
		?>
		<h1>Heh, something went wrong routing your request o.0</h1>
		<?php
	}

	public static function get_states_step_map () {
		return array(
			self::STEP_CHECK => array('check'),
			self::STEP_CONFIG => array('configuration'),
			self::STEP_DEPLOY => array('extract', 'files', 'tables', 'finalize', 'done')
		);
	}

	public function route () {
		$controller = $this;
		$state = $this->_request->get('state', 'check');

		@set_time_limit(0);

		switch ($state) {
			case 'extract':
				$controller = new Si_Controller_Extract;
				break;
			case 'files':
				$controller = new Si_Controller_Files;
				break;
			case 'tables':
				$controller = new Si_Controller_Tables;
				break;
			case 'finalize':
				$controller = new Si_Controller_Finalize;
				break;
			case 'done':
				$controller = new Si_Controller_Done;
				break;
			case 'cleanup':
				$controller = new Si_Controller_Cleanup;
				break;
			case 'configuration':
				$controller = new Si_Controller_Configuration;
				break;
			case 'check':
			default:
				$controller = new Si_Controller_Check;
				$state = 'check';
				break;
		}

		$controller->set_view_class($state);
		$controller->run();
	}

}



// Source: src/lib/Controller/class_si_controller_tables.php


class Si_Controller_Tables extends Si_Controller {

	public function run () {
		$source = Si_Model_Fs::temp($this->_env->get(Si_Model_Env::TEMP_DIR));
		$tables = $source->glob('sql/*.sql');   // Snapshot v4
		if (!count($tables)) {
			$tables = $source->glob('*.sql');   // Snapshot v3
		}

		$source->chroot('www');
		$config = Si_Model_Wpconfig::load($source);

		$db = Si_Model_Database::from_config($config);

		$chunk = $this->_request->get('chunk');
		$chunk = !empty($chunk) && is_numeric($chunk)
			? (int)$chunk
			: 0
		;

		$status = false;
		$error = false;

		$table = false;
		foreach ($tables as $idx => $table) {
			if ($idx < $chunk) continue;
			break;
		}

		if (!$db->connect()) {
			$status = false;
			$code = $db->get_connection_error_code();
			$error = "Unable to connect to database" . (!empty($code) ? " error code: {$code}" : '');
		} else {
			if (!empty($table)) {
				$status = $db->restore_from_file($table);
				if (!$status) {
					$tbl = basename($table, '.sql');
					$error = "Unable to restore table '{$tbl}' from '{$table}'";
					$reason = $db->last_query_error();
					if (!empty($reason)) $error .= ' because ' . $reason;
				}
			} else $error = 'Unable to determine table';
		}

		$next_url = $chunk >= count($tables)
			? $this->_request->get_clean_query('state', 'finalize', true)
			: $this->_request->get_query('chunk', $chunk+1)
		;

		$current = $chunk ? $chunk - 1 : 1;
		$percentage = (($current * 45) / count($tables));

		if ($percentage < 1 && $chunk > 1) $percentage = 45; // Normalize last step refresh

		$percentage += 50; // Extract (5%) and files(45%) being the previous step

		$this->get_view()->out(array(
			'status' => $status,
			'error' => $error,
			'progress' => array(
				'percentage' => $percentage,
				'action' => 'Restoring tables',
			),
			'next_url' => $next_url,
			'this_url' => $this->_request->to_query(),
			'cleanup_url' => $this->_request->get_query('state', 'cleanup'),
		));
	}
}



// Source: src/lib/Helper/class_si_helper_debug.php


/**
 * Deals with all our debugging needs
 */
class Si_Helper_Debug {

	/**
	 * Formats the output variables
	 *
	 * @param array $args What to inspect
	 *
	 * @return string
	 */
	static public function inspect ($args) {
		if (is_array($args) && count($args) === 1) $args = array_pop($args);
		return var_export($args, 1);
	}

	/**
	 * Outputs text-only
	 *
	 * @param mixed Whatever
	 *
	 * @return void
	 */
	static public function text () {
		$args = 1 === func_num_args() ? func_get_arg(0) : func_get_args();
		echo self::inspect($args);
	}

	static public function textx () {
		$args = 1 === func_num_args() ? func_get_arg(0) : func_get_args();
		self::text($args);
		die;
	}

	static public function html () {
		$args = 1 === func_num_args() ? func_get_arg(0) : func_get_args();
		echo '<pre>' . self::inspect($args) . '</pre>';
	}

	static public function htmlx () {
		$args = 1 === func_num_args() ? func_get_arg(0) : func_get_args();
		self::html($args);
		die;
	}

	static public function log () {
		$args = 1 === func_num_args() ? func_get_arg(0) : func_get_args();
		Si_Helper_Log::log(self::inspect($args));
	}
}



// Source: src/lib/Helper/class_si_helper_log.php


/**
 * Deals with output logging
 */
class Si_Helper_Log {

	const FILENAME = 'si-error.log';

	static public function log ($msg) {
		$env = new Si_Model_Env;
		$file = Si_Model_Fs::trailing($env->get_path_root()) . self::FILENAME;
		$date = date("Y-m-d@H:i:sP");
		return error_log("[{$date}] {$msg}\n", 3, $file);
	}
}



// Source: src/lib/Model/class_si_model_archive.php


class Si_Model_Archive extends Si_Model {

	private $_archive_path;
	private $_zip;

	private function __construct () {
		if (class_exists('ZipArchive')) {
			$this->_zip = new ZipArchive;
		}
	}

	public static function load ($file) {
		$me = new self;
		$me->set_archive_path($file);
		return $me;
	}

	/**
	 * Sets archive path to fully qualified one
	 *
	 * @param string $file Path to archive file
	 */
	public function set_archive_path ($file) {
		$this->_archive_path = false;
		if (empty($file) || !file_exists($file)) return false;
		$file = Si_Model_Fs::normalize_real($file);

		if (!is_readable($file)) return $file;
		return !!$this->_archive_path = $file;
	}

	/**
	 * Checks zip archive validity
	 *
	 * @return string|bool Error string on failure, (bool)true on success
	 */
	public function check () {
		if (!class_exists('ZipArchive')) return "Unable to open archive - ZipArchive class is missing.";
		if (empty($this->_archive_path)) return "Archive file missing.";

		$status = true;
		$errors = array(
			ZipArchive::ER_EXISTS => 'File already exists.',
			ZipArchive::ER_INCONS => 'Zip archive inconsistent.',
			ZipArchive::ER_INVAL => 'Invalid argument.',
			ZipArchive::ER_MEMORY => 'Malloc failure.',
			ZipArchive::ER_NOENT => 'No such file.',
			ZipArchive::ER_NOZIP => 'Not a zip archive.',
			ZipArchive::ER_OPEN => "Can't open file.",
			ZipArchive::ER_READ => 'Read error.',
			ZipArchive::ER_SEEK => 'Seek error.',
		);

		$handle = $this->_zip->open($this->_archive_path);
		if (true !== $handle) {
			$status = in_array($handle, array_keys($errors))
				? $errors[$handle]
				: "Generic archive open error"
			;
		}
		if (true === $handle) $this->_zip->close();

		return $status;
	}

	/**
	 * Extract *specific* files to a TMP-relative destination directory
	 *
	 * @param object $destination A Si_Model_Fs instance describing the destination
	 *
	 * @return bool
	 */
	public function extract_specific ($destination, $files) {
		$status = false;
		if (empty($this->_archive_path)) return $status;

		if (!($destination instanceof Si_Model_Fs)) return $status;
		if (!$destination->exists()) return false;

		if (empty($files)) return false;
		if (!is_array($files)) return false;

		$path = $destination->get_root();
		if (empty($path)) return false;

		$handle = $this->_zip->open($this->_archive_path);
		if (!$handle) return false;

		$status = $this->_zip->extractTo($path, $files);
		$this->_zip->close();

		return $status;
	}

	/**
	 * Extract *all* files to a TMP-relative destination directory
	 *
	 * @param object $destination A Si_Model_Fs instance describing the destination
	 *
	 * @return bool
	 */
	public function extract_to ($destination) {
		$status = false;
		if (empty($this->_archive_path)) return $status;

		if (!($destination instanceof Si_Model_Fs)) return $status;
		if (!$destination->exists()) return false;

		$path = $destination->get_root();
		if (empty($path)) return false;

		$handle = $this->_zip->open($this->_archive_path);
		$status = $this->_zip->extractTo($path);
		$this->_zip->close();

		return !!$status;
	}

}



// Source: src/lib/Model/class_si_model_configconsumer.php


abstract class Si_Model_ConfigConsumer extends Si_Model {

	protected $_file;
	protected $_raw;
	protected $_data = array();

	/**
	 * Consume the config file
	 *
	 * @return bool
	 */
	abstract public function consume ();

	/**
	 * Update raw values in config file
	 *
	 * @param string $key Key to update
	 * @param string $value New value to set
	 *
	 * @return bool
	 */
	abstract public function update_raw ($key, $value);

	/**
	 * Sets internal file path
	 *
	 * @param string $path Absolute path to a file
	 *
	 * @return bool
	 */
	public function set_file ($path) {
		return !!($this->_file = $path);
	}

	/**
	 * Checks whether we have the file and are able to access it
	 *
	 * @return bool
	 */
	public function has_file () {
		return !empty($this->_file) && is_readable($this->_file);
	}

	/**
	 * Data element setter
	 *
	 * @param string $key Key to set
	 * @param mixed $value Value
	 */
	public function set ($key, $value) {
		if (!is_array($this->_data)) $this->_data = array();
		return $this->_data[$key] = $value;
	}

	/**
	 * Data element getter
	 *
	 * @param string $key Key to get
	 * @param mixed $fallback Optional fallback value
	 *
	 * @return mixed Key value on success, or fallback value on failure
	 */
	public function get ($key, $fallback=false) {
		return isset($this->_data[$key])
			? $this->_data[$key]
			: $fallback
		;
	}

	/**
	 * Writes buffered content to the destination file
	 *
	 * @return bool
	 */
	public function write () {
		if (empty($this->_file) || !is_writable($this->_file)) return false;
		return !!file_put_contents($this->_file, $this->_raw);
	}
}



// Source: src/lib/Model/class_si_model_database.php


class Si_Model_Database extends Si_Model {

	private $_host;
	private $_port;
	private $_user;
	private $_password;
	private $_name;
	private $_tbl_prefix;

	private $_config;

	private $_handle;

	private function __construct () {}

	public static function from_config (Si_Model_Wpconfig $conf) {
		$me = new self;
		$env = new Si_Model_Env;

		$_dbhost = $env->can_override() ? $env->get('_dbhost') : false;
		$me->_host = !empty($_dbhost) ? $_dbhost : $conf->get('DB_HOST');

		if (false !== strpos($me->_host, ':')) {
			$tmp = explode(':', $me->_host, 2);
			if (!empty($tmp[1]) && is_numeric($tmp[1])) {
				$me->_host = $tmp[0];
				$me->_port = $tmp[1];
			}
		}

		$_dbuser = $env->can_override() ? $env->get('_dbuser') : false;
		$me->_user =  !empty($_dbuser) ? $_dbuser : $conf->get('DB_USER');

		$_dbpassword = $env->can_override() ? $env->get('_dbpassword') : false;
		$me->_password =  !empty($_dbpassword) ? $_dbpassword : $conf->get('DB_PASSWORD');

		$_dbname = $env->can_override() ? $env->get('_dbname') : false;
		$me->_name =  !empty($_dbname) ? $_dbname : $conf->get('DB_NAME');

		$_dbtable_prefix = $env->can_override() ? $env->get('_dbtable_prefix') : false;
		$me->_tbl_prefix =  !empty($_dbtable_prefix) ? $_dbtable_prefix : $conf->get('$table_prefix');

		$me->_config = $conf;

		return $me;
	}

	/**
	 * Connects to the database with credentials already set
	 *
	 * @return bool
	 */
	public function connect () {
		if ($this->_handle) return true;

		$port = !empty($this->_port) && is_numeric($this->_port)
			? $this->_port
			: ini_get("mysqli.default_port")
		;

		$this->_handle = @mysqli_connect($this->_host, $this->_user, $this->_password, $this->_name, (int)$port);

		return !!$this->_handle;
	}

	/**
	 * Gets DB connection error code
	 *
	 * @return int
	 */
	public function get_connection_error_code () {
		return mysqli_connect_errno();
	}

	/**
	 * Check if database is empty (has no tables)
	 *
	 * @return bool
	 */
	public function is_db_empty () {
		if (!$this->_handle) return true; // Invalid handle
		$rows = mysqli_query($this->_handle, "SELECT table_name FROM information_schema.tables WHERE table_schema = '{$this->_name}'");
		return $rows
		 	? !mysqli_num_rows($rows)
			: true
		;
	}

	/**
	 * Gets table prefix
	 *
	 * @return string Table prefix
	 */
	public function get_prefix () {
		return $this->_tbl_prefix;
	}

	/**
	 * Restores table from SQL file
	 *
	 * @param string $path Full path to SQL file
	 *
	 * @return bool
	 */
	public function restore_from_file ($path) {
		if (!file_exists($path) || !is_readable($path)) return false;
		$sql = file_get_contents($path);

		$old_pfx = $this->_config->get('$table_prefix');
		if ($old_pfx !== $this->_tbl_prefix) {
			$src_name = basename($path, '.sql');
			$old_pfx = preg_quote($old_pfx, '/');
			$dest_name = preg_replace("/{$old_pfx}/", $this->_tbl_prefix, $src_name);
			if ($src_name !== $dest_name) {
				$esc_name = preg_quote("`{$src_name}`", '/');
				$sql = preg_replace("/{$esc_name}/", "`{$dest_name}`", $sql);
			}
		}

		return !empty($sql)
			? $this->restore_from_sql($sql)
			: false
		;
	}

	/**
	 * Restores table from SQL buffer
	 *
	 * @param string $sql Buffer
	 *
	 * @return bool
	 */
	public function restore_from_sql ($sql) {
		if (empty($sql)) return false;
		if (!$this->connect()) return false;

		return $this->restore_databases($sql);
	}

	/**
	 * Perform a query
	 *
	 * @param string $sql SQL query to perform
	 *
	 * @return bool
	 */
	public function query ($sql) {
		if (!$this->connect()) return false;

/*
// ---------------------------------------------------------
// Collation fix for compatibility reasons with older MySQLs
// ---------------------------------------------------------
		// Try to clean up the collations
		$sql = preg_replace('/\butf8mb4_unicode_520_ci\b/', 'utf8_unicode_ci', $sql);
		// Now try to clean up charsets
		$sql = preg_replace('/\bCHARSET=utf8mb4\b/', 'CHARSET=utf8', $sql);
*/
		$result = mysqli_query($this->_handle, $sql);
		if (false === $result) {
			$log_query = trim($sql);
			if (!!preg_match('/\binsert\b/i', $sql)) {
				preg_match('/\binto\s+(\S+?)\s/i', $sql, $matches);
				$tbl = !empty($matches[1]) ? "(into {$matches[1]})" : "";
				$log_query = "An insert {$tbl} query";
			}
			Si_Helper_Log::log("[[Query]] {$log_query}");
			Si_Helper_Log::log("Error: " . $this->last_query_error());
		}

		return false !== $result;
	}

	/**
	 * Return string description of the last error
	 *
	 * @return string
	 */
	public function last_query_error () {
		return mysqli_error($this->_handle);
	}






	function restore_databases( $buffer ) {
		$sql                         = '';
		$start_pos                   = 0;
		$i                           = 0;
		$len                         = 0;
		$big_value                   = 2147483647;
		$delimiter_keyword           = 'DELIMITER '; // include the space because it's mandatory
		$length_of_delimiter_keyword = strlen( $delimiter_keyword );
		$sql_delimiter               = ';';
		$finished                    = false;

		$status = true;

		$len = strlen( $buffer );

		$this->query("SET SQL_MODE='ALLOW_INVALID_DATES';");
		$this->query("SET FOREIGN_KEY_CHECKS=0");
		$this->query("SET NAMES utf8mb4");

		// Grab some SQL queries out of it
		while ( $i < $len ) {
			//@set_time_limit( 300 );

			$found_delimiter = false;

			// Find first interesting character
			$old_i = $i;

			// this is about 7 times faster that looking for each sequence i
			// one by one with strpos()
			if ( preg_match( '/(\'|"|#|-- |\/\*|`|(?i)(?<![A-Z0-9_])' . $delimiter_keyword . ')/', $buffer, $matches, PREG_OFFSET_CAPTURE, $i ) ) {
				// in $matches, index 0 contains the match for the complete
				// expression but we don't use it

				$first_position = $matches[1][1];
			} else {
				$first_position = $big_value;
			}

			$first_sql_delimiter = strpos( $buffer, $sql_delimiter, $i );
			if ( $first_sql_delimiter === false ) {
				$first_sql_delimiter = $big_value;
			} else {
				$found_delimiter = true;
			}

			// set $i to the position of the first quote, comment.start or delimiter found
			$i = min( $first_position, $first_sql_delimiter );
			//echo "i=[". $i ."]<br />";

			if ( $i == $big_value ) {
				// none of the above was found in the string

				$i = $old_i;
				if ( ! $finished ) {
					break;
				}

				// at the end there might be some whitespace...
				if ( trim( $buffer ) == '' ) {
					$buffer = '';
					$len    = 0;
					break;
				}

				// We hit end of query, go there!
				$i = strlen( $buffer ) - 1;
			}

			// Grab current character
			$ch = $buffer[ $i ];

			// Quotes
			if ( strpos( '\'"`', $ch ) !== false ) {
				$quote = $ch;
				$endq  = false;

				while ( ! $endq ) {
					// Find next quote
					$pos = strpos( $buffer, $quote, $i + 1 );

					// No quote? Too short string
					if ( $pos === false ) {
						// We hit end of string => unclosed quote, but we handle it as end of query
						if ( $finished ) {
							$endq = true;
							$i    = $len - 1;
						}

						$found_delimiter = false;
						break;
					}

					// Was not the quote escaped?
					$j = $pos - 1;

					while ( $buffer[ $j ] == '\\' ) {
						$j --;
					}

					// Even count means it was not escaped
					$endq = ( ( ( ( $pos - 1 ) - $j ) % 2 ) == 0 );

					// Skip the string
					$i = $pos;

					if ( $first_sql_delimiter < $pos ) {
						$found_delimiter = false;
					}
				}

				if ( ! $endq ) {
					break;
				}

				$i ++;

				// Aren't we at the end?
				if ( $finished && $i == $len ) {
					$i --;
				} else {
					continue;
				}
			}

			// Not enough data to decide
			if ( ( ( $i == ( $len - 1 ) && ( $ch == '-' || $ch == '/' ) )
			       || ( $i == ( $len - 2 ) && ( ( $ch == '-' && $buffer[ $i + 1 ] == '-' )
			                                    || ( $ch == '/' && $buffer[ $i + 1 ] == '*' ) ) ) ) && ! $finished
			) {
				break;
			}


			// Comments
			if ( $ch == '#'
			     || ( $i < ( $len - 1 ) && $ch == '-' && $buffer[ $i + 1 ] == '-'
			          && ( ( $i < ( $len - 2 ) && $buffer[ $i + 2 ] <= ' ' )
			               || ( $i == ( $len - 1 ) && $finished ) ) )
			     || ( $i < ( $len - 1 ) && $ch == '/' && $buffer[ $i + 1 ] == '*' )
			) {
				// Copy current string to SQL
				if ( $start_pos != $i ) {
					$sql .= substr( $buffer, $start_pos, $i - $start_pos );
				}

				// Skip the rest
				$start_of_comment = $i;

				// do not use PHP_EOL here instead of "\n", because the export
				// file might have been produced on a different system
				$i = strpos( $buffer, $ch == '/' ? '*/' : "\n", $i );

				// didn't we hit end of string?
				if ( $i === false ) {
					if ( $finished ) {
						$i = $len - 1;
					} else {
						break;
					}
				}

				// Skip *
				if ( $ch == '/' ) {
					$i ++;
				}

				// Skip last char
				$i ++;

				// We need to send the comment part in case we are defining
				// a procedure or function and comments in it are valuable
				$sql .= substr( $buffer, $start_of_comment, $i - $start_of_comment );

				// Next query part will start here
				$start_pos = $i;

				// Aren't we at the end?
				if ( $i == $len ) {
					$i --;
				} else {
					continue;
				}
			}

			// Change delimiter, if redefined, and skip it (don't send to server!)
			if ( strtoupper( substr( $buffer, $i, $length_of_delimiter_keyword ) ) == $delimiter_keyword
			     && ( $i + $length_of_delimiter_keyword < $len )
			) {
				// look for EOL on the character immediately after 'DELIMITER '
				// (see previous comment about PHP_EOL)
				$new_line_pos = strpos( $buffer, "\n", $i + $length_of_delimiter_keyword );

				// it might happen that there is no EOL
				if ( false === $new_line_pos ) {
					$new_line_pos = $len;
				}

				$sql_delimiter = substr( $buffer, $i + $length_of_delimiter_keyword, $new_line_pos - $i - $length_of_delimiter_keyword );
				$i             = $new_line_pos + 1;

				// Next query part will start here
				$start_pos = $i;
				continue;
			}

			if ( $found_delimiter || ( $finished && ( $i == $len - 1 ) ) ) {
				$tmp_sql = $sql;

				if ( $start_pos < $len ) {
					$length_to_grab = $i - $start_pos;

					if ( ! $found_delimiter ) {
						$length_to_grab ++;
					}

					$tmp_sql .= substr( $buffer, $start_pos, $length_to_grab );
					unset( $length_to_grab );
				}

				// Do not try to execute empty SQL
				if ( ! preg_match( '/^([\s]*;)*$/', trim( $tmp_sql ) ) ) {
					$sql = $tmp_sql;
					//echo "sql=[". $sql ."]<br />";
					$ret_db = $this->query( $sql );
					if (!$ret_db) $status = false;
					//echo "ret_db<pre>"; print_r($ret_db); echo "</pre>";

					$buffer = substr( $buffer, $i + strlen( $sql_delimiter ) );
					// Reset parser:

					$len       = strlen( $buffer );
					$sql       = '';
					$i         = 0;
					$start_pos = 0;

					// Any chance we will get a complete query?
					//if ((strpos($buffer, ';') === FALSE) && !$GLOBALS['finished']) {
					if ( ( strpos( $buffer, $sql_delimiter ) === false ) && ! $finished ) {
						break;
					}
				} else {
					$i ++;
					$start_pos = $i;
				}
			}

		}

		return $status;

	}


}



// Source: src/lib/Model/class_si_model_env.php


class Si_Model_Env extends Si_Model {

	const TEMP_DIR = 'TEMP_DIR';
	const PATH_ROOT = 'PATH_ROOT';
	const ARCHIVE = 'ARCHIVE';
	const TARGET = 'TARGET';
	const TARGET_URL = 'TARGET_URL';

	private $_overrides = array();
	private $_can_override = false;

	public function __construct () {
		if (@session_start()) {
			$_SESSION['si_overrides'] = !empty($_SESSION['si_overrides']) && is_array($_SESSION['si_overrides'])
				? $_SESSION['si_overrides']
				: array()
			;
			$this->_overrides = $_SESSION['si_overrides'];
			$this->_can_override = true;
		}
	}

	/**
	 * Check if we can offer data overrides
	 *
	 * @return bool
	 */
	public function can_override () {
		return !!$this->_can_override;
	}

	/**
	 * Checks if we have any overrides
	 *
	 * @return bool
	 */
	public function has_overrides () {
		if (!$this->can_override()) return false;
		return !empty($this->_overrides) && !empty($_SESSION['si_overrides']);
	}

	/**
	 * Option getter
	 *
	 * @param string $what Value key to get
	 * @param mixed $fallback Optional fallback
	 *
	 * @return mixed Value or fallback
	 */
	public function get ($what, $fallback=false) {
		$define = 'SI_' . strtoupper($what);
		$method = 'get_' . strtolower($what);

		if (method_exists($this, $method)) return call_user_func(array($this, $method));

		if (isset($this->_overrides[$what])) return $this->_overrides[$what];
		if (defined($define)) return constant($define);

		return $fallback;
	}

	/**
	 * Sets override value
	 *
	 * @param string $what Value key to set
	 * @param mixed $value Value to set
	 *
	 * @return bool
	 */
	public function set ($what, $value) {
		if (!$this->can_override()) return false;

		$this->_overrides[$what] = $value;
		$_SESSION['si_overrides'][$what] = $value;

		return true;
	}

	/**
	 * Clears an override value
	 *
	 * @param string $what Value key to unset
	 *
	 * @return bool
	 */
	public function drop ($what) {
		if (!$this->can_override()) return false;

		unset($this->_overrides[$what]);
		if (array_key_exists($what, $this->_overrides)) return false;

		unset($_SESSION['si_overrides'][$what]);
		if (array_key_exists($what, $_SESSION['si_overrides'])) return false;

		return true;
	}

	/**
	 * Gets the path root define
	 *
	 * @return string Full path to root
	 */
	public function get_path_root () {
		$path = defined('SI_PATH_ROOT') && SI_PATH_ROOT
			? SI_PATH_ROOT
			: dirname(__FILE__)
		;
		$path = Si_Model_Fs::normalize_any($path);

		return preg_match('/\/(src|build)(\/|$)/', $path)
			? dirname($path)
			: $path
		;
	}

	/**
	 * Gets archive source file to extract
	 *
	 * @return mixed Archive file relative path as string, or (bool)false on failure
	 */
	public function get_archive () {
		$archive = '';
		$fs = Si_Model_Fs::path(false);
		$locations = array(
			str_repeat('[0-9a-f]', 12) . '.zip',    // Snapshot v4
			'full_*.zip',                           // Snapshot v3
			'build/data/*.zip',
		);

		foreach ($locations as $loc) {
			if (!$fs->exists_rx($loc)) continue;
			$archive = $fs->resolve_rx($loc);
		}

		return !empty($archive)
			? Si_Model_Fs::self_resolve($archive)
			: false
		;
	}

	/**
	 * Gets target directory relative path
	 *
	 * @return string Target directory
	 */
	public function get_target () {
		$fs = Si_Model_Fs::path(false);
		if ($fs->exists('/src') && $fs->exists('/build')) return 'build/target';
		return '';
	}

	/**
	 * Gets target URL
	 *
	 * @return string
	 */
	public function get_target_url () {
		if (!empty($this->_overrides[self::TARGET_URL])) return $this->_overrides[self::TARGET_URL];

		$target = $this->get(self::TARGET);
		return Si_Controller::get_url($target);
	}
}



// Source: src/lib/Model/class_si_model_fs.php


class Si_Model_Fs extends Si_Model {

	private $_root;

	private function __construct () {}

	public static function load ($path) {
		$me = new self;
		$me->set_root($path);
		return $me;
	}

	public static function path ($path) {
		$me = new self;
		$env = new Si_Model_Env;
		$me->set_root($env->get(Si_Model_Env::PATH_ROOT));

		$full = self::self_resolve($path);
		$real = self::normalize_real($full);
		if (!file_exists($real)) {
			$me->mkdir($path);
		}
		$me->set_root($real);
		return $me;
	}

	public static function temp ($relative_path) {
		$me = new self;
		$me->set_root(sys_get_temp_dir());

		if (!$me->exists($me->resolve($relative_path))) {
			$path = $me->mkdir($relative_path);
			$me->set_root($path);
		}

		return $me;
	}

	/**
	 * Normalize path convenience method
	 *
	 * @param string $path Path to normalize
	 * @param bool $fake Whether this should resolve to an already existing FS item
	 *
	 * @return mixed Full path as string on success, (bool)false on failure
	 */
	public static function normalize ($path, $fake=false) {
		return $fake
			? self::normalize_any($path)
			: self::normalize_real($path)
		;
	}

	/**
	 * Normalizes path to an existing FS item
	 *
	 * @param string $path Path to normalize
	 *
	 * @return mixed Full path as string on success, (bool)false on failure
	 */
	public static function normalize_real ($path) {
		$path = self::normalize_any(realpath($path));
		return !empty($path) && file_exists($path)
			? $path
			: false
		;
	}

	/**
	 * Normalizes path to a potentially non-existent FS item
	 *
	 * @param string $path Path to normalize
	 *
	 * @return mixed Full path as string on success, (bool)false on failure
	 */
	public static function normalize_any ($path) {
		if (!is_string($path)) return false;
		return str_replace('\\', '/', $path);
	}

	/**
	 * Adds trailing slash to string
	 *
	 * @param string $str Source
	 *
	 * @return string Source with singular trailing slash
	 */
	public static function trailing ($str) {
		return self::untrailing($str) . '/';
	}

	/**
	 * Strips trailing slash from a string
	 *
	 * @param string $str Sournce
	 *
	 * @return string Source without trailing slashes
	 */
	public static function untrailing ($str) {
		return rtrim($str, '\\/');
	}

	/**
	 * Resolves a relative path to a location within distribution folder
	 *
	 * @param string $relative_path Path relative to distribution folder
	 *
	 * @return mixed Full path as string on success, (bool)false on failure
	 */
	public static function self_resolve ($relative_path) {
		$env = new Si_Model_Env;
		$path = $env->get(Si_Model_Env::PATH_ROOT);
		return !empty($path)
			? self::_resolve($relative_path, $path)
			: false
		;
	}

	protected static function _resolve ($relative_path, $root) {
		return self::normalize_any(self::trailing($root) . $relative_path);
	}

	/**
	 * Root path getter
	 *
	 * @return string Root path
	 */
	public function get_root () {
		return $this->_root;
	}

	/**
	 * Root path setter
	 *
	 * @param string $path Root path
	 *
	 * @return bool
	 */
	public function set_root ($path) {
		return !!$this->_root = self::normalize_any($path);
	}

	/**
	 * Change FS object root to a directory within it
	 *
	 * @param string $relative_path Root-relative path to chroot to
	 *
	 * @return bool
	 */
	public function chroot ($relative_path) {
		$root = $this->resolve($relative_path);
		return file_exists($root)
			? $this->set_root($root)
			: false
		;
	}

	/**
	 * Resolves a relative path to a root-relative location
	 *
	 * @param string $relative_path Path relative to instance root
	 *
	 * @return mixed Full path as string on success, (bool)false on failure
	 */
	public function resolve ($relative_path) {
		$root = $this->get_root();
		return !empty($root)
			? self::_resolve($relative_path, $root)
			: false
		;
	}

	/**
	 * Check whether a path exists
	 *
	 * @param string $relative_path Optional root-relative path to check
	 *
	 * @return bool
	 */
	public function exists ($relative_path=false) {
		$path = $this->resolve($relative_path);
		return file_exists($path);
	}

	/**
	 * Check whether a path pattern exists
	 *
	 * @param string $pattern Pattern to check
	 *
	 * @return bool
	 */
	public function exists_rx ($pattern) {
		$path = self::trailing($this->get_root()) . $pattern;
		$set = glob($path);

		return !empty($set);
	}

	/**
	 * Resolves the pattern regex to an actual file location
	 *
	 * @param string $pattern Pattern to check
	 *
	 * @return mixed Pattern as string, or (bool)false on failure
	 */
	public function resolve_rx ($pattern) {
		$path = self::trailing($this->get_root()) . $pattern;
		$set = glob($path);

		$file = !empty($set) && is_array($set)
			? reset($set)
			: false
		;

		if (empty($file)) return $file;

		return $this->relative($file);
	}

	/**
	 * Check if a directory is empty
	 *
	 * @param string $relative_path Optional root-relative path to check
	 *
	 * @return bool
	 */
	public function is_empty ($relative_path=false) {
		$path = $this->resolve($relative_path);
		$handle = opendir($path);
		while ($file = readdir($handle)) {
		    if (in_array($file, array('.', '..'))) continue;
		    return false;
		}
		return true;
	}

	/**
	 * Recursive directory creation
	 *
	 * @param string $relative_path Optional root-relative path
	 *
	 * @return string Final created path
	 */
	public function mkdir ($relative_path=false) {
		if ($this->exists($relative_path)) return $this->resolve($relative_path);

		$normal = explode('/', self::normalize_any($relative_path));
		$path = $this->get_root();
		foreach ($normal as $frag) {
			$path = self::_resolve($frag, $path);
			if (file_exists($path) && is_dir($path)) continue;
			mkdir($path);
		}

		return $path;
	}

	/**
	 * Recursively remove directory and its contents
	 *
	 * @param string $relative_path Optional root-relative path
	 *
	 * @return bool
	 */
	public function rmdir ($relative_path=false) {
		if (!$this->exists($relative_path)) return true;

		$path = $this->resolve($relative_path);
		$list = array_diff(scandir($path), array('.', '..'));

		$status = true;

		foreach ($list as $item) {
			$tmp = self::_resolve($item, $path);
			if (is_dir($tmp)) {
				$rel = $this->relative($tmp);
				$this->rmdir($rel);
				$res = rmdir($tmp);
				if (!$res) $status = false;
			} else if (is_file($tmp)) {
				$res = unlink($tmp);
				if (!$res) $status = false;
			}
		}

		return $status;
	}

	/**
	 * Removes the relative path (file)
	 *
	 * @param string $relative_path Optional root-relative path
	 *
	 * @return bool
	 */
	public function rm ($relative_path=false) {
		if (!$this->exists($relative_path)) return true;

		$path = $this->resolve($relative_path);
		if (!is_file($path) || !is_writable($path)) return false;

		return unlink($path);
	}

	/**
	 * List FS items in a path (recursively)
	 *
	 * @param string $relative_path Optional root-relative path
	 *
	 * @return array List of items
	 */
	public function ls ($relative_path=false) {
		$path = self::trailing($this->resolve($relative_path));

		$data = array_diff(scandir($path), array('.', '..'));

		$subs = array();
		foreach ($data as $key => $value) {
			$tmp = self::_resolve($value, $path);
			if (is_dir($tmp)) {
				unset($data[$key]);
				$rel = $this->relative($tmp);
				$subs[] = $this->ls($rel);
			} else if (is_file($tmp))  {
				$data[$key] = $tmp;
			}
		}

		if (!empty($subs)) {
			foreach ($subs as $sub) {
				$data = array_merge($data, $sub);
			}
		}

		asort($data);
		return $data;
	}

	/**
	 * Lists FS items matching a pattern within a directory
	 *
	 * Non-recursive
	 *
	 * @param string $pattern Pattern to match
	 * @param string $relative_path Optional root-relative pattern
	 *
	 * @return array
	 */
	public function glob ($pattern, $relative_path=false) {
		$path = self::trailing($this->resolve($relative_path));

		$data = glob("{$path}{$pattern}");
		if (!is_array($data)) $data = array();
		asort($data);

		return $data;
	}

	/**
	 * Copy the entire paths
	 *
	 * Similar to cp -R, hence the method name
	 *
	 * @param Si_Model_Fs $source_fs Source FS model
	 * @param string $relpath Relative path (both source and destination)
	 *
	 * @return bool
	 */
	public function cpr (Si_Model_Fs $source_fs, $relpath) {
		$source_fullpath = self::normalize_real($source_fs->resolve($relpath));

		if (empty($source_fullpath)) return false;
		if (!is_file($source_fullpath) || !is_readable($source_fullpath)) return false;

		$destination_fullpath = $this->resolve($relpath);
		$dirname = dirname($relpath);
		if ('.' !== $dirname) $this->mkdir($dirname);

		$status = copy($source_fullpath, $destination_fullpath);

		return $status;
	}

	/**
	 * Converts an absolute path to root-relative form
	 *
	 * @param string $full_path Absolute path
	 * @param bool $fail Failure flag (optional) - return (bool)false if path is not root-relative
	 *
	 * @return string Root-relative path
	 */
	public function relative ($full_path, $fail=false) {
		$full_path = self::normalize_any($full_path);
		$root = self::trailing($this->get_root());

		if ($fail && !preg_match('/^' . preg_quote($root, '/') . '/', $full_path)) return false;

		return preg_replace('/' . preg_quote($root, '/') . '/', '', $full_path);
	}
}



// Source: src/lib/Model/class_si_model_htaccess.php


class Si_Model_Htaccess extends Si_Model_ConfigConsumer {

	const REWRITE_BASE = 'RewriteBase';
	const REWRITE_RULE = 'RewriteRule';

	const FILE_NAME = '.htaccess';

	public static function load ($destination) {
		$path = false;
		if (is_object($destination) && $destination->exists(self::FILE_NAME)) {
			$path = $destination->resolve(self::FILE_NAME);
		}

		$me = new self;

		if (!empty($path)) {
			$me->_file = $path;
			$me->consume();
		}

		return $me;
	}

	/**
	 * Consume the config file
	 *
	 * @return bool
	 */
	public function consume () {
		$raw = $this->read_file();
		return $this->process_content($raw);
	}

	/**
	 * Reads file content
	 *
	 * @return bool|string Bool false on failure, content on success
	 */
	public function read_file () {
		if (empty($this->_file) || !is_readable($this->_file)) return false;

		$raw = file_get_contents($this->_file);
		$this->_raw = $raw;

		return $raw;
	}

	/**
	 * Processes raw file content into data blocks
	 *
	 * @param string $raw Raw file content.
	 *
	 * @return bool
	 */
	public function process_content ($raw) {
		if (empty($raw)) return false;

		$lines = array_filter(array_map('trim', explode("\n", $raw)));
		$data = array();
		foreach ($lines as $line) {
			if (!preg_match('/^[A-Z]/', trim($line))) continue;
			if (!strstr($line, ' ')) continue;
			$tuple = explode(' ', $line, 2);

			if (self::REWRITE_RULE === $tuple[0]) {
				$triple = explode(' ', $line, 3);
				$key = $triple[0] . ' ' . $triple[1];
				if (isset($data[$key])) continue;
				$data[$key] = $triple[2];
			} else {
				if (isset($data[$tuple[0]])) continue;
				$data[$tuple[0]] = $tuple[1];
			}

		}
		$this->_data = $data;

		return !empty($this->_data);
	}

	/**
	 * Gets internal data storage content
	 *
	 * @return array
	 */
	public function get_data () {
		return $this->_data;
	}

	/**
	 * Gets full text content of the file
	 *
	 * @return string
	 */
	public function get_raw () {
		return !empty($this->_raw) ? $this->_raw : '';
	}

	/**
	 * Update raw values in config file
	 *
	 * @param string $key Key to update
	 * @param string $value New value to set
	 *
	 * @return bool
	 */
	public function update_raw ($key, $value) {
		if (!in_array($key, array_keys($this->_data))) return false;

		$old = $this->get($key);
		$rx = '/' .
			preg_quote($key, '/') .
			'\s+' .
			preg_quote($old, '/') .
		'/';

		$this->_raw = preg_replace($rx, "{$key} {$value}", $this->_raw);

		return true;
	}

	/**
	 * Updates rewrite bases using the internal API
	 *
	 * @param string $new_base New base to use
	 *
	 * @return bool
	 */
	public function update_raw_base ($new_base) {
		$old = $this->get(self::REWRITE_BASE);
		$old_rx = '/' . preg_quote($old, '/') . '/';

		foreach ($this->_data as $key => $value) {
			if (!preg_match($old_rx, $value)) continue;
			$new_value = preg_replace($old_rx, $new_base, $value, 1); // Only first instance, so we keep rewrite rules
			$this->update_raw($key, $new_value);
		}

		return true;
	}


}



// Source: src/lib/Model/class_si_model_manifest.php


class Si_Model_Manifest extends Si_Model_ConfigConsumer {

	const LINE_DELIMITER = "\n";
	const ENTRY_DELIMITER = ":";

	const FILE_NAME = 'snapshot_manifest.txt';

	protected $_file;
	protected $_data;

	public static function load ($destination) {
		$path = false;
		if (is_object($destination) && $destination->exists(self::FILE_NAME)) {
			$path = $destination->resolve(self::FILE_NAME);
		}

		$me = new self;

		if (!empty($path)) {
			$me->set_file($path);
			$me->consume();
		}

		return $me;
	}

	/**
	 * Satisfy interface
	 */
	public function update_raw ($key, $value) { return false; }

	/**
	 * Consume the manifest file
	 *
	 * @return bool
	 */
	public function consume () {
		if (!$this->has_file()) return false;

		$raw = file_get_contents($this->_file);
		if (empty($raw)) return false;

		$data = explode(self::LINE_DELIMITER, $raw);
		if (empty($data)) return false;

		foreach ($data as $line) {
			$line = trim($line);
			if (empty($line)) continue;

			list($key, $value) = explode(self::ENTRY_DELIMITER, $line, 2);

			// @TODO this is quite simplistic, improve!
			$value = preg_match('/\{.*?:/', $value)
				? unserialize($this->deep_trim($value))
				: $value
			;

			$this->_data[$key] = $value;
		}

		return true;
	}

	/**
	 * Get manifest queue for a given type
	 *
	 * @param string $type Queue type
	 *
	 * @return array Queue sources
	 */
	public function get_queue ($type) {
		$queues = $this->get('QUEUES', array());
		$result = array();
		if (is_array($queues)) foreach ($queues as $queue) {
			if (!empty($queue['type']) && $type === $queue['type']) {
				$result = $queue;
				break;
			}
		}
		return $result;
	}

	/**
	 * Get manifest sources for a queue type
	 *
	 * @param string $type Queue type
	 *
	 * @return array Queue sources
	 */
	public function get_sources ($type) {
		$queue = $this->get_queue($type);
		return !empty($queue['sources']) && is_array($queue['sources'])
			? $queue['sources']
			: array()
		;
	}

}



// Source: src/lib/Model/class_si_model_request.php


class Si_Model_Request extends Si_Model {

	const REQUEST_GET = 'get';
	const REQUEST_POST = 'post';
	const REQUEST_COOKIE = 'cookie';
	const REQUEST_EMPTY = 'empty';

	private $_data = array();

	private function __construct () {}

	public static function load ($method) {
		$method = !empty($method) && in_array($method, array(self::REQUEST_GET, self::REQUEST_POST, self::REQUEST_COOKIE, self::REQUEST_EMPTY))
			? $method
			: self::REQUEST_GET
		;
		$source = array();
		if (self::REQUEST_GET === $method) $source = $_GET;
		if (self::REQUEST_POST === $method) $source = $_POST;
		if (self::REQUEST_COOKIE === $method) $source = $_COOKIE;

		$me = new self;
		$me->_data = self::strip($source);

		return $me;
	}

	/**
	 * Recursively strip slashes from source if magic quotes are on
	 *
	 * @param mixed $val Value to process
	 *
	 * @return mixed
	 */
	public static function strip ($val) {
		if (version_compare(PHP_VERSION, '7.4.0', '>=') || !get_magic_quotes_gpc()) {
			return $val;
		}

		$val = is_array($val)
			? array_map(array(__CLASS__, 'strip'), $val)
			: stripslashes($val)
		;

		return $val;
	}

	/**
	 * Gets a value from request
	 *
	 * @param string $what Value key to get
	 * @param mixed $fallback Optional fallback value
	 *
	 * @return mixed
	 */
	public function get ($what, $fallback=false) {
		return isset($this->_data[$what])
			? $this->_data[$what]
			: $fallback
		;
	}

	/**
	 * Set request value by key
	 *
	 * @param string $what Value key to set
	 * @param mixed $value Value to set
	 *
	 * @return bool
	 */
	public function set ($what, $value) {
		return !!$this->_data[$what] = $value;
	}

	/**
	 * Convert the whole request to a GET-type query
	 *
	 * @return string
	 */
	public function to_query () {
		$str = http_build_query($this->_data, null, '&');
		return !empty($str)
			? "?{$str}"
			: ''
		;
	}

	/**
	 * Query string spawning convenience method
	 *
	 * Gets a query witout affecting the current request
	 *
	 * @param mixed $arg Optional array key (string), or arguments map (array)
	 * @param mixed $value Optional value
	 *
	 * @return string
	 */
	public function get_query ($arg=array(), $value=false) {
		$rq = new self;
		$rq->_data = $this->_data;

		if (!empty($arg) && is_array($arg) && empty($value)) {
			foreach ($arg as $key => $val) $rq->set($key, $val);
		} else if (!empty($arg)) {
			$rq->set($arg, $value);
		}

		return $rq->to_query();
	}

	/**
	 * Clean query string spawning convenience method
	 *
	 * Gets a "clean slate" query witout affecting the current request
	 *
	 * @param mixed $arg Optional array key (string), or arguments map (array)
	 * @param mixed $value Optional value
	 *
	 * @return string
	 */
	public function get_clean_query ($arg=array(), $value=false) {
		$rq = new self;

		if (!empty($arg) && is_array($arg) && empty($value)) {
			foreach ($arg as $key => $val) $rq->set($key, $val);
		} else if (!empty($arg)) {
			$rq->set($arg, $value);
		}

		return $rq->to_query();
	}
}



// Source: src/lib/Model/class_si_model_wpconfig.php


class Si_Model_Wpconfig extends Si_Model_ConfigConsumer {

	const FILE_NAME = 'wp-config.php';

	public static function load ($destination) {
		$path = false;
		if (is_object($destination) && $destination->exists(self::FILE_NAME)) {
			$path = $destination->resolve(self::FILE_NAME);
		}

		$me = new self;

		if (!empty($path)) {
			$me->set_file($path);
			$me->consume();
		}

		return $me;
	}

	/**
	 * Consume the config file
	 *
	 * @return bool
	 */
	public function consume () {
		if (!$this->has_file()) return false;

		$raw = file_get_contents($this->_file);
		$this->_raw = $raw;
		if (empty($raw)) return false;

		return $this->parse();
	}

	/**
	 * Parses raw content
	 *
	 * @return bool
	 */
	public function parse () {
		$raw = $this->_raw;
		if (empty($raw)) return false;

		$tokens = token_get_all($raw);

		$result = array();
		$gathering = false;
		foreach ($tokens as $tok) {
			if ($gathering) {
				//if (is_array($tok) && !empty($tok[0]) && 315 === $tok[0]) { // Found a string
				if (is_array($tok) && !empty($tok[0]) && 'T_CONSTANT_ENCAPSED_STRING' === token_name($tok[0])) { // Found a string
					$tmp = isset($tok[1]) ? $tok[1] : false;
					if (!isset($key)) $key = trim($tmp, "'\"");
					else if (!isset($value)) $value = trim($tmp, "'\"");

					if (isset($key) && isset($value)) $result[$key] = $value;
				}

				// End condition, we're not gathering anymore
				if (in_array($tok, array(')', ';'))) {
					$gathering = false;
				}
			}

			// Restart the gathering cycle for defines
			//if (!$gathering && is_array($tok) && !empty($tok[0]) && 307 === $tok[0]) {
			if (!$gathering && is_array($tok) && !empty($tok[0]) && 'T_STRING' === token_name($tok[0])) {
				$gathering = true;
				unset($key);
				unset($value);
			}

			// Restart the gathering cycle for variables
			//if (!$gathering && is_array($tok) && !empty($tok[0]) && 309 === $tok[0]) {
			if (!$gathering && is_array($tok) && !empty($tok[0]) && 'T_VARIABLE' === token_name($tok[0])) {
				$gathering = true;
				$key = !empty($tok[1]) ? $tok[1] : false;
				if (empty($key)) unset($key);
				unset($value);
			}
		}
		$this->_data = array_merge($this->get_defaults(), $result);

		return !empty($this->_data);
	}

	/**
	 * Update raw values in config file
	 *
	 * @param string $key Key to update
	 * @param string $value New value to set
	 *
	 * @return bool
	 */
	public function update_raw ($key, $value) {
		$pattern = strstr($key, '$')
			? preg_quote($key, '/') . '\s*=\s*[\'"].*?[\'"];'
			: 'define\s*\(\s*[\'"]' . preg_quote($key, '/') . '[\'"]\s*,\s*[\'"].*?[\'"]\s*\);'
		;
		$value = strstr($key, '$')
			? "{$key} = '{$value}';"
			: "define('{$key}', '{$value}');"
		;

		$this->_raw = preg_replace("/{$pattern}/", $value, $this->_raw);

		return true;
	}

	/**
	* Gets the defaults so we have bare minimum we'll need to know defined
	*
	* @return array
	*/
	public function get_defaults () {
		return array(
			'DB_NAME' => '',
			'DB_USER' => '',
			'DB_PASSWORD' => '',
			'DB_HOST' => 'localhost',
			'DB_CHARSET' => 'utf8',
			'DB_COLLATE' => '',
			'$table_prefix' => 'wp_',
		);
	}

}



// Source: src/lib/View/class_si_view_img.php


class Si_View_Img extends Si_View {

	public function out ($params=array()) {
?>
<svg width="119px" height="118px" viewBox="0 0 119 118" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
    <!-- Generator: Sketch 42 (36781) - http://www.bohemiancoding.com/sketch -->
    <title>snapshot-wizard-character</title>
    <desc>Created with Sketch.</desc>
    <defs>
        <rect id="path-1" x="0" y="0" width="120" height="118"></rect>
        <path d="M27.3386462,5.56612876 C24.4777163,2.32799567 20.2960591,0.286288344 15.6381049,0.286288344 C7.01450164,0.286288344 0.0239114754,7.28439571 0.0239114754,15.9166933 C0.0239114754,20.7928887 2.25451942,25.1474852 5.75005688,28.0138307 L25.526342,28.0138307 C26.1762321,27.4809397 26.7824023,26.8966094 27.3386462,26.2670513 L27.3386462,5.56612876 L27.3386462,5.56612876 Z" id="path-3"></path>
        <path d="M21.2550043,1.32798089 C19.5120623,0.655047421 17.618256,0.286288344 15.6381049,0.286288344 C7.01450164,0.286288344 0.0239114754,7.28439571 0.0239114754,15.9166933 C0.0239114754,16.542477 0.060654802,17.1596981 0.132099648,17.7663037 L21.2550043,17.7663037 L21.2550043,1.32798089 L21.2550043,1.32798089 Z" id="path-5"></path>
        <path d="M11.0051393,19.0605711 C7.97352179,18.8524642 5.18540236,17.7587104 2.89610164,16.0359018 C1.08726885,13.4846503 0.0239114754,10.3659847 0.0239114754,6.99951534 C0.0239114754,4.52533491 0.598191583,2.18540393 1.62060294,0.106020552 L11.0051393,0.106020552 L11.0051393,19.0605711 L11.0051393,19.0605711 Z" id="path-7"></path>
        <path d="M0.74345369,20.6208331 C0.275988886,19.1361234 0.0239114754,17.5557802 0.0239114754,15.9166933 C0.0239114754,7.80919305 6.19035806,1.14322301 14.0846092,0.362677838 L14.0846092,20.6208331 L0.74345369,20.6208331 Z" id="path-9"></path>
        <path d="M0.74345369,20.6208331 C0.275988886,19.1361234 0.0239114754,17.5557802 0.0239114754,15.9166933 C0.0239114754,7.80919305 6.19035806,1.14322301 14.0846092,0.362677838 L14.0846092,20.6208331 L0.74345369,20.6208331 Z" id="path-11"></path>
        <path d="M20.6876459,1.29033358 C18.9719401,0.641306284 17.1121599,0.286288344 15.1692525,0.286288344 C8.0392471,0.286288344 2.02556982,5.07019586 0.156330886,11.6063172 L20.6876459,11.6063172 L20.6876459,1.29033358 L20.6876459,1.29033358 Z" id="path-13"></path>
        <path d="M20.6876459,1.29033358 C18.9719401,0.641306284 17.1121599,0.286288344 15.1692525,0.286288344 C8.0392471,0.286288344 2.02556982,5.07019586 0.156330886,11.6063172 L20.6876459,11.6063172 L20.6876459,1.29033358 L20.6876459,1.29033358 Z" id="path-15"></path>
        <path d="M15.0571498,8.6085761 C14.3804537,6.17954667 13.119553,3.994438 11.4353115,2.21427607 C9.20498033,0.985113497 6.64270164,0.286288344 3.91679344,0.286288344 C2.63419477,0.286288344 1.38771992,0.441093219 0.194995738,0.73305527 L0.194995738,15.8261135 L15.0571498,15.8261135 L15.0571498,8.6085761 L15.0571498,8.6085761 Z" id="path-17"></path>
        <path d="M15.0571498,8.6085761 C14.3804537,6.17954667 13.119553,3.994438 11.4353115,2.21427607 C9.20498033,0.985113497 6.64270164,0.286288344 3.91679344,0.286288344 C2.63419477,0.286288344 1.38771992,0.441093219 0.194995738,0.73305527 L0.194995738,15.8261135 L15.0571498,15.8261135 L15.0571498,8.6085761 L15.0571498,8.6085761 Z" id="path-19"></path>
        <path d="M8.68966459,22.3963371 C12.5973122,19.6271156 15.1481541,15.0660679 15.1481541,9.90886196 C15.1481541,6.21883153 13.842557,2.83451859 11.668033,0.193033436 L0.143140656,0.193033436 L0.143140656,22.3963371 L8.68966459,22.3963371 L8.68966459,22.3963371 Z" id="path-21"></path>
        <path d="M8.68966459,22.3963371 C12.5973122,19.6271156 15.1481541,15.0660679 15.1481541,9.90886196 C15.1481541,6.21883153 13.842557,2.83451859 11.668033,0.193033436 L0.143140656,0.193033436 L0.143140656,22.3963371 L8.68966459,22.3963371 L8.68966459,22.3963371 Z" id="path-23"></path>
        <path d="M0.155143279,10.0174357 C0.537154027,10.8116889 0.984264006,11.5686327 1.48954426,12.2813006 C3.84929391,14.0571253 6.73903505,15.1645837 9.87914328,15.3226586 L9.87914328,0.20542362 L0.155143279,0.20542362 L0.155143279,10.0174357 L0.155143279,10.0174357 Z" id="path-25"></path>
        <path d="M2.33699508,16.7285414 C1.62612997,16.6908823 0.928175313,16.6045633 0.246381967,16.4728515 L0.246381967,0.330357975 L2.33699508,0.330357975 L2.33699508,16.7285414 L2.33699508,16.7285414 Z" id="path-27"></path>
    </defs>
    <g id="Symbols" stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
        <g id="Header-Default">
            <g id="snapshot-wizard-character">
                <mask id="mask-2" fill="white">
                    <use xlink:href="#path-1"></use>
                </mask>
                <g id="Mask"></g>
                <g id="Snapshot" mask="url(#mask-2)">
                    <g transform="translate(-10.000000, 0.000000)">
                        <g id="Group-174" transform="translate(0.000000, 0.405028)">
                            <path d="M95.5651652,68.0234245 C95.5651652,68.0234245 100.413569,67.3621454 103.206991,68.0234245 C105.267598,68.5115227 120.41669,72.3290135 117.484018,87.426265 C114.551346,102.523517 101.660247,106.31801 95.5651652,104.420529 L95.5651652,68.0234245 Z" id="Fill-5" fill="#886752"></path>
                            <path d="M97.2743669,102.421157 C97.2743669,102.421157 102.910911,114.045402 107.753219,121.162718 C112.59459,128.280034 117.960606,127.214197 119.530793,132.715626 C123.855488,129.780467 128.797193,125.365994 129.000675,111.34866 C129.204626,97.3317948 119.762406,87.5496506 113.137521,83.6795954 C106.512636,79.8090709 97.2743669,102.421157 97.2743669,102.421157" id="Fill-7" fill="#886752"></path>
                            <path d="M126.532776,138.208608 C128.63558,131.715963 127.357957,125.161368 126.887698,123.594761 C126.44557,122.123427 127.25059,119.899764 127.278252,118.314853 C127.350455,114.073092 126.783612,109.926135 126.000629,105.756651 C125.274376,101.888003 122.638488,98.0066844 120.514586,94.7462825 C119.56938,93.2951291 118.064832,92.1945617 116.957871,90.8612089 C115.673685,89.3147825 115.549908,87.5740555 114.857412,85.7976598 C113.763111,82.9906261 110.918114,80.737396 108.299573,82.9615279 C107.12838,83.9569666 106.317265,86.4870985 104.665029,86.5696997 C103.718416,86.6166322 102.226527,85.7943745 101.31836,85.5113715 C100.142009,85.1448285 98.9164289,84.8768439 97.7133534,84.6158991 C96.9969469,84.4600831 96.5027764,84.296758 95.5650715,84.1540831 L95.5650715,104.420482 C96.5027764,104.680019 97.4029731,104.828325 98.4466387,104.860709 C100.366121,108.655203 104.263222,116.064439 107.731324,121.162718 C109.634865,123.960835 111.629363,125.492712 113.43257,126.657577 C112.562849,129.098537 111.266003,133.983273 111.935524,140.061034 C112.887763,148.702718 123.83078,156.86569 126.027822,166.444148 C128.104839,165.688065 127.636455,166.618267 129.914609,166.444148 C132.66818,166.23389 123.222209,152.280384 126.532776,138.208608" id="Fill-12" fill="#3E225E"></path>
                            <path d="M37.5121351,156.998556 C37.5121351,156.998556 38.5829941,137.375602 38.1310203,135.080602 C37.6795154,132.785602 33.660981,119.214596 29.8862498,114.842363 C26.1115187,110.47013 10.3787056,86.9208018 13.2794957,74.2668571 C16.179817,61.6129123 37.0470334,65.4623172 43.2485449,61.6434184 C49.4500564,57.8240503 56.1823089,49.8816607 61.9656039,51.9917466 C67.748899,54.1018325 82.4952466,63.3118693 82.4952466,63.3118693 C82.4952466,63.3118693 106.505181,68.7654276 108.696597,73.4023601 C110.888483,78.0392926 103.529843,104.28724 99.7476105,108.99551 L95.9658466,113.703311 C95.9658466,113.703311 95.2124007,119.934541 90.2308433,122.485793 C91.2402826,127.452191 91.4826793,127.837977 88.5800138,146.100826 C82.9312793,152.976909 71.3299941,173.296342 71.3299941,173.296342 L37.5121351,156.998556 Z" id="Fill-14" fill="#977558"></path>
                            <path d="M99.7481731,108.995417 C99.748642,108.994478 99.7495797,108.993539 99.7505174,108.992601 C99.8456944,108.873392 99.9441534,108.736818 100.044488,108.590858 C100.058085,108.570677 100.071681,108.551435 100.086216,108.530315 C100.18233,108.38764 100.280321,108.230886 100.380186,108.065214 C100.39894,108.033769 100.417226,108.002794 100.43598,107.97088 C100.535845,107.802392 100.636649,107.62311 100.738858,107.432564 C100.756675,107.40018 100.774491,107.36592 100.792308,107.332598 C100.898268,107.133134 101.005635,106.924754 101.114409,106.704171 C101.124255,106.684929 101.133632,106.664748 101.143478,106.645506 C101.25319,106.421168 101.364308,106.186975 101.476832,105.942926 C101.484803,105.924622 101.493711,105.907257 101.501681,105.888484 C101.615612,105.639742 101.73095,105.379266 101.847226,105.111282 C101.863167,105.074674 101.878639,105.039006 101.89458,105.001929 C102.008511,104.73676 102.12338,104.462205 102.239186,104.18014 C102.255596,104.140248 102.272006,104.100355 102.287947,104.060463 C102.406567,103.769481 102.525186,103.471929 102.644744,103.16499 C102.652714,103.143401 102.661622,103.120874 102.669593,103.099285 C102.923711,102.445045 103.179235,101.757484 103.43476,101.041763 C103.439449,101.028622 103.444137,101.01642 103.448826,101.003279 C103.574478,100.649877 103.700599,100.288966 103.825783,99.922892 C103.834222,99.8980178 103.842662,99.8731436 103.85157,99.8482693 C103.977222,99.4812571 104.102406,99.1086129 104.22759,98.7303368 L104.241186,98.6876282 C104.629396,97.5096221 105.012449,96.2832755 105.382373,95.0325239 C105.384249,95.0273613 105.385186,95.0226681 105.386593,95.0175055 C105.509432,94.6030914 105.630396,94.1858613 105.749953,93.7672233 C106.593419,90.8090669 107.35296,87.7631466 107.934337,84.9373399 C107.49596,85.2949656 107.060865,85.656346 106.608891,86.0078706 C102.876357,88.9092387 97.2266846,86.2185975 93.22456,85.1339871 C91.1789567,84.5797141 89.0747469,84.1371405 86.9480321,84.1085117 C86.1200387,84.0977172 85.2090584,84.0977172 84.4007567,84.2985883 C83.5816715,84.5022755 82.8844879,84.8481681 82.0072649,84.8857141 C80.12576,84.9659687 78.2967666,84.2418 76.5465403,83.6466957 C70.1753043,81.4807601 63.3942911,80.719984 56.6653207,80.7035577 C51.293678,80.6904166 45.9346944,81.1423767 40.5944649,81.6863245 C35.6250977,82.1917877 30.6341633,82.7526313 25.995337,84.7425699 C22.9492026,86.0496405 20.1487469,87.2924135 17.4486256,89.2556006 C16.903819,89.651711 16.3538551,90.0590853 15.8324911,90.4763153 C17.5273928,94.8311834 19.6109731,99.2034166 21.9552354,103.049067 L21.9552354,103.049067 C22.4240879,103.936091 22.9960879,104.794956 23.4986977,105.619091 C23.4996354,105.620499 23.510419,105.622377 23.5113567,105.623785 C23.6749862,105.891769 23.8423666,106.15553 24.0041207,106.416006 C24.0102157,106.425861 24.0186551,106.435717 24.0247502,106.445573 C24.5095436,107.226999 24.9863666,107.972757 25.4463108,108.677214 C25.4645961,108.704904 25.4833502,108.732594 25.5016354,108.760754 C25.6436977,108.978052 25.7852911,109.192064 25.9240715,109.401383 C25.9311043,109.412177 25.938137,109.422972 25.944701,109.433297 C26.2382026,109.874463 26.5232649,110.295917 26.799419,110.698128 C26.824737,110.735205 26.8500551,110.771812 26.8749043,110.80842 C26.9860223,110.969398 27.0952649,111.127091 27.203101,111.281499 C27.2288879,111.318576 27.2551436,111.356591 27.2809305,111.393199 C27.5373928,111.759742 27.784478,112.106104 28.0193731,112.430877 C28.0456289,112.467015 28.0714157,112.502214 28.0972026,112.537883 C28.1862846,112.659907 28.2734911,112.779116 28.3588223,112.895039 C28.3907043,112.937748 28.4225862,112.980456 28.4535305,113.022696 C28.5388616,113.137211 28.6218485,113.248441 28.70296,113.355917 C28.7254649,113.385484 28.7484387,113.41599 28.7704748,113.445088 C28.8708092,113.577907 28.9683305,113.705094 29.062101,113.82618 C29.0831993,113.853401 29.1028911,113.878275 29.1239895,113.905027 C29.1961928,113.997953 29.2665207,114.087594 29.3345043,114.173012 C29.3631043,114.20915 29.3912354,114.24388 29.4193666,114.27861 C29.4789108,114.352763 29.5361108,114.423162 29.5914354,114.490745 C29.6167534,114.52172 29.6430092,114.553634 29.6678584,114.583202 C29.741937,114.672843 29.8132026,114.757791 29.8802485,114.835229 C29.8821239,114.837576 29.8844682,114.840392 29.8863436,114.842269 C32.1274584,117.438107 34.454842,123.27745 36.0705075,128.073484 C36.1553698,128.325981 36.2383567,128.575193 36.3194682,128.821588 C37.2909305,131.773644 37.9618584,134.220236 38.1311141,135.080978 C38.2159764,135.511349 38.2469207,136.550435 38.2417633,137.955594 C38.2375436,139.126561 38.207537,140.551432 38.1625272,142.08941 C37.9731108,148.547794 37.51176,156.998463 37.51176,156.998463 L71.3300879,173.296248 C71.3300879,173.296248 71.8852092,172.324275 72.8116616,170.732794 C72.943878,170.50564 73.0840649,170.265346 73.2308157,170.013788 C73.4516452,169.63645 73.6879469,169.2333 73.9383141,168.807153 C77.6938223,162.416352 84.6084584,150.935251 88.5801075,146.101202 C90.7569895,132.403947 91.1648911,128.762453 90.8071567,125.702923 C90.7626157,125.320423 90.7058846,124.94684 90.6388387,124.563871 C90.5277207,123.926058 90.3889403,123.261963 90.230937,122.486168 C92.2549731,121.449429 93.5808879,119.805852 94.4421698,118.219064 C94.6404944,117.85299 94.8149075,117.489733 94.9668157,117.138208 C95.7774616,115.261377 95.9664092,113.703217 95.9664092,113.703217 L99.7481731,108.995417 Z" id="Fill-16" fill="#661E88"></path>
                            <path d="M51.0241816,26.4716282 C51.0241816,25.9769595 48.982798,24.2625147 47.8955292,24.8163184 C46.8082603,25.3696528 46.6549456,29.7414166 48.47128,30.1412816 C50.2880833,30.541616 51.0241816,29.8803368 51.0241816,29.8803368 L51.0241816,26.4716282 Z" id="Fill-24" fill="#FBC39C"></path>
                            <path d="M73.8660639,27.4886558 C74.7418803,26.809073 75.8174279,24.9050209 77.1386541,26.2670025 C78.4603492,27.6294534 78.4331557,31.9317571 74.9223885,31.4638399 C71.4116213,30.9959227 73.8660639,27.4886558 73.8660639,27.4886558" id="Fill-26" fill="#FBC39C"></path>
                            <path d="M76.0189407,59.1816202 C75.2434587,57.8876908 73.472603,57.2982184 72.0397898,57.2648963 C69.8291505,57.2137399 68.07658,56.8209147 65.8654718,56.8335865 C62.32798,56.8537675 58.7904882,56.8739485 55.2534652,56.8941294 C52.5008325,56.9100865 50.2189275,57.0142767 48.1245636,59.1102828 C46.778957,61.0659607 47.9131111,63.2379975 49.5222128,64.0719883 C54.9318325,66.8762061 70.9567407,68.642746 75.9800259,72.1640926 C76.5689046,72.5766294 77.5216128,72.3987552 77.5895964,71.7651663 C78.0185964,67.7294393 78.2684948,66.6471755 78.1292456,64.5952859 C77.9637407,62.1562031 77.2810915,61.2888902 76.0189407,59.1816202" id="Fill-28" fill="#3B3F47"></path>
                            <polygon id="Fill-30" fill="#FBC39C" points="73.9979521 62.1467696 74.2445685 67.7293923 51.0241816 62.1603801 51.0241816 49.4848463 73.9979521 49.4848463"></polygon>
                            <path d="M73.9979521,49.4846117 L51.0241816,49.4846117 L51.0241816,54.170354 C53.8372964,56.2640135 58.3617226,58.6190871 64.5168177,60.4123785 C64.7915652,60.4921638 65.3157423,60.4827773 65.5848636,60.3837497 C69.0796898,59.1001454 72.1225423,57.498808 73.9979521,55.8979399 L73.9979521,49.4846117 Z" id="Fill-32" fill="#BA8F6D"></path>
                            <path d="M71.5515738,11.312989 C71.5515738,11.312989 73.7411148,24.5916055 73.8662984,25.7879153 C73.991482,26.9837558 74.2216885,27.6295472 75.2812951,26.3379644 C75.262541,25.9136945 74.8912098,23.1334123 74.7458656,20.240492 C74.601459,17.3480411 77.3100197,11.6729613 75.8354787,8.35201656 C73.5765475,7.93713313 71.5515738,11.312989 71.5515738,11.312989" id="Fill-34" fill="#FEC100"></path>
                            <path d="M52.7152856,14.2194727 C52.7152856,14.2194727 47.7623282,46.2973776 47.6033872,48.6594911 C47.4463216,50.9892212 53.5484364,55.433261 64.3925249,58.3665433 C64.6883708,58.4467979 65.0090659,58.436942 65.2988167,58.3383837 C73.9514889,55.3867979 78.3136921,50.7569052 78.002843,48.3154758 C77.6863675,45.8327457 73.0855184,16.784804 73.1474069,15.0163868 C73.2083577,13.248439 72.7587282,8.72226718 72.7587282,8.72226718 L56.1060266,6.52300951 L52.7152856,14.2194727 Z" id="Fill-36" fill="#FBC39C"></path>
                            <path d="M76.0885184,35.6991239 C76.0411643,35.390308 75.9938102,35.0791454 75.9459872,34.7670442 C75.3313216,34.7637589 74.6763348,34.5840074 74.1413741,34.4479031 C72.660738,34.0738509 71.14822,33.7251423 69.7318167,33.1577282 C68.2314889,32.5560534 66.7860167,31.888673 65.4047774,31.0382558 C64.7399446,30.628535 64.0877708,30.2178755 63.4032462,29.841946 C63.1144331,29.6828448 62.6624593,29.2412098 62.3211348,29.1966239 C61.4298462,29.0792926 60.5793479,30.4708417 59.981561,30.8913571 C58.8033348,31.7201853 57.3798987,32.3551822 56.0352298,32.8465656 C54.7388528,33.3210534 53.4349741,33.7856853 52.1057774,34.1766331 C51.3152921,34.4094184 50.5069905,34.5990258 49.7263511,34.8632558 C49.6710266,34.8820288 49.6152331,34.9073724 49.5594397,34.9299 C49.5088036,35.2668755 49.4586364,35.6019736 49.4084692,35.9347252 C50.657961,35.7056945 51.9548069,35.2053939 53.13022,34.8299337 C54.3989348,34.4249061 55.6521774,34.0114307 56.8833839,33.5106607 C58.1872626,32.9807926 59.5900692,32.3345319 60.7035938,31.4615871 C61.1438462,31.1161638 61.6047282,30.3309828 62.1903249,30.1587405 C62.2278331,30.1822067 62.2934725,30.2249153 62.3956823,30.3103325 C62.6971544,30.5604828 63.1167774,30.7055043 63.4571643,30.8983969 C64.9429577,31.7427129 66.3373249,32.6733847 67.8864134,33.4121025 C69.1537216,34.0165933 70.4735413,34.3887681 71.8111774,34.7900411 C73.1145872,35.1814583 74.6721151,35.7488724 76.0885184,35.6991239" id="Fill-38" fill="#DEB18A"></path>
                            <path d="M70.4032603,15.7544006 C71.4206702,15.5455509 72.3241489,15.2700571 73.1493292,14.8608055 C73.1572997,14.4309037 73.1422964,13.878508 73.1113521,13.2721399 C71.9092144,13.8029466 70.6526898,14.2023423 69.3539685,14.4623485 C67.2366308,14.8847411 65.4123259,14.5613761 63.3460931,14.0577902 C61.5077226,13.610054 59.9187816,12.5662748 58.6946079,11.1338945 C58.1099489,10.4491491 57.4122964,9.87281779 56.8834308,9.14113988 C56.5416374,8.6675908 56.3770702,8.23815828 55.9091554,8.00396503 C55.8899325,7.85143436 55.7511521,7.83876258 55.6545685,7.90493742 C55.6475357,7.90306012 55.6423784,7.90024417 55.6348767,7.89836687 C55.5875226,7.88522577 55.5457948,7.88944969 55.503598,7.89132699 L55.1697751,8.64787914 C55.2180669,8.71123804 55.2808931,8.76567975 55.3751325,8.79149264 C55.4060767,8.79947117 55.4346767,8.81542822 55.4642144,8.82903865 C55.2935521,10.1637994 55.1275784,11.4966828 54.9292538,12.8300356 C54.801257,13.6893699 54.629657,14.5552748 54.4050767,15.3948975 C54.2447292,15.9956337 53.9188767,16.6081031 53.8846505,17.2341828 C53.8593325,17.6917748 54.4214866,17.8677718 54.6690407,17.4955969 C55.4042013,16.3922135 55.8482046,15.1048546 56.0765357,13.8062319 C56.2354767,12.9023117 56.3925423,11.9251767 56.4525554,10.9663454 C56.6236866,11.1545448 56.8023193,11.3408669 56.974857,11.5379834 C57.6017128,12.2541736 58.1873095,12.8976184 58.9829521,13.438281 C60.6947325,14.601738 62.6310931,15.7651951 64.7395226,15.9008301 C66.584457,16.0195693 68.5822374,16.1284528 70.4032603,15.7544006" id="Fill-40" fill="#DEB18A"></path>
                            <path d="M55.4292849,8.36891227 C55.4109997,8.34216074 55.3720849,8.35858712 55.3786489,8.3900319 C55.54978,9.2277773 56.2652489,13.6352098 53.7723603,19.2168939 C51.676121,23.6998877 51.2485275,24.1987804 51.1617898,24.2283479 C51.1463177,24.2335104 51.1397538,24.2518141 51.1392849,24.2682405 L51.1097472,26.4496638 C51.1097472,26.4628049 51.1027144,26.4735994 51.0900554,26.477354 C50.9090784,26.5294491 49.5358095,26.9096025 49.9469931,26.2670963 C50.3867767,25.579535 50.3548948,21.5616423 49.9788751,20.3118294 C49.6033243,19.0643632 47.0147898,8.41631411 53.4915177,5.59097669 C53.506521,5.58440613 53.5135538,5.5651638 53.506521,5.5501454 C52.7188489,3.86104417 51.5921964,3.57006258 51.2283669,3.52031411 C51.1983603,3.51609018 51.1922652,3.47478957 51.2208652,3.46399509 C51.6536161,3.30348589 53.2561538,2.87358405 54.3560816,4.78420675 C54.3729603,4.81377423 54.4146882,4.7982865 54.4081243,4.76496442 C53.5932587,0.791188344 54.764921,0.159007362 55.0448259,0.0660809816 C55.0687374,0.058102454 55.0893669,0.0820380368 55.0804587,0.10597362 C54.929957,0.495982822 54.0921177,2.84824049 55.591039,3.3447865 C55.6008849,3.34807178 55.6126062,3.34619448 55.6205767,3.33962393 C58.9855308,0.650860123 64.2019833,0.746602454 69.5539341,3.69443374 C73.6624882,5.83080184 78.7462554,3.63858405 79.2882489,3.39312699 C79.3102849,3.38327117 79.3309144,3.4025135 79.3271636,3.42597975 C79.1935407,4.2402589 77.473321,13.890992 70.7663866,15.0394307 C63.8461243,16.2240074 57.7758915,11.8137589 55.4292849,8.36891227" id="Fill-42" fill="#FEF202"></path>
                            <path d="M62.0797226,28.3649798 C62.0797226,28.3649798 61.4781849,33.5951393 61.3862898,34.5948018 C61.2948636,35.5944644 61.9826702,40.2145012 62.5195062,40.3233847 C63.0558734,40.4327374 68.1171357,40.0511761 68.1171357,40.0511761 C68.1171357,40.0511761 63.9227816,41.5455074 62.809257,41.679265 C61.6962013,41.813492 60.781939,41.2531178 60.8039751,39.691673 C60.82648,38.1302282 60.9826079,35.046762 60.9826079,35.046762 L61.6066505,28.3649798 L62.0797226,28.3649798 Z" id="Fill-44" fill="#BA8F6D"></path>
                            <path d="M53.239322,43.1888494 C53.239322,43.1888494 53.6716039,43.3136899 53.5211023,44.4264598 C53.3701318,45.2895488 52.8426728,45.7232052 52.8426728,45.7232052 C52.8426728,45.7232052 52.6898269,45.314423 52.8426728,45.164239 C52.9955187,45.0140549 53.4578072,43.7891163 53.239322,43.1888494" id="Fill-46" fill="#DEB18A"></path>
                            <path id="Fill-48" fill="#DEB18A"></path>
                            <path d="M56.5547184,45.4474767 C59.7949577,45.5300779 65.676243,44.456262 71.4543807,40.3135288 C72.9284528,38.9135319 71.2790298,43.1046055 70.9794331,44.1047374 C70.6798364,45.1048693 66.6608331,50.6194399 60.2394298,52.2653632 L59.2149872,52.4150779 C59.2149872,52.4150779 60.3392954,51.9152466 60.2891282,51.3149798 C60.2394298,50.7151822 58.7794233,45.7234399 58.7794233,45.7234399 C58.7794233,45.7234399 55.5766921,45.4226025 56.5547184,45.4474767" id="Fill-50" fill="#333333"></path>
                            <path d="M66.3490931,36.1129748 C66.3490931,36.1129748 71.5510111,35.0180393 73.5201915,36.7385853 C74.513221,37.5599043 73.0532144,36.7385853 73.0532144,36.7385853 L67.2961751,36.5719748 C67.2961751,36.5719748 65.8900866,36.196984 66.3490931,36.1129748" id="Fill-52" fill="#DEB18A"></path>
                            <path d="M59.2194882,45.6353475 C59.2194882,45.6353475 60.5627505,49.6199181 60.8037407,51.8229304 C61.851157,51.615958 64.4270325,50.3046635 66.87538,48.8272279 C69.3410751,46.8283721 70.8020193,44.693412 70.9792456,44.1058169 C70.6280751,44.3376635 70.1761013,44.6868414 69.5595603,45.1172126 C69.983403,44.7150009 70.7992062,43.8279764 71.2863439,43.1887555 C71.3955866,42.8710224 71.5160816,42.5162126 71.6295439,42.1604641 C71.4874816,41.6338813 71.1128685,40.8731052 71.1128685,40.8731052 C67.2129538,44.3395408 59.2194882,45.6353475 59.2194882,45.6353475" id="Fill-54" fill="#F0F2F2"></path>
                            <path d="M75.835338,27.4886558 C75.835338,27.4886558 75.0509479,28.8787969 75.1475315,29.5161405 C75.2445839,30.153484 76.2680889,29.5161405 76.2680889,29.5161405 L75.7040593,28.6619687 L75.835338,27.4886558 Z" id="Fill-56" fill="#CB916B"></path>
                            <path d="M48.1247511,26.2672371 C48.1247511,26.2672371 49.1144987,26.2874181 49.8276233,27.4888905 C50.2645938,28.413461 49.3573643,29.2253936 49.3573643,29.2253936 C49.3573643,29.2253936 49.0390134,28.227139 49.1252823,27.4888905 C48.9658725,26.5680745 48.1247511,26.2672371 48.1247511,26.2672371" id="Fill-58" fill="#CB916B"></path>
                            <path d="M66.2570574,16.19505 C66.2570574,16.19505 66.7362246,12.3644181 68.5900672,10.9118567 C70.4439098,9.45882607 72.8397459,10.1046175 73.761041,12.2259672 C74.1300279,13.286642 70.9216705,11.6946911 69.5540279,13.3438997 C68.1863852,14.9931083 66.0915525,17.1168046 66.2570574,16.19505" id="Fill-60" fill="#FEC100"></path>
                            <path d="M51.9694351,14.2327546 C51.9694351,14.2327546 57.2857531,16.7455215 59.6956548,17.0224233 C60.584599,17.2993252 60.5161466,17.1200429 60.8035531,16.1950031 C61.0914285,15.2699632 60.2399925,15.4788129 58.7767039,15.5356012 C57.3134154,15.5928589 54.2729072,13.0111012 51.9694351,14.2327546" id="Fill-62" fill="#FEC100"></path>
                            <path d="M71.6294033,42.160558 C71.487341,41.6339752 71.1127279,40.8731991 71.1127279,40.8731991 C67.213282,44.3396347 59.2193475,45.6354414 59.2193475,45.6354414 C59.2193475,45.6354414 59.3778197,46.1061745 59.5939607,46.8064077 C62.4070754,46.5595426 67.5930525,45.7616899 70.1497049,43.1888494 C70.3236492,43.6361163 70.1787738,44.1814721 69.7488361,44.9309844 C70.1956525,44.4818402 70.8642361,43.7431224 71.2866721,43.1883801 C71.3954459,42.8711163 71.515941,42.5163064 71.6294033,42.160558" id="Fill-64" fill="#CCCCCB"></path>
                            <path d="M71.1564249,48.2375209 C71.1484544,48.1854258 71.1376708,48.1333307 71.1236052,48.0812356 C71.0143626,48.2271957 70.9004315,48.3637693 70.8254151,48.5303798 C70.7518052,48.6932356 70.6430315,48.8330945 70.5305069,48.9729534 C70.3059266,49.2526712 70.034461,49.5004748 69.70392,49.6483123 C69.3438413,49.8102294 68.9406282,49.8426129 68.5589823,49.9444564 C67.435143,50.2448245 66.5724544,51.1168307 65.6080249,51.7677847 C65.29952,51.9756957 64.9774184,52.1629564 64.6435954,52.3276896 C64.5371659,52.3807233 64.2310052,52.5168276 64.209438,52.6491773 C64.1728675,52.8777387 64.5090348,52.840662 64.6595364,52.7984227 C65.3117102,52.6158552 65.8799593,52.2657387 66.4078872,51.8494472 C66.942379,51.4279933 67.457179,50.967116 68.0713757,50.6733184 C68.5195987,50.4588368 69.0067364,50.3410362 69.4769954,50.180527 C69.9477233,50.0200178 70.4161069,49.8074135 70.7569626,49.4455638 C71.0603102,49.1245454 71.21972,48.6744626 71.1564249,48.2375209" id="Fill-66" fill="#DEB18A"></path>
                            <g id="Group-161" transform="translate(67.983607, 78.377301)">
                                <polygon id="Fill-87" fill="#71533E" points="23.3916118 19.031839 22.895097 18.9445445 22.6681725 9.31117638 23.1646872 9.39143098"></polygon>
                                <polygon id="Fill-89" fill="#614734" points="22.3014361 23.1698319 21.805859 23.0792521 19.3776721 18.6187859 19.8713738 18.7060804"></polygon>
                                <polygon id="Fill-91" fill="#846349" points="15.3098613 28.7183347 14.8180351 28.623531 21.8058121 23.0793929 22.3013892 23.1699727"></polygon>
                                <polygon id="Fill-93" fill="#491361" points="15.3098613 28.7183347 14.8180351 28.623531 19.3776252 18.6189267 19.8713269 18.7062212"></polygon>
                                <polygon id="Fill-95" fill="#5C1B7B" points="19.8715613 18.7062212 22.3016236 23.1699727 15.3100957 28.7183347"></polygon>
                                <polygon id="Fill-97" fill="#42115B" points="18.4082259 9.8501357 17.9140554 9.7695611 22.6682193 9.31094172 23.1647341 9.39151632"></polygon>
                                <polygon id="Fill-99" fill="#370F4B" points="23.3916118 19.031839 22.895097 18.9445445 17.9140085 9.77017638 18.408179 9.85090031"></polygon>
                                <polygon id="Fill-101" fill="#5C1B7B" points="23.1645466 9.39143098 23.3914711 19.031839 18.4080384 9.85090031"></polygon>
                                <polygon id="Fill-103" fill="#7B5D44" points="15.576357 21.4481595 15.084062 21.3585184 17.7218259 15.5792485 18.2155275 15.6641963"></polygon>
                                <polygon id="Fill-105" fill="#614734" points="18.2154807 15.6643371 17.721779 15.5789199 14.7328446 10.0901623 15.2256085 10.1713555"></polygon>
                                <polygon id="Fill-107" fill="#7B5D44" points="11.7073866 29.9390963 11.2174357 29.8428847 13.5387243 24.7469521 14.0300816 24.8394092"></polygon>
                                <polygon id="Fill-109" fill="#553D2C" points="20.7094475 6.39582239 20.2138705 6.31744509 12.9302475 2.1418592 13.4225426 2.21742055"></polygon>
                                <polygon id="Fill-111" fill="#765741" points="9.89470918 22.0343466 9.40522721 21.9442362 15.0839682 21.3585184 15.5762633 21.4481595"></polygon>
                                <polygon id="Fill-113" fill="#201035" points="9.89470918 22.034219 9.40522721 21.9441993 9.3935059 21.9076288 9.88345672 21.9976485"></polygon>
                                <polygon id="Fill-115" fill="#73553F" points="11.140122 7.30307485 10.6492334 7.2237589 20.2142925 6.31749202 20.7094007 6.39586933"></polygon>
                                <polygon id="Fill-117" fill="#471361" points="11.140122 7.30307485 10.6492334 7.2237589 12.9302007 2.14190613 13.4224957 2.21746748"></polygon>
                                <polygon id="Fill-119" fill="#5C1B7B" points="13.4225426 2.21727975 20.7094475 6.3956816 11.1401689 7.30288712"></polygon>
                                <polygon id="Fill-121" fill="#25123C" points="9.61771115 10.7011298 9.12776033 10.6189979 14.7286718 10.077866 15.2214357 10.1595285"></polygon>
                                <polygon id="Fill-123" fill="#271441" points="6.94468951 16.6524543 6.45614525 16.5656291 9.12766656 10.6192794 9.61761738 10.700942"></polygon>
                                <polygon id="Fill-125" fill="#351C53" points="15.2215295 10.1593877 18.2156213 15.6641025 15.5764508 21.4480656 9.89489672 22.0342528 9.88317541 21.9981147 6.94487705 16.6525012 9.61780492 10.700989"></polygon>
                                <polygon id="Fill-127" fill="#1D0F30" points="9.88326918 21.997927 9.39331836 21.9078166 6.4559577 16.5659577 6.94450197 16.6523135"></polygon>
                                <polygon id="Fill-129" fill="#2F0E41" points="11.7110436 29.9262368 11.2206239 29.8300252 3.86714197 25.7619147 4.35381082 25.8557798"></polygon>
                                <polygon id="Fill-131" fill="#45125D" points="4.35367016 25.855639 3.86700131 25.7622433 13.5389587 24.7470929 14.0303161 24.8390807"></polygon>
                                <polygon id="Fill-133" fill="#5C1B7B" points="14.0301285 24.8393153 11.7111843 29.9263307 4.35348262 25.8558736"></polygon>
                                <polygon id="Fill-135" fill="#7C5C44" points="5.25152262 13.5679555 4.76344721 13.483477 9.34413574 3.28504141 9.83502426 3.36154141"></polygon>
                                <polygon id="Fill-137" fill="#765741" points="1.84226197 22.8641938 1.35653082 22.7728447 6.19977672 22.2744064 6.68785213 22.3652871"></polygon>
                                <polygon id="Fill-139" fill="#553D2C" points="12.7028072 32.0515288 12.2123875 31.9539092 0.467633443 25.2876147 0.952426885 25.3810104"></polygon>
                                <polygon id="Fill-141" fill="#360F4B" points="5.25152262 13.5679555 4.76344721 13.483477 2.21101443 8.8395046 2.69768328 8.92022853"></polygon>
                                <polygon id="Fill-143" fill="#4D1466" points="2.69754262 8.92027546 2.21087377 8.83955153 9.34446393 3.28508834 9.83535246 3.36158834"></polygon>
                                <polygon id="Fill-145" fill="#604633" points="6.68785213 22.3652678 6.19977672 22.274688 1.10241279 13.0266359 1.58814393 13.1106451"></polygon>
                                <polygon id="Fill-147" fill="#401157" points="1.84226197 22.8652868 1.35653082 22.7737684 1.10241279 13.0263543 1.58814393 13.1103635"></polygon>
                                <path d="M23.3916118,19.031839 L23.1646872,9.39143098 L18.408179,9.85090031 L23.3916118,19.031839 L23.3916118,19.031839 Z M13.422402,2.21732669 L11.1400282,7.30293405 L20.7093069,6.39572853 L13.422402,2.21732669 L13.422402,2.21732669 Z M1.58809705,13.1103635 L1.84221508,22.8652868 L6.68780525,22.3654555 L1.58809705,13.1103635 L1.58809705,13.1103635 Z M11.7073397,29.9389555 L14.0300348,24.8392684 L4.3538577,25.8558267 L11.7073397,29.9389555 L11.7073397,29.9389555 Z M15.310002,28.7182408 L22.3015298,23.1698788 L19.8714675,18.7061273 L15.310002,28.7182408 L15.310002,28.7182408 Z M9.8352118,3.36154141 L2.69740197,8.92022853 L5.25171016,13.5679555 L9.8352118,3.36154141 L9.8352118,3.36154141 Z M18.2154807,15.664431 L15.2256085,10.1714494 L9.61766426,10.7013175 L6.94473639,16.6523604 L9.88303475,21.9979739 L9.89475607,22.034112 L15.5763102,21.4479248 L18.2154807,15.664431 L18.2154807,15.664431 Z M12.2024479,0.0955076687 L24.1066118,6.93498313 L24.4755987,22.7005537 L12.7027134,32.051388 L0.952801967,25.3808696 L0.528490492,9.16099233 L0.60819541,9.12344632 L12.2024479,0.0955076687 Z" id="Fill-149" fill="#9C7557"></path>
                                <polygon id="Fill-151" fill="#5C1B7B" points="9.8352118 3.36172914 5.25171016 13.5681433 2.69740197 8.92041626"></polygon>
                                <polygon id="Fill-153" fill="#5C1B7B" points="1.58814393 13.1105043 6.68785213 22.365127 1.84226197 22.8654276"></polygon>
                                <polygon id="Fill-155" fill="#826147" points="0.608336066 9.12354018 0.122604918 9.04234693 11.7107623 0.0219174847 12.2025885 0.0956015337"></polygon>
                                <polygon id="Fill-157" fill="#816047" points="0.528678033 9.16077935 0.0429468852 9.07966787 0.122651803 9.04262853 0.608382951 9.12327115"></polygon>
                                <polygon id="Fill-159" fill="#70523C" points="0.952661311 25.3810574 0.467867869 25.2876617 0.043087541 9.07951748 0.528818689 9.16071074"></polygon>
                            </g>
                            <path d="M28.8647141,63.2607129 C21.8300518,61.9714767 13.3203797,63.3559859 10.951737,70.423084 C7.19388459,76.2403693 9.97839934,76.2004767 10.2067305,78.9385196 C10.4345928,81.6760933 10.3042518,87.0428264 15.000278,90.011308 C15.572278,95.7718049 15.0818584,100.609609 24.3946748,106.916869 C35.9936157,107.564069 38.3116223,108.28636 38.3116223,108.28636 C38.3116223,108.28636 50.5083502,103.537728 48.6802944,96.6381791 C46.8517698,89.7381607 45.9703272,84.7389092 40.413019,81.6629521 C40.7946649,78.5869951 40.2564223,74.1025933 37.4780026,70.6347497 C34.3760748,66.5985534 33.2198846,64.0524644 28.8647141,63.2607129" id="Fill-162" fill="#977558"></path>
                            <path d="M24.394581,106.916869 C24.7293416,106.935642 25.0547252,106.954415 25.3744826,106.973188 C25.8667777,104.951805 25.1325548,102.646949 23.63504,101.200489 C22.5937187,100.193787 21.1519974,99.7516822 20.0070597,98.8735748 C18.7894498,97.9391485 16.9285744,95.9252742 18.3477908,94.425311 C18.4861023,94.2784123 18.4940728,94.0892742 18.4270269,93.909992 C18.0224072,92.8235043 17.374922,91.8576331 16.5464597,91.0475779 C16.1357449,90.6458356 15.6059416,90.1314552 15.010499,90.1178448 C15.5660892,95.8314092 15.1394334,100.648563 24.394581,106.916869" id="Fill-164" fill="#977558"></path>
                            <path d="M40.5182295,81.7236828 C40.4825967,81.7039712 40.4493082,81.6828515 40.4132066,81.6631399 C40.4638426,81.256235 40.4966623,80.8239865 40.5116656,80.374373 C39.7033639,80.5104773 38.8416131,80.3809436 38.0684754,80.1833577 C36.1804066,79.7008914 34.2210721,78.8776951 32.2462656,78.845781 C30.1556525,78.8124589 28.2164787,79.5094067 26.3640426,80.4137963 C24.4244,81.3608945 22.3164393,82.859919 20.0940787,82.9706798 C19.8784066,82.9814742 19.6580459,82.9688025 19.4390918,82.9434589 C18.8614656,82.6496613 18.2763377,82.3356828 17.6776131,82.289689 C17.5800918,82.2821798 17.5491475,82.4276706 17.6236951,82.4769497 C18.0400361,82.7505663 18.4610656,82.9289098 18.885377,83.0396706 C19.4414361,83.3353454 20.0636033,83.5930049 20.6206,83.7629006 C22.2465803,84.2594466 23.7670689,84.5110049 25.3597607,83.8239129 C27.5680557,82.8702442 29.7519705,81.8320969 32.1056098,81.2717227 C34.4765967,80.7071245 37.6174393,80.6667626 39.906377,81.482919 C40.1159541,81.5575417 40.3161541,81.6415509 40.5182295,81.7236828" id="Fill-166" fill="#053A5F"></path>
                            <path d="M40.4131597,81.6629521 C40.7760515,78.7376485 40.3025105,74.5400043 37.8654154,71.1524153 C34.8122482,72.0018939 25.9542187,70.7089031 21.831599,69.8781975 C17.2307498,68.950811 14.2793236,72.699311 15.8138777,74.520762 C17.3489007,76.3417436 21.831599,83.8068294 21.831599,83.8068294 C21.831599,83.8068294 21.4110384,88.3620994 22.7697728,90.2694368 C23.9222121,91.8876699 26.3157039,103.566357 27.0157007,107.074563 C36.3458646,107.675768 38.3122318,108.28636 38.3122318,108.28636 C38.3122318,108.28636 50.5084908,103.537728 48.6799662,96.6381791 C46.8519105,89.7386301 45.9704679,84.7389092 40.4131597,81.6629521" id="Fill-168" fill="#661E88"></path>
                            <path d="M57.461057,139.227466 C49.4854079,142.129303 30.25308,141.914821 24.0956407,125.816969 C17.9382013,109.718647 18.860903,95.5210914 32.8753718,89.0096742 C46.8898407,82.4977877 61.8935882,96.0894442 67.6370308,106.189321 C73.3804734,116.289199 68.8021292,135.101159 57.461057,139.227466" id="Fill-170" fill="#BCCCD4"></path>
                            <path d="M64.0723456,119.637458 C64.0723456,128.269756 57.0812866,135.267863 48.4576833,135.267863 C39.83408,135.267863 32.8434898,128.269756 32.8434898,119.637458 C32.8434898,111.005161 39.83408,104.007053 48.4576833,104.007053 C57.0812866,104.007053 64.0723456,111.005161 64.0723456,119.637458" id="Fill-172" fill="#514E55"></path>
                        </g>
                        <g id="Group-177" transform="translate(32.819672, 104.125887)">
                            <mask id="mask-4" fill="white">
                                <use xlink:href="#path-3"></use>
                            </mask>
                            <g id="Clip-176"></g>
                            <path d="M27.3386462,12.7250945 C27.3386462,21.1687233 20.500902,28.0138307 12.0653085,28.0138307 C3.63018393,28.0138307 -3.20756033,21.1687233 -3.20756033,12.7250945 C-3.20756033,4.28146564 3.63018393,-2.56364172 12.0653085,-2.56364172 C20.500902,-2.56364172 27.3386462,4.28146564 27.3386462,12.7250945" id="Fill-175" fill="#02ADEE" mask="url(#mask-4)"></path>
                        </g>
                        <g id="Group-220" transform="translate(32.819672, 104.125887)">
                            <g id="Group-180">
                                <mask id="mask-6" fill="white">
                                    <use xlink:href="#path-5"></use>
                                </mask>
                                <g id="Clip-179"></g>
                                <path d="M21.2550043,5.41178834 C21.2550043,12.2348374 15.7291092,17.7663037 8.91293213,17.7663037 C2.09675508,17.7663037 -3.42914,12.2348374 -3.42914,5.41178834 C-3.42914,-1.41173006 2.09675508,-6.94272699 8.91293213,-6.94272699 C15.7291092,-6.94272699 21.2550043,-1.41173006 21.2550043,5.41178834" id="Fill-178" fill="#2DAAE1" mask="url(#mask-6)"></path>
                            </g>
                            <g id="Group-183" transform="translate(0.000000, 8.917178)">
                                <mask id="mask-8" fill="white">
                                    <use xlink:href="#path-7"></use>
                                </mask>
                                <g id="Clip-182"></g>
                                <path d="M9.01908033,0.106020552 C9.01908033,0.106020552 8.56757541,9.76003896 11.0051393,19.9481494 C-1.73827049,28.860634 -2.8939918,28.8052537 -2.8939918,28.8052537 L-2.8939918,0.685167791 L9.01908033,0.106020552 Z" id="Fill-181" fill="#291532" mask="url(#mask-8)"></path>
                            </g>
                            <g id="Group-186">
                                <mask id="mask-10" fill="white">
                                    <use xlink:href="#path-9"></use>
                                </mask>
                                <g id="Clip-185"></g>
                                <path d="M14.0846092,8.28415215 C14.0846092,8.28415215 5.8328059,13.2360018 -1.59006623,20.6208331 C-15.8244269,14.3642595 -16.1877875,13.6607411 -16.1877875,13.6607411 L7.80151738,-0.970470552 L14.0846092,8.28415215 Z" id="Fill-184" fill="#291532" mask="url(#mask-10)"></path>
                            </g>
                            <g id="Group-189">
                                <mask id="mask-12" fill="white">
                                    <use xlink:href="#path-11"></use>
                                </mask>
                                <g id="Clip-188"></g>
                                <path d="M14.0846092,8.28415215 C14.0846092,8.28415215 5.8328059,13.2360018 -1.59006623,20.6208331 C-15.8244269,14.3642595 -16.1877875,13.6607411 -16.1877875,13.6607411 L7.80151738,-0.970470552 L14.0846092,8.28415215 Z" id="Stroke-187" stroke="#2DAAE1" stroke-width="0.595833333" mask="url(#mask-12)"></path>
                            </g>
                            <g id="Group-192" transform="translate(0.468852, 0.000000)">
                                <mask id="mask-14" fill="white">
                                    <use xlink:href="#path-13"></use>
                                </mask>
                                <g id="Clip-191"></g>
                                <path d="M16.0614787,11.6063172 C16.0614787,11.6063172 7.54336721,7.12895521 -2.61900984,4.62604417 C-4.68758689,-10.796919 -4.27687213,-11.4741552 -4.27687213,-11.4741552 L20.6876459,1.41961472 L16.0614787,11.6063172 Z" id="Fill-190" fill="#291532" mask="url(#mask-14)"></path>
                            </g>
                            <g id="Group-195" transform="translate(0.468852, 0.000000)">
                                <mask id="mask-16" fill="white">
                                    <use xlink:href="#path-15"></use>
                                </mask>
                                <g id="Clip-194"></g>
                                <path d="M16.0614787,11.6063172 C16.0614787,11.6063172 7.54336721,7.12895521 -2.61900984,4.62604417 C-4.68758689,-10.796919 -4.27687213,-11.4741552 -4.27687213,-11.4741552 L20.6876459,1.41961472 L16.0614787,11.6063172 Z" id="Stroke-193" stroke="#2DAAE1" stroke-width="0.595833333" mask="url(#mask-16)"></path>
                            </g>
                            <g id="Group-198" transform="translate(11.721311, 0.000000)">
                                <mask id="mask-18" fill="white">
                                    <use xlink:href="#path-17"></use>
                                </mask>
                                <g id="Clip-197"></g>
                                <path d="M3.95988098,15.8261135 C3.95988098,15.8261135 3.34755967,6.21480368 0.194995738,-3.77431288 C12.2763859,-13.5677209 13.0678089,-13.5691288 13.0678089,-13.5691288 L15.0571498,14.4805583 L3.95988098,15.8261135 Z" id="Fill-196" fill="#291532" mask="url(#mask-18)"></path>
                            </g>
                            <g id="Group-201" transform="translate(11.721311, 0.000000)">
                                <mask id="mask-20" fill="white">
                                    <use xlink:href="#path-19"></use>
                                </mask>
                                <g id="Clip-200"></g>
                                <path d="M3.95988098,15.8261135 C3.95988098,15.8261135 3.34755967,6.21480368 0.194995738,-3.77431288 C12.2763859,-13.5677209 13.0678089,-13.5691288 13.0678089,-13.5691288 L15.0571498,14.4805583 L3.95988098,15.8261135 Z" id="Stroke-199" stroke="#2DAAE1" stroke-width="0.595833333" mask="url(#mask-20)"></path>
                            </g>
                            <g id="Group-204" transform="translate(12.190164, 2.815951)">
                                <mask id="mask-22" fill="white">
                                    <use xlink:href="#path-21"></use>
                                </mask>
                                <g id="Clip-203"></g>
                                <path d="M0.143140656,13.6101009 C0.143140656,13.6101009 8.02408164,8.08567454 14.905898,0.193033436 C29.5467538,5.42507025 29.9598128,6.10089847 29.9598128,6.10089847 L7.06527836,22.3963371 L0.143140656,13.6101009 Z" id="Fill-202" fill="#291532" mask="url(#mask-22)"></path>
                            </g>
                            <g id="Group-207" transform="translate(12.190164, 2.815951)">
                                <mask id="mask-24" fill="white">
                                    <use xlink:href="#path-23"></use>
                                </mask>
                                <g id="Clip-206"></g>
                                <path d="M0.143140656,13.6101009 C0.143140656,13.6101009 8.02408164,8.08567454 14.905898,0.193033436 C29.5467538,5.42507025 29.9598128,6.10089847 29.9598128,6.10089847 L7.06527836,22.3963371 L0.143140656,13.6101009 Z" id="Stroke-205" stroke="#2DAAE1" stroke-width="0.595833333" mask="url(#mask-24)"></path>
                            </g>
                            <g id="Group-216" transform="translate(1.406557, 12.671779)">
                                <mask id="mask-26" fill="white">
                                    <use xlink:href="#path-25"></use>
                                </mask>
                                <g id="Clip-215"></g>
                                <path d="M9.87914328,16.2723009 C8.36568754,9.9486138 7.97044492,3.96002485 7.7852482,0.20542362 L6.66750393,0.20542362 L3.78406131,4.89116595 L0.155143279,15.7062948 L9.87914328,16.2723009 Z" id="Fill-214" fill="#291532" mask="url(#mask-26)"></path>
                            </g>
                            <g id="Group-219" transform="translate(8.908197, 11.263804)">
                                <mask id="mask-28" fill="white">
                                    <use xlink:href="#path-27"></use>
                                </mask>
                                <g id="Clip-218"></g>
                                <path d="M0.246381967,0.330357975 C0.304988525,3.86015245 0.661316393,10.598723 2.33699508,17.6015236" id="Stroke-217" stroke="#2DAAE1" stroke-width="0.595833333" mask="url(#mask-28)"></path>
                            </g>
                        </g>
                        <g id="Group-393" transform="translate(20.160656, 16.362083)">
                            <path d="M3.05218262,98.1030837 C3.05218262,98.1030837 4.53844492,92.4233107 5.2604777,90.8632739 C2.34890393,89.1859058 1.20912361,88.2481942 1.20912361,88.2481942 L0.0538711475,93.9964887 C0.0538711475,93.9964887 1.17677279,96.1084518 3.05218262,98.1030837" id="Fill-221" fill="#5A7F92"></path>
                            <path d="M35.2381538,81.3546994 L35.011698,76.6201472 C35.011698,76.6201472 39.664121,78.8419325 40.1207833,80.016184 C40.5774456,81.1899663 41.1827341,84.3152025 41.1827341,84.3152025 L35.2381538,81.3546994 Z" id="Fill-223" fill="#5A7F92"></path>
                            <path d="M5.26066525,90.8633678 C5.26066525,90.8633678 4.07681279,85.6224138 5.60855377,78.0681561 C3.43823574,81.5115948 1.2088423,88.248288 1.2088423,88.248288 C1.2088423,88.248288 2.14279639,89.0456715 3.12926197,89.6557942 C4.11572754,90.2663862 5.26066525,90.8633678 5.26066525,90.8633678" id="Fill-225" fill="#8FA8B5"></path>
                            <path d="M35.2381538,81.3546994 C35.2381538,81.3546994 31.41138,74.9376166 25.3420849,71.9897853 C31.1966456,73.2081534 35.011698,76.6201472 35.011698,76.6201472 C35.011698,76.6201472 35.1940816,77.265 35.2381538,78.8578896 C35.2822259,80.4503098 35.2381538,81.3546994 35.2381538,81.3546994" id="Fill-227" fill="#8FA8B5"></path>
                            <path d="M20.3575738,65.7666276 C20.321941,65.746916 20.2886525,65.7257963 20.2525508,65.7060847 C20.302718,65.2996491 20.3350689,64.8669313 20.3500721,64.4173178 C19.5417705,64.5534221 18.6809574,64.423419 17.9078197,64.2263025 C16.0197508,63.7438362 14.0604164,62.9206399 12.0856098,62.8887258 C9.99499672,62.8554037 8.05582295,63.5523515 6.20338689,64.4567411 C4.5947541,65.2419221 2.87031475,66.4044405 1.06101311,66.8423209 C1.43750164,67.4608914 1.67099016,67.8494926 1.67099016,67.8494926 C1.67099016,67.8494926 1.66208197,67.9485202 1.65129836,68.1188853 C2.84359016,68.3690356 4.00118689,68.384054 5.19910492,67.8668577 C7.4074,66.913189 9.59131475,65.8750417 11.9449541,65.3146675 C14.315941,64.7500693 17.4567836,64.7097074 19.7457213,65.5258638 C19.9552984,65.6004865 20.1554984,65.6844957 20.3575738,65.7666276" id="Fill-229" fill="#3E225E"></path>
                            <path d="M7.20616852,113.345591 C7.20616852,113.345591 7.10536525,113.106705 6.91548,112.658969 C6.73309639,112.208417 6.47100787,111.542444 6.21829639,110.668091 C5.96136525,109.795147 5.70208984,108.716637 5.52205049,107.457907 C5.43203082,106.829012 5.36498492,106.154591 5.32653902,105.441687 C5.3148177,105.263343 5.30919148,105.082653 5.30544066,104.899616 L5.30075213,104.614266 L5.31106689,104.336895 C5.32560131,103.96519 5.34060459,103.584567 5.35513902,103.195966 C5.36264066,103.001665 5.3701423,102.805487 5.37811279,102.607432 L5.3898341,102.308472 L5.43203082,102.005288 C5.48876197,101.598852 5.54689967,101.184907 5.60550623,100.763923 C5.63551279,100.553196 5.66505049,100.340591 5.6955259,100.12611 C5.72553246,99.9116282 5.79210951,99.7051252 5.83946361,99.4911129 C5.94308,99.0659043 6.0485718,98.6341252 6.15547016,98.1967141 C6.24267672,97.7522632 6.4283423,97.3322172 6.57274885,96.8929288 C6.73168984,96.4588031 6.87140787,96.0096589 7.05144721,95.5717785 C7.25352262,95.1432847 7.4579423,94.7100975 7.66423738,94.2726865 C7.77066689,94.055389 7.8681882,93.8324595 7.9825882,93.6170393 C8.10870951,93.4081896 8.23483082,93.1979319 8.36142098,92.9867356 C8.61741443,92.5662202 8.86684393,92.1363184 9.13455869,91.7134564 C9.43228,91.3117141 9.73234557,90.9066865 10.0338177,90.4993123 C10.188539,90.2989104 10.328257,90.0844288 10.4970439,89.8934135 L11.0104374,89.3231834 C11.3597325,88.948662 11.6841784,88.5450423 12.0597292,88.1892939 C12.8244275,87.4928153 13.569903,86.7587908 14.4330603,86.1599319 C15.2451128,85.4930209 16.1678144,84.9805178 17.0619161,84.4257755 C17.5218603,84.1718706 18.0075915,83.9644288 18.4783193,83.7321129 L19.1895685,83.3932601 L19.9275423,83.1224595 C20.4212439,82.9464626 20.9097882,82.7601405 21.4034898,82.594938 C21.9084439,82.4672816 22.4124603,82.3396252 22.9136636,82.212438 C23.1659062,82.1542417 23.4125226,82.0744564 23.6666407,82.0340945 L24.4289948,81.9205178 C24.936762,81.8491804 25.4398407,81.7595393 25.9429193,81.7055669 C26.4488111,81.6821006 26.9518898,81.6586344 27.4512177,81.6351681 C27.704398,81.6239043 27.9561718,81.6121712 28.2070079,81.6009074 C28.4517489,81.6093552 28.6950833,81.6178031 28.9379489,81.6267202 C30.8963456,81.6877325 32.79848,82.0317479 34.5168243,82.5465975 C36.243139,83.0619166 37.8062931,83.7551098 39.172998,84.5210485 C40.5486111,85.2761926 41.702457,86.1444442 42.6673554,86.9681098 C43.6439751,87.7819196 44.397421,88.5966681 45.0078669,89.2696804 C45.6000275,89.9600577 46.04028,90.5227785 46.313621,90.9254595 C46.5954013,91.3211006 46.745903,91.5322969 46.745903,91.5322969 C46.745903,91.5322969 46.5846177,91.3290791 46.2826767,90.9484564 C45.9919882,90.5598552 45.5151652,90.0295178 44.9051882,89.3635454 C44.2722374,88.7186926 43.4944111,87.9410209 42.4980997,87.1746129 C41.512103,86.3992877 40.3432538,85.5920485 38.979362,84.875389 C37.619221,84.1549748 36.0710702,83.5124687 34.3733554,83.0497141 C32.6709521,82.5841436 30.8419587,82.3095883 28.9187259,82.249984 C28.6758603,82.2434135 28.432057,82.2368429 28.1877849,82.2302724 C27.9500767,82.2434135 27.710962,82.2570239 27.4718472,82.270165 C26.9861161,82.2964472 26.4975718,82.3231988 26.0052767,82.3499503 C25.5167325,82.4062693 25.028657,82.4977877 24.536362,82.5705331 L23.7969816,82.6859871 C23.5503652,82.7272877 23.3112505,82.807073 23.0669784,82.8657387 C22.5812472,82.9924564 22.0936407,83.1201129 21.6041587,83.2482387 C21.126398,83.4120331 20.6542636,83.5974166 20.1769718,83.7710669 L19.4633784,84.0385822 L18.7765095,84.3736804 C18.3221915,84.602711 17.853339,84.8063982 17.4093357,85.0560791 C16.5419587,85.5911098 15.6408243,86.0759227 14.8494013,86.7184288 C14.0068734,87.2914748 13.2796833,87.9996865 12.530457,88.6694135 C12.1624079,89.0115515 11.8459325,89.4020301 11.5027325,89.7629411 L10.9991849,90.3125209 C10.8332111,90.4964963 10.6967751,90.7044074 10.5453357,90.8977693 C10.2485521,91.2915331 9.95364393,91.6829503 9.66061115,92.0715515 C9.39805377,92.4803337 9.15237508,92.8966252 8.90013246,93.3039994 C8.77494885,93.5081558 8.6502341,93.7118429 8.5259882,93.9141221 C8.41299475,94.1229718 8.31641115,94.3393307 8.2113882,94.5500577 C8.00368656,94.9724503 7.79786033,95.3910883 7.59437836,95.8055025 C7.40918164,96.2274258 7.26336852,96.6610822 7.09786361,97.0811282 C6.94689311,97.5058675 6.75466361,97.9127724 6.65995541,98.3440822 C6.54508656,98.7692908 6.43209311,99.1883982 6.32050623,99.6018736 C6.26940131,99.8093153 6.19907344,100.012064 6.16484721,100.219036 C6.13108984,100.426009 6.09686361,100.631104 6.06357508,100.834321 C5.99606033,101.242165 5.92995213,101.642969 5.86478164,102.036263 L5.81602098,102.330061 L5.79679803,102.628552 C5.78413902,102.826607 5.77194885,103.022315 5.76022754,103.216616 C5.73584721,103.604748 5.71193574,103.984432 5.68896197,104.356137 L5.67161443,104.633039 L5.67020787,104.895392 C5.67161443,105.075613 5.67255213,105.253956 5.67302098,105.429484 C5.68333574,106.133002 5.7231882,106.800383 5.78835869,107.425524 C5.91729311,108.675337 6.13249639,109.754315 6.3533259,110.631015 C6.57509311,111.507714 6.7912341,112.187297 6.95205049,112.644889 C7.11802426,113.101542 7.20616852,113.345591 7.20616852,113.345591" id="Fill-231" fill="#8FA8B5"></path>
                            <path d="M20.3575738,89.1467172 C20.2577082,89.1870791 18.7179967,85.617392 16.9194787,81.1728828 C15.1204918,76.7288429 13.7430033,73.092981 13.8428689,73.052619 C13.9427344,73.0122571 15.4824459,76.5819442 17.2814328,81.025984 C19.0804197,85.4704933 20.4574393,89.1063552 20.3575738,89.1467172" id="Fill-235" fill="#8FA8B5"></path>
                            <path d="M45.2713151,87.2980454 C45.2961643,87.2877202 45.3678987,87.4139687 45.4771413,87.6542632 C45.5301216,87.7767571 45.5938856,87.9250638 45.6679643,88.0968368 C45.703597,88.1841313 45.7415741,88.277527 45.7818954,88.3765546 C45.8090889,88.4798061 45.8376889,88.5891589 45.8672266,88.7041436 C46.1480692,89.5972693 46.3149807,90.995389 45.8001807,92.3944472 C45.5760692,93.1017202 45.1695741,93.690254 44.7818331,94.1868 C44.3762757,94.6763061 43.9416495,95.0480117 43.5609413,95.3319534 C42.7868659,95.888573 42.2200233,96.077711 42.2012692,96.0303092 C42.17642,95.9791528 42.6865315,95.7064748 43.390279,95.1146558 C44.0794921,94.5218982 44.9806266,93.5771466 45.4316626,92.2639748 C45.9117675,90.9644135 45.8039315,89.6540577 45.5985741,88.7679718 C45.5793511,88.6548644 45.560597,88.5464503 45.5427807,88.4441374 C45.5137118,88.3446405 45.4860495,88.2507755 45.4597938,88.1630117 C45.4110331,87.9860761 45.3693052,87.8330761 45.3346102,87.7072969 C45.2708462,87.4543307 45.2464659,87.3083706 45.2713151,87.2980454" id="Fill-237" fill="#8FA8B5"></path>
                            <path d="M57.5523895,3.52763558 C57.3104616,3.66280123 57.1515207,3.84067546 56.9705436,4.05140245 C56.7825338,4.27010798 56.5448256,4.45314479 56.4215174,4.71690552 C56.3652551,4.83846074 56.4093272,5.0224362 56.5706125,5.02525215 C56.8392649,5.03088405 57.1304223,4.75632883 57.3212452,4.59488098 C57.449242,4.48646687 57.5659862,4.35223988 57.6986715,4.25133497 C57.7990059,4.17436564 57.9105928,4.13588098 57.9659174,4.01010184 C58.0821928,3.7444638 57.878242,3.34459877 57.5523895,3.52763558" id="Fill-249" fill="#A78A72"></path>
                            <path d="M45.3583341,5.16820859 C45.3372357,4.9687454 45.1164062,4.91289571 44.9705931,4.99878221 C44.8313439,4.92979141 44.6958456,4.85516871 44.5626915,4.77819939 C44.4647013,4.70357669 44.3601472,4.63834049 44.2429341,4.59469325 C43.8767603,4.45905828 43.6188915,5.05557055 43.9395866,5.24236196 C43.9649046,5.25691104 43.9911603,5.26629755 43.9911603,5.2803773 L43.9911603,5.28131595 C43.9911603,5.28319325 44.0291374,5.28647853 44.0342948,5.28835583 C44.1805767,5.36720245 44.341862,5.43994785 44.4928325,5.50471472 C44.5176816,5.51644785 44.5490948,5.52771166 44.5739439,5.53803681 C44.7066292,5.59529448 44.8430652,5.65067485 44.9743439,5.71450307 C45.1745439,5.8121227 45.3513013,5.58403067 45.3653669,5.41413497 C45.3723997,5.32824847 45.3672423,5.25409509 45.3583341,5.16820859" id="Fill-251" fill="#A78A72"></path>
                            <path d="M65.7912056,13.0301089 C65.7912056,13.0301089 64.4906089,14.307612 64.4666974,14.9121028 C64.442317,15.5170629 65.5849105,15.6653696 66.132999,15.775661 C66.6820252,15.8854831 67.5812843,16.0962101 67.5114252,15.0758972 C67.4415662,14.055115 67.3168515,12.9132469 67.3168515,12.9132469 L65.7912056,13.0301089 Z" id="Fill-253" fill="#661E88"></path>
                            <path d="M66.1331866,10.4093972 C66.1331866,10.4093972 67.7160325,10.7355782 68.0906456,11.0847561 C68.4657275,11.4344034 68.2116095,12.1182101 68.1558161,12.4664494 C68.1004915,12.8146887 67.9415505,13.5106979 67.4816062,13.5280629 C67.0221308,13.5449586 65.4561636,13.0507592 65.4561636,13.0507592 L66.1331866,10.4093972 Z" id="Fill-255" fill="#654E7E"></path>
                            <path d="M65.4715889,11.2318426 C65.512379,10.9765298 65.8218216,10.3420021 66.1331397,10.409585 C66.4444577,10.4762291 66.303802,11.4486709 66.1331397,12.1817567 C65.9624774,12.9148426 65.7350839,13.0457844 65.4561167,13.0509469 C65.1776184,13.0561095 65.2202839,12.7993887 65.4715889,11.2318426" id="Fill-257" fill="#661E88"></path>
                            <path d="M65.9537098,11.1099589 C65.5926934,10.9743239 65.1838541,10.9142503 64.8134607,10.795511 C64.4815131,10.6894436 64.1097131,10.5650724 63.7566672,10.5707043 C63.7299426,10.5711736 63.7121262,10.5908853 63.7036869,10.6134129 C63.6469557,10.6378178 63.6117918,10.723235 63.6779,10.7720448 C63.5799098,11.0940018 63.4626967,11.4323853 63.3994016,11.7609129 C63.3731459,11.8974865 63.3501721,11.9331552 63.4467557,12.023735 C63.4828574,12.0579957 63.6882148,12.0716061 63.7355689,12.0805233 C63.8148049,12.0950724 63.8935721,12.1105601 63.9723393,12.1255785 C64.5190213,12.310962 65.1018049,12.4836736 65.6475492,12.5766 C65.711782,12.5873945 65.7610115,12.5371767 65.7900803,12.4846123 C65.8660344,12.4752258 65.9152639,12.4146828 65.927923,12.329735 C65.9794967,11.9871276 66.0446672,11.5975877 66.0474803,11.2549804 C66.0479492,11.1911521 66.013723,11.1324865 65.9537098,11.1099589" id="Fill-259" fill="#666666"></path>
                            <path d="M62.5669072,8.70443282 L63.692622,9.16155552 C63.8384351,9.22069049 63.9242351,9.37275184 63.8998548,9.52809847 L63.7277859,10.6188101 L63.0756121,12.7950709 L62.5251793,13.5384819 C62.3868679,13.7252733 62.1566613,13.8196077 61.9269236,13.7834696 L60.8476252,13.6140433 L62.5669072,8.70443282 Z" id="Fill-261" fill="#989898"></path>
                            <path d="M62.9546951,12.6765193 C62.9368787,12.6483598 62.9120295,12.6258322 62.8768656,12.6173844 C62.4966262,12.5277433 62.0981016,12.4723629 61.7075475,12.4944212 C61.7052033,12.4944212 61.7042656,12.4967678 61.7019213,12.4967678 C61.636282,12.4953598 61.5701738,12.4897279 61.5064098,12.4920745 C61.4309246,12.4948905 61.3835705,12.5507402 61.3671607,12.6140991 C61.3549705,12.6140991 61.3423115,12.6126911 61.3291836,12.6131604 C61.2574492,12.6145684 61.2522918,12.7304917 61.3249639,12.733777 C61.3455934,12.735185 61.366223,12.7384702 61.3873213,12.7398782 C61.4032623,12.7642831 61.4229541,12.7877494 61.4571803,12.7994825 C61.4745279,12.8051144 61.493282,12.8083997 61.5110984,12.8140316 C61.4604623,12.8783291 61.4595246,12.9829887 61.5101607,13.0477555 C61.4989082,13.0806083 61.4975016,13.116277 61.5017213,13.1505377 C61.4023246,13.1749426 61.302459,13.1946543 61.2030623,13.2214058 C61.0896,13.2514426 61.0647508,13.3678353 61.1013213,13.4560684 C61.0952262,13.4602923 61.0886623,13.4631083 61.0825672,13.4678015 C61.0338066,13.5076942 61.0352131,13.5888874 61.0661574,13.6480224 L61.3338721,13.6902617 C61.3816951,13.6827525 61.4290492,13.6761819 61.4768721,13.669142 C61.4782787,13.6846298 61.4820295,13.7001175 61.4871869,13.7141972 L61.9269705,13.7836574 C62.1562393,13.8197954 62.3869148,13.7249917 62.5252262,13.5382003 L63.075659,12.7947893 L63.100977,12.7103107 C63.0991016,12.7093721 63.0986328,12.7074948 63.0962885,12.7070255 C63.050341,12.6967003 63.0015803,12.6868445 62.9546951,12.6765193" id="Fill-263" fill="#666666"></path>
                            <path d="M63.4551482,10.5200641 C63.4518662,10.5158402 63.4471777,10.5181868 63.4434269,10.5163095 C63.4579613,10.4416868 63.433581,10.3595549 63.3491875,10.3384353 C62.9961416,10.2497328 62.6384072,10.2628739 62.2853613,10.1957604 C62.171899,10.1741715 62.1189187,10.3464138 62.2314433,10.3816132 C62.5708925,10.486742 62.9244072,10.5149015 63.2610433,10.64115 C63.3018334,10.6566377 63.3365285,10.6500672 63.3660662,10.6336408 C63.3037089,10.7744383 63.2638564,11.1710181 63.2516662,11.2386009 C63.208063,11.4901592 63.1433613,11.7318617 63.0688138,11.9749721 C63.065063,11.9815426 63.0641252,11.9904598 63.061781,11.9984383 C63.0599056,12.0050089 63.0580302,12.0111101 63.0561548,12.0176807 L63.0566236,12.0176807 C63.055217,12.0251899 63.0519351,12.0303525 63.0514662,12.0378617 C63.0509974,12.0449015 63.0505285,12.0510028 63.0500597,12.0575733 C63.0374007,12.2251224 63.1822761,12.3579414 63.2324433,12.1979015 C63.3280892,11.8923709 63.4490531,11.4821807 63.4771843,11.1649169 C63.4945318,10.9635764 63.5761121,10.696061 63.4551482,10.5200641" id="Fill-265" fill="#666666"></path>
                            <path id="Fill-267" fill="#666666"></path>
                            <path d="M62.4707456,11.7124316 C62.4233915,11.5697567 62.3976046,11.422858 62.3535325,11.2792445 C62.3146177,11.1525267 62.2513226,11.0366034 62.2320997,10.9042537 C62.2217849,10.8343242 62.1205128,10.8493426 62.1191062,10.9169255 C62.116762,11.0347261 62.1345784,11.1553426 62.1566144,11.2754899 C62.0928505,11.2595328 62.015021,11.305996 62.0032997,11.3693549 C61.9854833,11.461812 61.9531325,12.0451831 61.8860866,12.0428365 C61.7215193,12.036266 61.7126111,12.3070666 61.8762407,12.3173917 C62.0844111,12.3305328 62.2846111,12.4220512 62.4927816,12.4065635 C62.5715489,12.4004623 62.6292177,12.3455512 62.6334374,12.264358 C62.6418767,12.0803825 62.5274767,11.8842046 62.4707456,11.7124316" id="Fill-269" fill="#666666"></path>
                            <path d="M60.8055223,5.98291012 C60.768483,6.04157577 61.788237,6.73617699 62.4136862,7.15528436 C62.7170338,7.35803282 62.8853518,7.70721074 62.8511256,8.07140706 C62.7536043,9.11847147 62.3874305,11.4594653 61.0085354,14.5344837 C60.1955452,15.3398457 59.1242174,15.2520819 59.1242174,15.2520819 L60.8055223,5.98291012 Z" id="Fill-271" fill="#977558"></path>
                            <path d="M57.9466007,3.56156779 C57.9466007,3.56156779 60.4483974,2.09023344 61.1188564,6.15177331 C61.7883777,10.2133132 60.2594498,14.814577 58.616122,17.2114206 C57.5208826,18.6489635 56.3332793,17.8145034 54.5853974,17.8891261 C52.8379843,17.9637488 57.9466007,3.56156779 57.9466007,3.56156779" id="Fill-273" fill="#1A1A1A"></path>
                            <path d="M42.1159849,1.77409601 L42.1159849,13.5072248 C42.1159849,13.5072248 49.1558046,17.400277 50.9665128,17.8893138 C52.777221,18.3778813 55.115857,19.1414733 55.8177292,19.159777 C56.5191325,19.1780807 56.5261652,18.1948445 56.6068079,17.8714794 C57.9800767,16.290323 62.3750997,10.0947617 58.2126275,3.13889356 C58.2126275,3.13889356 59.0635948,2.39782914 57.9960177,1.69806534 C57.0554997,1.08137209 56.6766669,1.66145798 55.7595915,1.87406227 C54.8425161,2.08713589 54.9752013,0.295252454 50.7095816,0.604068405 C46.443962,0.913353681 44.9290997,1.77409601 42.1159849,1.77409601" id="Fill-275" fill="#333333"></path>
                            <path d="M58.6159813,8.86414417 C58.6159813,13.2110337 55.0953682,16.7351963 50.7528567,16.7351963 C46.4103452,16.7351963 42.8897321,13.2110337 42.8897321,8.86414417 C42.8897321,4.51678528 46.4103452,0.993092025 50.7528567,0.993092025 C55.0953682,0.993092025 58.6159813,4.51678528 58.6159813,8.86414417" id="Fill-277" fill="#8B6342"></path>
                            <path d="M58.2346167,9.1208181 C58.2346167,13.1152445 55.0000036,16.3531187 51.0096003,16.3531187 C47.019197,16.3531187 43.7841151,13.1152445 43.7841151,9.1208181 C43.7841151,5.12639172 47.019197,1.88804816 51.0096003,1.88804816 C55.0000036,1.88804816 58.2346167,5.12639172 58.2346167,9.1208181" id="Fill-279" fill="#977558"></path>
                            <path d="M57.2753915,9.1208181 C57.2753915,12.5849071 54.4702472,15.3933488 51.0096472,15.3933488 C47.5485784,15.3933488 44.7434341,12.5849071 44.7434341,9.1208181 C44.7434341,5.65672914 47.5485784,2.84828742 51.0096472,2.84828742 C54.4702472,2.84828742 57.2753915,5.65672914 57.2753915,9.1208181" id="Fill-281" fill="#661E88"></path>
                            <path d="M52.6427072,0.247193558 C52.1485367,0.178672086 52.1640089,0.779877607 52.1321269,1.11075184 C52.10634,1.37779785 52.0411695,1.6603316 52.058517,1.92972423 C52.0664875,2.04377025 52.0833662,2.16344816 52.1100908,2.27467822 C52.1208744,2.31832546 52.1686974,2.59381933 52.2085498,2.49197577 C52.1902646,2.53937761 52.2066744,2.6027365 52.2390252,2.63981319 L52.2479334,2.65013834 C52.3206056,2.73273957 52.4425072,2.71443589 52.4987695,2.62385613 C52.6844351,2.32161074 52.681622,1.89968742 52.748199,1.56083466 C52.7796121,1.40032546 52.783363,1.24450951 52.7922711,1.08165368 C52.7997728,0.946488037 52.7707039,0.766267178 52.8396252,0.654098466 C52.945117,0.482794785 52.8311859,0.273475767 52.6427072,0.247193558" id="Fill-283" fill="#A78A72"></path>
                            <path d="M59.7574495,9.1664365 C59.6196069,9.08336595 59.5370889,9.13076779 59.3940889,9.15376472 C59.2815643,9.17159908 59.0368233,9.17066043 58.8891348,9.18474018 C58.538902,9.21759294 58.0545774,9.20022791 57.8131184,9.50435061 C57.7934266,9.52875552 57.788738,9.56160828 57.8098364,9.58742117 C58.004879,9.82302239 58.4184069,9.79251626 58.697843,9.83006227 C58.9660266,9.86526166 59.3800233,9.98400092 59.6463315,9.87981074 C59.9196725,9.77233528 60.0528266,9.34478006 59.7574495,9.1664365" id="Fill-285" fill="#A78A72"></path>
                            <path d="M58.0862718,13.7446095 C58.0145374,13.4357936 57.6783702,13.2687138 57.4040915,13.1574837 C57.1790423,13.0659653 56.8672554,12.9608365 56.6389243,13.0913089 C56.5554685,13.1391801 56.4945177,13.2668365 56.5268685,13.3630482 C56.6009472,13.5864469 56.8372489,13.7934193 57.040262,13.9004255 C57.2868784,14.0308979 57.5428718,14.2575819 57.8382489,14.1829592 C58.022039,14.1360267 58.1284685,13.9238917 58.0862718,13.7446095" id="Fill-287" fill="#A78A72"></path>
                            <path d="M53.778221,16.7420954 C53.736962,16.5825248 53.6563193,16.3722672 53.5419193,16.1925156 C53.5597357,16.1896997 53.56208,16.1596629 53.5423882,16.1601322 C53.536762,16.1601322 53.5278538,16.1610709 53.5203521,16.1620095 C53.4111095,15.9991537 53.2742046,15.8649267 53.1119816,15.8245647 C52.9366308,15.7813868 52.7814407,15.9386107 52.8241062,16.1169543 C52.831139,16.1483991 52.8494243,16.171396 52.8625521,16.1981475 C52.8653652,16.3145402 52.8742734,16.4342181 52.8906833,16.5426322 C52.9188144,16.7289543 53.0257128,16.8791383 53.1082308,17.0213439 C53.3257784,17.3972733 53.8888702,17.1691813 53.778221,16.7420954" id="Fill-289" fill="#A78A72"></path>
                            <path d="M48.8872928,15.9796767 C48.8690075,15.8172902 48.746637,15.7126307 48.6158272,15.7961706 C48.6177026,15.7942933 48.619578,15.792416 48.6214534,15.7905387 C48.6144207,15.7905387 48.606919,15.7900693 48.6003551,15.7900693 C48.4882993,15.7863147 48.4039059,15.8557748 48.3565518,15.9472933 C48.1788567,16.1645908 48.1164993,16.4673055 48.1024338,16.743738 C48.0846174,17.1046491 48.6280174,17.2351215 48.7077223,16.8549681 C48.7461682,16.6724006 48.8235289,16.4705908 48.8638502,16.2725356 C48.9313649,16.1631828 48.9271452,16.0613393 48.8872928,15.9796767" id="Fill-291" fill="#A78A72"></path>
                            <path d="M45.4231295,13.0241485 C45.2623131,12.9429552 45.0696148,13.0246178 45.0185098,13.1804337 C44.9983492,13.1916975 44.9758443,13.2081239 44.9645918,13.2151638 C44.8825426,13.2667896 44.8042443,13.3235779 44.7212574,13.3747344 C44.5801328,13.4606209 44.4221295,13.5413448 44.2828803,13.6370871 C44.2683459,13.644127 44.2547492,13.6525748 44.2397459,13.6591454 C44.2406836,13.6610227 44.2416213,13.6624307 44.2420902,13.664308 C44.2219295,13.6793264 44.1970803,13.6910595 44.1783262,13.7074859 C43.9092049,13.9426178 44.2195852,14.3720503 44.5144934,14.2162344 C44.5159,14.2195196 44.5177754,14.2228049 44.519182,14.2260902 C44.7901787,14.0862313 45.0457033,13.913989 45.2998213,13.7450319 C45.5215885,13.5971945 45.7747689,13.2020227 45.4231295,13.0241485" id="Fill-293" fill="#A78A72"></path>
                            <path d="M55.5153662,5.51541534 C55.6503957,5.42155031 55.7854252,5.32674663 55.9209236,5.2328816 C55.7793302,5.05453804 55.6311728,4.88182638 55.4712941,4.7199092 C53.9353334,5.68906564 52.4561039,6.7666362 51.0139138,7.85875583 C50.1854515,8.48671288 49.3255761,9.0616362 48.4685138,9.64923129 C47.8505662,10.0720933 47.0033498,10.9131239 46.2292744,11.175946 C46.2138023,11.1623356 46.1945793,11.1534184 46.1697302,11.1604583 C46.0440777,11.1951883 46.0379826,11.1956577 45.9315531,11.2660564 C45.8166843,11.3430258 45.7955859,11.5443663 45.8860744,11.6396393 C45.7913662,11.8344092 45.7641728,12.0371577 45.8973269,12.248354 C45.9906285,12.3961914 46.15754,12.4853632 46.325858,12.3868049 C48.2068941,11.2895227 49.8731957,9.74356564 51.6482711,8.48108098 C52.961058,7.54712393 54.2138318,6.48363313 55.5153662,5.51541534" id="Fill-295" fill="#654E7E"></path>
                            <path d="M55.7809243,8.42096043 C55.3448915,8.48525798 54.9257374,8.90342669 54.54878,9.13245736 C53.8736325,9.5417089 53.2153636,9.98193589 52.5711603,10.4381199 C51.3160423,11.3279604 50.1523505,12.3872273 48.848003,13.2010371 C48.7078161,13.2883316 48.7772062,13.5901077 48.9577144,13.5164236 C50.2006423,13.0104911 51.2986948,11.7541077 52.349862,10.9510923 C52.9795308,10.4695647 53.6223275,10.0025862 54.2843472,9.5661138 C54.7213177,9.27794816 55.295662,8.70443282 55.8156193,8.58146963 C55.9112652,8.5584727 55.8793833,8.40641135 55.7809243,8.42096043" id="Fill-297" fill="#654E7E"></path>
                            <path d="M54.3226056,11.0993991 C54.3104154,11.064669 54.292599,11.0332242 54.2700941,11.0064727 C54.2747826,10.9994328 54.2813466,10.9928623 54.2869728,10.9862917 C54.3446416,10.911669 54.2644679,10.7910525 54.1838252,10.8656752 C54.1585072,10.8891414 54.1322515,10.9088531 54.1069334,10.93185 C54.0769269,10.9351353 54.0459826,10.9445218 54.0150384,10.967988 C53.5888515,11.2866598 53.1396908,11.5748255 52.7144416,11.8963132 C52.5784744,11.9990954 52.4481334,12.1103255 52.3154482,12.2168623 C52.3009138,12.2215555 52.2863793,12.2229635 52.2709072,12.2318807 C52.0383564,12.3614144 51.8081498,12.4951721 51.5793498,12.6331537 C51.5043334,12.6786782 51.4701072,12.7561169 51.4640121,12.8363715 C51.337422,12.9860862 51.5001138,13.2475003 51.6885925,13.1442488 C51.6895302,13.1465954 51.6904679,13.148942 51.6918744,13.1512887 C51.7143793,13.1367396 51.7368843,13.1212518 51.7593892,13.1062334 C51.8639433,13.0780739 51.9609957,13.0391199 52.0528908,12.9931261 C52.0960252,13.0930923 52.19214,13.1583285 52.3084154,13.0790126 C52.7266318,12.7931936 53.0773334,12.3900433 53.4814843,12.076534 C53.7693597,11.8521966 54.0867728,11.6546107 54.2888482,11.3443868 C54.3230744,11.3110647 54.3601138,11.2805586 54.3934023,11.2462979 C54.4552908,11.182939 54.3915269,11.08485 54.3226056,11.0993991" id="Fill-299" fill="#654E7E"></path>
                            <path d="M57.4383646,3.49304632 C57.3605351,3.45690828 57.2681711,3.46207086 57.192217,3.54279479 C57.1092302,3.63102791 57.0248367,3.72254632 56.9399744,3.81312607 C56.9132498,3.83612301 56.8879318,3.86005859 56.8607384,3.88211687 C56.7941613,3.93561994 56.7149252,4.03417822 56.6272498,4.04074877 C56.4823744,4.05154325 56.407358,4.16652791 56.4087646,4.2819819 C56.378758,4.31436534 56.3487515,4.3472181 56.321558,4.38523344 C56.1930925,4.56263834 56.4143908,4.85737454 56.614122,4.72690215 C56.6324072,4.71516902 56.6492859,4.70061994 56.6675711,4.68794816 C56.8640203,4.64289294 57.0163974,4.50162607 57.1579908,4.36552178 C57.3234957,4.20688988 57.4979089,4.03464755 57.6413777,3.85630399 C57.7693744,3.69720276 57.6516925,3.41044509 57.4383646,3.49304632" id="Fill-301" fill="#A78A72"></path>
                            <path d="M48.0329498,2.17602607 C48.0010679,2.0310046 47.9391793,1.90428681 47.8730711,1.77663037 C47.8055564,1.53539724 47.73054,1.29322546 47.612858,1.08906902 C47.542999,0.967983129 47.4210974,0.925743865 47.3113859,0.944516871 C47.2893498,0.930437117 47.2659072,0.930906442 47.2405892,0.947802147 C47.0882121,0.906501534 46.9372416,1.07076534 46.9611531,1.24629294 C46.9981925,1.51803221 47.1168121,1.77099847 47.2180843,2.0234954 C47.2541859,2.11407515 47.2879433,2.21357209 47.3306089,2.30509049 C47.33014,2.3984862 47.3751498,2.48765798 47.4853302,2.51863344 C47.4904875,2.52004141 47.4942384,2.51957209 47.498458,2.52098006 C47.5233072,2.53975307 47.5444056,2.56228067 47.5739433,2.57542178 C47.6470843,2.60686656 47.7042843,2.58621626 47.7422613,2.54163037 C47.7989925,2.54491564 47.8566613,2.52144939 47.905422,2.48343405 C48.0048187,2.41772853 48.0606121,2.29898926 48.0329498,2.17602607" id="Fill-303" fill="#A78A72"></path>
                            <path d="M45.393123,4.70808221 C45.3101361,4.64284601 45.2074574,4.61187055 45.1188443,4.55649018 C44.9875656,4.47388896 44.8708213,4.36782147 44.7475131,4.27489509 C44.5248082,4.10828466 44.2800672,3.92383988 44.0207918,3.82011902 C43.727759,3.70278773 43.4970836,4.15474785 43.7090049,4.35702699 C43.8749787,4.51518957 44.0564246,4.66114969 44.2463098,4.79584601 C44.3766508,4.91083067 44.5360607,4.99202393 44.6921885,5.08260368 C44.8895754,5.19711902 45.0668016,5.32571411 45.3045098,5.2787816 C45.5736311,5.22527853 45.5764443,4.85216503 45.393123,4.70808221" id="Fill-305" fill="#A78A72"></path>
                            <path d="M26.9139597,3.56156779 C26.9139597,3.56156779 24.412163,2.09023344 23.7421728,6.15177331 C23.0721826,10.2133132 24.6015793,14.814577 26.2444384,17.2114206 C27.3396777,18.6489635 28.5277498,17.8145034 30.275163,17.8891261 C32.0225761,17.9637488 26.9139597,3.56156779 26.9139597,3.56156779" id="Fill-307" fill="#1A1A1A"></path>
                            <path d="M42.5848374,1.77409601 L42.5848374,13.5072248 C42.5848374,13.5072248 35.6251915,17.400277 33.8140144,17.8893138 C32.0033062,18.3778813 29.7049915,19.1414733 29.0035882,19.159777 C28.3017161,19.1780807 28.3143751,18.1948445 28.2337325,17.8714794 C26.8609325,16.290323 22.4757554,10.0947617 26.6382275,3.13889356 C26.6382275,3.13889356 25.7924177,2.39782914 26.8599948,1.69806534 C27.8000439,1.08137209 28.1816898,1.66145798 29.0987652,1.87406227 C30.0158407,2.08713589 29.8043882,0.295252454 34.0700079,0.604068405 C38.3356275,0.913353681 39.7717226,1.77409601 42.5848374,1.77409601" id="Fill-309" fill="#333333"></path>
                            <path d="M26.244579,8.86414417 C26.244579,13.2110337 29.7651921,16.7351963 34.1077036,16.7351963 C38.4506839,16.7351963 41.9708282,13.2110337 41.9708282,8.86414417 C41.9708282,4.51678528 38.4506839,0.993092025 34.1077036,0.993092025 C29.7651921,0.993092025 26.244579,4.51678528 26.244579,8.86414417" id="Fill-311" fill="#8B6342"></path>
                            <path d="M26.6262249,9.1208181 C26.6262249,13.1152445 29.860838,16.3531187 33.8517102,16.3531187 C37.8416446,16.3531187 41.0767266,13.1152445 41.0767266,9.1208181 C41.0767266,5.12639172 37.8416446,1.88804816 33.8517102,1.88804816 C29.860838,1.88804816 26.6262249,5.12639172 26.6262249,9.1208181" id="Fill-313" fill="#977558"></path>
                            <path d="M27.5854502,9.1208181 C27.5854502,12.5849071 30.3905944,15.3933488 33.8516633,15.3933488 C37.3122633,15.3933488 40.1174075,12.5849071 40.1174075,9.1208181 C40.1174075,5.65672914 37.3122633,2.84828742 33.8516633,2.84828742 C30.3905944,2.84828742 27.5854502,5.65672914 27.5854502,9.1208181" id="Fill-315" fill="#661E88"></path>
                            <path d="M30.9138807,0.728204908 C31.3907036,0.58271411 31.4704085,1.17875706 31.5538643,1.50071411 C31.6209102,1.76025092 31.7301528,2.02870491 31.755002,2.29809755 C31.7657856,2.41167423 31.767661,2.5322908 31.7582839,2.64633681 C31.755002,2.69139202 31.7512511,2.97110982 31.6954577,2.87677546 C31.7212446,2.9204227 31.7146807,2.9856589 31.6888938,3.02695951 L31.6813921,3.03869264 C31.6223167,3.13208834 31.4994774,3.13349632 31.4296184,3.05230307 C31.198943,2.78337975 31.1356479,2.36614969 31.0160905,2.04184601 C30.9598282,1.88837669 30.931697,1.73537669 30.897002,1.57580613 C30.8688708,1.44345644 30.8688708,1.26135828 30.7835397,1.16139202 C30.652261,1.00886135 30.731497,0.783585276 30.9138807,0.728204908" id="Fill-317" fill="#A78A72"></path>
                            <path d="M27.6616856,14.915435 C27.6841905,14.5991098 27.9898823,14.3813429 28.2435315,14.2278736 C28.4507643,14.1025638 28.7419216,13.9490945 28.9880692,14.0424902 C29.0780889,14.0762816 29.1582626,14.1931436 29.1418528,14.2926405 C29.1038757,14.5249564 28.9032069,14.7666589 28.7194167,14.9046405 C28.496243,15.0721896 28.2791643,15.3364196 27.9758167,15.3087294 C27.7868692,15.2918337 27.6485577,15.0989411 27.6616856,14.915435" id="Fill-319" fill="#A78A72"></path>
                            <path d="M32.3877184,17.1962613 C32.4031905,17.0319975 32.4500757,16.811884 32.5344692,16.6161755 C32.5161839,16.6161755 32.5091511,16.5870773 32.5293118,16.5842613 C32.5340003,16.5833227 32.5438462,16.5833227 32.5513479,16.5828534 C32.633397,16.4045098 32.7473282,16.2505712 32.9015807,16.185335 C33.0675544,16.1144669 33.2457184,16.2454086 33.2316528,16.4284454 C33.2293085,16.4608288 33.215243,16.4861724 33.2068036,16.5148012 C33.2222757,16.6302552 33.2316528,16.7494638 33.2325905,16.8592859 C33.2344659,17.0479546 33.1524167,17.2126877 33.0933413,17.3666264 C32.9372134,17.771654 32.3455216,17.6355497 32.3877184,17.1962613" id="Fill-321" fill="#A78A72"></path>
                            <path d="M37.0977633,15.6721279 C37.0902616,15.5092721 37.1943469,15.3863089 37.336878,15.4482598 C37.3345338,15.4468518 37.3326584,15.4454439 37.3303141,15.4435666 C37.3373469,15.4421586 37.3443797,15.4407506 37.3514125,15.4393426 C37.4611239,15.418223 37.5553633,15.4740727 37.6163141,15.5566739 C37.82636,15.742996 37.9356026,16.0321003 37.9928026,16.3033702 C38.0673502,16.6567721 37.5511436,16.8712537 37.4123633,16.507996 C37.3457862,16.3338764 37.2379502,16.147085 37.1666846,15.9574776 C37.0832289,15.859858 37.0710387,15.7594224 37.0977633,15.6721279" id="Fill-323" fill="#A78A72"></path>
                            <path d="M40.053503,12.2074288 C40.1993161,12.1018307 40.402798,12.1520485 40.4778144,12.2975393 C40.4993816,12.3059871 40.523762,12.3181896 40.536421,12.3238215 C40.625503,12.3618368 40.7117718,12.405484 40.8017915,12.4425607 C40.9546374,12.5059196 41.1234243,12.5603613 41.2758013,12.6331067 C41.2912734,12.6378 41.3062767,12.6439012 41.3222177,12.6481252 C41.32128,12.6500025 41.3208111,12.6518798 41.3203423,12.6537571 C41.3428472,12.6654902 41.369103,12.6725301 41.3902013,12.6861405 C41.69308,12.8757479 41.4539652,13.3488276 41.1384275,13.2413521 C41.1374898,13.2446374 41.1360833,13.248392 41.1351456,13.2521466 C40.8453948,13.1564043 40.5664275,13.0268706 40.288398,12.8996834 C40.0460013,12.7889227 39.7342144,12.4388061 40.053503,12.2074288" id="Fill-325" fill="#A78A72"></path>
                            <path d="M40.5905734,7.81388834 C40.6374587,7.65994969 40.7532652,7.62756626 40.8798554,7.60738528 C41.0575505,7.50507239 41.2765046,7.4529773 41.4635767,7.40229018 C41.67878,7.34315521 41.9338357,7.24225031 42.149039,7.35582699 C42.4336325,7.50507239 42.4387898,7.9119773 42.1152816,8.01616748 C41.8278751,8.10909387 41.53578,8.14992515 41.2394652,8.20436687 C41.0552062,8.23815828 40.8737603,8.29353865 40.7068489,8.17573804 C40.5966685,8.09783006 40.5521275,7.94107546 40.5905734,7.81388834" id="Fill-327" fill="#A78A72"></path>
                            <path d="M38.6490085,5.51541534 C38.7845069,5.42155031 38.4506839,5.32674663 38.5857134,5.2328816 C38.5800872,5.22537239 38.5739921,5.21833252 38.5683659,5.21082331 C38.3320643,4.91467914 37.8997823,4.86633865 37.589402,5.0836362 C36.343661,5.95517301 35.4115823,6.90133252 34.1475561,7.85875583 C33.3190938,8.48671288 32.4592184,9.0616362 31.6021561,9.64923129 C30.9842085,10.0720933 30.1369921,10.9131239 29.3633856,11.175946 C29.3479134,11.1623356 29.3282216,11.1534184 29.3033725,11.1604583 C29.1781889,11.1951883 29.1716249,11.1956577 29.0656643,11.2660564 C28.9503266,11.3430258 28.9292282,11.5443663 29.0197167,11.6396393 C28.9254774,11.8344092 28.8978151,12.0371577 29.0309692,12.248354 C29.1247397,12.3961914 29.2911823,12.4853632 29.4599692,12.3868049 C31.3405364,11.2895227 33.006838,9.74356564 34.7819134,8.48108098 C36.0947003,7.54712393 37.3474741,6.48363313 38.6490085,5.51541534" id="Fill-329" fill="#654E7E"></path>
                            <path d="M38.9145666,8.42096043 C38.4785338,8.48525798 38.0593797,8.90342669 37.6824223,9.13245736 C37.0072748,9.5417089 36.3490059,9.98193589 35.7048026,10.4381199 C34.4496846,11.3279604 33.2864616,12.3872273 31.9816452,13.2010371 C31.8419272,13.2883316 31.9108485,13.5901077 32.0913567,13.5164236 C33.3342846,13.0104911 34.432337,11.7541077 35.4835043,10.9510923 C36.1131731,10.4695647 36.7559698,10.0025862 37.4179895,9.5661138 C37.85496,9.27794816 38.4293043,8.70443282 38.9492616,8.58146963 C39.0453764,8.5584727 39.0130256,8.40641135 38.9145666,8.42096043" id="Fill-331" fill="#654E7E"></path>
                            <path d="M37.4562948,11.0993991 C37.4441046,11.064669 37.4262882,11.0332242 37.4037833,11.0064727 C37.4089407,10.9994328 37.4155046,10.9928623 37.420662,10.9862917 C37.4783308,10.911669 37.398157,10.7910525 37.3175144,10.8656752 C37.2926652,10.8891414 37.2659407,10.9088531 37.2406226,10.93185 C37.2106161,10.9351353 37.1801407,10.9445218 37.1487275,10.967988 C36.7225407,11.2866598 36.27338,11.5748255 35.8485997,11.8963132 C35.7121636,11.9990954 35.5818226,12.1103255 35.4491374,12.2168623 C35.434603,12.2215555 35.4205374,12.2229635 35.4045964,12.2318807 C35.1720456,12.3614144 34.941839,12.4951721 34.7135079,12.6331537 C34.6384915,12.6786782 34.6037964,12.7561169 34.5977013,12.8363715 C34.4711111,12.9860862 34.6342718,13.2475003 34.8222816,13.1442488 C34.8232193,13.1465954 34.824157,13.148942 34.8255636,13.1512887 C34.8480685,13.1367396 34.8710423,13.1212518 34.8935472,13.1062334 C34.9976325,13.0780739 35.0946849,13.0391199 35.18658,12.9931261 C35.2297144,13.0930923 35.326298,13.1583285 35.4421046,13.0790126 C35.860321,12.7931936 36.2110226,12.3900433 36.6151734,12.076534 C36.9030489,11.8521966 37.220462,11.6546107 37.4225374,11.3443868 C37.4567636,11.3110647 37.493803,11.2805586 37.5270915,11.2462979 C37.58898,11.182939 37.5252161,11.08485 37.4562948,11.0993991" id="Fill-333" fill="#654E7E"></path>
                            <path d="M26.6884885,4.6897316 C26.7597541,4.6413911 26.852118,4.6320046 26.9397934,4.70005675 C27.0354393,4.77421012 27.1329607,4.85117945 27.2314197,4.92721012 C27.2609574,4.9455138 27.2900262,4.96522546 27.3205016,4.98259049 C27.3945803,5.02482975 27.4883508,5.11024693 27.5760262,5.10273773 C27.7204328,5.09053528 27.8123279,5.19190951 27.8296754,5.30642485 C27.8643705,5.33411503 27.8990656,5.36180521 27.9318852,5.39418865 C28.0866066,5.54953528 27.9145377,5.87571626 27.6965213,5.77809663 C27.6763607,5.76964877 27.6576066,5.75791564 27.6374459,5.74852914 C27.436777,5.73444939 27.2642393,5.6189954 27.1029541,5.50729601 C26.9144754,5.37635429 26.7152131,5.23414877 26.5454885,5.08067945 C26.3940492,4.94316718 26.4648459,4.64186043 26.6884885,4.6897316" id="Fill-335" fill="#A78A72"></path>
                            <path d="M35.7692698,1.90607025 C35.778178,1.75823282 35.8189682,1.62306718 35.8644469,1.48649356 C35.8930469,1.23775123 35.9286797,0.987131595 36.0130731,0.766548773 C36.0632403,0.63607638 36.1767026,0.57506411 36.2878207,0.576472086 C36.3075125,0.559107055 36.3309551,0.555821779 36.3586174,0.568493558 C36.5025551,0.503726687 36.677437,0.641708282 36.6811879,0.81911319 C36.687283,1.09319908 36.6103911,1.36165307 36.550378,1.62682178 C36.528342,1.72256411 36.5109944,1.82581564 36.4828633,1.92296595 C36.4983354,2.01495368 36.4683289,2.11022669 36.3642436,2.15856718 C36.3595551,2.16044448 36.3558043,2.16044448 36.3511157,2.1627911 C36.3300174,2.18531871 36.3126698,2.21066227 36.2854764,2.22849663 C36.2184305,2.27073589 36.1588862,2.25900276 36.1138764,2.22145675 C36.0585518,2.2336592 35.997601,2.21957945 35.943683,2.18954264 C35.835378,2.14073282 35.7617682,2.03184939 35.7692698,1.90607025" id="Fill-337" fill="#A78A72"></path>
                            <path d="M38.7744734,3.99053098 C38.8462079,3.91309233 38.9423226,3.86615982 39.0215587,3.79716902 C39.138303,3.69532546 39.236762,3.57189294 39.3441292,3.46113221 C39.5372964,3.26119969 39.7506243,3.04061687 39.989739,2.89700337 C40.2612046,2.7350862 40.5598636,3.14480706 40.3821685,3.37853098 C40.2433882,3.56062914 40.0872603,3.7333408 39.9208177,3.89619663 C39.8101685,4.03042362 39.665762,4.13555245 39.5255751,4.25006779 C39.3488177,4.39368129 39.1940964,4.54855859 38.9521685,4.53964141 C38.6778898,4.52978558 38.6160013,4.16183466 38.7744734,3.99053098" id="Fill-339" fill="#A78A72"></path>
                            <path id="Fill-341" fill="#989898"></path>
                            <path id="Fill-343" fill="#989898"></path>
                            <path id="Fill-345" fill="#989898"></path>
                            <path id="Fill-347" fill="#989898"></path>
                            <path id="Fill-349" fill="#989898"></path>
                            <path id="Fill-351" fill="#989898"></path>
                            <path id="Fill-353" fill="#989898"></path>
                            <path id="Fill-355" fill="#989898"></path>
                            <path d="M66.5938341,10.9034558 C66.5938341,10.9034558 66.244539,8.66055092 66.3158046,8.20248957 C66.3880079,7.74489755 67.4072931,7.97298957 68.1719915,8.20248957 C68.9366898,8.43198957 69.3717849,8.69856626 68.6516275,9.73014294 C67.9314702,10.760781 67.6623489,11.0902472 67.6623489,11.0902472 L66.5938341,10.9034558 Z" id="Fill-357" fill="#654E7E"></path>
                            <path d="M44.2013,8.77018528 C44.1309721,8.62563313 44.0114148,8.61155337 43.8829492,8.6120227 C43.6916574,8.53880798 43.467077,8.52191227 43.2743787,8.50079264 C43.0526115,8.47685706 42.7848967,8.41772209 42.590323,8.56321288 C42.332923,8.75610552 42.3919984,9.15831718 42.7272279,9.21041227 C43.0263557,9.25640613 43.3207951,9.25077423 43.6217984,9.25781411 C43.8093393,9.26203804 43.9973492,9.28878957 44.1431623,9.14611472 C44.2402148,9.05131104 44.2594377,8.88939387 44.2013,8.77018528" id="Fill-359" fill="#A78A72"></path>
                            <path d="M37.7188521,9.1208181 C37.7188521,11.2590635 35.98738,12.9922813 33.8512882,12.9922813 C31.7151964,12.9922813 29.9837243,11.2590635 29.9837243,9.1208181 C29.9837243,6.9825727 31.7151964,5.24935491 33.8512882,5.24935491 C35.98738,5.24935491 37.7188521,6.9825727 37.7188521,9.1208181 Z" id="Stroke-361" stroke="#A78A72" stroke-width="0.595833333"></path>
                            <path d="M37.7188521,9.1208181 C37.7188521,11.2590635 35.98738,12.9922813 33.8512882,12.9922813 C31.7151964,12.9922813 29.9837243,11.2590635 29.9837243,9.1208181 C29.9837243,6.9825727 31.7151964,5.24935491 33.8512882,5.24935491 C35.98738,5.24935491 37.7188521,6.9825727 37.7188521,9.1208181" id="Fill-363" fill="#FFFFFF"></path>
                            <path d="M33.1015462,13.1717043 C33.1015462,14.7110908 31.8548675,15.9590264 30.3170315,15.9590264 C28.7787266,15.9590264 27.5320479,14.7110908 27.5320479,13.1717043 C27.5320479,11.6323178 28.7787266,10.3839129 30.3170315,10.3839129 C31.8548675,10.3839129 33.1015462,11.6323178 33.1015462,13.1717043 Z" id="Stroke-365" stroke="#A78A72" stroke-width="0.595833333"></path>
                            <path d="M33.1015462,13.1717043 C33.1015462,14.7110908 31.8548675,15.9590264 30.3170315,15.9590264 C28.7787266,15.9590264 27.5320479,14.7110908 27.5320479,13.1717043 C27.5320479,11.6323178 28.7787266,10.3839129 30.3170315,10.3839129 C31.8548675,10.3839129 33.1015462,11.6323178 33.1015462,13.1717043" id="Fill-367" fill="#FFFFFF"></path>
                            <path d="M27.853962,11.8721429 L25.210103,9.38753558 L26.767162,4.06351104 C26.8374898,3.82368589 27.126303,3.72982086 27.3222833,3.88516748 C28.2965587,4.65861534 30.6811423,6.55703558 30.7181816,6.63963681" id="Stroke-369" stroke="#C0B17C" stroke-width="0.595833333"></path>
                            <path d="M26.244579,9.24617485 L21.5551167,13.7521656" id="Stroke-371" stroke="#C0B17C" stroke-width="0.595833333"></path>
                            <path d="M26.5396748,7.20568988 C26.3146256,7.0494046 25.7496584,7.08178804 25.3741075,7.26623282 C25.4631895,7.14561626 25.5522715,7.02453037 25.6408846,6.9039138 C25.6783928,6.8776316 26.2086649,6.78845982 26.2888387,6.91189233 C26.3141567,6.95084632 26.3788584,6.92972669 26.3638551,6.88044755 C26.2058518,6.36043528 24.9460452,6.72040767 25.0543502,7.26998742 C25.0656026,7.32771442 25.1002977,7.37746288 25.1490584,7.42017147 C25.0651338,7.50230337 25.0126223,7.59945368 25.0130911,7.71162239 C25.0130911,7.7435365 25.0182485,7.77263466 25.0248125,7.8012635 C24.8358649,7.8965365 24.7191207,8.03686472 24.7589731,8.23632791 C24.7749141,8.31799049 24.8194551,8.38416534 24.8752485,8.44283098 C24.7950748,8.48553957 24.7322485,8.52965613 24.7003666,8.57048742 C24.5554911,8.75915613 24.6122223,8.92529724 24.7683502,9.05154571 C24.4354649,9.52368681 25.131242,9.6335089 25.4444354,9.5645181 C25.5996256,9.53072669 25.5447698,9.28949356 25.388642,9.30779724 C25.1734387,9.37725736 25.0998289,9.34722055 25.147183,9.23317454 C25.5236715,9.34158865 25.9901797,9.32375429 26.15756,9.10786472 C26.1964748,9.05764693 26.2152289,8.97786166 26.1636551,8.92623589 C25.9456387,8.70753037 25.4069272,8.69298129 25.0491928,8.84316534 C25.0196551,8.79623282 25.0177797,8.73568988 25.0670092,8.65261933 C25.0763862,8.6371316 25.1049862,8.62164387 25.1424944,8.60662546 C25.5363305,8.75258558 26.1144256,8.70095982 26.3422879,8.52214693 C26.3765141,8.4953954 26.3844846,8.43860706 26.3765141,8.39918374 C26.313219,8.07722669 25.6361961,8.15654264 25.1556223,8.32221442 C25.1195207,8.30344141 25.0895141,8.28232178 25.0759174,8.25744755 C25.0234059,8.16029724 25.0824813,8.08379724 25.1973502,8.02560092 C25.5433633,8.22788006 26.275242,8.08661319 26.4965403,7.89559785 C26.5429567,7.85570521 26.5401436,7.75245368 26.4820059,7.72241687 C26.3029043,7.62949049 25.7693502,7.59851503 25.3412879,7.69425736 C25.3431633,7.65530337 25.366137,7.60884018 25.4163043,7.55299049 C25.8317075,7.6726684 26.4665338,7.60649356 26.5729633,7.36760706 C26.5978125,7.31175736 26.5949993,7.24417454 26.5396748,7.20568988" id="Fill-373" fill="#C0B17C"></path>
                            <path d="M25.0909675,11.1853325 C24.8026233,10.9764828 24.5639774,10.7145994 24.3131413,10.4630411 C24.2292167,10.3790319 24.1120036,10.5085656 24.18702,10.5911669 C24.428479,10.8568049 24.6647807,11.1942497 24.9770364,11.3810411 C25.1017511,11.4556638 25.200679,11.2651178 25.0909675,11.1853325" id="Fill-375" fill="#C0B17C"></path>
                            <path d="M25.2171357,10.9777031 C24.9827095,10.7716693 24.7764144,10.5642276 24.5794964,10.3215865 C24.517139,10.2450865 24.4172734,10.3563166 24.4754111,10.4267153 C24.6756111,10.6693564 24.8790931,10.9105896 25.0914833,11.1424362 C25.1946308,11.2541356 25.337162,11.0593656 25.2171357,10.9777031" id="Fill-377" fill="#C0B17C"></path>
                            <path d="M25.7777895,10.4132926 C25.5893108,10.1927098 25.3651993,9.98386012 25.1528092,9.78627423 C25.0946715,9.73230184 25.0201239,9.81396442 25.0684157,9.87122209 C25.1448387,9.96227117 25.2156354,10.0570748 25.2864321,10.1514092 C25.2161043,10.0777252 25.1462452,10.0031025 25.0763862,9.92894908 C25.0069961,9.85573436 24.9207272,9.96180184 24.9727698,10.0345472 C25.1265534,10.2509061 25.2779928,10.4667957 25.451937,10.6667282 C25.4369338,10.6610963 25.4209928,10.6582804 25.4050518,10.6596883 C25.1603108,10.524992 24.9479207,10.260762 24.7716321,10.0622374 C24.7167764,10.0007558 24.6351961,10.0922742 24.6848944,10.1500012 C24.7472518,10.2227466 24.8110157,10.2945534 24.8733731,10.3672988 C24.8138289,10.318489 24.7524092,10.2715564 24.6947403,10.2208693 C24.6370715,10.1711209 24.5676813,10.2748417 24.6155043,10.3250595 C24.8532125,10.5738018 25.1077993,10.8103417 25.3342551,11.0698785 C25.4355272,11.2008202 25.6216616,10.9985411 25.5081993,10.8929429 C25.5063239,10.891535 25.5039797,10.8896577 25.5021043,10.8877804 C25.5330485,10.8502344 25.5466452,10.794854 25.5246092,10.753084 C25.6357272,10.8647834 25.7759141,10.7305564 25.7421567,10.6061853 C25.7993567,10.5681699 25.8345207,10.4799368 25.7777895,10.4132926" id="Fill-379" fill="#C0B17C"></path>
                            <path d="M26.6175043,10.2601988 C26.6643895,10.168211 26.5631174,10.0527571 26.4660649,10.1311344 C26.4473108,10.1466221 26.4280879,10.1644564 26.4088649,10.1804135 C26.4440289,10.1428675 26.4759108,10.1020362 26.5115436,10.0649595 C26.5945305,9.97860368 26.4768485,9.82607301 26.3858911,9.91806074 C26.1664682,10.1391129 25.8518682,10.4056896 25.7449698,10.7088736 C25.7271534,10.7595607 25.7491895,10.8299595 25.7956059,10.859527 C25.8054518,10.8660975 25.8152977,10.8721988 25.8251436,10.8783 C25.858901,10.9003583 25.890783,10.8966037 25.9189141,10.8848706 C25.92876,10.9829595 26.0295633,11.0308307 26.103642,10.9838982 C26.1589666,11.0946589 26.3061862,11.0317693 26.3146256,10.9238245 C26.3104059,10.9782663 26.3277534,10.9130301 26.3469764,10.8862785 C26.3877666,10.8285515 26.4238682,10.7661313 26.4637207,10.707935 C26.5368616,10.6009288 26.6090649,10.498616 26.7089305,10.4150761 C26.7816026,10.3545331 26.7033043,10.2170209 26.6175043,10.2601988" id="Fill-381" fill="#A9944B"></path>
                            <path d="M27.0014475,10.8296779 C26.9939459,10.8371871 26.9864443,10.8461043 26.9789426,10.8531442 C26.9841,10.7724202 26.9001754,10.6945123 26.8232836,10.7667883 C26.7862443,10.8019877 26.7318574,10.8517362 26.6779393,10.9085245 C26.7318574,10.8179448 26.799841,10.7339356 26.8673557,10.6527423 C26.9550311,10.5462055 26.8265656,10.366454 26.7182607,10.4786227 C26.5250934,10.6790245 26.3216115,10.8888129 26.3206738,11.1830798 C26.3206738,11.2975951 26.4149131,11.3327945 26.4899295,11.3036963 C26.5002443,11.3398344 26.5180607,11.3745644 26.5569754,11.4050706 C26.5921393,11.4327607 26.6240213,11.4257209 26.6507459,11.4078865 C26.6493393,11.4229049 26.6427754,11.4365153 26.6423066,11.4520031 C26.6418377,11.5768436 26.8003098,11.6073497 26.8434443,11.4886104 C26.9114279,11.3008804 27.0051984,11.1389632 27.1388213,10.9901871 C27.225559,10.8939755 27.1027197,10.7297117 27.0014475,10.8296779" id="Fill-383" fill="#A9944B"></path>
                            <path id="Fill-385" fill="#A9944B"></path>
                            <path d="M26.6294131,3.61906012 C26.6303508,3.6167135 26.6317574,3.61389755 26.6331639,3.61155092 C26.6537934,3.63267055 26.6847377,3.63877178 26.7100557,3.60920429 C26.9304164,3.34638221 27.246423,3.69743742 27.2393902,3.9414865 C27.2262623,4.36857239 26.6500426,4.11607546 26.7555344,3.87625031 C26.7996066,3.77628405 26.7227148,3.6261 26.6294131,3.61906012 M27.3622295,3.27504479 C27.0199672,3.06197117 26.4817246,3.04789141 26.410459,3.52894969 C26.4062393,3.55570123 26.4146787,3.57775951 26.4287443,3.59512454 C26.3509148,3.80068896 26.3813902,4.0794681 26.4859443,4.23950798 C26.7147443,4.58868589 27.2572066,4.65767669 27.5432066,4.34275951 C27.8217049,4.03582086 27.700741,3.48577178 27.3622295,3.27504479" id="Fill-387" fill="#A9944B"></path>
                            <path d="M30.7756161,6.2269592 C30.7334193,6.30252055 30.6696554,6.37010337 30.6007341,6.43487025 C30.6171439,6.41281196 30.6344915,6.39122301 30.6509013,6.36916472 C30.7015374,6.30205123 30.5993275,6.19926902 30.5379079,6.26732117 C30.4441374,6.3705727 30.349898,6.47147761 30.26738,6.58270767 C30.2345603,6.62682423 30.2598784,6.68079663 30.299262,6.70848681 C30.1984587,6.81690092 30.362557,7.00369233 30.4544521,6.88495307 C30.6002652,6.69675368 30.814062,6.51512485 30.905957,6.2921954 C30.9383079,6.2138181 30.8192193,6.1485819 30.7756161,6.2269592" id="Fill-389" fill="#A9944B"></path>
                            <path d="M30.4491541,5.96751626 C30.3549148,6.11910828 30.1448689,6.20264816 30.0154656,6.32420337 C29.9268525,6.40727393 30.0295311,6.59781994 30.1434623,6.51897331 C30.2991213,6.4105592 30.5612098,6.21719724 30.586059,6.0130408 C30.5954361,5.93372485 30.4890066,5.90321871 30.4491541,5.96751626" id="Fill-391" fill="#A9944B"></path>
                        </g>
                    </g>
                </g>
            </g>
        </g>
    </g>
</svg>
<?php
	}
}



// Source: src/lib/View/class_si_view_page.php


abstract class Si_View_Page extends Si_View {

	abstract public function body ($params=array());
	abstract public function get_title ();
	abstract public function get_step ();

	public function get_step_titles_map () {
		return array(
			Si_Controller_Install::STEP_CHECK => 'Requirements Check',
			Si_Controller_Install::STEP_CONFIG => 'Configuration',
			Si_Controller_Install::STEP_DEPLOY => 'Deployment',
		);
	}

	public function get_state_step ($state) {
		$states_map = Si_Controller_Install::get_states_step_map();

		foreach ($states_map as $step => $states) {
			if (in_array($state, $states)) return $step;
		}

		return false;
	}

	public function get_first_step_state ($step) {
		$states_map = Si_Controller_Install::get_states_step_map();
		if (empty($states_map[$step])) return false;

		$map = $states_map[$step];
		if (!is_array($map)) return false;

		return reset($map);
	}

	public function get_current_state_step () {
		$state = $this->get_state();
		return $this->get_state_step($state);
	}

	public function get_previous_step_titles () {
		$steps = array();
		$all = $this->get_step_titles_map();
		$current_step = $this->get_current_state_step();

		$start = true;
		foreach ($all as $step => $title) {
			if ($step === $current_step) $start = false;
			if ($start) $steps[$this->get_first_step_state($step)] = $title;
		}

		return $steps;
	}

	public function get_next_step_titles () {
		$steps = array();
		$all = $this->get_step_titles_map();
		$current_step = $this->get_current_state_step();

		$start = false;
		foreach ($all as $step => $title) {
			if ($start) $steps[$this->get_first_step_state($step)] = $title;
			if ($step === $current_step) $start = true;
		}

		return $steps;
	}

	public function get_next_steps () {

	}

	public function header ($params=array()) {
		$step_titles = $this->get_step_titles_map();
		$total_steps = count($step_titles);
		$current_position = $this->get_current_state_step();

		$current_step = $this->get_step();
		$current_title = $current_step >= 0 && !empty($step_titles[$current_position])
			? $step_titles[$current_position]
			: $this->get_title()
		;

		$previous_steps = $this->get_previous_step_titles();

		$request = Si_Model_Request::load(Si_Model_Request::REQUEST_EMPTY);
		$styles = new Si_View_Style;
		$img = new Si_View_Img;


		?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">

	<title>Snapshot: <?php echo $this->get_title(); ?></title>
	<meta name="author" content="Incsub">
	<link href='https://fonts.googleapis.com/css?family=Roboto:400,500,700' rel='stylesheet' type='text/css'>
	<link href='https://fonts.googleapis.com/css?family=Roboto+Condensed:400,700' rel='stylesheet' type='text/css'>

	<?php $styles->out(); ?>

</head>
<body>
	<div class="main-header">
		<header>
			<?php $img->out(); ?>
			<h1>Snapshot v4 Migration Wizard</h1>
		</header>
	</div>
	<div class="body">
		<div class="out">
			<h2>
			<?php if ($current_step >= 0) { ?>
				<?php echo $current_step - 1; ?>/<?php echo $total_steps; ?> Steps complete
			<?php } else { ?>
				<?php echo $total_steps; ?>/<?php echo $total_steps; ?> Steps complete
			<?php } ?>
			</h2>
		<?php foreach ($previous_steps as $idx => $ttl) { ?>
			<div class="step empty">
				<div class="step-title">
					<h3>
						<?php
							$args = array('state' => $idx);
							if ('check' === $idx) $args['preview'] = true;
						?>
						<a href="<?php echo $request->get_clean_query($args); ?>">
							<?php echo $ttl; ?>
						</a>
					</h3>
				</div>
				<div class="step-status">
					<div class="success"><span>Passed</span></div>
				</div>
				<div class="step-output"></div>
			</div>
		<?php } ?>
			<div class="step current state-<?php echo $this->get_state(); ?>">
				<div class="step-title">
					<h3><?php echo $current_title; ?></h3>
				</div>
		<?php
	}

	/**
	 * Checks whether the step is to be shown as linked
	 *
	 * @param string $idx Step index
	 * @param array $params View data
	 *
	 * @return bool
	 */
	public function prevents_next_steps ($idx, $params) {
		return 'extract' === $idx;
	}

	public function footer ($params=array()) {
		$next_steps = $this->get_next_step_titles();
		$request = Si_Model_Request::load(Si_Model_Request::REQUEST_EMPTY);

		?>
			</div> <!-- .step -->
		<?php foreach ($next_steps as $idx => $ttl) { ?>
			<div class="step empty">
				<div class="step-title">
					<h3>
					<?php if ($this->prevents_next_steps($idx, $params)) { ?>
						<?php echo $ttl; ?>
					<?php } else { ?>
						<a href="<?php echo $request->get_clean_query('state', $idx); ?>">
							<?php echo $ttl; ?>
						</a>
					<?php } ?>
					</h3>
				</div>
				<div class="step-output"></div>
			</div>
		<?php } ?>
		</div> <!-- .out -->
	</div> <!-- .body -->
</body>
</html>
		<?php
	}

	public function out ($params=array()) {
		$this->header($params);
		$this->body($params);
		$this->footer($params);
	}
}



// Source: src/lib/View/class_si_view_style.php


class Si_View_Style extends Si_View {

	public function out ($params=array()) {
		?>
		<style>
		*, *:before, *:after {
			box-sizing: border-box;
		}
		body {
			background: #F4F4F4;
			color: #555555;
			font-family: "Roboto", sans-serif;
			margin: 0;
			padding: 0;
		}

		.main-header h1 {
			text-align: center;
			text-transform: uppercase;
			margin: 0;
			padding: 0;
			margin-top: 20px;
			padding-left: 150px;
			line-height: 100px;
			font-size: 50px;
			font-family: "Roboto Condensed";
			letter-spacing: -2px;
		}
		.main-header svg {
			position: absolute;
			top: 0;
			left: 30px;
		}
		.main-header header {
			position: relative;
			height: 120px;
		}

		.body, .main-header header {
			width: 1000px;
			margin: 0 auto;
		}

		.body {
			background: #ffffff;
		}
		body h2 {
			padding: 18px 30px;
			border-bottom: 1px solid #EEEEEE;
			text-transform: uppercase;
			font-size: 18px;
			font-family: "Roboto Condensed";
			margin: 0;
		}
		.step {
			padding: 18px 30px;
			border-bottom: 1px solid #EEEEEE;
		}

		.step-title, .step-status {
			display: inline-block;
			float: left;
			line-height: 2em;
		}
		.step-title h3 {
			display: inline;
			margin: 0;
			padding: 0;
			font-size: 14px;
		}
		.step-title h3 a {
			text-decoration: none;
			color: #555555;
		}
		.step-title {
			margin-bottom: 18px;
		}
		.step-output {
			clear: both;
			padding: 20px 30px;
			border-radius: 3px;
		}
		.empty .step-title { margin: 0; }
		.empty .step-output { padding: 0; }


		.step-status {
			color: #fff;
			text-align: center;
			text-transform: uppercase;
			width: 100px;
			margin-left: 30px;
			font-size: 13px;
		}
		.step-status div { border-radius: 3px; }
		.step-status span { display: inline-block; padding: 8px 10px; line-height: 1em;}
		.step-status .success { background: #1ABC9C; }
		.step-status .failed { background: #FF6D6D; }
		.step-status .warning { background: #FECF2F; }
		.step-output { background: #F9F9F9; }

		button, a.button {
			background: #A9A9A9;
			color: #FFFFFF;
			text-decoration: none;
			text-transform: uppercase;
			border: none;
			padding: 10px 30px;
			font-size: 1em;
			border-radius: 4px;
			font-weight: 500;
			font-family: "Roboto";
			border-bottom: 3px solid #A9A9A9;
		}
		button.primary, a.button.primary {
			background: #19B4CF;
			color: #FFFFFF;
			border-bottom: 3px solid #1490A5;
		}
		</style>
		<?php
		$this->_check();
		$this->_configuration();
		$this->_deployment();
	}

	private function _check () {
		?>
		<style>
		/**
		 * --- Check step ---
		 */
		 .check {
		 	padding: 15px 0;
		 	border-bottom: 1px solid #EEEEEE;
		 }
		 .check:first-child {
			 padding-top: 5px;
		 }
		 .check:last-child {
		 	border: none;
		 }
		 .check .check-title {
		 	display: table-cell;
		 	width: 180px;
		 }
		 .check .check-status {
		 	display: table-cell;
		 	width: 100px;
		 	color: #FFFFFF;
		 	text-align: center;
		 	text-transform: uppercase;
			font-size: 13px;
		 }
		 .check .check-output {
		 	display: table-cell;
		 	padding-left: 20px;
		 }

		 .check .check-title h4 {
			 margin: 0;
			 padding: 0;
			 font-size: 15px;
			 font-weight: 500;
		 }
		 .check .check-status div { border-radius: 3px; }
		 .check-status span { display: inline-block; padding: 8px 10px; }
		 .check .success { background: #1ABC9C; }
		 .check .failed { background: #FF6D6D; }
		 .check .warning { background: #FECF2F; }
		</style>
		<?php
	}

	private function _configuration () {
		?>
		<style>
		/**
		 * --- Configuration step ---
		 */
		.state-configuration {
			font-size: 15px;
			line-height: 1.4em;
		}
		.state-configuration .error-message {
			background: #FF6D6D;
			color: #FFFFFF;
			padding: 10px;
			border-radius: 5px;
		}
		.state-configuration .step-output h3 {
			text-transform: uppercase;
			margin: 20px 0;
			margin-top: 30px;
			font-size: 18px;
			font-family: "Roboto Condensed";
		}
		form input {
			border: 1px solid #EEEEEE;
			padding: 10px;
			background: #FFFFFF;
			color: #2A6988;
			font-weight: bold;
		}
		.config-item {
			margin: 10px 0;
		}
		.config-item label span {
			display: inline-block;
			width: 200px;
			font-weight: 500;
		}
		.config-item input {
			width: 450px;
			border-radius: 3px;
			font-size: 15px;
		}

		.config-item input.error { border: 1px solid #f33; }
		.config-item input.warning { border: 1px solid #FECF2F; }

		.config-item.host input { width: 275px; }
		.config-item.host label[for="port"] span { padding-left: 25px; width: 65px; }
		.config-item.host label[for="port"] input { width: 100px; }

		.config-test {
			background: #EBFCFF;
			color: #487386;
			border: 1px solid #B5D3E0;
			padding: 0 20px;
			border-radius: 5px;
			margin: 20px 0;
		}
		.config-test .result-item {
			padding: 15px 0;
			border-bottom: 1px solid #B5D3E0;
		}
		.config-test .result-item:last-child {
			border: none;
		}
		.config-test .check-title {
			display: table-cell;
			width: 200px;
		}
		.config-test .check-status {
			display: table-cell;
			width: 100px;
			color: #FFFFFF;
			text-align: center;
			text-transform: uppercase;
		}
		.config-test .check-output {
			display: table-cell;
			padding-left: 20px;
		}
		.config-test .check-status div { border-radius: 3px; font-size: 13px; }
		.config-test .check-status div span { padding: 4px 5px; }
		.config-test .result-item .success { background: #1ABC9C; }
		.config-test .result-item .failed { background: #FF6D6D; }
		.config-test .result-item .warning { background: #FECF2F; }
		.config-test .result-item .stopped { background: #EAEAEA; color: #8B8B8B; }

		.config-actions {
			margin: 20px 0;
		}

		.continue p {
			display: inline-block;
		}
		.continue p:first-child {
			width: 80%;
		}
		.continue p:last-child {
			float: right;
		}
		</style>
		<?php
	}

	private function _deployment () {
		?>
		<style>
		/**
		* --- Deployment step ---
		*/
		.deployment header {
			text-align: center;
		}
		.deployment header h3 {
			text-transform: uppercase;
			font-family: "Roboto Condensed";
			font-weight: normal;
			font-size: 24px;
			margin-bottom: 15px;
		}
		</style>
		<?php
		$this->_deployment_failure();
		$this->_deployment_success();
		$this->_progress_bar();
		$this->_cleanup();
	}

	private function _deployment_failure () {
		?>
		<style>
		/**
		* Failure
		*/
		.deployment.failure {
			text-align: center;
		}
		.deployment.failure .error {
			background: #FF6D6D;
			color: #FFFFFF;
			padding: 10px;
			border-radius: 5px;
		}
		</style>
		<?php
	}

	private function _deployment_success () {
		?>
		<style>
		/**
		* Success
		*/
		.deployment.success {
			text-align: center;
		}
		.deployment.success .success {
			background: #1ABC9C;
			color: #FFFFFF;
			padding: 10px;
			border-radius: 5px;
		}
		.deployment.actions {
			text-align: center;
		}
		.deployment.actions p {
			display: inline-block;
		}
		</style>
		<?php
	}

	private function _progress_bar () {
		?>
		<style>
		.progress .progress-bar_wrapper {
		}
		.progress .progress-bar {
			background: #14485F;
			border-radius: 5px;
			padding: 5px;
			height: 50px;
		}
		.progress .progress-bar_indicator {
			background: #FECF2F;
			color: #14485F;
			border-radius: 5px;
			white-space: nowrap;
			line-height: 50px;
		}
		.progress .progress-bar_indicator span {
			padding: 0 10px;
		}
		.progress .progress-bar_indicator.percentage-only span.progress-bar_message {
			display: none;
		}
		.progress .progress-info {
			text-align: center;
			font-size: 12px;
		}
		/**
		* Progress bar color and animation
		*/
		.progress .progress-bar_indicator {
			background-image: linear-gradient(135deg,rgba(255,255,255,.4) 25%,transparent 25%,transparent 50%,rgba(255,255,255,.4) 50%,rgba(255,255,255,.4) 75%,transparent 75%,transparent);
			background-color: #FECF2F;
			background-size: 38px 38px;
			border-top-left-radius: 5px;
			border-bottom-left-radius: 5px;
			box-shadow: 0 1px 1px rgba(0,0,0,.75);
			height: 40px;
			line-height: 40px;
			min-width: 5%;
			animation: si-animate-progress-bars 2s linear infinite;
		}
		@keyframes si-animate-progress-bars {
			from  { background-position: 38px 0; }
			to    { background-position: 0 0; }
		}
		</style>
		<?php
	}

	private function _cleanup () {
		?>
		<style>
		/**
		* Success
		*/
		.cleanup.success {
			text-align: center;
		}
		.cleanup.success div, .cleanup.warning div {
			color: #FFFFFF;
			padding: 10px;
			border-radius: 5px;
			text-align: center;
		}
		.cleanup.actions {
			text-align: center;
		}
		.cleanup.actions p {
			display: inline-block;
		}

		.cleanup-status {
			text-transform: uppercase;
		}
		.cleanup-status h3 {
			display: inline-block;
			font-family: "Roboto Condensed";
		}
		.cleanup-status div {
			display: inline-block;
			margin-left: 30px;
			width: 100px;
			border-radius: 3px;
			font-size: 13px;
			padding: 4px 5px;
			text-align: center;
		}
		.cleanup-status .success { background: #1ABC9C; color: #FFFFFF; }
		.cleanup-status .warning { background: #FECF2F; color: #FFFFFF; }

		.cleanup.success .success { background: #1ABC9C; }
		.cleanup.warning .warning { background: #FECF2F; }

		.cleanup-results-root {
			background: #EBFCFF;
			color: #487386;
			border: 1px solid #B5D3E0;
			padding: 0 20px;
			border-radius: 5px;
			margin: 20px 0;
		}
		.cleanup-results-root .result-item {
			padding: 15px 0;
			border-bottom: 1px solid #B5D3E0;
		}
		.cleanup-results-root .result-item:last-child {
			border: none;
		}
		.cleanup-results-root .result-item-title {
			display: table-cell;
			padding-right: 20px;
			width: 80%;
		}
		.cleanup-results-root .result-item-status {
			display: table-cell;
			width: 100px;
			min-width: 100px;
			color: #FFFFFF;
			text-align: center;
			text-transform: uppercase;
		}
		.cleanup-results-root .result-item-status div { border-radius: 3px; font-size: 13px; }
		.cleanup-results-root .result-item-status div span { display: inline-block; padding: 8px 10px; }
		.cleanup-results-root .result-item .success { background: #1ABC9C; }
		.cleanup-results-root .result-item .failed { background: #FF6D6D; }
		.cleanup-results-root .result-item .warning { background: #FECF2F; }
		</style>
		<?php
	}
}



// Source: src/lib/View/Page/class_si_view_page_check.php


class Si_View_Page_Check extends Si_View_Page {

	public function get_title () {
		return 'Requirements check';
	}

	public function get_step () { return 1; }

	/**
	 * Check if we're able to link up the next step
	 *
	 * If we don't have an archive present, we are not
	 *
	 * {@inheritDoc}
	 */
	public function prevents_next_steps ($idx, $params) {
		if (parent::prevents_next_steps($idx, $params)) return true;

		if (empty($params['checks'])) return true; // No checks, not proper data
		return empty($params['checks']['Archive']['test']);
	}


	public function body ($params=array()) {
		$checks = !empty($params['checks']) && is_array($params['checks'])
			? $params['checks']
			: array()
		;
		$overall = true; $checks = array();

		foreach ($params['checks'] as $cname => $check) {
			$class = 'Si_View_Partial_' . $cname;
			if (!class_exists($class)) continue;

			if (empty($check['test'])) $overall = false;
			$checks[$cname] = new $class;
		}
		?>
	<div class="step-status">
	<?php if ($overall) { ?>
		<div class="success"><span>Passed</span></div>
	<?php } else { ?>
		<div class="failed"><span>Failed</span></div>
	<?php } ?>
	</div>

	<div class="step-output">
		<?php
		foreach ($checks as $cname => $check) {
			$check->out($params['checks'][$cname]);
		}
		?>
	</div> <!-- .step-output -->
</div> <!-- .step -->
		<?php

		return $overall;
	}
}



// Source: src/lib/View/Page/class_si_view_page_cleanup.php


class Si_View_Page_Cleanup extends Si_View_Page {

	public function get_title () {
		return '';
	}
	public function get_step () { return -1; }

	public function body ($params=array()) {
		?>

		<div class="step-output">

			<div class="cleanup-status">
				<h3>File Clean Up</h3>
			<?php if (!empty($params['status'])) { ?>
				<div class="success"><span>Complete</span></div>
			<?php } else { ?>
				<div class="warning"><span>Incomplete</span></div>
			<?php } ?>
			</div>

			<div class="cleanup-results-root">
				<div class="results">

					<div class="result-item temp">
						<div class="result-item-title">
							<?php echo $params['temp_path']; ?>
						</div>
						<div class="result-item-status">
						<?php if ($params['temp_status']) { ?>
							<div class="success"><span>Removed</span></div>
						<?php } else { ?>
							<div class="failed"><span>Fail</span></div>
						<?php } ?>
						</div>
					</div>

					<div class="result-item source">
						<div class="result-item-title">
							<?php echo $params['source_path']; ?>
						</div>
						<div class="result-item-status">
						<?php if ($params['source_status']) { ?>
							<div class="success"><span>Removed</span></div>
						<?php } else { ?>
							<div class="failed"><span>Fail</span></div>
						<?php } ?>
						</div>
					</div>

					<div class="result-item self">
						<div class="result-item-title">
							<?php echo $params['self_path']; ?>
						</div>
						<div class="result-item-status">
						<?php if ($params['self_status']) { ?>
							<div class="success"><span>Removed</span></div>
						<?php } else { ?>
							<div class="failed"><span>Fail</span></div>
						<?php } ?>
						</div>
					</div>

				</div>
			</div>

			<?php if (!empty($params['status'])) { ?>
			<div class="cleanup success">
				<div class="success">
					<p>
						All files were successfully cleaned up. Happy coding!
					</p>
				</div>
			</div>
			<?php } else { ?>
			<div class="cleanup warning">
				<div class="warning">
					<p>
						Some files couldn't be cleaned up. Please manually remove these from the server.
					</p>
				</div>
			</div>
		<?php } ?>
			<div class="cleanup actions">
				<p><a class="button" href="<?php echo $params['view_url']; ?>">View website</a></p>
			</div>
		</div>
		<?php
	}

}



// Source: src/lib/View/Page/class_si_view_page_configuration.php


class Si_View_Page_Configuration extends Si_View_Page {

	public function get_title () {
		return 'Configuration';
	}

	public function get_step () { return 2; }


	public function body ($params=array()) {
		$overall = !empty($params['status']);
		$has_override = true; // @TODO Fix this

		// Map: 100 = success, 50 = warning, 0 = stop, -1 = error
		$server_state = $db_state = $empty_state = 100; // Set all to "success"
		if (!empty($params['db_connection_errno'])) {
			$server_state = $db_state = $empty_state = 0; // Set all to "stop"

			// Server connection error
			if ((int)$params['db_connection_errno'] > 2000) $server_state = -1;
			else $server_state = 100;

			// User/pass combo error
			if (1045 === (int)$params['db_connection_errno']) {
				$server_state = 100;
				$db_state = -1;
			}

			// Not an existing DB
			if (1049 === (int)$params['db_connection_errno']) {
				$server_state = 100;
				$db_state = 100;
				$empty_state = -1;
			}

		}
		// Additional check
		// Not an empty database
		if (empty($params['db_empty'])) {
			$server_state = 100;
			$db_state = 100;
			$empty_state = 50;
		}

		?>
	<div class="step-status">
	<?php if ($overall) { ?>
		<?php if (100 == $server_state && 100 == $db_state && 100 == $empty_state) { ?>
			<div class="success"><span>Passed</span></div>
		<?php } else { ?>
			<div class="warning"><span>Warning</span></div>
		<?php } ?>
	<?php } else { ?>
		<div class="failed"><span>Failed</span></div>
	<?php } ?>
	</div>

	<div class="step-output">
	<form method="post">
		<p>
			The first step is to connect to your database.
			By default we recommend creating a new database,
			but you can choose to overwrite an existing database
			once you've tested the connection.
		</p>

	<?php if (empty($params['has_config_file'])) { ?>
		<div class="error-message">
			<p>We could not locate the <code>wp-config.php</code> file in your backup.</p>
		</div>
	<?php } ?>
	<?php if (empty($params['has_manifest_file'])) { ?>
		<div class="error-message">
			<p>We could not locate the Snapshot manifest file in your backup.</p>
		</div>
	<?php } ?>

		<div class="config-database">
			<h3>Connect Database</h3>
			<?php
				$config = !empty($params['database'])
					? $params['database']
					: array()
				;
			?>
			<div class="config-item host">
				<?php
				// Get port and host info
				$config['host'] = !empty($config['host']) ? $config['host'] : '';
				$list = explode(':', $config['host'], 2);
				$host = $list[0];
				$port = !empty($list[1]) ? $list[1] : false;

				$cls = '';
				if (50 === $server_state) $cls = 'class="warning"';
				if (50 > $server_state) $cls = 'class="error"';
				?>
				<label for="host">
					<span>Database Host</span>
					<input id="host" <?php echo $cls; ?> name="host" value="<?php echo $host; ?>" placeholder="localhost" />
				</label>
				<label for="port">
					<span>Port</span>
					<input id="port" <?php echo $cls; ?> name="port" value="<?php echo $port; ?>" placeholder="3306" />
				</label>
			</div>

			<div class="config-item dbname">
				<?php
					$cls = '';
					if (100 === $server_state && 50 === $empty_state) $cls = 'class="warning"';
					if (100 === $server_state && 50 > $empty_state) $cls = 'class="error"';
				?>
				<label for="name">
					<span>Database Name</span>
					<input id="name" <?php echo $cls; ?> name="name" value="<?php echo !empty($config['name']) ? $config['name'] : false; ?>" placeholder="Enter a new or existing database" />
				</label>
			</div>

			<div class="config-item dbuser">
				<?php
					$cls = '';
					if (100 === $server_state && 50 === $db_state) $cls = 'class="warning"';
					if (100 === $server_state && 50 > $db_state) $cls = 'class="error"';
				?>
				<label for="user">
					<span>Database Username</span>
					<input id="user" <?php echo $cls; ?> name="user" value="<?php echo !empty($config['user']) ? $config['user'] : false; ?>" placeholder="Enter a valid database username" />
				</label>
			</div>

			<div class="config-item dbpassword">
				<label for="password">
					<span>Database Password</span>
					<input id="password" <?php echo $cls; ?> name="password" value="<?php echo !empty($config['password']) ? $config['password'] : false; ?>" placeholder="Enter the password for database user" />
				</label>
			</div>
		</div>

		<div class="config-settings">
			<h3>Settings</h3>
			<div class="config-item site-url">
				<label for="site-url">
					<span>New Site URL</span>
					<input id="site-url" name="site-url" value="<?php echo $params['site_url']; ?>" placeholder="Your new site URL" />
				</label>
			</div>
		</div>

<?php if (!empty($has_override)) { ?>
		<div class="config-test">
			<div class="results">

				<div class="result-item server">
					<div class="check-title">
						Connect to Server
					</div>

					<div class="check-status">
						<?php
							switch($server_state) {
								case 100: echo '<div class="success"><span>Connected</span></div>'; break;
								case 50: echo '<div class="warning"><span>Warning</span></div>'; break;
								case 0: echo '<div class="stopped"><span>Stopped</span></div>'; break;
								case -1: echo '<div class="failed"><span>Failed</span></div>'; break;
							}
						?>
					</div>

					<div class="check-output">
						<?php if (0 > $server_state) { ?>
							<p>We couldn't connect to the database host. Please check your details.</p>
							<?php if ((int)$params['db_connection_errno']) { ?>
								<p>Error code: <code><?php echo (int)$params['db_connection_errno']; ?></code>
							<?php } ?>
						<?php } ?>
					</div>
				</div>

				<div class="result-item database">
					<div class="check-title">
						Connect to Database
					</div>

					<div class="check-status">
						<?php
							switch($db_state) {
								case 100: echo '<div class="success"><span>Connected</span></div>'; break;
								case 50: echo '<div class="warning"><span>Warning</span></div>'; break;
								case 0: echo '<div class="stopped"><span>Stopped</span></div>'; break;
								case -1: echo '<div class="failed"><span>Failed</span></div>'; break;
							}
						?>
					</div>

					<div class="check-output">
						<?php if (0 > $db_state) { ?>
							<p>The database username and/or password you entered were invalid.</p>
							<?php if ((int)$params['db_connection_errno']) { ?>
								<p>Error code: <code><?php echo (int)$params['db_connection_errno']; ?></code>
							<?php } ?>
						<?php } ?>
					</div>
				</div>

				<div class="result-item check">
					<div class="check-title">
						Database Check
					</div>

					<div class="check-status">
						<?php
							switch($empty_state) {
								case 100: echo '<div class="success"><span>Connected</span></div>'; break;
								case 50: echo '<div class="warning"><span>Warning</span></div>'; break;
								case 0: echo '<div class="stopped"><span>Stopped</span></div>'; break;
								case -1: echo '<div class="failed"><span>Failed</span></div>'; break;
							}
						?>
					</div>

					<div class="check-output">
						<?php if (0 > $empty_state) { ?>
							<p>There doesn't seem to be such a database.</p>
							<?php if ((int)$params['db_connection_errno']) { ?>
								<p>Error code: <code><?php echo (int)$params['db_connection_errno']; ?></code>
							<?php } ?>
						<?php } else if (50 === $empty_state) { ?>
							<p>
								A database with this name already exists.
								By proceeding we will wipe and overwrite all existing data.
								We recommend creating a new database instead.
							</p>
							<?php if ((int)$params['db_connection_errno']) { ?>
								<p>Error code: <code><?php echo (int)$params['db_connection_errno']; ?></code>
							<?php } ?>
						<?php } ?>
					</div>
				</div>

			</div>
		</div>
<?php } ?>

		<div class="config-actions">
			<button><?php echo empty($has_override) ? 'Test' : 'Re-test'; ?> connection</button>
		</div>

<?php if (!empty($overall)) { ?>
		<div class="continue">
			<p>
				By proceeding you are doing so at your own risk.
				We recommend you backup your database and files before continuing
				and if you are unsure about anything seek advice from our support staff.
			</p>
			<p><a class="button primary" href="<?php echo $params['next_url']; ?>">Deploy site</a></p>
		</div>
<?php } ?>
	</form>
	</div> <!-- .step-output -->
</div> <!-- .step -->
		<?php

		return $overall;
	}
}



// Source: src/lib/View/Page/class_si_view_page_deploy.php


abstract class Si_View_Page_Deploy extends Si_View_Page {

	public function get_title () {
		return 'Deployment';
	}

	public function get_step () { return 3; }

	public function body ($params=array()) {
		$overall = !empty($params['status']);
		?>
	<div class="step-status">
	<?php if ($overall) { ?>
		<div class="warning"><span>In&nbsp;progress</span></div>
	<?php } else { ?>
		<div class="failed"><span>Failed</span></div>
	<?php } ?>
	</div>

	<div class="step-output">
	<?php
		if (!empty($overall)) {
			$this->_success($params);
		} else {
			$this->_failure($params);
		}
	?>
	</div>
		<?php
	}

	protected function _success ($params) {
		$progress = new Si_View_Partial_Progress;
		$progress_info = !empty($params['progress']) && is_array($params['progress'])
			? $params['progress']
			: array()
		;
		?>
		<div class="deployment">
			<header>
				<h3>Running Deployment</h3>
				<p>This will take a few minutes, please be patient.</p>
			</header>
			<?php $progress->out($progress_info); ?>
		</div>
	<?php if (!empty($params['next_url'])) { ?>
		<script>
		;(function () {
			setTimeout(function () {
				window.location = "<?php echo $params['next_url']; ?>";
			});
		})();
		</script>
<!--<a href="<?php echo $params['next_url']; ?>">Continue</a>-->
	<?php } ?>
		<?php
	}

	protected function _failure ($params) {
		$phase = !empty($params['progress']['action'])
			? $params['progress']['action']
			: false
		;
		$message = !empty($phase)
			? 'in &quot;' . $phase . '&quot; phase'
			: ''
		;
		$error = !empty($params['error'])
			? '<br />Reason: ' . $params['error']
			: 'due to an error'
		;
		?>
		<div class="deployment failure">
			<div class="error">
				<p>
					Snapshot failed to restore your website package
					<?php echo $message; ?>
					<?php echo $error; ?>
				</p>
			</div>
		<?php if (!empty($params['cleanup_url'])) { ?>
			<p><a class="button" href="<?php echo $params['cleanup_url']; ?>">Clean up files and try again</a></p>
		<?php } ?>
		</div>
		<?php
	}

}



// Source: src/lib/View/Page/class_si_view_page_done.php


class Si_View_Page_Done extends Si_View_Page_Deploy {

	public function get_step () { return 4 ;}

	public function body ($params=array()) {
		?>
	<div class="step-status">
		<div class="success"><span>Complete</span></div>
	</div>

	<div class="step-output">
		<div class="deployment success">
			<div class="success">
				<p>
					Snapshot successfully deployed your new site!
					We recommend you quickly run the cleanup wizard.
				</p>
			</div>
		</div>
		<div class="deployment actions">
			<p><a class="button primary" href="<?php echo $params['cleanup_url']; ?>">Run Cleanup Wizard</a></p>
			<p><a class="button" href="<?php echo $params['view_url']; ?>">View website</a></p>
		</div>
	</div>
		<?php
	}
}



// Source: src/lib/View/Page/class_si_view_page_error.php


class Si_View_Page_Error extends Si_View_Page {

	public function get_title () {
		return 'Uh oh, something went wrong!';
	}
	public function get_step () { return -1; }

	public function body ($params=array()) {

	}
}



// Source: src/lib/View/Page/class_si_view_page_extract.php


class Si_View_Page_Extract extends Si_View_Page_Deploy {

	
}



// Source: src/lib/View/Page/class_si_view_page_files.php


class Si_View_Page_Files extends Si_View_Page_Deploy {


}



// Source: src/lib/View/Page/class_si_view_page_finalize.php


class Si_View_Page_Finalize extends Si_View_Page_Deploy {

}



// Source: src/lib/View/Page/class_si_view_page_tables.php


class Si_View_Page_Tables extends Si_View_Page_Deploy {


}



// Source: src/lib/View/Partial/class_si_view_partial_archive.php


class Si_View_Partial_Archive extends Si_View {

	public function out ($params=array()) {
		?>
<div class="check php-version">
	<div class="check-title">
		<h4>Archive</h4>
	</div>

	<div class="check-status">
	<?php if (!empty($params['test'])) { ?>
		<div class="success"><span>Passed</span></div>
	<?php } else { ?>
		<div class="failed"><span>Failed</span></div>
	<?php } ?>
	</div>

	<div class="check-output">
	<?php if (empty($params['test'])) { ?>
		<p>
			<b>Source archive not found</b>.
			The installer needs to be able to find and recognize a full backup
			snapshot archive. Please, download an archive from your Hub page and
			place it in the same directory as installer script.
		</p>
	<?php } ?>
	</div>
</div>
		<?php
		return false;
	}
}



// Source: src/lib/View/Partial/class_si_view_partial_archivevalid.php


class Si_View_Partial_ArchiveValid extends Si_View {

	public function out ($params=array()) {
		?>
<div class="check archive-validity">
	<div class="check-title">
		<h4>Archive Validity</h4>
	</div>

	<div class="check-status">
	<?php if (!empty($params['test'])) { ?>
		<div class="success"><span>Passed</span></div>
	<?php } else { ?>
		<div class="failed"><span>Failed</span></div>
	<?php } ?>
	</div>

	<div class="check-output">
	<?php if (empty($params['test'])) { ?>
		<p>
			Your archive failed the validity check.
			We encountered this error while trying to open your archive: <code><?php echo $params['value']; ?></code>
			Please, try re-downloading your backup archive.
		</p>
	<?php } ?>
	</div>
</div>
		<?php
		return false;
	}
}



// Source: src/lib/View/Partial/class_si_view_partial_maxexectime.php


class Si_View_Partial_MaxExecTime extends Si_View {

	public function out ($params=array()) {
		?>
<div class="check php-version">
	<div class="check-title">
		<h4>Max Execution Time</h4>
	</div>

	<div class="check-status">
	<?php if (!empty($params['test'])) { ?>
		<div class="success"><span>Passed</span></div>
	<?php } else { ?>
		<div class="failed"><span>Failed</span></div>
	<?php } ?>
	</div>

	<div class="check-output">
	<?php if (empty($params['test'])) { ?>
		<p>
			<b><code>max_execution_time</code> is set to <?php echo $params['value']; ?> which is too low</b>.
			A minimum execution time of 150 seconds is recommended to give the migration process the
			best chance of succeeding. If you use a managed host, contact them directly to have it updated.
		</p>
	<?php } ?>
	</div>
</div>
		<?php
		return false;
	}
}



// Source: src/lib/View/Partial/class_si_view_partial_mysqli.php


class Si_View_Partial_Mysqli extends Si_View {

	public function out ($params=array()) {
		?>
<div class="check php-version">
	<div class="check-title">
		<h4>MySQLi</h4>
	</div>

	<div class="check-status">
	<?php if (!empty($params['test'])) { ?>
		<div class="success"><span>Passed</span></div>
	<?php } else { ?>
		<div class="failed"><span>Failed</span></div>
	<?php } ?>
	</div>

	<div class="check-output">
	<?php if (empty($params['test'])) { ?>
		<p>
			<b>PHP MySQLi module not found</b>.
			Snapshot needs the MySQLi module to be installed and enabled
			on the target server. If you use a managed host, contact them
			directly to have this module installed and enabled.
		</p>
	<?php } ?>
	</div>
</div>
		<?php
		return false;
	}
}



// Source: src/lib/View/Partial/class_si_view_partial_openbasedir.php


class Si_View_Partial_OpenBasedir extends Si_View {

	public function out ($params=array()) {
		?>
<div class="check php-version">
	<div class="check-title">
		<h4>Open Base Dir</h4>
	</div>

	<div class="check-status">
	<?php if (!empty($params['test'])) { ?>
		<div class="success"><span>Passed</span></div>
	<?php } else { ?>
		<div class="failed"><span>Failed</span></div>
	<?php } ?>
	</div>

	<div class="check-output">
	<?php if (empty($params['test'])) { ?>
		<p>
			<b><code>open_basedir</code> is enabled</b>.
			Issues can occur when this directive is enabled, and we recommend
			to disable this value in your php.ini file.
		</p>
	<?php } ?>
	</div>
</div>
		<?php
		return false;
	}
}



// Source: src/lib/View/Partial/class_si_view_partial_phpversion.php


class Si_View_Partial_PhpVersion extends Si_View {

	public function out ($params=array()) {
		?>
<div class="check php-version">
	<div class="check-title">
		<h4>PHP Version</h4>
	</div>

	<div class="check-status">
	<?php if (!empty($params['test'])) { ?>
		<div class="success"><span>Passed</span></div>
	<?php } else { ?>
		<div class="failed"><span>Failed</span></div>
	<?php } ?>
	</div>

	<div class="check-output">
	<?php if (empty($params['test'])) { ?>
		<p>
			Your PHP version is out of date.
			Your current version is <?php echo $params['value']; ?> and we require 5.2 or newer.
			You'll need to update your PHP version to proceed.
			If you use a managed host, contact them directly to have it updated.
		</p>
	<?php } ?>
	</div>
</div>
		<?php
		return false;
	}
}



// Source: src/lib/View/Partial/class_si_view_partial_progress.php


class Si_View_Partial_Progress extends Si_View {

	public function out ($params=array()) {
		$percentage = !empty($params['percentage']) && is_numeric($params['percentage'])
			? (int)$params['percentage']
			: 30
		;
		$action = !empty($params['action'])
			? $params['action']
			: 'Deploying package'
		;
		?>
		<div class="progress">
			<div class="progress-bar_wrapper">
				<div class="progress-bar">
					<div class="progress-bar_indicator <?php if ($percentage < 28) echo 'percentage-only'; ?>" style="width: <?php echo(int)$percentage; ?>%">
						<span class="progress-bar_message">Deployment in progress... </span>
						<span class="progress-bar_percentage"><?php echo (int)$percentage; ?>%</span>
					</div>
				</div>
				<div class="progress-info">
					<p><?php echo $action; ?></p>
				</div>
			</div>
		</div>
		<?php
		return false;
	}
}



// Source: src/lib/View/Partial/class_si_view_partial_zip.php


class Si_View_Partial_Zip extends Si_View {

	public function out ($params=array()) {
		?>
<div class="check php-version">
	<div class="check-title">
		<h4>Zip</h4>
	</div>

	<div class="check-status">
	<?php if (!empty($params['test'])) { ?>
		<div class="success"><span>Passed</span></div>
	<?php } else { ?>
		<div class="failed"><span>Failed</span></div>
	<?php } ?>
	</div>

	<div class="check-output">
	<?php if (empty($params['test'])) { ?>
		<p>
			<b>PHP Zip module not found</b>.
			To unpack the zip file, Snapshot needs the Zip module to be installed and enabled.
			If you use a managed host, contact them directly to have it updated.
		</p>
	<?php } ?>
	</div>
</div>
		<?php
		return false;
	}
}




// Source: src/loader.php

/**
 * Loads everything and bootstraps the restore process
 */

/**
 * Class loader function
 *
 * @param string $class Class to look for.
 *
 * @return bool
 */
function si_load_class ($class) {
	if (!preg_match('/^Si_/', $class)) return false;
	$rqsimple = preg_replace('/^Si_/', '', $class);

	$pathparts = explode('_', $rqsimple);
	$path = array();
	foreach ($pathparts as $part) {
		$path[] = $part;
	}
	array_pop($path);
	$rqsimple = strtolower($rqsimple);
	$rqfile = rtrim(join('/', $path), '/') . '/class_si_' . $rqsimple;

	$rqpath = dirname(__FILE__) . '/lib/' . $rqfile . '.php';
	if (!file_exists($rqpath)) {
		xd(array("{$rqpath} doesnot exist, for {$class}", debug_backtrace()));
		return false;
	}
	require_once $rqpath;

	if (!class_exists($class)) {
		xd(array("{$class} doesnot exist in {$rqfile}", debug_backtrace()));
		return false;
	}

	return true;
}
spl_autoload_register('si_load_class');

//ini_set('memory_limit','1024M');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!function_exists('d')) {
	function d () { echo '<pre>'; var_export(func_get_args()); echo '</pre>'; }
}
if (!function_exists('xd')) {
	function xd () { d(func_get_args()); die; }
}

/**
 * Boots the standalone installer
 *
 * @return void
 */
function si_boot () {
	define('SI_PATH_ROOT', dirname(__FILE__));

	$dirname = 'si_test';

	$env = new Si_Model_Env;
	if ($env->can_override()) {
		$value = $env->get('temp_dir');
		if (!empty($value)) {
			$dirname = $value;
		} else {
			$dirname = uniqid($dirname);
			$env->set('temp_dir', $dirname);
		}
	}

	define('SI_TEMP_DIR', $dirname);

	$front = new Si_Controller_Install;
	$front->route();
}
si_boot();

