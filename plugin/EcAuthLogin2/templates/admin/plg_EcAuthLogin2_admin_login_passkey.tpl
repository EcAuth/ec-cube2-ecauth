<!--{*
 * EcAuthLogin2 管理画面ログイン用 パスキーログインスクリプト
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * EcAuthLogin2.php の prefilterTransform でこのファイルが
 * file_get_contents() され、login.tpl の </body> 直前に挿入される。
 * Smarty タグは使えない (コンパイル済み HTML として渡るため)。
 * 動的な値は @ecauth/auth-js が API を fetch して取得する。
 *}-->
<script src="https://cdn.ec-auth.io/auth-js/0.1.3/ecauth-auth.umd.js"></script>
<script>
(function () {
    function injectButton() {
        if (location.protocol !== 'https:' || typeof window.PublicKeyCredential === 'undefined') {
            return;
        }
        // ボタンが既にある場合は再挿入しない
        if (document.getElementById('ecauth-passkey-login')) { return; }

        // EC-CUBE 2 管理画面ログインの form 配下、LOGIN リンク or submit ボタンを探す
        var form = document.querySelector('form[name="form1"]') || document.querySelector('form');
        if (!form) { return; }
        var anchor = form.querySelector('a[onclick*="submit"], a.btn-login, a[href="javascript:;"]')
            || form.querySelector('input[type="submit"], button[type="submit"]');
        if (!anchor) { return; }

        var passkeyBtn = document.createElement('button');
        passkeyBtn.type = 'button';
        passkeyBtn.id = 'ecauth-passkey-login';
        passkeyBtn.style.cssText = 'display:block; margin: 10px auto 0; padding: 10px 20px; background: #4285f4; color: #fff; border: none; border-radius: 4px; cursor: pointer; min-width: 200px;';
        passkeyBtn.textContent = 'パスキーでログイン';

        var container = anchor.closest('p') || anchor.parentNode;
        container.parentNode.insertBefore(passkeyBtn, container.nextSibling);

        passkeyBtn.addEventListener('click', function () {
            passkeyBtn.disabled = true;
            passkeyBtn.textContent = '認証中...';
            // URL は EcAuthLogin2::insertAdminPasskeyScript() で HTTPS_URL を
            // 基底にプレースホルダ置換される。サブディレクトリインストール
            // (ROOT_URLPATH=/shop/ 等) でも正しい絶対 URL が埋め込まれる。
            EcAuth.webauthn.authenticate({
                optionsUrl: '%%ECAUTH_OPTIONS_URL%%',
                verifyUrl: '%%ECAUTH_VERIFY_URL%%'
            }).then(function (result) {
                if (result && result.redirect_url) {
                    window.location.href = result.redirect_url;
                    return;
                }
                passkeyBtn.disabled = false;
                passkeyBtn.textContent = 'パスキーでログイン';
                alert('パスキー認証に失敗しました。');
            }).catch(function (error) {
                passkeyBtn.disabled = false;
                passkeyBtn.textContent = 'パスキーでログイン';
                if (!error || error.name === 'NotAllowedError') { return; }
                console.error('Passkey authentication error:', error);
                alert('パスキー認証に失敗しました。');
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectButton);
    } else {
        injectButton();
    }
})();
</script>
