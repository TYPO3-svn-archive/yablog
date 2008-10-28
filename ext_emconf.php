<?php

########################################################################
# Extension Manager/Repository config file for ext: "yablog"
#
# Auto generated 26-10-2008 17:15
#
# Manual updates:
# Only the data in the array - anything else is removed by next write.
# "version" and "dependencies" must not be touched!
########################################################################

$EM_CONF[$_EXTKEY] = array(
	'title' => 'Yet Another Blog',
	'description' => 'Adds typical blogging tools (trackbacks and pings) to tt_news and comments',
	'category' => 'fe',
	'author' => 'Dmitry Dulepov',
	'author_email' => 'dmitry@typo3.org',
	'shy' => '',
	'dependencies' => 'tt_news,comments',
	'conflicts' => '',
	'priority' => '',
	'module' => '',
	'state' => 'beta',
	'internal' => '',
	'uploadfolder' => 0,
	'createDirs' => '',
	'modify_tables' => '',
	'clearCacheOnLoad' => 0,
	'lockType' => '',
	'author_company' => '',
	'version' => '0.0.0',
	'constraints' => array(
		'depends' => array(
			'tt_news' => '',
			'comments' => '',
		),
		'conflicts' => array(
		),
		'suggests' => array(
		),
	),
	'_md5_values_when_last_written' => 'a:4:{s:9:"ChangeLog";s:4:"105e";s:12:"ext_icon.gif";s:4:"1bdc";s:19:"doc/wizard_form.dat";s:4:"205d";s:20:"doc/wizard_form.html";s:4:"7f7c";}',
);

?>