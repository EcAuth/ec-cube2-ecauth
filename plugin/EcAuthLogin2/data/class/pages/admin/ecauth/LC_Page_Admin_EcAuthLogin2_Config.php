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
require_once __DIR__ . '/../../../helper/SC_Helper_EcAuthLogin2.php';
// プラグインが有効なら SC_Helper_Plugin::load() が読み込み済みだが、
// この画面はプラグイン管理から load_plugin_config.php 経由で開かれ、
// 無効状態でも到達しうる。パスワード認証の状態表示に使うため明示的に読む。
require_once PLUGIN_UPLOAD_REALDIR . 'EcAuthLogin2/EcAuthLogin2.php';

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

    /**
     * @var string 申込フォーム（ec-auth.io）の URL
     */
    public $signup_url;

    /**
     * @var string マイページ（ec-auth.io）の URL
     */
    public $mypage_url;

    /**
     * 現在 DB に保存されている client_id。
     * テンプレート側が「入力値と違っていたら確認ダイアログを出す」判定に使う。
     *
     * @var string
     */
    public $saved_client_id;

    /**
     * 管理画面のパスワード認証が無効化されているか（読み取り専用の表示用）。
     *
     * 切り替えは data/config/config.php の定数で行うため、この画面からは変更できない。
     * 表示する目的は、定数の書き方を間違えて「無効化したつもりで有効のまま」に
     * なっていないかを目視で確認できるようにすること。
     *
     * @var bool
     */
    public $password_login_disabled;

    /**
     * 上記を切り替える定数の名前。画面に手順を出すために渡す。
     *
     * @var string
     */
    public $password_login_const_name;

    public function init()
    {
        parent::init();
        $this->tpl_mainpage = PLUGIN_UPLOAD_REALDIR . 'EcAuthLogin2/templates/admin/plg_EcAuthLogin2_admin_config.tpl';
        $this->tpl_subno = 'ecauth_login2_config';
        $this->tpl_mainno = 'ownersstore';
        $this->tpl_maintitle = 'プラグイン';
        $this->tpl_subtitle = 'EcAuthLogin2 設定';

        // この画面はプラグイン管理から eccube.openWindow(..., 615, 400) で開かれる
        // ポップアップ。LC_Page_Admin::init() の既定は MAIN_FRAME だが、
        // 管理画面フレームは #contents 1000px / #navi-wrap 1030px の固定幅で
        // 615px には収まらない。EC-CUBE はポップアップ用の軽量レイアウト
        // (admin_popup_header.tpl / admin_popup_footer.tpl、#popup-container は 560px)
        // を持っているので、そちらをテンプレート側で include して使う。
        // setTemplate() でフレームを介さずこのテンプレートを直接描画させる。
        $this->setTemplate($this->tpl_mainpage);
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

        // doPostMode() より後に読むこと。保存が成功していれば新しい client_id が
        // 入るため、同じ内容を再送しても確認ダイアログが二重に出ない。
        // 入力エラーで保存されなかった場合は旧値のままなので、再送時に正しく出る。
        $savedConfig = $objHelper->getConfig();
        $this->saved_client_id = isset($savedConfig['client_id']) ? (string) $savedConfig['client_id'] : '';

        $signupUrls = $objHelper->getSignupUrls();
        $this->signup_url = $signupUrls['signup'];
        $this->mypage_url = $signupUrls['mypage'];

        $this->password_login_disabled = EcAuthLogin2::isAdminPasswordLoginDisabled();
        $this->password_login_const_name = EcAuthLogin2::DISABLE_ADMIN_PASSWORD_LOGIN;
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

        // 保存で上書きされる前の設定。接続先が変わるかどうかの判定と、
        // Base URL 欄が事前入力のままかどうかの判定に使う。
        $savedConfig = $objHelper->getConfig();
        $previousClientId = isset($savedConfig['client_id']) ? (string) $savedConfig['client_id'] : '';
        $savedBaseUrl = isset($savedConfig['ecauth_base_url']) ? trim((string) $savedConfig['ecauth_base_url']) : '';
        $clientIdChanged = SC_Helper_EcAuthLogin2::hasClientIdChanged($previousClientId, $config['client_id']);

        // 接続先が変わるなら、新しいテナントの Client Secret を必須にする。
        // Client Secret 欄は「変更時のみ入力」で、空欄なら下の分岐で既存値が
        // 維持される。テナントが変わるとその既存値は前のテナントのもので確実に
        // 無効なため、空欄のまま保存させると「新しい client_id + 前のテナントの
        // secret」が出来上がり、トークン交換が必ず失敗する。
        $newSecret = isset($_POST['client_secret']) ? trim($_POST['client_secret']) : '';
        if ($clientIdChanged && $newSecret === '') {
            $this->arrErr = array(
                'client_secret' => '※ Client ID を変更する場合は、新しい接続先の Client Secret も入力してください。前の接続先の Client Secret は使用できません。',
            );

            return;
        }

        // Base URL 欄には保存済みの値が事前入力される。Client ID を変えたのに欄が
        // 前の接続先の URL のままなら、それは「触っていない」だけなので採用しない。
        // 判定の詳細は shouldDiscardBaseUrlInput() を参照。
        $inputBaseUrl = trim($values['ecauth_base_url']);
        if (SC_Helper_EcAuthLogin2::shouldDiscardBaseUrlInput($clientIdChanged, $inputBaseUrl, $savedBaseUrl)) {
            $inputBaseUrl = '';
        }

        if ($inputBaseUrl === '') {
            $resolved = $objHelper->resolveClient($config['client_id']);
            if (!$resolved['success']) {
                $this->arrErr = array(
                    'client_id' => '※ Client ID に対応するテナントが見つかりませんでした。Client ID を確認するか、EcAuth URL を直接指定してください。',
                );

                return;
            }
            // client-resolve 応答も無条件には信頼しない。応答が汚染されると
            // トークン交換先ごと攻撃者のホストに向く（EcAuthDocs #101）。
            $candidateBaseUrl = $resolved['base_url'];
            $errorField = 'client_id';
        } else {
            $candidateBaseUrl = $inputBaseUrl;
            $errorField = 'ecauth_base_url';
        }

        $validator = new SC_Helper_EcAuthLogin2_BaseUrl();
        $normalizedBaseUrl = $validator->normalize($candidateBaseUrl);
        if ($normalizedBaseUrl === null) {
            $this->arrErr = array(
                $errorField => '※ EcAuth URL が許可されていないホストを指しています。https で始まる正しい EcAuth の URL を指定してください。開発・ステージング環境のホストを使う場合は、環境変数 ECAUTH_ALLOWED_HOSTS に追加してください。',
            );

            return;
        }
        $config['ecauth_base_url'] = $normalizedBaseUrl;

        // client_secret は空入力時は既存値を維持する
        // （接続先が変わる場合は上で空欄を弾いているので、ここには来ない）
        if ($newSecret !== '') {
            $config['client_secret'] = $newSecret;
        }

        // ecauth_subject のクリアは saveConfig() より「先」に行う。
        //
        // この 2 つは別々の UPDATE で、間で落ちる可能性がある。EC-CUBE 2 の
        // SC_Query は force_run=false（getSingletonInstance() の既定）だと
        // DB エラーで trigger_error(E_USER_ERROR) を呼びスクリプトごと終了するため、
        // try/catch で拾って後始末する形にはできない。
        //
        // 先に client_id を保存してしまうと、クリア側で落ちたときに
        // 「新しい client_id + 古い subject」という #18 そのものの状態が残る。
        // しかも再保存しても新旧が同値になりクリア判定が立たないため、
        // 管理画面からは復旧できず手動 SQL が要る。
        // 順序を逆にすれば、落ちても接続先は旧 client_id のままなので、
        // 保存し直せば同じ判定を経てやり直せる。
        $resetMessage = '';
        if ($clientIdChanged) {
            $resetMessage = $this->handleClientIdChanged($objHelper);
        }

        $objHelper->saveConfig($config);

        $this->tpl_onload = "alert('設定を保存しました。" . $resetMessage . "');";
    }

    /**
     * 接続先テナント（client_id）が変わるときの前始末。
     *
     * 呼び出しは saveConfig() の前。理由は呼び出し側のコメントを参照。
     *
     * dtb_member.ecauth_subject は EcAuth の B2BUser.Subject と 1:1 で、
     * EcAuth 側では Subject が Organization をまたいでグローバル一意。
     * そのため古いテナントで発番済みの subject を残したまま client_id を
     * 差し替えると、新しいテナントへの登録が一意制約に阻まれ
     * register/options が必ず 400 になる（#18）。テナントを移す以上、
     * 古い subject に紐づくパスキーはどのみち使えないのでクリアしてよい。
     *
     * @param SC_Helper_EcAuthLogin2 $objHelper
     * @return string 保存完了アラートに追記するメッセージ
     */
    protected function handleClientIdChanged($objHelper)
    {
        $cleared = $objHelper->clearMemberEcauthSubjects();

        // 旧テナントで取得した access_token / credential_id はもう通用しない。
        // 残すとパスキー管理画面が不可解なエラーで一覧取得に失敗するため破棄する。
        unset(
            $_SESSION['ecauth_access_token'],
            $_SESSION['ecauth_current_credential_id'],
            $_SESSION['ecauth_register_session_id']
        );

        GC_Utils_Ex::gfPrintLog(
            '[EcAuthLogin2] client_id changed; cleared ecauth_subject of ' . $cleared . ' member(s)'
        );

        if ($cleared === 0) {
            return '接続先のテナントが変わりました。';
        }

        return '接続先のテナントが変わったため、登録済みのパスキー ' . $cleared
            . ' 件分の紐付けを解除しました。パスキー管理から登録し直してください。';
    }

    /**
     * @return SC_FormParam_Ex
     */
    protected function buildFormParam()
    {
        $objFormParam = new SC_FormParam_Ex();
        // client_id / client_secret / rp_id は STEXT_LEN(50) では足りない。
        // EcAuth の申込で払い出される client_id は "ec-{組織コード}-{UUID32桁}" で、
        // 固定部だけで 36 文字あるため、組織コードが 15 文字以上（例 shop.example.jp →
        // shop-example-jp）になると 50 文字を超える。テンプレート側の maxlength で
        // 静かに切り詰められ、Client ID から Base URL を解決できずに
        // 「テナントが見つかりません」となって設定を保存できなくなる。
        // 保存先の dtb_plugin.free_field1 は text なので、上限は MTEXT_LEN(200) で足りる。
        $objFormParam->addParam('Client ID', 'client_id', MTEXT_LEN, 'a', array('EXIST_CHECK', 'MAX_LENGTH_CHECK'));
        $objFormParam->addParam('Client Secret', 'client_secret', MTEXT_LEN, 'a', array('MAX_LENGTH_CHECK'));
        $objFormParam->addParam('EcAuth Base URL', 'ecauth_base_url', URL_LEN, 'a', array('URL_CHECK', 'MAX_LENGTH_CHECK'));
        // rp_id はサイトのホスト名。ドメインは 50 文字を超えうる。
        $objFormParam->addParam('RP ID', 'rp_id', MTEXT_LEN, 'a', array('MAX_LENGTH_CHECK'));
        // provider_name は federate-oauth2 のような短い識別子なので STEXT_LEN のまま。
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
