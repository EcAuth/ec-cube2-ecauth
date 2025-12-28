<?php
/**
 * EcAuth コールバックエントリーポイント
 *
 * @package EcAuthLogin2
 * @version 1.0.0
 */

require_once '../require.php';
require_once CLASS_EX_REALDIR . 'page_extends/ecauth/LC_Page_EcAuth_Callback_Ex.php';

$objPage = new LC_Page_EcAuth_Callback_Ex();
$objPage->init();
$objPage->process();
