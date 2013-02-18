<?php
/**
 * Auto Synchronization for OpenSRS Pro Registrar Module for WHMCS.
 *
 * @author     Fedor Vyushkov
 * @website    http://abcit.ru
 * @copyright  (c) Fedor Vyushkov
 * @license    The MIT License (MIT)
 */

include(dirname(__FILE__).'/../../../dbconnect.php');
include(dirname(__FILE__).'/../../../includes/functions.php');
include(dirname(__FILE__).'/../../../includes/registrarfunctions.php');
include(dirname(__FILE__).'/opensrspro.php');

$params=getRegistrarConfigOptions("opensrspro");

$cronreport = "OpenSRSPro Domain Sync Report<br>\n---------------------------------------------------<br>\n";

$queryresult=select_query("tbldomains","id,domain","registrar='opensrspro'  AND status!='Cancelled'");

while ($data=mysql_fetch_array($queryresult)) {


        $hashKey = $params["HashKey"];
        $ex_domain = explode('.', $data['domain']);
	$tld = $ex_domain[1];
	$sld = $ex_domain[0];

        $domainUser = getDomainUser($tld, $sld);
        $domainPass = getDomainPass($tld, $sld, $hashKey);
        $results = getFullDomainInfo($data['domain'], $domainUser, $domainPass, $params);

	$created = isset($results['registry_createdate']) ? $results['registry_createdate'] : NULL;
	$expired = isset($results['registry_expiredate']) ? $results['registry_expiredate'] : NULL;
	$expired = (!isset($expired) AND isset($results['expiredate'])) ? $results['expiredate'] : $expired;

	$update = array();

	if (isset($created)){
		$created_arr = explode(' ', $created);
		$update['registrationdate'] = $created_arr[0];
	}

	if (isset($expired)){
		$expired_arr = explode(' ', $expired);
		$update['expirydate'] = $expired_arr[0];
	}

	if(isset($update['expirydate'])) {
		$table = 'tbldomains';
		$where = array("domain"=>$data['domain']);
		update_query($table,$update,$where);
		$cronreport .= "Updating whois info ".$data['domain'];
		$cronreport .= isset($update['registrationdate']) ? "reg.date = {$update['registrationdate']}, " : "";
        	$cronreport .= isset($update['expirydate']) ? " exp.date = {$update['expirydate']}<br>\n" : "<br>\n";
	} else {
	  $cronreport .= "error: expiration date not set for domain ".$data['domain']."<br>\n";
	}


}

sendAdminNotification("system","WHMCS OpenSRSPro Domain Synchronization Report",$cronreport);
echo $cronreport;



function getFullDomainInfo($domain, $domainUser, $domainPass, $params){
    global $osrsLogError;
    global $osrsError;

    $osrsLogError = "";
    $osrsError = "";
    $all_info = false;

    if(strcmp($params['CookieBypass'],"on")==0)
            $cookieBypass = true;
        else
            $cookieBypass = false;

    if(!$cookieBypass)
            $cookie = getCookie($domain, $domainUser, $domainPass, $params);
        else
            $cookie = false;

    if($cookie !== false || $cookieBypass){

        $expirationCall = array(
            'func' => 'lookupGetDomain',
            'data' => array(
                'domain' => $domain,
                'type' => "all_info"
            ),
            'connect' =>generateConnectData($params)
        );

        if($cookieBypass)
                $expirationCall['data']['bypass'] = $domain;
            else
                $expirationCall['data']['cookie'] = $cookie;

        set_error_handler("osrsError", E_USER_WARNING);

        $expiryReturn = processOpenSRS("array", $expirationCall);

        restore_error_handler();

        if(strcmp($expiryReturn->resultFullRaw["is_success"], "1") == 0){
            $all_info = $expiryReturn->resultFullRaw["attributes"];
        } else {
            $osrsLogError .= $expiryReturn->resultFullRaw["response_text"] . "\n";
        }
    }

    return $all_info;
}


