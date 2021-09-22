<?php

require_once(CONST_LibDir.'/init-website.php');
require_once(CONST_LibDir.'/log.php');
require_once(CONST_LibDir.'/output.php');
require_once(CONST_LibDir.'/PlaceLookup.php');
require_once(CONST_LibDir.'/Hierarchy.php');
ini_set('memory_limit', '200M');

// Parse URL query parameters
$oParams = new Nominatim\ParameterParser();

// Format for output
$sOutputFormat = $oParams->getSet('format', ['json'], 'json');
set_exception_handler_by_format($sOutputFormat);

$sCountryCode = strtolower($oParams->getString('country_code'));

$oDB = new Nominatim\DB(CONST_Database_DSN);
$oDB->connect();

$oPlaceLookup = new Nominatim\PlaceLookup($oDB);
$oPlaceLookup->loadParamArray($oParams);

$aPlace = $oPlaceLookup->lookupCountryCode($sCountryCode);

// Get all (grand) children for the given place
$oHierarchy = new Nominatim\Hierarchy($oDB);

$aHierarchy = $aPlace
    ? $oHierarchy->getChildren($aPlace)
    : [];

include(CONST_LibDir.'/template/hierarchy-'.$sOutputFormat.'.php');
