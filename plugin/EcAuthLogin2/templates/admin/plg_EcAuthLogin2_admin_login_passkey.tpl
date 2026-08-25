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
 *
 * 値は %%...%% プレースホルダを PHP 側で置換して埋める。置換する場所が
 * 2 つあるので注意すること。
 *  - URL 系 (%%ECAUTH_*_URL%%): prefilterTransform が置換する。
 *    サイト設定から決まる静的な値なのでコンパイル時に焼き込んでよい。
 *  - 状態系 (%%ECAUTH_PASSWORD_LOGIN_*%%): outputfilterTransform が置換する。
 *    prefilterTransform はコンパイル時にしか走らないため、ここで焼き込むと
 *    templates_c が残る限り古い状態が表示され続ける。
 *}-->
<script src="https://cdn.ec-auth.io/auth-js/0.1.3/ecauth-auth.umd.js"></script>
<script>
(function () {
    // EcAuthLogin2::outputfilterTransform() が毎リクエスト置換する。
    // templates_c にコンパイル結果が残っていても常に「今の設定」が反映される。
    // config.php の定数を消してパスワードログインへ戻す緊急復旧で、
    // フォームが隠れたままにならないために重要。
    //
    // 置換されなかった場合（このフックが未登録の古いインストール等）、
    // 比較は false になり「無効化されていない」と扱われる（UI はフェイルオープン）。
    // 実際の拒否はサーバー側のフックが独立して行うので、認証は緩まない。
    var PASSWORD_LOGIN_DISABLED = ('%%ECAUTH_PASSWORD_LOGIN_DISABLED%%' === '1');
    var PASSWORD_LOGIN_REJECTED = ('%%ECAUTH_PASSWORD_LOGIN_REJECTED%%' === '1');

    var NO_PASSKEY_HINT = 'このアカウントで初めてパスキーを使う場合は、'
        + 'パスワードでログインしてから「基本情報管理 → パスキー管理」で'
        + 'パスキーを登録してください。';

    function canUsePasskey() {
        return location.protocol === 'https:' && typeof window.PublicKeyCredential !== 'undefined';
    }

    function findForm() {
        return document.querySelector('form[name="form1"]') || document.querySelector('form');
    }

    function findSubmit(form) {
        return form.querySelector('a.btn-tool-format, a[onclick*="submit"], a.btn-login, a[href="javascript:;"]')
            || form.querySelector('input[type="submit"], button[type="submit"]');
    }

    /**
     * ID / PASSWORD 欄と LOGIN ボタンを隠す。
     *
     * 要素は remove せず display:none にとどめる。理由が 2 つある。
     *  - 本体 login.tpl の末尾が document.form1.login_id.focus() を実行しており、
     *    要素を消すと TypeError でその後のスクリプトが止まる
     *  - 古いタブや直接 POST でフォームが送られたとき、サーバー側が
     *    「パスワード認証は無効化されています」と正しく返せるように、
     *    name 属性を持つ入力欄をそのまま残しておく（disabled にもしない）
     */
    function hidePasswordLogin(form) {
        var hidden = [];

        ['login_id', 'password'].forEach(function (name) {
            var input = form.querySelector('input[name="' + name + '"]');
            if (!input) { return; }
            hidden.push(input);
            // 本体は <p><label for="..."></label></p> を入力欄の直前に置いている。
            // ラベルだけ残ると「ID」「PASSWORD」の見出しが宙に浮くので一緒に隠す。
            var label = input.previousElementSibling;
            if (label && label.tagName === 'P' && label.querySelector('label')) {
                hidden.push(label);
            }
        });

        var submit = findSubmit(form);
        if (submit) {
            hidden.push(submit.closest('p') || submit);
        }

        hidden.forEach(function (el) {
            el.style.display = 'none';
        });
    }

    /**
     * 無効化時の案内を出す。
     *
     * ログイン画面は本体テンプレートで、右カラム (#input-form) は紺の背景に
     * 白文字という配色。ここに警告色の箱を置くと「異常が起きている」ように見えるが、
     * 無効化は運営者が意図して選んだ通常状態なので、周囲と同じ配色のまま
     * 「見出し + 説明」として静かに置く。
     *
     * 色は <p> に直接書く。本体 admin.css が
     *   #input-form { p { margin-top: 10px; color: #fff; font-size: 100%; } }
     * と <p> を要素セレクタで直接指定しているため、ラッパー側の指定は継承されない。
     */
    function insertNotice(form) {
        if (document.getElementById('ecauth-password-login-disabled')) { return; }

        var notice = document.createElement('div');
        notice.id = 'ecauth-password-login-disabled';
        notice.style.cssText = 'margin: 0 0 16px; text-align: left;';

        var heading = document.createElement('p');
        heading.style.cssText = 'margin: 0 0 6px; color: #fff; font-size: 108%; font-weight: bold;'
            + ' line-height: 1.4;';
        // ボタンの文言と重ねない。見出しは「ここは何の画面か」、ボタンは「何をするか」。
        heading.textContent = '管理画面ログイン';
        notice.appendChild(heading);

        var messages = ['このサイトの管理画面は、パスワードでのログインを受け付けていません。'];
        if (PASSWORD_LOGIN_REJECTED) {
            // フォームは隠してあるので通常は起きない。古いタブからの再送や
            // 直接 POST でここに来たとき、無言で戻されたように見えないようにする。
            messages.push('送信された ID とパスワードは確認していません。');
        }
        if (!canUsePasskey()) {
            messages.push('このブラウザではパスキーを利用できません。HTTPS でアクセスしているかご確認ください。');
        }

        messages.forEach(function (message) {
            var p = document.createElement('p');
            // 白 (#fff) だと見出しと同じ強さになるので、説明文は少し落として階層を付ける。
            p.style.cssText = 'margin: 0 0 4px; color: #d3d5e0; font-size: 92%; line-height: 1.6;';
            p.textContent = message;
            notice.appendChild(p);
        });

        form.insertBefore(notice, form.firstChild);
    }

    /**
     * 右カラムをロゴと縦中央で揃える。
     *
     * 本体は #input-form { margin-top: 40px; float: right; } で、ID / PASSWORD の
     * 2 段フォームが左のロゴと釣り合う前提の上寄せ。フォームを隠して中身が短くなると
     * 上に張り付いて見えるので、中身の実高さを測ってロゴの中央に来る margin-top を出す。
     *
     * ロゴ側は本体 CSS の固定値から求める（#login-form h1: margin-top 46px、
     * height 150px、padding-bottom 40px）。offsetTop は offsetParent の取り方で
     * 基準がずれるため使わない。右カラム側だけ実測する。
     */
    function alignWithLogo(form) {
        var column = form.closest('#input-form');
        if (!column) { return; }

        // 揃える相手は h1 の箱全体ではなく画像そのもの (150px)。padding-bottom 40px は
        // 画像の下に空く余白で、ここまで含めて中央を取ると右カラムが視覚的に
        // 下がって見える。
        var logoTop = 46;
        var logoHeight = 150;
        var columnHeight = column.getBoundingClientRect().height;
        var offset = logoTop + (logoHeight - columnHeight) / 2;
        // 中身が高すぎて負になる場合は本体の既定 (40px) のままにする。
        if (offset > 40) {
            column.style.marginTop = Math.round(offset) + 'px';
        }
    }

    function injectButton(form) {
        if (!canUsePasskey()) { return; }
        // ボタンが既にある場合は再挿入しない
        if (document.getElementById('ecauth-passkey-login')) { return; }

        // EC-CUBE 2 管理画面ログインの form 配下、LOGIN リンク or submit ボタンを探す。
        // 本体 (admin/login.tpl) の LOGIN は
        //   <p><a class="btn-tool-format" href="javascript:;" onclick="...submit()"><span>LOGIN</span></a></p>
        // なので btn-tool-format を最優先で拾う。
        var anchor = findSubmit(form);
        if (!anchor) { return; }

        // 見た目は本体の LOGIN ボタンに完全に合わせる。独自の色・サイズを
        // 直書きすると EC-CUBE のバージョンやテーマ変更に追従できず、
        // 管理画面だけ浮いたボタンになる (実際 1.0.0 がその状態だった)。
        // 同じ <p><a class="btn-tool-format"><span> 構造を複製し、
        // スタイル指定は本体 CSS (admin.css の .btn-tool-format) に委ねる。
        //
        // 初回利用者向けの案内はボタンの title（ツールチップ）で出す。
        // ログイン画面は本体テンプレートなので、常設の案内文を差し込むと
        // レイアウトに手を入れることになり、本体の変更に追従しづらい。
        var passkeyBtn = document.createElement('a');
        passkeyBtn.id = 'ecauth-passkey-login';
        passkeyBtn.className = anchor.className || 'btn-tool-format';
        passkeyBtn.href = 'javascript:;';
        passkeyBtn.setAttribute('role', 'button');
        passkeyBtn.title = NO_PASSKEY_HINT;
        var passkeyLabel = document.createElement('span');
        passkeyLabel.textContent = 'パスキーでログイン';
        passkeyBtn.appendChild(passkeyLabel);

        // パスワード認証が無効なときはこれが唯一のログイン手段なので、
        // LOGIN と並ぶ補助ボタンではなく主操作として見せる。本体の
        // btn-tool-format の見た目（枠・グラデーション・角丸）はそのまま使い、
        // 幅と高さだけ広げる。色を変えないのは、本体テーマの変更に追従するため。
        if (PASSWORD_LOGIN_DISABLED) {
            passkeyBtn.style.cssText = 'display: block; box-sizing: border-box; width: 100%;'
                + ' padding: 9px 0; text-align: center; font-size: 100%;';
        }

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
                if (!error) { return; }
                // NotAllowedError は「利用者がキャンセルした」場合と
                // 「該当するパスキーが無いままタイムアウトした」場合の双方で起きる。
                // ブラウザ API 上は区別できないため、両方に当てはまる文面で案内する。
                // 黙って終了すると、パスキー未登録の管理者には手がかりが何も残らない。
                if (error.name === 'NotAllowedError') {
                    alert('パスキーが見つからないか、認証がキャンセルされました。\n\n'
                        + NO_PASSKEY_HINT);
                    return;
                }
                console.error('Passkey authentication error:', error);
                alert('パスキー認証に失敗しました。');
            });
        });
    }

    function apply() {
        var form = findForm();
        if (!form) { return; }

        // 無効化時の案内は WebAuthn が使えない環境でも出す。
        // 隠したフォームだけが残ると、何が起きたのか分からなくなるため。
        if (PASSWORD_LOGIN_DISABLED) {
            hidePasswordLogin(form);
            insertNotice(form);
            alignWithLogo(form);
        }

        injectButton(form);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', apply);
    } else {
        apply();
    }
})();
</script>
