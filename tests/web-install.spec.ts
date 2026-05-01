/*
 * EcAuthLogin2 プラグインの Web インストール経路 E2E スモークテスト。
 *
 * 目的:
 *   オーナーズストア UI 経由で tar.gz を投入し、playwright.yml（パスキー機能 E2E）の
 *   スタートラインに立てる状態（プラグイン有効化済み + 設定画面到達可能 +
 *   パスキー UI 到達可能 + フロントエントリポイント配置済み）になることを確認する。
 *   パスキー登録〜ログインまでの機能テストは admin-passkey-flow.spec.ts に分離。
 *
 * 前提条件:
 *   - SKIP_PLUGIN_INSTALL=true で起動された素の EC-CUBE 2 環境
 *     （プラグインが docker-entrypoint.sh で自動インストールされていない状態）
 *   - tools/build-archive.sh でビルド済みの dist/EcAuthLogin2-*.tar.gz が存在
 *   - ADMIN_DIR がランダム化されている可能性があるため ECCUBE_ADMIN_BASE 環境変数で受ける
 *
 * 検証フロー:
 *   1. 管理画面ログイン
 *   2. オーナーズストアでプラグインアップロード（install() 関数経由 = mode=install POST）
 *   3. プラグイン一覧に EcAuthLogin2 が表示
 *   4. 「有効にする」 checkbox で有効化（mode=enable POST）
 *   5. 設定画面が 200 で開く
 *   6. /<ADMIN_BASE>ecauth/passkey.php が 200 で「登録済みパスキー」が表示
 *   7. /ecauth/callback.php が 302 で必要 param 不足エラーを返す
 */

import { test, expect } from '@playwright/test';
import * as path from 'path';
import * as fs from 'fs';

const ADMIN_BASE = process.env.ECCUBE_ADMIN_BASE || '/admin/';
const ADMIN_BASE_RE = ADMIN_BASE.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
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

test.describe.serial('Web インストール経路スモーク', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto(ADMIN_BASE);
    await page.fill('input[name="login_id"]', ADMIN_LOGIN_ID);
    await page.fill('input[name="password"]', ADMIN_PASSWORD);
    // EC-CUBE 2 admin の login form は submit ボタンが画面外配置のため、
    // 表示中の <a>LOGIN</a> リンク (javascript:void(0) で form.submit を呼ぶ) をクリック
    await Promise.all([
      page.waitForURL(new RegExp(`${ADMIN_BASE_RE}(home\\.php|index\\.php|$)`), { timeout: 15000 }),
      page.click('a:has-text("LOGIN")'),
    ]);
  });

  test('オーナーズストア UI 経由で tar.gz をアップロードしプラグインを有効化する', async ({ page }) => {
    test.setTimeout(60000);
    const archive = findArchive();

    // オーナーズストア「プラグイン管理」ページ
    await page.goto(`${ADMIN_BASE}ownersstore/`);
    await expect(page.locator('h2', { hasText: 'プラグイン登録' })).toBeVisible();

    // 画面に表示されるのは plugin_name（"EcAuth Login (パスキー / ソーシャルログイン)"）であり
    // plugin_code（"EcAuthLogin2"）は本文には現れないため "EcAuth Login" で検出する。
    const pluginNameMarker = 'EcAuth Login';

    // 前回の試行で既にインストール済みなら（Playwright のリトライ時など）
    // tar.gz アップロードはスキップする。失敗時に再現性が保たれる。
    const alreadyInstalled = await page
      .locator('body')
      .filter({ hasText: pluginNameMarker })
      .count();

    if (alreadyInstalled === 0) {
      // プラグインファイルをセット
      await page.locator('input[type="file"][name="plugin_file"]').setInputFiles(archive);

      // <a onclick="install()"> がクリックされると confirm 後 mode=install で submit
      page.once('dialog', (dialog) => {
        expect(dialog.message()).toContain('プラグインをインストール');
        dialog.accept().catch(() => {});
      });
      await Promise.all([
        page.waitForLoadState('networkidle', { timeout: 30000 }),
        page.locator('a.btn-action:has-text("インストール")').click(),
      ]);

      // プラグイン一覧に EcAuth Login が現れる
      await expect(page.locator('body')).toContainText(pluginNameMarker, { timeout: 15000 });
    }

    // 有効化処理: name="enable" checkbox があれば有効化、name="disable" なら既に有効状態
    const enableCheckbox = page.locator('input[type="checkbox"][name="enable"]').first();
    const isAlreadyEnabled = (await page.locator('input[type="checkbox"][name="disable"]').count()) > 0;
    if (!isAlreadyEnabled) {
      await expect(enableCheckbox).toBeVisible();
      page.once('dialog', (dialog) => {
        expect(dialog.message()).toContain('プラグインを有効');
        dialog.accept().catch(() => {});
      });
      await Promise.all([
        page.waitForLoadState('networkidle', { timeout: 30000 }),
        enableCheckbox.click(),
      ]);
    }

    // 有効化後（または既に有効）は「プラグイン設定」リンクが現れる
    await expect(page.locator('a:has-text("プラグイン設定")')).toBeVisible({ timeout: 10000 });
  });

  test('スモーク: プラグイン設定画面が開く', async ({ page }) => {
    // EC-CUBE 2 の「プラグイン設定」リンクは eccube.openWindow() で popup を開く
    // ため、元の page を click() しても遷移しない。直接 load_plugin_config.php に
    // goto して設定画面の到達性を検証する。
    // plugin_id は dtb_plugin の値（admin-passkey-flow.spec.ts と同じデフォルト 10000）。
    const pluginId = process.env.ECCUBE_PLUGIN_ID || '10000';
    await page.goto(`${ADMIN_BASE}load_plugin_config.php?plugin_id=${pluginId}`);

    // 設定画面のタイトルとフォーム要素が表示される = LC_Page クラスのオートロード成功
    // + Smarty テンプレートのコピー成功 + dtb_plugin 登録成功 を間接的に保証
    await expect(page.locator('body')).toContainText('EcAuth Login プラグイン設定');
    await expect(page.locator('input[name="client_id"]')).toBeVisible();
    await expect(page.locator('input[name="client_secret"]')).toBeVisible();
  });

  test('スモーク: 管理画面パスキー登録 UI が開く', async ({ page }) => {
    // /<ADMIN_BASE>ecauth/passkey.php に直接遷移
    // = html/admin/ecauth/passkey.php が Web インストール経路で正しく配置されたこと
    // + LC_Page_Admin_EcAuthLogin2_Passkey の autoload が機能すること
    await page.goto(`${ADMIN_BASE}ecauth/passkey.php`);
    await expect(page.locator('span', { hasText: '登録済みパスキー' })).toBeVisible();
  });

  test('スモーク: フロントの ecauth/callback.php が必要 param 不足で正しくリダイレクトする', async ({ page }) => {
    // /ecauth/callback.php (ADMIN_DIR 配下ではなく、HTML ルート直下のフロントエントリポイント)
    // = html/ecauth/callback.php が Web インストール経路で正しく配置されたこと
    // + LC_Page_EcAuthLogin2_Callback の autoload が機能すること
    // パラメータなしでアクセスすると invalid_request → mypage/login.php にリダイレクト
    const response = await page.goto('/ecauth/callback.php');
    // sendRedirect の挙動上、Playwright は最終遷移先の URL を取得する
    expect(page.url()).toMatch(/\/mypage\/login\.php/);
    // status は最終ページの 200（マイページログイン画面）でよい。リダイレクトされたことが本質。
    expect(response?.status()).toBeLessThan(500);
  });
});
