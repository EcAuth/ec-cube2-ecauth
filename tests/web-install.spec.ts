/*
 * EcAuthLogin2 プラグインの Web インストール経路 E2E テスト。
 *
 * 前提条件:
 *   - SKIP_PLUGIN_INSTALL=true で起動された素の EC-CUBE 2 環境
 *     （プラグインが docker-entrypoint.sh で自動インストールされていない状態）
 *   - tools/build-archive.sh でビルド済みの dist/EcAuthLogin2-*.tar.gz が存在
 *
 * 検証内容:
 *   1. 管理画面ログイン
 *   2. オーナーズストア > プラグインを追加する 経由で tar.gz をアップロード
 *   3. プラグイン一覧に EcAuthLogin2 が現れる
 *   4. プラグインを有効化
 *   5. 「設定」リンクから設定画面に遷移できる
 *
 * パスキー登録〜ログインまでの完全な E2E は admin-passkey-flow.spec.ts に切り出す。
 */

import { test, expect } from '@playwright/test';
import * as path from 'path';
import * as fs from 'fs';

const ADMIN_BASE = process.env.ECCUBE_ADMIN_BASE || '/admin/';
const ADMIN_LOGIN_ID = process.env.ECCUBE_ADMIN_LOGIN_ID || 'admin';
const ADMIN_PASSWORD = process.env.ECCUBE_ADMIN_PASSWORD || 'password';

function findArchive(): string {
  const distDir = path.resolve(__dirname, '..', 'dist');
  if (!fs.existsSync(distDir)) {
    throw new Error(`dist/ does not exist; run ./tools/build-archive.sh first`);
  }
  const candidates = fs
    .readdirSync(distDir)
    .filter((f) => /^EcAuthLogin2-.+\.tar\.gz$/.test(f))
    .map((f) => path.join(distDir, f));
  if (candidates.length === 0) {
    throw new Error(`No EcAuthLogin2-*.tar.gz found in ${distDir}; run ./tools/build-archive.sh`);
  }
  // 最新を選ぶ
  candidates.sort((a, b) => fs.statSync(b).mtimeMs - fs.statSync(a).mtimeMs);
  return candidates[0];
}

test.describe('Web インストール経路', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto(`${ADMIN_BASE}`);
    await page.fill('input[name="login_id"]', ADMIN_LOGIN_ID);
    await page.fill('input[name="password"]', ADMIN_PASSWORD);
    await page.click('input[type="submit"], button[type="submit"]');
    await page.waitForURL(/\/admin\/(home\.php|index\.php|$)/);
  });

  test('tar.gz アップロード → 有効化 → 設定リンク', async ({ page }) => {
    const archive = findArchive();

    // オーナーズストア > プラグイン管理
    await page.goto(`${ADMIN_BASE}ownersstore/index.php`);

    // 「プラグインを追加する」ボタン or タブをクリック
    const addLink = page.locator('a:has-text("プラグインを追加")');
    if (await addLink.isVisible()) {
      await addLink.click();
    } else {
      // 直接アップロードページへ
      await page.goto(`${ADMIN_BASE}ownersstore/upload.php`);
    }

    // ファイル input にアーカイブをセット
    const fileInput = page.locator('input[type="file"]').first();
    await fileInput.setInputFiles(archive);

    // アップロード送信
    await page.click('button[type="submit"], input[type="submit"]:has-text("アップロード"), input[type="submit"]:has-text("追加")');

    // プラグイン一覧に戻り、EcAuthLogin2 が現れる
    await page.goto(`${ADMIN_BASE}ownersstore/index.php`);
    await expect(page.locator('body')).toContainText('EcAuthLogin2');

    // 有効化
    const enableButton = page.locator('a:has-text("有効"), button:has-text("有効")').first();
    if (await enableButton.isVisible()) {
      await enableButton.click();
      // 確認ダイアログ等があれば
      page.once('dialog', (d) => d.accept());
    }

    // 「設定」リンクが現れる
    const configLink = page.locator('a:has-text("設定")').first();
    await expect(configLink).toBeVisible();

    // 設定画面に遷移
    await configLink.click();
    await expect(page.locator('body')).toContainText('EcAuth Login プラグイン設定');
  });

  test('設定画面で client_id / client_secret を保存できる', async ({ page }) => {
    // 設定画面に直接遷移（前テストでインストール済み前提）
    await page.goto('/plugin/EcAuthLogin2/config.php');
    await expect(page.locator('body')).toContainText('EcAuth Login プラグイン設定');

    await page.fill('input[name="client_id"]', 'test_client_id_e2e');
    await page.fill('input[name="client_secret"]', 'test_client_secret_e2e');
    await page.fill('input[name="ecauth_base_url"]', 'https://localhost:9091');
    await page.click('button:has-text("登録")');

    // ページ再読み込み後、client_id が保存されている
    await page.goto('/plugin/EcAuthLogin2/config.php');
    await expect(page.locator('input[name="client_id"]')).toHaveValue('test_client_id_e2e');
    // client_secret は表示しないが has_client_secret = true で placeholder が出る
    const placeholder = await page.locator('input[name="client_secret"]').getAttribute('placeholder');
    expect(placeholder ?? '').toContain('保存済み');
  });
});
