<?php

require_once('MobileRedirection.php');

class WinningsRedirection {
	private $session = null;
	private $mobile_url = null;
	private $request_url = '';
	private $needsRedirection = false;
	
	function __construct($session) {
		require_once('Url.php');
		$this->request_url = new Url();
		$this->session = $session;
	}
	
	function redirect() {
		if (!$this->isHeaderProviderRequest()) {
			if (isset($_GET['dwr'])) {
				// dwr: Do Www Redirect. Tells the application to perform redirections when in the www subdomain.
				$_SESSION['winnings']['www_no_redirect'] = null;
			}
			$this->handleDirectToLobbyRedirection();
			$this->handleOldDomainsRedirection();
			$this->handleBlogSitemapRedirection();
			$this->handleWAPRedirection();
			$this->handleMobileRedirection();
			$this->handleInvalidSubdomainRedirection();
			$targetDomain = $this->getTargetDomain($this->getTargetSubdomain());
			$targetUrl = $targetDomain.$this->getQueryString($targetDomain);
			if ($this->needsRedirection()) {
				header("Location: ".PROTOCOL.$targetUrl);
				exit();
			}
			$_SESSION['winnings']['www_no_redirect'] = 1;
		}
	}
	
	private function needsRedirection() {
		$url = new Url();
		return $this->needsRedirection && !isset($_GET['nr']) && !$url->isBlog();
	}
	
	private function isHeaderProviderRequest() {
		return (strpos($_SERVER['REQUEST_URI'], "websiteheaderprovider") !== false);
	}
	
	private function getQueryString($targetDomain) {
		$queryString = $this->request_url->getUrlString();
		if ($this->request_url->hasHiddableParameters()) {
			if (strpos($_SERVER['SERVER_NAME'], $targetDomain) !== false) {
				$this->request_url->hideParameters();
				$queryString = $this->request_url->getUrlString();
			}
			$this->needsRedirection = true;
		}
		return $queryString;
	}
	
	private function getTargetDomain($targetSubdomain) {
		global $environment;
		foreach ($environment->getDedicatedDomains() as $subdomain => $domain) {
			if ($targetSubdomain == $subdomain) {
				return "www.".$environment->getDefaultDomainPrefix().$domain;
			} else {
				return $targetSubdomain.COOKIE_DOMAIN;
			}
		}
	}
	
	private function getTargetSubdomain() {
		global $environment;
		$targetSubdomain = $environment->getDomainPrefix();
		if ($environment->getDomainPrefix() == 'www') {
			$targetSubdomain = $this->calculateSubdomain();
			if ($targetSubdomain != 'www') {
				$this->needsRedirection = true;
			}
		}
		return $targetSubdomain;
	}
	
	private function calculateSubdomain() {
		global $environment;
		if (isset($_GET['vid']) && isset($_GET['abtc'])) { // then we come from some other own domain (italian domain for example)
			include_once('CookieManager.php');
			$cm = new CookieManager();
			$cm->setSpecificCookie('winnings[subdomain]', 'www');
			return 'www';
		}
		if (isset($_COOKIE['winnings']['subdomain'])) {
			return $_COOKIE['winnings']['subdomain'];
		}
		$browserLanguage = $this->getBrowserLanguage();
		if (!in_array($browserLanguage, $environment->getMultidomainLanguages())) {
			if ($environment->isEnabledSubdomain($browserLanguage)) {
				return $browserLanguage;
			}
		}
		require_once('CountryCodesTranslator.php');
		$countryCode = strtolower(CountryCodesTranslator::getCorrespondingNeoGamesCountryCode($this->session->getVisitor()->getCountryCode()));
		if ($environment->isEnabledSubdomain($countryCode) and $countryCode != 'us') {
			return $countryCode;
		}
		return 'www';
	}
	
	private function handleWAPRedirection() {
		global $environment;
		if ($environment->getDomainPrefix() == 'wap') {
			$targetPage = "http://info.winnings.com/wap";
			header("Location: ".$targetPage);
			die();
		}
	}
	
	private function redirectWithoutParameters($subdomainPrefix) {
		$this->request_url->hideParameters();
		header("Location: ".PROTOCOL.$subdomainPrefix.COOKIE_DOMAIN.$this->request_url->getUrlString());
		die();
	}
	
	private function handleInvalidSubdomainRedirection() {
		global $environment;
		if (!$environment->isEnabledSubdomain($environment->getDomainPrefix()) || !$environment->isEnabledEnvironmentSubdomain($environment->getEnvironmentSubdomain())) {
			$targetPage = '/404.html'.'?error=Invalid%20Subdomain&domain='.$_SERVER['SERVER_NAME'].'&url='.$_SERVER['REQUEST_URI'];
			if (isset($_REQUEST['abtc']) and $_REQUEST['abtc'] !== '') {
				$targetPage.= '&abtc='.$_REQUEST['abtc'];
			}
			header("Location: ".PROTOCOL."www.".$environment->getDomainBase().$targetPage);
			die();
		}
	}
	
	private function handleMobileRedirection() {
		$targetSubdomain = $this->getTargetSubdomain();
		$mobileRedirection = new MobileRedirection($this->session->getDevice(), $targetSubdomain, $this->session->getVisitor());
		if ($mobileRedirection->needsRedirection() && !isset($_SESSION['mobile_redirected'])) {
			$_SESSION['mobile_redirected'] = 1;
			header("Location: ".$mobileRedirection->getMobilePageUrl());
			die();
		}
	}
	
	private function handleOldDomainsRedirection() {
		global $environment;
		$dedicatedDomains = $environment->getDedicatedDomains();
		$presentDomainParts = explode(".", $_SERVER['HTTP_HOST']);
		foreach ($environment->getRedirectedDomains() as $domain=>$targetLanguage) {
			if ((isset($presentDomainParts[0]) && $domain == $presentDomainParts[0]) // italian.com
				|| (isset($presentDomainParts[1]) && $domain == $presentDomainParts[1]) // www.italian.com
				|| (isset($presentDomainParts[2]) && $domain == $presentDomainParts[2])) { // www.qa.italian.com
				header("Status: 301 Moved Permanently");
				header("Location:http://www.".$dedicatedDomains[$targetLanguage].$_SERVER['REQUEST_URI']);
				die();
			}
		}
	}
	
	function handleBlogSitemapRedirection() {
		// workaround for blog sitemap and feed to display them only on www.winnings.com and winnings.com 
		if ($_SERVER['SERVER_NAME'] != 'www.winnings.com' && $_SERVER['SERVER_NAME'] != 'winnings.com') {
			if ($this->request_url->getUrlString() == '/blog-sitemap.xml') {
				header("Location: http://".$_SERVER['SERVER_NAME']);
				die();
			}
			if ($this->request_url->getUrlString() == '/blog/rss') {
				header("Location: http://".$_SERVER['SERVER_NAME']."/blog");
				die();
			}
		}
	}
	
	private function getBrowserLanguage() {
		global $environment;
		$prefered_languages = array();
		if(isset($_SERVER["HTTP_ACCEPT_LANGUAGE"]) && preg_match_all("#([^;,]+)(;[^,0-9]*([0-9\.]+)[^,]*)?#i",$_SERVER["HTTP_ACCEPT_LANGUAGE"], $matches, PREG_SET_ORDER)) {
			$priority = 1.0;
			foreach($matches as $match) {
				if(!isset($match[3])) {
					$pr = $priority;
					$priority -= 0.001;
				} else {
					$pr = floatval($match[3]);
				}
				$prefered_languages[substr($match[1], 0, 2)] = $pr;
			}
			arsort($prefered_languages, SORT_NUMERIC);
			foreach($prefered_languages as $language => $priority) {
				if($environment->isEnabledLanguage($language)) {
					return $language;
				}
			}
			return 'en';
		}
	}
	
	private function handleDirectToLobbyRedirection() {
		if (isset($_GET['dtl'])) {
			header("Location: ".$this->getLobbyUrl($_GET['lang'], $_GET['cur'], 'DL', $_GET['gid']));
			die();
		}
	}
	
	private function getLobbyUrl($language='ENG', $currency='GBP', $placement='EP', $gid='', $bo='FM') {
		global $environment, $session;
		$BDparameter = 'info.'.$environment->getDomainBase();
		$SDNparameter = 'winnings.com';
		$link = "https://secure.".$environment->getDefaultDomainPrefix()."neogames-tech.com/ScratchCards/lobby.aspx?BD=";
		$link.= $BDparameter."&SDN=".$SDNparameter."&LNG=~".$language."&CUR=".$currency."&CSI=21&PRD=1";
		if ($gid != '') {
			$link.= "&GID=".$gid;
		}
		$link.= "&BO=".$bo."&PAR=".$session->getId().$placement.$session->getAbtcCode().$session->getFreeText();
		$par = $session->getId().$placement.$session->getAbtcCode();
		if (empty($par) && !isset($_SESSION['notified_empty_par'])) {
			global $logger;
			$logger->error("PAR is empty","","",print_r($session));
			$_SESSION['notified_empty_par'] = 1;
		}
		return $link;
	}
}
?>