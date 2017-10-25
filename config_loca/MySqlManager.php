<?php
class MySqlManager {
	private $username = null;
	private $password = null;
	private $database = null;
	private $host = null;
	private $mirror_host = null;
	private $conn = null;
	private $last_execution_success = null;
	private $last_error_string = '';
	private $result = null;
	
	function __construct($dbInfo) {
		$this->username = $dbInfo['username'];
		$this->password = $dbInfo['password'];
		$this->database = $dbInfo['database'];
		$this->host = $dbInfo['host'];
		if (isset($dbInfo['mirror_host'])) {
			$this->mirror_host = $dbInfo['mirror_host'];
		}
		$this->conn = $this->connect($this->host);
	}
	
	private function connect($keepOnTrying = true) {
		try {
			$this->conn = @mysql_connect($this->host, $this->username, $this->password, true );
			if ($this->conn) {
				if (@mysql_select_db($this->database, $this->conn)) {
					return $this->conn;
				} else {
					return false;
				}
			} else {
				if ($keepOnTrying && $this->mirror_host) {
					$temp = $this->host;
					$this->host = $this->mirror_host;
					$this->mirror_host = $temp;
					// make only one try, with the slave server. After that, do not keep trying to connect.
					return $this->connect(false);
				} else {
					return false;
				}
			}
		} catch (Exception $e) {
			return false;
		}
	}
	
	function execute($query, $params = NULL) {
		if ($result = @mysql_query($query, $this->conn)) {
			$this->result = $this->resultToArray($result);
			$this->last_execution_success = true;
			return true;
		} else {
			$this->last_execution_success = false;
			$this->setErrors();
			return false;
		}
	}

	private function resultToArray($result) {
		$results = array();
		if ($result && ($result !== true)) {
			while ($row = mysql_fetch_array($result, MYSQL_ASSOC)) {
				$results[] = $row;
			}
		}
		return $results;
	}
	
	private function setErrors() {
		if (isset($this->conn)) {
			$this->last_error_string = mysql_error($this->conn);
		} else {
			$this->last_error_string = "No connexion";
		}
	}
	
	function getErrors() {
		return $this->last_error_string;
	}
	
	function getResults() {
		return $this->result;
	}
	
	function getDatabase() {
		return $this->database;
	}
	
	function getHost() {
		return $this->host;
	}
	
	function getMirrorHost() {
		return $this->mirror_host;
	}
	
	function getConnection() {
		return $this->conn;
	}
	
	function lastQuerySucceded() {
		return $this->last_execution_success;
	}
}
?>