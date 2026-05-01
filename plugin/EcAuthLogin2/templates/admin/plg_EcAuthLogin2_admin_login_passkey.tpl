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
 *}-->
<script>
(function () {
    function b64urlToBuf(s) {
        s = s.replace(/-/g, '+').replace(/_/g, '/');
        var pad = s.length % 4;
        if (pad) { s += '='.repeat(4 - pad); }
        var bin = atob(s);
        var buf = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) { buf[i] = bin.charCodeAt(i); }
        return buf.buffer;
    }
    function bufToB64url(buf) {
        var bytes = new Uint8Array(buf);
        var s = '';
        for (var i = 0; i < bytes.length; i++) { s += String.fromCharCode(bytes[i]); }
        return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    async function doPasskeyAuthenticate() {
        var optionsUrl = location.origin + '/ecauth/passkey/authenticate-options.php';
        var verifyUrl = location.origin + '/ecauth/passkey/authenticate-verify.php';

        var optionsRes = await fetch(optionsUrl, { method: 'POST', credentials: 'include' });
        if (!optionsRes.ok) { throw new Error('authenticate_options_failed'); }
        var opts = await optionsRes.json();

        // EcAuth から timeout=0 が返ると Chrome が即時 NotAllowedError を返すため上書き
        var publicKey = Object.assign({}, opts, {
            challenge: b64urlToBuf(opts.challenge),
            allowCredentials: (opts.allowCredentials || []).map(function (c) {
                return Object.assign({}, c, { id: b64urlToBuf(c.id) });
            }),
            timeout: 60000
        });

        var assertion = await navigator.credentials.get({ publicKey: publicKey });

        var assertionJson = {
            id: assertion.id,
            rawId: bufToB64url(assertion.rawId),
            type: assertion.type,
            authenticatorAttachment: assertion.authenticatorAttachment,
            response: {
                clientDataJSON: bufToB64url(assertion.response.clientDataJSON),
                authenticatorData: bufToB64url(assertion.response.authenticatorData),
                signature: bufToB64url(assertion.response.signature),
                userHandle: assertion.response.userHandle ? bufToB64url(assertion.response.userHandle) : null
            },
            clientExtensionResults: assertion.getClientExtensionResults()
        };

        var verifyRes = await fetch(verifyUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ response: assertionJson })
        });
        if (!verifyRes.ok) {
            var errBody;
            try { errBody = await verifyRes.json(); } catch (e) { errBody = {}; }
            var err = new Error('authenticate_verify_failed');
            err.detail = errBody;
            throw err;
        }
        return await verifyRes.json();
    }

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
            doPasskeyAuthenticate().then(function (result) {
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
                console.error('Passkey authentication error:', error, error.detail || '');
                var detail = '';
                if (error.detail && error.detail.ecauth_response) {
                    var ec = error.detail.ecauth_response;
                    detail = ec.error_description || ec.title || '';
                }
                alert('パスキー認証に失敗しました。' + (detail ? '\n' + detail : ''));
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
