# ec-cube2-ecauth

EC-CUBE 2系向け EcAuth ソーシャルログインプラグイン

## 概要

EcAuth IdP と連携し、ソーシャルログイン機能を EC-CUBE 2系サイトに追加するプラグインです。

## 対応バージョン

- EC-CUBE 2.13, 2.17, 2.25
- PHP 5.6+ / 7.x / 8.x

## インストール

### プラグインマネージャーからインストール

1. [Releases](../../releases) から `EcAuthLogin2-vX.X.X.tar.gz` をダウンロード
2. EC-CUBE 管理画面 > オーナーズストア > プラグイン管理 > プラグインのアップロード
3. プラグインを有効化

### 開発環境

```bash
# リポジトリをクローン
git clone https://github.com/EcAuth/ec-cube2-ecauth.git
cd ec-cube2-ecauth

# 依存関係インストール
composer install

# Docker 環境起動（EC-CUBE2 + プラグイン自動インストール）
docker compose up -d
```

## 設定

プラグイン有効化後、以下の設定が必要です：

- Client ID
- Client Secret
- Authorization Endpoint
- Token Endpoint
- UserInfo Endpoint
- Provider Name

## ライセンス

LGPL-2.1-or-later
