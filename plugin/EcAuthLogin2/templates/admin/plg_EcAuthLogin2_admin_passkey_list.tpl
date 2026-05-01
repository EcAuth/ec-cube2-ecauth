<!--{*
 * EcAuthLogin2 パスキー一覧テンプレート
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *}-->
<div id="ownersstore" class="contents-main">
    <h2>パスキー管理</h2>

    <!--{if $error_message}-->
    <div class="message" style="margin-bottom: 1em; padding: 1em; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px;">
        <!--{$error_message|h}-->
    </div>
    <!--{/if}-->

    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1em;">
        <span>登録済みパスキー</span>
        <button type="button" id="ecauth-passkey-add" class="btn-action" <!--{if !$has_client_secret}-->disabled<!--{/if}-->>
            + パスキーを追加
        </button>
    </div>

    <!--{if $passkeys|@count > 0}-->
    <table class="list">
        <thead>
            <tr>
                <th>デバイス名</th>
                <th>登録日時</th>
                <th>最終使用日時</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <!--{foreach from=$passkeys item=passkey}-->
            <tr<!--{if $passkey.credential_id == $current_credential_id}--> style="background: #fff8dc;"<!--{/if}-->>
                <td>
                    <!--{if $passkey.device_name}--><!--{$passkey.device_name|h}--><!--{else}-->-<!--{/if}-->
                    <!--{if $passkey.credential_id == $current_credential_id}-->
                    <span style="background: #007bff; color: #fff; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; margin-left: 0.5em;">ログイン中</span>
                    <!--{/if}-->
                </td>
                <td><!--{if $passkey.created_at}--><!--{$passkey.created_at|h}--><!--{else}-->-<!--{/if}--></td>
                <td><!--{if $passkey.last_used_at}--><!--{$passkey.last_used_at|h}--><!--{else}-->-<!--{/if}--></td>
                <td>
                    <form method="post" action="?" style="display: inline;" onsubmit="return confirm('このパスキーを削除してよろしいですか？');">
                        <input type="hidden" name="<!--{$smarty.const.TRANSACTION_ID_NAME}-->" value="<!--{$transactionid}-->" />
                        <input type="hidden" name="mode" value="delete" />
                        <input type="hidden" name="credential_id" value="<!--{$passkey.credential_id|h}-->" />
                        <button type="submit" class="btn-cancel" style="padding: 2px 8px;">削除</button>
                    </form>
                </td>
            </tr>
        <!--{/foreach}-->
        </tbody>
    </table>
    <!--{else}-->
    <p>登録済みパスキーはありません。</p>
    <!--{/if}-->

    <!-- パスワード再認証モーダル -->
    <div id="ecauth-password-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center;">
        <div style="background: #fff; border-radius: 8px; padding: 30px; max-width: 400px; width: 90%;">
            <h5 style="margin-top: 0;">パスワード確認</h5>
            <p style="color: #666;">本人確認のため、パスワードを入力してください。</p>
            <input type="password" id="ecauth-password-input" style="width: 100%; padding: 8px;" autocomplete="current-password" />
            <div id="ecauth-password-error" style="color: #dc3545; font-size: 0.875rem; margin-top: 0.5rem; display: none;"></div>
            <div style="display: flex; justify-content: flex-end; margin-top: 1em;">
                <button type="button" id="ecauth-password-cancel" class="btn-cancel" style="margin-right: 0.5em;">キャンセル</button>
                <button type="button" id="ecauth-password-confirm" class="btn-action">確認</button>
            </div>
        </div>
    </div>
</div>

<script>
// base64url エンコード/デコードヘルパー
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

// パスキー登録: register-options → navigator.credentials.create → register-verify
async function doPasskeyRegister(b2bSubject) {
    var csrfToken = document.querySelector('meta[name="ecauth-csrf-token"]').content;
    var optionsUrl = '<!--{$smarty.const.HTTPS_URL}--><!--{$smarty.const.ADMIN_DIR}-->ecauth/api/register-options.php';
    var verifyUrl = '<!--{$smarty.const.HTTPS_URL}--><!--{$smarty.const.ADMIN_DIR}-->ecauth/api/register-verify.php';

    var optionsRes = await fetch(optionsUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        credentials: 'include',
        body: JSON.stringify({ b2b_subject: b2bSubject })
    });
    if (!optionsRes.ok) { throw new Error('register_options_failed'); }
    var opts = await optionsRes.json();

    // EcAuth から timeout=0 が返ると Chrome が即時 NotAllowedError を返すため上書き
    var publicKey = Object.assign({}, opts, {
        challenge: b64urlToBuf(opts.challenge),
        user: Object.assign({}, opts.user, { id: b64urlToBuf(opts.user.id) }),
        excludeCredentials: (opts.excludeCredentials || []).map(function (c) {
            return Object.assign({}, c, { id: b64urlToBuf(c.id) });
        }),
        timeout: 60000
    });

    var cred = await navigator.credentials.create({ publicKey: publicKey });

    // EcAuth は response.transports を必須としているため getTransports() の結果を含める
    var transports = (cred.response && typeof cred.response.getTransports === 'function')
        ? cred.response.getTransports()
        : [];

    var credJson = {
        id: cred.id,
        rawId: bufToB64url(cred.rawId),
        type: cred.type,
        authenticatorAttachment: cred.authenticatorAttachment,
        response: {
            clientDataJSON: bufToB64url(cred.response.clientDataJSON),
            attestationObject: bufToB64url(cred.response.attestationObject),
            transports: transports
        },
        clientExtensionResults: cred.getClientExtensionResults()
    };

    var verifyRes = await fetch(verifyUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        credentials: 'include',
        body: JSON.stringify({ response: credJson, device_name: navigator.userAgent.substring(0, 50) })
    });
    if (!verifyRes.ok) {
        var errBody;
        try { errBody = await verifyRes.json(); } catch (e) { errBody = {}; }
        var err = new Error('register_verify_failed');
        err.detail = errBody;
        throw err;
    }
    return await verifyRes.json();
}
</script>

<meta name="ecauth-csrf-token" content="<!--{$csrf_token|h}-->">

<script>
(function() {
    var addBtn = document.getElementById('ecauth-passkey-add');
    var modal = document.getElementById('ecauth-password-modal');
    var cancelBtn = document.getElementById('ecauth-password-cancel');
    var confirmBtn = document.getElementById('ecauth-password-confirm');
    var passwordInput = document.getElementById('ecauth-password-input');
    var errorMsg = document.getElementById('ecauth-password-error');
    var csrfToken = '<!--{$csrf_token|h}-->';

    if (!addBtn) { return; }

    addBtn.addEventListener('click', function() {
        if (location.protocol !== 'https:' || typeof window.PublicKeyCredential === 'undefined') {
            alert('お使いのブラウザはパスキー認証に対応していません。HTTPS 接続でアクセスしてください。');
            return;
        }
        modal.style.display = 'flex';
        passwordInput.value = '';
        errorMsg.style.display = 'none';
        passwordInput.focus();
    });

    cancelBtn.addEventListener('click', function() { modal.style.display = 'none'; });
    modal.addEventListener('click', function(e) { if (e.target === modal) { modal.style.display = 'none'; } });
    passwordInput.addEventListener('keypress', function(e) { if (e.key === 'Enter') { run(); } });
    confirmBtn.addEventListener('click', run);

    function run() {
        var password = passwordInput.value;
        if (!password) { return; }
        confirmBtn.disabled = true;
        errorMsg.style.display = 'none';

        // Step 1: パスワード確認 → b2b_subject 取得
        fetch('<!--{$smarty.const.HTTPS_URL}--><!--{$smarty.const.ADMIN_DIR}-->ecauth/api/verify-password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ password: password })
        })
        .then(async function(res) {
            // ステータス別にエラーを区別
            if (res.status === 401) {
                throw new Error('invalid_password');
            }
            if (res.status === 403) {
                throw new Error('csrf_token_invalid');
            }
            if (!res.ok) {
                // 500 等。EC-CUBE が CSRF 失敗時に HTML を返してくる場合もこちらに該当
                throw new Error('server_error');
            }
            return res.json();
        })
        .then(function(data) {
            modal.style.display = 'none';
            // Step 2: 登録オプション取得 → WebAuthn → 検証（@ecauth/auth-js 非依存の自前実装）
            return doPasskeyRegister(data.b2b_subject);
        })
        .then(function() {
            alert('パスキーを登録しました。');
            window.location.reload();
        })
        .catch(function(error) {
            confirmBtn.disabled = false;
            if (!error) { return; }
            if (error.message === 'invalid_password') {
                errorMsg.textContent = 'パスワードが正しくありません。';
                errorMsg.style.display = 'block';
                return;
            }
            if (error.message === 'csrf_token_invalid' || error.message === 'server_error') {
                errorMsg.textContent = 'セッションの有効期限が切れました。ページを再読み込みしてやり直してください。';
                errorMsg.style.display = 'block';
                return;
            }
            if (error.name === 'NotAllowedError') {
                // ユーザーがプロンプトをキャンセルした、または認証器がタイムアウトした
                return;
            }
            if (error.name === 'InvalidStateError') {
                alert('この認証器はすでに登録されています。別のパスキーを使用してください。');
                return;
            }
            console.error('Passkey registration error:', error, error.detail || '');
            var detail = '';
            if (error.detail && error.detail.ecauth_response) {
                var ec = error.detail.ecauth_response;
                if (ec.error_description) {
                    detail = ec.error_description;
                } else if (ec.errors) {
                    detail = JSON.stringify(ec.errors);
                } else if (ec.title) {
                    detail = ec.title;
                }
            }
            alert('パスキーの登録に失敗しました。' + (detail ? '\n' + detail : ''));
        });
    }
})();
</script>
