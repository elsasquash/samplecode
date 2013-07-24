<?php
function getRequestedURIWithoutQueryString() {
	$requested_uri_arr = explode("?", $_SERVER['REQUEST_URI']);
	$requested_uri = '/'.trim($requested_uri_arr[0],'/');
	return $requested_uri;
}

function setCookies($session, $request) {
	if (!isHeaderProviderRequest() && !isFaviconRequest()) {
		$cookieManager = new CookieManager();
		$cookieManager->setCookies($session, $request);
	}
}

function isHeaderProviderRequest() {
	return (strpos($_SERVER['REQUEST_URI'], "websiteheaderprovider") !== false);
}

function isFaviconRequest() {
	return (strpos($_SERVER['REQUEST_URI'], "favicon.ico") !== false);
}

function injectDynamicContent($session, $contents) {
	$session_id = $session->getId();
	if (!isset($session_id)) {
		$session_id = '000000000';
	}
	$correspondance_info = $session->getCorrespondanceInfo();
	$contents = VariableContentManager::replaceVariable($contents, 'session.id', $session_id);
	$contents = VariableContentManager::replaceVariable($contents, 'session.visitor_id', $session->getVisitor()->getId());
	$contents = VariableContentManager::replaceVariable($contents, 'session.abtc', $session->getAbtcCode());
	$contents = VariableContentManager::replaceVariable($contents, 'session.freetext', $session->getFreeText());
	$corID = isset($correspondance_info['CorID'])?$correspondance_info['CorID']:'';
	$sentDate = isset($correspondance_info['SentDate'])?$correspondance_info['SentDate']:'';
	$expDate = isset($correspondance_info['CorExpDate'])?$correspondance_info['CorExpDate']:'';
	$contents = VariableContentManager::replaceVariable($contents, 'session.CorId', $corID);
	$contents = VariableContentManager::replaceVariable($contents, 'session.SentDate', $sentDate);
	$contents = VariableContentManager::replaceVariable($contents, 'session.CorExpDate', $expDate);
	// Remove unused placements
	$contents = VariableContentManager::removeUnreplacedAnchors($contents);
	return $contents;
}

function createSession() {
	global $environment;
	session_name("WinningsSID");
	session_set_cookie_params(0, '/', $environment->getCookieDomain());
	if (!($session = regeneratePreviousSession())) {
		$session = generateNewSession();
	}
	// the subdomain country code may change with every request, so it is calculated everytime 
	if (!($cc = getSubdomainCountryCode())) {
		$cc = $session->getVisitor()->getCountryCode();
	}
	$session->setSubdomainCountryCode($cc);
	$session->incrementRequestsCount();
	return $session;
}

function regeneratePreviousSession() {
	global $environment;
	$session = null;
	if (isset($_GET['vid']) && $_GET['vid'] !="") {
		$query = "SELECT TOP 1 SessionId FROM sessions WHERE VisitorId = ".$_GET['vid']." ORDER BY id DESC";
		$environment->getSqlServerManager()->execute($query);
		if ($result = $environment->getSqlServerManager()->getResults()) {
			//used previous session for this user
			session_id($result[0]['SessionId']);
		}
	}
	session_start();
	if (isset($_SESSION['winnings-session'])) {
		$session = unserialize($_SESSION['winnings-session']);
	} else {
		// if no winnings-session was found, destroy the session. A new one will be generated in generateNewSession().
		session_regenerate_id();
		session_destroy();
	}
	return $session;
}

function generateNewSession() {
	$session = new Session();
	if (isset($_SERVER['HTTP_REFERER'])) {
		$session->setHTTPReferrer($_SERVER['HTTP_REFERER']);
	}
	if (isset($_SERVER['HTTP_USER_AGENT'])) {
		$session->setHTTPUserAgent($_SERVER['HTTP_USER_AGENT']);
	}
	$session->setRequestedDomain($_SERVER['SERVER_NAME']);
	$session->setLandingPage($_SERVER['REQUEST_URI']);
	$session->setHostComputerName($_SERVER['COMPUTERNAME']);
	$session->setCookies($_COOKIE);
	$session->setVisitor(new Visitor(getVisitorId()));
	$session->setAbtcAndAptc();
	$session->setFreeText(getFreeText());
	$session->setThirdPartyTracker(getThirdPartyTracker());
	$agent = null;
	if (isset($_SERVER['HTTP_USER_AGENT'])) {
		$agent = $_SERVER['HTTP_USER_AGENT'];
	}
	$session->setDevice(new Device($agent));
	$session->setMobile($session->getDevice()->isMobile());
	$session->setCorrespondanceInfo(getCorrespondanceInfo());
	if (session_id()) {
		session_regenerate_id(true);
	}
	session_start();
	$session->setSID(session_id());
	if (!$session->save()) {
		global $logger;
		$logger->warn("Session not saved, error while saving session.",__FILE__,__LINE__,serialize($session));
	}
	$_SESSION['winnings-session'] = serialize($session);
	return $session;
}

function createRequest($session, $segment = null) {
	$request = new Request();
	if (isset($_SERVER['HTTP_REFERER'])) {
		$request->setHTTPReferrer($_SERVER['HTTP_REFERER']);
	}
	if (isset($_SERVER['REQUEST_URI'])) {
		$request->setRequestedURI($_SERVER['REQUEST_URI']);
	}
	$request->setRequestedDomain($_SERVER['SERVER_NAME']);
	$request->setFreeText(getFreeText());
	$request->setRelatedSession($session);
	if ($segment) {
		$request->setSegmentId($segment->getId());
		$request->setSegmentAnchorName($segment->getAnchor());
	}
	if (!$request->save()) {
		global $logger;
		$logger->warn("Request not Saved, error while saving request.",__FILE__,__LINE__,serialize($request));
	}
	return $request;
}

//TODO: check code and correct if needed (i think it is needed)
function getCorrespondanceInfo() {
	// Check if correspondance info in COOKIE
	if (isset($_COOKIE['winnings']['CorID']) && isset($_COOKIE['winnings']['SentDate']) && isset($_COOKIE['winnings']['CorExpDate'])) {
		$corID = $_COOKIE['winnings']['CorID'];
		$sentDate = $_COOKIE['winnings']['SentDate'];
		$CorExpDate = $_COOKIE['winnings']['CorExpDate'];
	}
	// Check if correspondance info in GET, if it's still valid and if it's newest than info in GET
	if (isset($_GET['CorID']) && isset($_GET['SentDate'])) {
		$urlSentDate = strtotime($_GET['SentDate']);
		$now = time();
		$infoAgeInDays = abs($now - $urlSentDate) / (60*60*24);
		if ($infoAgeInDays <= 30) {
			if (!isset($sentDate) || ($urlSentDate - $sentDate) > 0) {
				$corID = $_GET['CorID'];
				$sentDate = $_GET['SentDate'];
				$date = date("Y-m-d");// current date
				$date = strtotime(date("Y-m-d", strtotime($date)) . " +1 day");
				$CorExpDate = date("Y-m-d", $date);
			}
		}
	}
	// Create result array
	$correspondanceInfo = array();
	if (isset($corID)) {
		$correspondanceInfo = array("CorID"=>$corID,
									"SentDate"=>$sentDate,
									"CorExpDate"=>$CorExpDate);
	}
	return $correspondanceInfo;
}

function getSubdomainCountryCode() {
	if ($cc = getCountryCodeFromSubdomain()) {
		return $cc;
	} elseif ($cc = getCountryCodeFromCookies()) {
		return $cc;
	} elseif ($cc = getCountryCodeFromURL()) {
		return $cc;
	}
	return null;
}

function getCountryCodeFromSubdomain() {
	global $environment;
	if (in_array($environment->getDomainPrefix(), $environment->getEnabledSubdomains())) {
		if ($environment->getDomainPrefix() != 'www') {
			return $environment->getDomainPrefix();
		}
	}
	return null;
}

function getCountryCodeFromCookies() {
	return isset($_COOKIE['winnings']['cc'])?$_COOKIE['winnings']['cc']:null;
}

function getCountryCodeFromURL() {
	return isset($_GET["cc"])?$_GET["cc"]:null;
}

function getThirdPartyTracker() {
	$t = null;
	if (isset($_GET['3rdptrk']) && $_GET['3rdptrk'] != "") {
		$t = $_GET['3rdptrk'];
	} elseif (isset($_COOKIE['winnings']['3rdptrk']) && $_COOKIE['winnings']['3rdptrk'] != "") {
		$t = $_COOKIE['winnings']['3rdptrk'];
	}
	return $t;
}

function getFreeText() {
	$ft = null;
	if (isset($_GET['ft']) && $_GET['ft'] != "") {
		$ft = $_GET['ft'];
	}
	return $ft;
}

function getVisitorId() {
	$vid = null;
	if (isset($_COOKIE['winnings']['vid']) && $_COOKIE['winnings']['vid'] != null) {
		$vid = $_COOKIE['winnings']['vid'];
	} elseif (isset($_GET['vid']) && $_GET['vid'] != null) {
		$vid = $_GET['vid'];
	}
	if ($vid != 'null' && $vid != '') {
		return $vid;
	}
	return null;
}

function manageSpecialCases($session, $contents) {
	if ($session->getAptc()->getAptcTypesId() == 1) {
		$contents = removeGACode($contents);
	}
	if ($_SERVER['HTTPS']=='on') {
		$contents = switchToHTTPS($contents);
	}
	return $contents;
}

/**
 * Removes Google Analytics code from the content
 */
function removeGACode($contents) {
	$splitContent = explode("GA START", $contents);
	$contents = $splitContent[0];
	$splitContent = explode("GA END", $splitContent[1]);
	$contents.= $splitContent[1];
	$toErase = "<script type='text/javascript' src='http://".STATIC_CONTENT_DOMAIN."/scripts/external-tracking.min.js'></script>";
	$contents = str_replace($toErase, "", $contents);
	$contents = str_replace("_gaq.push['_link'];", "return true;", $contents);
	return $contents;
}

function switchToHTTPS($contents) {
	global $environment;
	$pattern = array();
	$replacement = array();
	$pattern[] = "http://www.winnings.com";
	$replacement[] = "https://www.winnings.com";
	// parse sub domains for the menu links
	foreach ($environment->getEnabledSubdomains() as $key => $subdomain) {
		$pattern[] = "http://".$subdomain.".winnings.com";
		$replacement[] = "https://".$subdomain.".winnings.com";
	}
	// add dedicated domains
	foreach ($environment->getDedicatedDomains() as $key => $dedicated_domain) {
		$pattern[] = "http://www.".$dedicated_domain;
		$replacement[] = "https://www.".$dedicated_domain;
	}
	$contents = str_ireplace($pattern, $replacement, $contents);
    
    // revert canonical and alternate definitions as they should have without https
    $contents = str_ireplace('rel="canonical" href="https://', 'rel="canonical" href="http://', $contents);
    $contents = str_ireplace('rel="alternate" href="https://', 'rel="alternate" href="http://', $contents);
    $contents = str_ireplace("src='https", "src='http", $contents);
	return $contents;
}

function redirectHTTPS() {
	if ($_SERVER['HTTPS']=='on' and $_SERVER['SERVER_NAME'] == 'winnings.com') {
		header('Location: https://www.winnings.com'.$_SERVER['REQUEST_URI']);
		die();
	}
}

function killNonProcessableRequest() {
	if (isFaviconRequest()) {
		die();
	}
}

function performPreProcessingTasks() {
	killNonProcessableRequest();
	redirectHTTPS();
}

function logFailovers() {
	global $environment, $logger;
	if ($environment->getMySqlFailoverPerformed()) {
		$logger->error("MySql Failover Performed");
	}
}

// if page is blog, NOT 404 content (page exists), and subdomain is NOT allowed -> redirect to homepage
function handleBlogRedirection($contents) {
	$url = new Url();
	if ($url->isBlog()) {
		if (strpos($contents, "error404") == false) {
			global $environment, $session;
			$parts = array_reverse(explode(".", $_SERVER['SERVER_NAME']));
			$domain = $parts[1];
			$subdomain = is_null($parts[2])?'www':$parts[2];
			if (!in_array($domain, $environment->getBlogEnabledDomains())
				&& !in_array($subdomain, $environment->getBlogEnabledSubdomains())) {
				header("Location: http://".$_SERVER['SERVER_NAME']);
				die();
			}
		}
	}
}

performPreProcessingTasks();
require_once('Environment.php');
$environment = new Environment(); // Environment needs to be instanciated before anything else

require_once('WinningsLogger.php');
$logger = new WinningsLogger();
logFailovers();

require_once('Blocker.php');
$blocker = new Blocker(); // Blocker must remain global
$blocker->blockIfNeeded();

require_once('Request.php');
require_once('Session.php');
require_once('CookieManager.php');
require_once('VariableContentManager.php');
require_once('CountryCodesTranslator.php');
require_once('Device.php');
require_once('Cache.php');
require_once('Visitor.php');
require_once('Segment.php');
require_once('WinningsRedirection.php');
require_once('TrackerHandler.php');
require_once('APTC.php');
require_once('ABTC.php');
require_once('Scheduler.php');
require_once('WinningsQRCode.php');

$QRCode = new WinningsQRCode();
// Make $_GET keys lowercase
$_GET = array_change_key_case($_GET, CASE_LOWER);
$contents = '';
$cache = new Cache();
// $session must remain global, because of plugins
$session = createSession();
// $requested_uri_without_query_string must remain global, because of plugins
$requested_uri_without_query_string = getRequestedURIWithoutQueryString();
$segment = new Segment($session, $requested_uri_without_query_string);
$winningsRequest = createRequest($session, $segment);
$redirection = new WinningsRedirection($session);
$redirection->redirect();
setCookies($session, $winningsRequest);
$scheduler = new Scheduler();
if ($scheduler->hasUnpublishedPosts()) {
	$scheduler->publishPosts();
}
$key = $cache->buildKey($session->getVisitor()->getCC(), $requested_uri_without_query_string);
$pageIsCacheable = $cache->pageIsCacheable($requested_uri_without_query_string);
if (!$environment->isCacheEnabled() || !$pageIsCacheable || !($contents = $cache->getPage($key))) {
	// getting the content from WP must remain in a global scope, not inside a function
	ob_start();
	require($_SERVER['DOCUMENT_ROOT'].'/wp-blog-header.php');
	$contents = ob_get_contents();
	ob_end_clean();
	if ($environment->isCacheEnabled() && $pageIsCacheable && $cache->contentIsCacheable($contents)) {
		$cache->savePage($key, $contents);
	}
	handleBlogRedirection($contents);
}
if ($segmentedContent = $segment->getContents()) {
	$contents = VariableContentManager::replaceVariableContent($contents, $segment->getAnchor(), $segmentedContent);
	$_SESSION['segment_popup'] = 1;
}
$contents = injectDynamicContent($session, $contents);
$contents = manageSpecialCases($session, $contents);
echo $contents;
?>