<?php

class Device {
	/*
	 * There is no save() method since the device is saved when the session is saved.
	 * For these matters take a look at the session's save() method, and the
	 * 'sp_savesession' stored procedure in ScratchCards database
	 */
	private $agent = null;
	private $vendor = null;
	private $model = null;
	private $mobile = 0;
	private $isSmart = 0;
	private $isTablet = 0;
	private $exceptions = null;
	
	function __construct($agent) {
		$this->exceptions = Array('Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)');
		if ($agent) {
			$wurflManager = $this->createWurflManager();
			$device = $wurflManager->getDeviceForUserAgent($agent);
			$this->agent = $agent;
			$this->vendor = $this->setVendor($device);
			$this->model = $this->setModel($device);
			$this->mobile = $this->setMobile($device);
			$this->isSmart = $this->setSmart($device);
			$this->isTablet = $this->setTablet($device);
		}
	}
	
	function __toString() {
		return $this->model;
	}
	
	private function setTablet($device) {
		try {
			if ($device->getCapability("is_tablet") == "true") {
				return 1;
			} else {
				return 0;
			}
		} catch (Exception $e) {
			return 0;
		}
	}
	
	private function setVendor($device) {
		try {
			return $device->getCapability('brand_name');
		} catch (Exception $e) {
			return "Unknown";
		}
	}
	
	private function setModel($device) {
		try {
			return $device->getCapability('model_name');
		} catch (Exception $e) {
			return "Unknown";
		}
	}
	
	private function setMobile($device) {
		if ($this->agent && $device) {
			try {
				$canAssignPhoneNumber = $device->getCapability("can_assign_phone_number") == "true";
				$hasMmobileBrowser = $device->getCapability("mobile_browser") != "";
				$isTablet = $device->getCapability("is_tablet") == "true";
				$isWirelessDevice = $device->getCapability("is_wireless_device") == "true";
				if (($isTablet || $isWirelessDevice || $hasMmobileBrowser || $canAssignPhoneNumber)
					&& (isset($this->model) && $this->model != '')
					&& (isset($this->vendor) && $this->vendor != '')
					&& (!in_array($this->agent, $this->exceptions)))  {
					return true;
				} else {
					return false;
				}
			} catch (Exception $e) {
				return false;
			}
		} else {
			return false;
		}
	}
	
	private function setSmart($device) {
		if ($this->agent && $device) {
			try {
				$hasMmobileBrowser = $device->getCapability("mobile_browser") != "";
				$isTablet = $device->getCapability("is_tablet") == "true";
				$isWirelessDevice = $device->getCapability("is_wireless_device") == "true";
				if ($isTablet || ($isWirelessDevice && $hasMmobileBrowser)) {
					return 1;
				} else {
					return 0;
				}
			} catch (Exception $e) {
				return 0;
			}
		} else {
			return 0;
		}
	}
	
	function getVendor() {
		return $this->vendor;
	}
	
	function getModel() {
		return $this->model;
	}
	
	function getFullName() {
		if (($vendor = $this->getVendor()) && ($model = $this->getModel())) {
			return $vendor." ".$model;
		} else {
			return null;
		}
	}
	
	function isMobile() {
		return $this->mobile;
	}
	
	function isSmart() {
		return $this->isSmart;
	}
	
	function isTablet() {
		return $this->isTablet;
	}
	
	private function createWurflMAnager() {
		$wurflDir = dirname(__FILE__).'/includes/WURFL';
		$resourcesDir = $wurflDir.'/resources';
		require_once $wurflDir.'/Application.php';
		$persistenceDir = $resourcesDir.'/storage/persistence';
		$cacheDir = $resourcesDir.'/storage/cache';
		$wurflConfig = new WURFL_Configuration_InMemoryConfig();
		$wurflConfig->wurflFile($wurflDir.'/wurfl.xml');
		$wurflConfig->matchMode('performance');
		$wurflConfig->allowReload(true);
		$wurflConfig->persistence('file', array('dir' => $persistenceDir));
		$wurflConfig->cache('file', array('dir' => $cacheDir, 'expiration' => 36000));
		$wurflManagerFactory = new WURFL_WURFLManagerFactory($wurflConfig);
		return $wurflManagerFactory->create();
	}
}
?>