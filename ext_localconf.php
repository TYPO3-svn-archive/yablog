<?php
/* $Id: $ */

if (!defined ('TYPO3_MODE')) {
	die('Access denied.');
}

// TCEmain hook to ping hosts
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][$_EXTKEY] = 'EXT:' . $_EXTKEY . '/class.tx_yablog_ping.php:tx_yablog_ping';

// eID to process trackbacks
$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include'][$_EXTKEY] = 'EXT:' . $_EXTKEY . '/class.tx_yablog_trackback.php';

// Extra markers hook for tt_news to add RDF markup
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['tt_news']['extraItemMarkerHook'][$_EXTKEY] = 'EXT:' . $_EXTKEY . '/class.tx_yablog_rdf.php:&tx_yablog_rdf';

?>