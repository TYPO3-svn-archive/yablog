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
 * $Id: $
 *
 * [CLASS/FUNCTION INDEX of SCRIPT]
 */

/**
 * This class contains a TCEmain hook to ping external hosts when tt_news
 * record is added or modified. Host is pinged when:
 * - tt_news item is added and it is visible in the Frontend
 * - tt_news item is modified and it is visible in the Frontend
 *
 * This hook does not take care about items that should appear at a defined
 * time. This probably should be done using an additional field in the news
 * records by the tx_yablog_ping. This field could indicate that ping is necessary.
 *
 * @author	Dmitry Dulepov <dmitry@typo3.org>
 * @package	TYPO3
 * @subpackage	tx_yablog
 */
class tx_yablog_ping {

	/**
	 * Processes tt_news items after they are saved to the database. This function
	 * will determine URL of the news item and send a ping to all hosts listed
	 * in the news item text. It works only for regular news items, not for pages
	 * or external addresses.
	 *
	 * @param	string	$status	Status of the operation ('new' or other. See TCEmain)
	 * @param	string	$table	Table name
	 * @param	int	$id	ID of the record (can be 'NEW...' or integer)
	 * @param	array $fieldArray	Field array
	 * @param	t3lib_TCEmain	$pObj	Parent object
	 * @return	void
	 */
	public function processDatamap_afterDatabaseOperations($status, $table, $id, array $fields, t3lib_TCEmain &$pObj) {
		if ($table == 'tt_news') {
			// We need news id to link to a single page and single page to link to it
			$id = $this->getNewsId($id, $pObj);
			// TODO Check here if record is a news item and not other type!
			// TODO Check that record is not hidden (+ time, etc. Usual enableFields)
			$singlePid = $this->getSinglePid($this->getRecordStoragePid($id, $fields));
			if ($singlePid) {
				$links = $this->getPagesToPing($id, $fields);
				if (count($links) > 0) {
					$pageUrl = tx_pagepath_api::getPagePath($singlePid, '&tx_ttnews[tt_news]=' . $id);
					$this->pingPages($links, $pageUrl);
				}
			}
		}
	}

	/**
	 * Obtains record id
	 *
	 * @param	mixed	$id	Record id (either integer or 'NEW...')
	 * @param t3lib_TCEmain $pObj	Parent object
	 * @return	int	News Id
	 */
	protected function getNewsId($id, t3lib_TCEmain &$pObj) {
		if (!t3lib_div::testInt($id)) {
			$id = $pObj->substNEWwithIDs[$id];
		}
		return $id;
	}

	/**
	 * Obtains record pid from using its id or field information
	 *
	 * @param	int	$id	Record id
	 * @param	array	$fields	Fields
	 * @return	int	Record pid
	 */
	protected function getRecordStoragePid($id, array $fields) {
		if (isset($fields['pid']) && t3lib_div::testInt($fields['pid']) && $fields['pid'] > 0) {
			$pid = $fields['pid'];
		}
		else {
			$record = t3lib_BEfunc::getRecord('tt_content', $id, 'pid');
			$pid = $record['pid'];
		}
		return $pid;
	}

	/**
	 * Obtains single pid for the record using tt_news TSConfig
	 *
	 * @param	int	$storagePid	Storage pid
	 * @return	int	Single pid or 0 if not found
	 */
	protected function getSinglePid($storagePid) {
		$singlePid = 0;
		$tsConfig = t3lib_BEfunc::getPagesTSconfig($storagePid);
		if (isset($tsConfig['tx_ttnews.']) && t3lib_div::testInt($tsConfig['tx_ttnews.']['singlePid'])) {
			$singlePid = $tsConfig['tx_ttnews.']['singlePid'];
		}
		return $singlePid;
	}

	/**
	 * Obtains links to ping. This involves parsing text for links, collecting
	 * links, fetching corresponding pages and searching for RDF information
	 * inside them. If found, add link to the collection.
	 *
	 * @param	int	$id	ID of the news record
	 * @param	array	$fields	Record fields
	 * @return	array	Links (or empty array)
	 */
	protected function getPagesToPing($id, array $fields) {
		$bodytext = $this->getNewsItemBodytext($id, $fields);
		$links = $this->getAllLinks($id, $fields);
		if (count($links) > 0) {
			$links = $this->findPingablePages($links);
		}
		$links = $this->getExcerpts($bodytext, $links);
		// TODO Get excerpts! Insert uniqid(), strip_tags and find text around unique id
		return $links;
	}

	/**
	 * Obtains news item text.
	 *
	 * @param	int	$id	ID of the record
	 * @param	array	$fields	Fields
	 * @return	string	Text
	 */
	protected function getNewsItemBodytext($id, $fields) {
		if (isset($fields['bodytext'])) {
			$bodytext = $fields['bodytext'];
		}
		else {
			$record = t3lib_BEfunc::getRecord('tt_news', $id, 'bodytext');
			$bodytext = $record['bodytext'];
		}
		return $bodytext;
	}
	/**
	 * Obtains all links from the news item text.
	 *
	 * @param	string	$bodytext	Bodytext
	 * @return	array	Found links
	 */
	protected function getAllLinks($bodytext) {
		$links = array();

		// Fetch all links
		$matches = array();
		// Find all http (not https!) links
		if (preg_match_all('/<a[^>]*\shref=(")http:\/\/([^\1]+)\1/im', $bodytext, $matches, PREG_OFFSET_CAPTURE)) {
			foreach ($matches as $match) {
				$links[$match[2][0]] = array(
					'url' => $match[2][0],
					'position' => $match[0][1],
				);
			}
		}
		return $links;
	}

	/**
	 * Filters the array to ensure that only pingable links appear there
	 *
	 * @param	array	$links	Links to check
	 * @return	array	Pingable links
	 */
	protected function findPingablePages(array $links) {
		$newLinks = array();
		foreach ($links as $link) {
			if (($pingUrl = $this->getPingURL($link['url']))) {
				$newLinks[$pingUrl] = array(
					'url' => $pingUrl,
					'position' => $link['position'],
				);
			}
		}
		return $newLinks;
	}

	/**
	 * Fetches ping url from the given url
	 *
	 * @param	string	$url	URL to probe for RDF
	 * @return	string	Ping URL
	 */
	protected function getPingURL($url) {
		$pingUrl = '';
		// Get URL content
		$urlContent = t3lib_div::getURL($url);
		if ($urlContent && ($rdfPos = strpos($urlContent, '<rdf:RDF')) !== false) {
			// RDF exists in this content. Get it and parse
			$urlContent = substr($urlContent, $rdfPos);
			if (($endPos = strpos($urlContent, '</rdf:RDF>', $rdfPos)) !== false) {
				// We will use quick regular expression to find ping URL
				$rdfContent = substr($urlContent, $rdfPos, $endPos);
				$pingUrl = preg_replace('/trackback:ping="([^"]+)"/', '\1', $rdfContent);
			}
		}
		return $pingUrl;
	}

	/**
	 * Fetches excerpts for all links from the news item text
	 *
	 * @param	string	$bodytext	New item text
	 * @param	array	$links	Links
	 * @return	array	Links with excerpt
	 */
	protected function getExcerpts($bodytext, $links) {
		foreach ($links as $key => $link) {
			$links[$key]['excerpt'] = $this->getExcerpt($bodytext, $link['position']);
		}
		return $links;
	}

	/**
	 * Fetches excerpt from a bodytext in the given position
	 *
	 * @param	string	$bodytext	News item body text
	 * @param	int	$position	Position
	 * @return	string	Excerpt
	 */
	protected function getExcerpt($bodytext, $position) {
		$uniqueId = uniqid('yablog_');
		// Find start of the link
		$bodytext = strip_tags(substr($bodytext, 0, $position) .
						$uniqueId . substr($bodytext, $position));
		$excerptStartPos = strpos($bodytext, $uniqueId);
		$excerpt = '...' . substr($bodytext, max(0, $excerptStartPos - 30), 30) . '...';
		return $excerpt;
	}

	protected function pingPages($links, $pageUrl, $newsItemTitle) {
		$formData = array(
			'url' => $pageUrl,
			'title' => $newsItemTitle,
			'blog_name' => t3lib_div::getIndpEnv('HTTP_HOST'),
		);
		foreach ($links as $link) {
			$formData['excerpt'] = $link['excerpt'];
			$this->postHttpRequest($link['url'], $formData);
		}
	}

	protected function postHttpRequest($url, array $formData) {
		// use cURL for: http, https, ftp, ftps, sftp and scp
		if ($GLOBALS['TYPO3_CONF_VARS']['SYS']['curlUse']) {
			// External URL without error checking.
			if (!($ch = curl_init())) {
				return;
			}

			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_HEADER, false);
			curl_setopt($ch, CURLOPT_NOBODY, true);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, substr(t3lib_div::implodeArrayForUrl('', $formData), 1));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
			curl_setopt($ch, CURLOPT_FAILONERROR, true);
			curl_setopt($ch, CURLOPT_MUTE, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/x-www-form-urlencoded'));
			@curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);

				// (Proxy support implemented by Arco <arco@appeltaart.mine.nu>)
			if ($GLOBALS['TYPO3_CONF_VARS']['SYS']['curlProxyServer'])	{
				curl_setopt($ch, CURLOPT_PROXY, $GLOBALS['TYPO3_CONF_VARS']['SYS']['curlProxyServer']);

				if ($GLOBALS['TYPO3_CONF_VARS']['SYS']['curlProxyTunnel'])	{
					curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, $GLOBALS['TYPO3_CONF_VARS']['SYS']['curlProxyTunnel']);
				}
				if ($GLOBALS['TYPO3_CONF_VARS']['SYS']['curlProxyUserPass'])	{
					curl_setopt($ch, CURLOPT_PROXYUSERPWD, $GLOBALS['TYPO3_CONF_VARS']['SYS']['curlProxyUserPass']);
				}
			}
			curl_exec($ch);
			curl_close($ch);

		}
		else {
			$parsedURL = parse_url($url);
			$port = intval($parsedURL['port']);
			if ($port < 1)	{
				$port = 80;
			}
			$errno = 0; $errstr = '';
			$fp = @fsockopen($parsedURL['host'], $port, $errno, $errstr, 2.0);
			if (!$fp || $errno > 0)	{
				return;
			}
			$formDataStr = substr(t3lib_div::implodeArrayForUrl('', $formData), 1);
			$msg = 'POST ' . $parsedURL['path'] .
					($parsedURL['query'] ? '?' . $parsedURL['query'] : '') .
					' HTTP/1.0' . "\r\n" . 'Host: ' .
					$parsedURL['host'] . "\r\nConnection: close\r\n" .
					"Content-type: application/x-www-form-urlencoded\r\n" .
					'Content-length: ' . strlen($formDataStr) . "\r\n" .
					"\r\n" .
					$formDataStr;

			fputs($fp, $msg);
			while (!feof($fp))	{
				fgets($fp, 2048);
			}
			fclose($fp);
		}
	}

}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/yablog/class.tx_yablog_ping.php']) {
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/yablog/class.tx_yablog_ping.php']);
}

?>