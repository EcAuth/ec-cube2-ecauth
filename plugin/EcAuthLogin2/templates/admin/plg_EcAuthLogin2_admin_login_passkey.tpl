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

        // EC-CUBE 2 管理画面ログインの form 配下、LOGIN リンク or submit ボタンを探す。
        // 本体 (admin/login.tpl) の LOGIN は
        //   <p><a class="btn-tool-format" href="javascript:;" onclick="...submit()"><span>LOGIN</span></a></p>
        // なので btn-tool-format を最優先で拾う。
        var form = document.querySelector('form[name="form1"]') || document.querySelector('form');
        if (!form) { return; }
        var anchor = form.querySelector('a.btn-tool-format, a[onclick*="submit"], a.btn-login, a[href="javascript:;"]')
            || form.querySelector('input[type="submit"], button[type="submit"]');
        if (!anchor) { return; }

        // 見た目は本体の LOGIN ボタンに完全に合わせる。独自の色・サイズを
        // 直書きすると EC-CUBE のバージョンやテーマ変更に追従できず、
        // 管理画面だけ浮いたボタンになる (実際 1.0.0 がその状態だった)。
        // 同じ <p><a class="btn-tool-format"><span> 構造を複製し、
        // スタイル指定は本体 CSS (admin.css の .btn-tool-format) に委ねる。
        var passkeyBtn = document.createElement('a');
        passkeyBtn.id = 'ecauth-passkey-login';
        passkeyBtn.className = anchor.className || 'btn-tool-format';
        passkeyBtn.href = 'javascript:;';
        passkeyBtn.setAttribute('role', 'button');
        var passkeyLabel = document.createElement('span');
        passkeyLabel.textContent = 'パスキーでログイン';
        passkeyBtn.appendChild(passkeyLabel);

        var container = anchor.closest('p') || anchor.parentNode;
        // LOGIN と同じ <p> ラッパーで包む。<p> は本体 CSS で余白が付くため、
        // これだけで LOGIN ボタンと同じ間隔・位置に並ぶ。
        var passkeyWrapper = document.createElement('p');
        passkeyWrapper.appendChild(passkeyBtn);
        container.parentNode.insertBefore(passkeyWrapper, container.nextSibling);

        // <a> には disabled が無いので、二重送信防止はフラグで行う。
        var busy = false;
        function setBusy(state, label) {
            busy = state;
            passkeyLabel.textContent = label;
            passkeyBtn.setAttribute('aria-disabled', state ? 'true' : 'false');
            passkeyBtn.style.opacity = state ? '0.6' : '';
            passkeyBtn.style.pointerEvents = state ? 'none' : '';
        }

        passkeyBtn.addEventListener('click', function (event) {
            event.preventDefault();
            if (busy) { return; }
            setBusy(true, '認証中...');
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
                setBusy(false, 'パスキーでログイン');
                alert('パスキー認証に失敗しました。');
            }).catch(function (error) {
                setBusy(false, 'パスキーでログイン');
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
