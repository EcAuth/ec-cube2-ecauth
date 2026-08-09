<!--{*
 * EcAuthLogin2 設定画面テンプレート
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *}-->
<!--{*
 * この画面は プラグイン管理 > 「プラグイン設定」から
 *   eccube.openWindow('../load_plugin_config.php?plugin_id=N', 'load', 615, 400)
 * で開かれるポップアップ。615x400 は EC-CUBE 本体
 * (admin/ownersstore/plugin.tpl) のハードコードで変更できない。
 *
 * 通常の管理画面フレーム (MAIN_FRAME) は #contents が 1000px、
 * #navi-wrap が 1030px の固定幅で、この幅にはそもそも収まらない。
 * EC-CUBE はポップアップ用に専用レイアウトを持っており
 * (#popup-container が 560px)、そちらを使うのが本来の作り。
 * LC_Page_Admin_EcAuthLogin2_Config::init() が setTemplate() で
 * このテンプレートを直接描画するようにしているため、
 * ヘッダ／フッタはここで include する。
 *}-->
<!--{include file="`$smarty.const.TEMPLATE_ADMIN_REALDIR`admin_popup_header.tpl"}-->

<style>
/* #popup-container は 560px。size="60" の input はそのままだとセルを
   押し広げて横スクロールを生むので、セル幅に追従させる。 */
.ecauth-config table.list {
    width: 100%;
    table-layout: fixed;
}
.ecauth-config table.list th {
    width: 34%;
    word-break: break-word;
}
.ecauth-config table.list td {
    word-break: break-word;
}
.ecauth-config input[type="text"],
.ecauth-config input[type="password"],
.ecauth-config input[type="url"] {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}
.ecauth-config .card {
    overflow-wrap: break-word;
}
</style>
<div class="ecauth-config">
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

    <div class="card" style="margin-bottom: 1.5em; padding: 1em; background: #f8f9fa; border-radius: 4px;">
        <h3>Client ID / Client Secret の取得方法</h3>
        <p>本プラグインのご利用には、EcAuth が発行する Client ID と Client Secret が必要です。まだお持ちでない場合は、以下の手順で取得してください。管理者 5 ユーザーまで無料でご利用いただけます。</p>

        <h4 style="margin-top: 1.2em;">はじめて EcAuth をご利用の場合</h4>
        <ol style="margin: 0.5em 0 1em 1.5em;">
            <li style="margin-bottom: 0.3em;">下の「EcAuth に申し込む」ボタンから申込フォームを開き、メールアドレスとサイトの情報を入力します。</li>
            <li style="margin-bottom: 0.3em;">入力したメールアドレス宛に確認メールが届きます。メール内のリンクを開くと、EcAuth のアカウントが作成されます。</li>
            <li>EcAuth のマイページに Client ID と Client Secret が表示されます。これを下の設定欄に入力し、「登録」ボタンを押してください。</li>
        </ol>
        <p>
            <a class="btn-action" id="ecauth-signup-link" href="<!--{$signup_url|h}-->" target="_blank" rel="noopener noreferrer">EcAuth に申し込む</a>
        </p>

        <hr style="margin: 1.2em 0; border: none; border-top: 1px solid #ddd;" />

        <h4>すでにお申し込み済みの場合</h4>
        <p>申込は不要です。EcAuth のマイページを開くと、ご契約中のショップの Client ID と Client Secret を確認できます。Client Secret が分からなくなった場合は、マイページから再発行できます。</p>
        <p style="margin-bottom: 0;">
            <a class="btn-action" id="ecauth-mypage-link" href="<!--{$mypage_url|h}-->" target="_blank" rel="noopener noreferrer">EcAuth マイページを開く</a>
        </p>
    </div>

    <form name="form1" id="form1" method="post" action="">
        <input type="hidden" name="<!--{$smarty.const.TRANSACTION_ID_NAME}-->" value="<!--{$transactionid}-->" />
        <input type="hidden" name="mode" value="save" />

        <table class="list">
            <tr>
                <th>Client ID <span style="color: #e60012;">*</span></th>
                <td>
                    <!--{* client_id は "ec-{組織コード}-{UUID32桁}" で 50 文字を超えうる。
                           maxlength が短いとブラウザが静かに切り詰め、Base URL の自動解決に失敗する。 *}-->
                    <!--{* data-saved は「保存済みの値」。JS 側でこれと入力値を比べて
                           テナント変更の確認ダイアログを出す。値を JS リテラルに
                           直接埋めるとエスケープを誤りやすいので属性経由で渡す。 *}-->
                    <input type="text" name="client_id" id="ecauth-client-id" value="<!--{$arrForm.client_id.value|h}-->" data-saved="<!--{$saved_client_id|h}-->" size="60" maxlength="<!--{$smarty.const.MTEXT_LEN}-->" />
                    <p style="color: #666; font-size: 0.9em;">Client ID を別のテナントのものに変更すると、登録済みのパスキーは使えなくなります。</p>
                </td>
            </tr>
            <tr>
                <th>Client Secret <!--{if !$has_client_secret}--><span style="color: #e60012;">*</span><!--{/if}--></th>
                <td>
                    <input type="password" name="client_secret" value="" size="60" maxlength="<!--{$smarty.const.MTEXT_LEN}-->"
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
                    <input type="text" name="rp_id" value="<!--{$arrForm.rp_id.value|h}-->" size="60" maxlength="<!--{$smarty.const.MTEXT_LEN}-->" />
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
        <a class="btn-action" href="<!--{$smarty.const.HTTPS_URL}--><!--{$smarty.const.ADMIN_DIR}-->ecauth/passkey.php" target="_blank" rel="noopener">
            パスキーを登録する
        </a>
    </div>
    <!--{/if}-->
</div>

<script>
(function () {
    // 接続先テナント（client_id）を変えると、サーバー側で全管理者の
    // ecauth_subject をクリアする（登録済みパスキーの紐付けが切れる）。
    // 取り返しのつく操作ではないので、送信前に確認を挟む。
    // なお確認はあくまで UI 上の保険で、実際のクリアは保存処理側が
    // 新旧 client_id の比較で判断する（JS を経由しない送信でも整合する）。
    var form = document.getElementById('form1');
    var input = document.getElementById('ecauth-client-id');
    if (!form || !input) { return; }

    form.addEventListener('submit', function (event) {
        // サーバー側の hasClientIdChanged() は新旧どちらも trim して比べる。
        // ここで saved を trim しないと、空白付きで保存された値のときだけ
        // 「確認ダイアログは出るのにクリアはされない」という食い違いが起きる。
        var saved = (input.getAttribute('data-saved') || '').replace(/^\s+|\s+$/g, '');
        var current = input.value.replace(/^\s+|\s+$/g, '');
        // 初回登録（saved が空）と、値が変わっていない場合は確認しない。
        if (saved === '' || saved === current) { return; }

        var ok = window.confirm(
            '接続先のテナント (Client ID) を変更しようとしています。\n\n'
            + '登録済みのパスキーはすべて使えなくなり、EcAuth との紐付けが解除されます。\n'
            + '各管理者は、パスワードでログインしてパスキーを登録し直す必要があります。\n\n'
            + '続行しますか？'
        );
        if (!ok) {
            event.preventDefault();
        }
    });
})();
</script>

<!--{include file="`$smarty.const.TEMPLATE_ADMIN_REALDIR`admin_popup_footer.tpl"}-->
