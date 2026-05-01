<?php

/*
 * EcAuthLogin2 管理画面 設定ページ
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 */

require_once CLASS_EX_REALDIR . 'page_extends/admin/LC_Page_Admin_Ex.php';
require_once CLASS_REALDIR . 'helper/SC_Helper_EcAuthLogin2.php';

/**
 * EcAuthLogin2 設定画面。
 * - client_id / client_secret / ecauth_base_url / rp_id を dtb_plugin.free_field1 に保存
 * - ecauth_base_url 未入力時は ClientResolveService で client_id から自動解決
 */
class LC_Page_Admin_EcAuthLogin2_Config extends LC_Page_Admin_Ex
{
    /**
     * @var bool
     */
    public $has_client_secret;
    public function init()
    {
        parent::init();
        $this->tpl_mainpage = PLUGIN_UPLOAD_REALDIR . 'EcAuthLogin2/templates/admin/plg_EcAuthLogin2_admin_config.tpl';
        $this->tpl_subno = 'ecauth_login2_config';
        $this->tpl_mainno = 'ownersstore';
        $this->tpl_maintitle = 'プラグイン';
        $this->tpl_subtitle = 'EcAuthLogin2 設定';
    }

    public function process()
    {
        $this->action();
        $this->sendResponse();
    }

    public function action()
    {
        $objHelper = new SC_Helper_EcAuthLogin2();
        $objFormParam = $this->buildFormParam();

        if (isset($_POST['mode']) && $_POST['mode'] === 'save') {
            $this->doPostMode($objHelper, $objFormParam);
        } else {
            $current = $objHelper->getConfig();
            $objFormParam->setParam(array(
                'client_id' => isset($current['client_id']) ? $current['client_id'] : '',
                'ecauth_base_url' => isset($current['ecauth_base_url']) ? $current['ecauth_base_url'] : '',
                'rp_id' => isset($current['rp_id']) ? $current['rp_id'] : '',
                'provider_name' => isset($current['provider_name']) ? $current['provider_name'] : '',
                // client_secret は表示しない（保存済みなら placeholder）
            ));
        }

        $this->arrForm = $objFormParam->getFormParamList();
        $this->has_client_secret = $this->loadConfigBoolean('client_secret');
    }

    /**
     * @param SC_Helper_EcAuthLogin2 $objHelper
     * @param SC_FormParam_Ex $objFormParam
     */
    protected function doPostMode($objHelper, $objFormParam)
    {
        $objFormParam->setParam($_POST);
        $objFormParam->convParam();
        $arrErr = $objFormParam->checkError();

        if (!empty($arrErr)) {
            $this->arrErr = $arrErr;

            return;
        }

        $values = $objFormParam->getHashArray();
        $config = array(
            'client_id' => trim($values['client_id']),
            'rp_id' => trim($values['rp_id']),
            'provider_name' => trim($values['provider_name']),
        );

        $inputBaseUrl = trim($values['ecauth_base_url']);
        if ($inputBaseUrl === '') {
            $resolved = $objHelper->resolveClient($config['client_id']);
            if (!$resolved['success']) {
                $this->arrErr = array(
                    'client_id' => '※ Client ID に対応するテナントが見つかりませんでした。Client ID を確認するか、EcAuth URL を直接指定してください。',
                );

                return;
            }
            $config['ecauth_base_url'] = $resolved['base_url'];
        } else {
            $config['ecauth_base_url'] = rtrim($inputBaseUrl, '/');
        }

        // client_secret は空入力時は既存値を維持する
        $newSecret = isset($_POST['client_secret']) ? trim($_POST['client_secret']) : '';
        if ($newSecret !== '') {
            $config['client_secret'] = $newSecret;
        }

        $objHelper->saveConfig($config);
        $this->tpl_onload = "alert('設定を保存しました。');";
    }

    /**
     * @return SC_FormParam_Ex
     */
    protected function buildFormParam()
    {
        $objFormParam = new SC_FormParam_Ex();
        $objFormParam->addParam('Client ID', 'client_id', STEXT_LEN, 'a', array('EXIST_CHECK', 'MAX_LENGTH_CHECK'));
        $objFormParam->addParam('Client Secret', 'client_secret', STEXT_LEN, 'a', array('MAX_LENGTH_CHECK'));
        $objFormParam->addParam('EcAuth Base URL', 'ecauth_base_url', URL_LEN, 'a', array('URL_CHECK', 'MAX_LENGTH_CHECK'));
        $objFormParam->addParam('RP ID', 'rp_id', STEXT_LEN, 'a', array('MAX_LENGTH_CHECK'));
        $objFormParam->addParam('Provider Name', 'provider_name', STEXT_LEN, 'a', array('MAX_LENGTH_CHECK'));

        return $objFormParam;
    }

    /**
     * @param string $key
     * @return bool
     */
    protected function loadConfigBoolean($key)
    {
        $objHelper = new SC_Helper_EcAuthLogin2();
        $config = $objHelper->getConfig();

        return !empty($config[$key]);
    }
}
