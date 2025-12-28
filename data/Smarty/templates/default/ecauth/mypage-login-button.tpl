<!--{*
 * マイページ用 EcAuth ログインボタンテンプレート
 *
 * 変数:
 *   $ecauth_auth_url - 認可URL
 *   $ecauth_provider_name - プロバイダー名
 *   $ecauth_error - エラーメッセージ（オプション）
 *}-->
<!--{if $ecauth_error}-->
<div class="ecauth-error" style="color: #dc3545; margin-bottom: 10px; padding: 10px; background-color: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;">
    <!--{$ecauth_error|h}-->
</div>
<!--{/if}-->

<div class="ecauth-login-section" style="margin: 20px 0; padding: 20px; background-color: #f8f9fa; border-radius: 8px; text-align: center;">
    <p style="margin-bottom: 15px; color: #666;">または、ソーシャルアカウントでログイン</p>
    <a href="<!--{$ecauth_auth_url|h}-->" class="btn btn-lg" style="background-color: #4285f4; border-color: #4285f4; color: #fff; padding: 12px 30px; text-decoration: none; display: inline-block; border-radius: 4px; font-size: 16px;">
        <span style="margin-right: 8px;">&#x1F511;</span>
        <!--{$ecauth_provider_name|h|default:'EcAuth'}--> でログイン
    </a>
</div>
