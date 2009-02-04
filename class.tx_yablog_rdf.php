<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2008 Dmitry Dulepov <dmitry@typo3.org>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

/**
 * $Id$
 *
 * [CLASS/FUNCTION INDEX of SCRIPT]
 */

/**
 * This class contains a hook for tt_news. This hook adds RDF discovery marker
 * to the output. It happens only if tt_news single mode is active.
 *
 * TODO Check that comments are enabled for this news item (comments_ic).
 *
 * @author	Dmitry Dulepov <dmitry@typo3.org>
 * @package	TYPO3
 * @subpackage	tx_yablog
 */
class tx_yablog_rdf {
	/**
	 * Adds RDF markup to the page if necessary.
	 *
	 * @param	array	$markerArray	Array with merkers to modify
	 * @param	array	$row	New item row
	 * @param	mixed	$lConf	Unused
	 * @param	tx_ttnews	$pObj	Reference to tx_ttnews
	 */
	public function extraItemMarkerProcessor(array $markerArray, array &$row, &$lConf, tx_ttnews &$pObj) {
		$markerArray['###YABLOG_RDF###'] = '';
		if ($pObj->theCode == 'SINGLE') {
			$commentsEnabled = true;
			if (t3lib_extMgm::isLoaded('comments_ic') &&
					(!$row['tx_commentsic_disable'] || $row['tx_commentsic_closetime'] <= time())) {
				$commentsEnabled = false;
			}
			if ($commentsEnabled) {
				$url = t3lib_div::getIndpEnv('TYPO3_REQUEST_URL');
				$pid = $this->getCommentsPid($pObj);
				$clearCache = $GLOBALS['TYPO3_DB']->cleanIntList($pObj->cObj->data['pid'] . ',' . $pObj->conf['tx_yablog']['clearCache']);
				$data = base64_encode(serialize(array(
					'pid' => $pid,
					'news_id' => $pObj->tt_news_uid,
					'news_url' => $url,
					'cache' => $clearCache,
					'check' => md5($pObj->tt_news_uid . $url . $pid .
								$clearCache .
								$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']),
				)));
				$ping = t3lib_div::getIndpEnv('TYPO3_SITE_URL') . 'index.php?eID=yablogtb&amp;data=' . $data;
				$url = htmlspecialchars($url);
				$rdfCode = '
<!--
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
	xmlns:dc="http://purl.org/dc/elements/1.1/"
	xmlns:trackback="http://madskills.com/public/xml/rss/module/trackback/">
<rdf:Description
	rdf:about="' . $url . '"
	dc:identifier="' . $url . '"
	trackback:ping="' . $ping . '"
	dc:title="' . htmlspecialchars($row['title']) . '"
/>
</rdf:RDF>
-->
';
				$markerArray['###YABLOG_RDF###'] = $rdfCode;
			}
		}
		return $markerArray;
	}

	/**
	 * Obtains storage pid for comments
	 *
	 * @param	tx_ttnews	$pObj	Parent object
	 * @return	int	Storage pid for comments
	 */
	protected function getCommentsPid(tx_ttnews &$pObj) {
		$pid = 0;

		// Get the page where tt_news is located. Comments must be on the
		// same page
		$currentPid = $pObj->cObj->data['pid'];

		// Firsts, we try to fetch storage pid from flexform configuration.
		// Get comments instances
		$commentsRecords = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows('pi_flexform',
				'tt_content', 'pid=' . intval($currentPid) .
				' AND CType=\'list\' AND list_type=\'comments_pi1\'' .
				$pObj->cObj->enableFields('tt_content'));
		foreach ($commentsRecords as $commentRecord) {
			$flexform = t3lib_div::xml2array($commentRecord['pi_flexform']);
			$modes = $pObj->pi_getFFvalue($flexform, 'code', 'sDEF');
			if (t3lib_div::inList($modes, 'COMMENTS')) {
				// Ok, we have comments instrance that displays comments.
				// Let's see if it has pid
				$pid = intval($pObj->pi_getFFvalue($flexform, 'storagePid', 'sDEF'));
				if ($pid > 0) {
					// Got it!
					break;
				}
			}
		}
		// See if we got pid. If not, look in TS setup
		if ($pid == 0) {
			$pid = intval($GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_comments_pi1.']['storagePid']);
		}
		return $pid;
	}
}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/yablog/class.tx_yablog_rdf.php']) {
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/yablog/class.tx_yablog_rdf.php']);
}

?>