<!--{*
 * EcAuth ログインボタンテンプレート
 *
 * 変数:
 *   $ecauth_auth_url - 認可URL
 *   $ecauth_provider_name - プロバイダー名
 *}-->
<div class="ecauth-login-button" style="margin-top: 15px; text-align: center;">
    <a href="<!--{$ecauth_auth_url|h}-->" class="btn btn-primary" style="background-color: #4285f4; border-color: #4285f4; color: #fff; padding: 10px 20px; text-decoration: none; display: inline-block; border-radius: 4px;">
        <!--{$ecauth_provider_name|h|default:'EcAuth'}--> でログイン
    </a>
</div>
