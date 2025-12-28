# 1Password テンプレートファイル
# 使用方法: op inject -i .env.tpl -o .env

# EcAuth クライアント設定
ECAUTH_CLIENT_ID=op://EcAuth/eccube2-ecauth-plugin/client_id
ECAUTH_CLIENT_SECRET=op://EcAuth/eccube2-ecauth-plugin/client_secret

# EcAuth エンドポイント（ステージング環境）
ECAUTH_AUTHORIZATION_ENDPOINT=op://EcAuth/eccube2-ecauth-plugin/authorization_endpoint
ECAUTH_TOKEN_ENDPOINT=op://EcAuth/eccube2-ecauth-plugin/token_endpoint
ECAUTH_USERINFO_ENDPOINT=op://EcAuth/eccube2-ecauth-plugin/userinfo_endpoint
ECAUTH_EXTERNAL_USERINFO_ENDPOINT=op://EcAuth/eccube2-ecauth-plugin/external_userinfo_endpoint

# 外部 IdP プロバイダー名
ECAUTH_PROVIDER_NAME=op://EcAuth/eccube2-ecauth-plugin/provider_name
