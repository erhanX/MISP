<?php
App::uses('AppModel', 'Model');

class Module extends AppModel {
	public $useTable = false;
	
	private $__validTypes = array(
		'Enrichment' => array('hover', 'expansion'),
		'import' => array('import'),
		'Export' => array('export')
	);
	
	private $__typeToFamily = array(
		'import' => 'import',
		'export' => 'export',
		'hover' => 'Enrichment',
		'expansion' => 'Enrichment'	
	);
	
	public $configTypes = array(
		'IP' => array(
			'validation' => 'validateIPField',
			'field' => 'text',
			'class' => 'input-xxlarge'
		),
		'String' => array(
			'validation' => 'validateStringField',
			'field' => 'text',
			'class' => 'input-xxlarge'
		),
		'Integer' => array(
			'validation' => 'validateIntegerField',
			'field' => 'number',
		),
		'Boolean' => array(
			'validation' => 'validateBooleanField',
			'field' => 'checkbox'
		),
		'Select' => array(
			'validation' => 'validateSelectField',
			'field' => 'select'
		)
	);
	
	public function validateIPField($value) {
		if (!filter_var($value, FILTER_VALIDATE_IP) === false) {
			return 'Value is not a valid IP.';
		}
		return true;
	}

	public function validateStringField($value) {
		if (!empty($value)) return true;
		return 'Field cannot be empty.';
	}
	
	public function validateIntegerField($value) {
		if (is_numeric($value) && is_int(intval($value))) {
			return true;
		}
		return 'Value is not an integer.';
	}
	
	public function validateBooleanField($value) {
		if ($value == true || $value == false) {
			return true;
		}
		return 'Value has to be a boolean.';
	}
	

	public function getModules($type = false, $moduleFamily = 'Enrichment') {
		$modules = $this->queryModuleServer('/modules', false, false, $moduleFamily);
		if (!$modules) return 'Module service not reachable.';
		if (!empty($modules)) {
			$result = array('modules' => $modules);
			return $result;
		} else return 'The module service reports that it found no modules.';
	}
	
	public function getEnabledModules($type = false, $moduleFamily = 'Enrichment') {
		$modules = $this->getModules($type, $moduleFamily);
		if (is_array($modules)) {
			foreach ($modules['modules'] as $k => &$module) {
				if (!Configure::read('Plugin.' . $moduleFamily . '_' . $module['name'] . '_enabled') || ($type && in_array($type, $module['meta']['module-type']))) {
					unset($modules['modules'][$k]);
				}
			}
		} else return 'The modules system reports that it found no suitable modules.';
		if (!isset($modules) || empty($modules)) $modules = array();
		if (isset($modules['modules']) && !empty($modules['modules'])) $modules['modules'] = array_values($modules['modules']);
		if (!is_array($modules)) return array();
		foreach ($modules['modules'] as $temp) {
			if (isset($temp['meta']['module-type']) && in_array('import', $temp['meta']['module-type']))  $modules['import'] = $temp['name'];
			else if (isset($temp['meta']['module-type']) && in_array('export', $temp['meta']['module-type']))  $modules['export'] = $temp['name'];
			else {
				foreach ($temp['mispattributes']['input'] as $input) {
					if (!isset($temp['meta']['module-type']) || in_array('expansion', $temp['meta']['module-type'])) $modules['types'][$input][] = $temp['name'];
					if (isset($temp['meta']['module-type']) && in_array('hover', $temp['meta']['module-type']))  $modules['hover_type'][$input][] = $temp['name'];
				}
			}
		}
		return $modules;
	}
	
	public function getEnabledModule($name, $type) {
		$moduleFamily = $this->__typeToFamily[$type]; 
		$url = $this->__getModuleServer($moduleFamily);
		$modules = $this->getModules($type);
		$module = false;
		if (!Configure::read('Plugin.' . $moduleFamily . '_' . $name . '_enabled')) return 'The requested module is not enabled.';
		if (is_array($modules)) {
			foreach ($modules['modules'] as $k => &$module) {
				if ($module['name'] == $name) {
					if ($type && in_array($type, $module['meta']['module-type'])) {
						return $module;
					} else {
						return 'The requested module is not available for the requested action.';
					}
				}
			}
		} else return $modules;
		return 'The modules system reports that it found no suitable modules.';
	}
	
	private function __getModuleServer($moduleFamily = 'Enrichment') {
		$this->Server = ClassRegistry::init('Server');
		if (!Configure::read('Plugin.' . $moduleFamily . '_services_enable')) return false;
		$url = Configure::read('Plugin.' . $moduleFamily . '_services_url') ? Configure::read('Plugin.' . $moduleFamily . '_services_url') : $this->Server->serverSettings['Plugin'][$moduleFamily . '_services_url']['value'];
		$port = Configure::read('Plugin.' . $moduleFamily . '_services_port') ? Configure::read('Plugin.' . $moduleFamily . '_services_port') : $this->Server->serverSettings['Plugin'][$moduleFamily . '_services_port']['value'];
		return $url . ':' . $port;
	}
	
	public function queryModuleServer($uri, $post = false, $hover = false, $moduleFamily = 'Enrichment') {
		$url = $this->__getModuleServer($moduleFamily);
		if (!$url) return false;
		App::uses('HttpSocket', 'Network/Http');
		if ($hover) {
			$httpSocket = new HttpSocket(array('timeout' => Configure::read('Plugin.' . $moduleFamily . '_hover_timeout') ? Configure::read('Plugin.' . $moduleFamily . '_hover_timeout') : 2));			
		} else {
			$httpSocket = new HttpSocket(array('timeout' => Configure::read('Plugin.' . $moduleFamily . '_timeout') ? Configure::read('Plugin.' . $moduleFamily . '_timeout') : 5));
		}
		try {
			if ($post) $response = $httpSocket->post($url . $uri, $post);
			else $response = $httpSocket->get($url . $uri);
			return json_decode($response->body, true);
		} catch (Exception $e) {
			return false;
		}
	}

	public function getModuleSettings($moduleFamily = 'Enrichment') {
		$modules = $this->getModules(false, $moduleFamily);
		$result = array();
		if (!empty($modules['modules'])) {
			foreach ($modules['modules'] as $module) {
				if (array_intersect($this->__validTypes[$moduleFamily], $module['meta']['module-type'])) {
					$result[$module['name']][0] = array('name' => 'enabled', 'type' => 'boolean');
					if (isset($module['meta']['config'])) foreach ($module['meta']['config'] as $conf) $result[$module['name']][] = array('name' => $conf, 'type' => 'string');
				}
			}
		}
		return $result;
	}
}
