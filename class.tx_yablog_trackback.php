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

require_once(PATH_t3lib . 'class.t3lib_befunc.php');
require_once(PATH_t3lib . 'class.t3lib_tcemain.php');
require_once(PATH_tslib . 'class.tslib_eidtools.php');

/**
 * Trackback implementation. This class watches for trackback requests and
 * records them appropriately. To avoid trackback spam it will fetch the
 * resource and verify that link to the trackbacked resource is present.
 *
 * The implementation is based on the following description of trackbacks:
 * http://www.sixapart.com/pronet/docs/trackback_spec
 *
 * The following URL parameters expected:
 * - url: see trackback specification
 * - excerpt: see trackback specification
 * - blog_name: see trackback specification
 * - title: see trackback specification
 * - data: base64-encoded serialized array of the following parameters:
 * 	- news_id: tt_news id
 *	- news_url: news item url
 *	- pid: pid for comments
 *	- cache: comma-separated list of page uids to clear cache for
 *	- check: md5 of thge previous parameters in the order of the parameters + encryptionKey
 *
 * @author	Dmitry Dulepov <dmitry@typo3.org>
 * @package	TYPO3
 * @subpackage	tx_yablog
 */
class tx_yablog_trackback {

	protected	$url;
	protected	$title;
	protected	$excerpt;
	protected	$blogName;
	protected	$newsUrl;
	protected	$newsId;
	protected	$clearCache;
	protected	$pid;
	protected	$check;
	protected	$error = '';

	/**
	 * Initializes the instance of this class.
	 *
	 * @return	void
	 */
	public function __construct() {
		$this->url = t3lib_div::GPvar('url');
		$this->title = trim(strip_tags(t3lib_div::GPvar('title')));
		$this->excerpt = trim(strip_tags(t3lib_div::GPvar('excerpt')));
		$this->blogName = trim(strip_tags(t3lib_div::GPvar('blog_name')));

		$data = unserialize(base64_decode(t3lib_div::GPvar('data')));
		if (is_array($data)) {
			$this->newsId = $data['news_id'];
			$this->newsUrl = $data['news_url'];
			$this->pid = $data['pid'];
			$this->clearCache = $data['cache'];
			$this->check = $data['check'];
		}
	}

	/**
	 * Handles incoming trackback requests
	 *
	 * @return	void
	 */
	public function main() {
		if ($this->validateParameters()) {
			$this->addTrackback();
		}

		// Output response
		header('Content-type: text/xml; charset=UTF-8');
		if ($this->error == '') {
			echo '<?xml version="1.0" encoding="utf-8"?><response><error>0</error></response>';
		}
		else {
			echo '<?xml version="1.0" encoding="utf-8"?><response><error>1</error>' .
				'<message>' . htmlentities($this->error) . '</message></response>';
		}
	}

	/**
	 * Validates trackback parameters. This function will set $this->error to
	 * error message if necessary.
	 *
	 * @return	boolean	true if parameters are ok
	 */
	protected function validateParameters() {
		$result = false;
		if ($this->title == '' || $this->excerpt == '') {
			$this->error = 'Missing title or excerpt';
		}
		elseif (strpos($this->excerpt, '[url') !== false) {
			$this->error = 'Spam must die';
		}
		elseif (!t3lib_div::testInt($this->newsId)) {
			$this->error = 'Missing article id';
		}
		elseif (!filter_var($this->url, FILTER_VALIDATE_URL)) {
			$this->error = 'Invalid blog URL';
		}
		elseif (!filter_var($this->newsUrl, FILTER_VALIDATE_URL)) {
			$this->error = 'Invalid article URL';
		}
		elseif (md5($this->newsId . $this->newsUrl . $this->pid . $this->clearCache . $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']) != $this->check) {
			$this->error = 'Faked post';
		}
		else {
			// Deeper checks

			tslib_eidtools::connectDB();
			$this->includeTCA();

			// Do we have such news item?
			$hasCommentsIC = t3lib_extMgm::isLoaded('comments_ic');
			$rows = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows(
				($hasCommentsIC ? 'tx_commentsic_disable,tx_commentsic_closetime' : 'uid'),
				'tt_news', 'uid=' . $this->newsId . t3lib_BEfunc::deleteClause('tt_news') .
				t3lib_BEfunc::BEenableFields('tt_news'));
			if (count($rows) == 0) {
				$this->error = 'Article does not exist';
			}
			else {
				// Check if comments are still enabled
				if ($hasCommentsIC &&
					(!$rows[0]['tx_commentsic_disable'] || $rows[0]['tx_commentsic_closetime'] <= time())) {
					$this->error = 'Comments are disabled';
				}
				else {
					// Does trackback host exist?
					$urlParts = parse_url($this->url);
					$ip = gethostbyname($urlParts['host']);
					if (!$ip || $ip != '127.0.0.1' || $ip != long2ip(ip2long($ip))) {
						$this->error = 'No route to host';
					}
					else {
						// Get content
						$content = t3lib_div::getURL($this->url);
						if (!preg_match('/<a[^>]*\shref="' . preg_quote($this->newsUrl, '/') . '"/mi', $content)) {
							// Do not give spammers any clues
							$this->error = 'Sorry.';
						}
						else {
							$result = true;
						}
					}
				}
			}
		}
		return $result;
	}

	/**
	 * Includes TCA for the necessary extensions.
	 *
	 * @return	void
	 */
	protected function includeTCA() {
		global	$TYPO3_CONF_VARS, $TCA;

		foreach (array('tt_news', 'comments') as $_EXTKEY) {
			require_once(t3lib_extMgm::extPath($_EXTKEY, 'ext_tables.php'));
		}
	}

	/**
	 * Adds trackback and clears cache.
	 *
	 * @return	void
	 */
	protected function addTrackback() {
		// Prepare field array
		$fields = array(
			'pid' => $this->pid,
			'external_ref' => 'tt_news_' . $this->newsId,
			'external_prefix' => 'tt_news',
			'approved' => 1,
			'firstName' => $this->blogName,
			'homepage' => $this->url,
			'content' => '...' . trim($this->excerpt, '.') . '...',
			'remote_addr' => t3lib_div::getIndpEnv('REMOTE_ADDR'),
		);
		$double_post_check = md5(implode(',', $fields));

		// Are there duplicate posts? Notice, we just ignore them, no error sent!
		list($row) = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows('COUNT(*) as counter',
			'tx_comments_comments', 'pid=' . $this->pid . ' AND double_post_check=' .
			$GLOBALS['TYPO3_DB']->fullQuoteStr($double_post_check, 'tx_comments_comments') .
			t3lib_BEfunc::deleteClause('tx_comments_comments') .
			t3lib_BEfunc::BEenableFields('tx_comments_comments'));
		if ($row['count'] == 0) {
			// Insert data
			$fields['double_post_check'] = $double_post_check;
			$GLOBALS['TYPO3_DB']->exec_INSERTquery('tx_comments_comments', $fields);
			// Clear cache
			$tce = t3lib_div::makeInstance('t3lib_TCEmain');
			/* @var $tce t3lib_TCEmain */
			foreach (t3lib_div::trimExplode(',', $this->clearCache, true) as $pid) {
				$tce->clear_cacheCmd(intval($pid));
			}
		}
	}
}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/yablog/class.tx_yablog_trackback.php']) {
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/yablog/class.tx_yablog_trackback.php']);
}

$trackback = t3lib_div::makeInstance('tx_yablog_trackback');
/* @var $trackback tx_yablog_trackback */
$trackback->main();

?>