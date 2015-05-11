<?php
namespace amekusa\WPVagrantize;

use Ifsnop\Mysqldump\Mysqldump;

class ReWP {
	private $path;
	private $parser;
	private $user;
	private $data;

	public function __construct($xPath = null) {
		$this->path = $xPath ? $xPath : __DIR__;
		$this->parser = new \Spyc();
	}

	public function reset() {
		$dataSrcFile = $this->path . '/site.yml';
		if (!file_exists($dataSrcFile)) return true;
		return unlink($dataSrcFile);
	}

	public function getParser() {
		return $this->parser;
	}

	public function getUser() {
		if (!$this->user) $this->user = wp_get_current_user();
		return $this->user;
	}

	public function getData() {
		if (!$this->data) $this->updateData();
		return $this->data;
	}

	public function getSiteData() {
		$r = array ( // @formatter:off
			'hostname_old' => gethostname(),
			'version' => get_bloginfo('version'),
			'lang' => get_bloginfo('language'),
			'title' => get_bloginfo('name'),
			'multisite' => is_multisite(),
			'admin_user' => $this->getUser()->user_login,
			'admin_pass' => '',
			'db_prefix' => $GLOBALS['wpdb']->prefix,
			'db_host' => DB_HOST,
			'db_name' => DB_NAME,
			'db_user' => DB_USER,
			'db_pass' => DB_PASSWORD,
			'plugins' => get_option('active_plugins'),
			'theme' => wp_get_theme(),
			'import_sql' => $this->getUser()->has_cap('import'),
		); // @formatter:on
		return $r;
	}

	public function setData($xData) {
		$data = $this->sanitizeData($xData);
		$this->data = array_merge($this->data, $data);
		return $this->exportData();
	}

	public function updateData() {
		$src = file_get_contents($this->path . '/provision/default.yml');
		$this->data = $this->parser->load($this->sanitizeDataSource($src));

		$data = null;
		$dataSrcFile = $this->path . '/site.yml';
		if (!file_exists($dataSrcFile)) $data = $this->getSiteData();
		else {
			$src = file_get_contents($dataSrcFile);
			$data = $this->parser->load($this->sanitizeDataSource($src));
		}
		$this->setData($data);
	}

	public function sanitizeDataSource($xDataSource) {
		$r = $xDataSource;
		$r = preg_replace('/\s*#.*$/m', '', $r); // Remove comments
		return $r;
	}

	public function sanitizeData($xData) {
		$r = array ();
		foreach ($this->getData() as $i => $iData) {
			if (!array_key_exists($i, $xData)) continue;
			if ($xData[$i]) {
				// TODO Abort if the type of $xData[$i] doesn't match for $iData
			}
			$r[$i] = $xData[$i];
			unset($xData[$i]);
		}
		return $r;
	}

	public function export() {
		if (!$this->getUser()->has_cap('edit_post')) throw new UserCapabilityException();
	}

	public function exportData() {
		$dump = $this->parser->dump($this->data, 2, 0, true);
		$file = fopen($this->path . '/site.yml', 'w');
		if (!$file) return false;
		if (fwrite($file, $dump) === false) {
			fclose($file);
			return false;
		}
		return fclose($file);
	}

	public function exportDB() {
		if (!$this->getUser()->has_cap('export')) throw new UserCapabilityException('You have no sufficient rights to export the database');

		$data = $this->getData();
		if (!array_key_exists('import_sql_file', $data)) throw new \RuntimeException('Insufficient data.');

		$memLim = ini_get('memory_limit');
		@ini_set('memory_limit', '2048M');

		$timeLim = ini_get('max_execution_time');
		@ini_set('max_execution_time', 0);

		try {
			$dump = new Mysqldump( // @formatter:off
				DB_NAME,
				DB_USER,
				DB_PASSWORD,
				DB_HOST,
				'mysql',
				array (
					'add-drop-table' => true,
					'single-transaction' => false, // This requires SUPER privilege (@see https://github.com/ifsnop/mysqldump-php/issues/54)
					'lock-tables' => true          // So we must use this instead
				)
			); // @formatter:on
			$dump->start($this->path . '/' . $data['import_sql_file']);
		} catch (\Exception $e) {
			throw $e;
		}

		@ini_set('memory_limit', $memLim);
		@ini_set('max_execution_time', $timeLim);
	}
}
