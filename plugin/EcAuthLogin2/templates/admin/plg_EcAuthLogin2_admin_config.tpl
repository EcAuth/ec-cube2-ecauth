<!--{*
 * EcAuthLogin2 設定画面テンプレート
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *}-->
<div id="ownersstore" class="contents-main">
    <h2>EcAuth Login プラグイン設定</h2>

    <!--{if $arrErr}-->
    <div class="message" style="color: #e60012; margin-bottom: 1em;">
        <ul>
        <!--{foreach key=field item=msg from=$arrErr}-->
            <li><!--{$msg|h}--></li>
        <!--{/foreach}-->
        </ul>
    </div>
    <!--{/if}-->

    <form name="form1" id="form1" method="post" action="">
        <input type="hidden" name="<!--{$smarty.const.TRANSACTION_ID_NAME}-->" value="<!--{$transactionid}-->" />
        <input type="hidden" name="mode" value="save" />

        <table class="list">
            <tr>
                <th>Client ID <span style="color: #e60012;">*</span></th>
                <td>
                    <input type="text" name="client_id" value="<!--{$arrForm.client_id.value|h}-->" size="60" maxlength="<!--{$smarty.const.STEXT_LEN}-->" />
                </td>
            </tr>
            <tr>
                <th>Client Secret <span style="color: #e60012;">*</span></th>
                <td>
                    <input type="password" name="client_secret" value="" size="60" maxlength="<!--{$smarty.const.STEXT_LEN}-->"
                        placeholder="<!--{if $has_client_secret}-->●●●●●●●●●●●●●●●●（保存済み。変更時のみ入力）<!--{/if}-->" autocomplete="new-password" />
                </td>
            </tr>
            <tr>
                <th>EcAuth Base URL</th>
                <td>
                    <input type="url" name="ecauth_base_url" value="<!--{$arrForm.ecauth_base_url.value|h}-->" size="60" maxlength="<!--{$smarty.const.URL_LEN}-->" placeholder="https://shop1.ec-auth.io" />
                    <p style="color: #666; font-size: 0.9em;">通常は未入力で構いません。Client ID から自動解決されます。</p>
                </td>
            </tr>
            <tr>
                <th>RP ID</th>
                <td>
                    <input type="text" name="rp_id" value="<!--{$arrForm.rp_id.value|h}-->" size="60" maxlength="<!--{$smarty.const.STEXT_LEN}-->" />
                    <p style="color: #666; font-size: 0.9em;">未設定の場合、サイトのホスト名が使用されます。</p>
                </td>
            </tr>
            <tr>
                <th>Provider Name<br /><small>(B2C 用)</small></th>
                <td>
                    <input type="text" name="provider_name" value="<!--{$arrForm.provider_name.value|h}-->" size="60" maxlength="<!--{$smarty.const.STEXT_LEN}-->" />
                    <p style="color: #666; font-size: 0.9em;">B2C ソーシャルログイン時のフェデレーション先プロバイダ名（federate-oauth2 等）。</p>
                </td>
            </tr>
        </table>

        <div class="btn_area" style="margin-top: 1em;">
            <button type="submit" class="btn-action">登録</button>
        </div>
    </form>

    <!--{if $has_client_secret}-->
    <div class="card" style="margin-top: 2em; padding: 1em; background: #f8f9fa; border-radius: 4px;">
        <h3>次のステップ</h3>
        <p>接続設定が完了しました。続けて管理者用のパスキーを登録すると、管理画面にパスキーでログインできるようになります。</p>
        <a class="btn-action" href="<!--{$smarty.const.HTTPS_URL}--><!--{$smarty.const.ADMIN_DIR}-->ecauth/passkey.php">
            パスキーを登録する
        </a>
    </div>
    <!--{/if}-->
</div>
