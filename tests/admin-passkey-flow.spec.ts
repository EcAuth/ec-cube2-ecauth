/*
 * EcAuthLogin2 B2B パスキー登録〜ログインの E2E。
 *
 * 前提条件:
 *   - プラグインがインストール・有効化済み
 *   - 設定画面で client_id / client_secret / ecauth_base_url が保存済み
 *   - HTTPS 接続でアクセス可能（ECCUBE_BASE_URL は https:// を指す）
 *
 * 注意:
 *   - WebAuthn は HTTPS 必須のため、playwright.config.ts の baseURL は
 *     https://localhost:24430 を使う。CI では ECCUBE_BASE_URL=https://localhost:24430 を渡す。
 *   - virtualauthenticator は CDP の WebAuthn ドメインを使ってシミュレートする。
 */

import { test, expect } from '@playwright/test';

const ADMIN_BASE = process.env.ECCUBE_ADMIN_BASE || '/admin/';
const ADMIN_LOGIN_ID = process.env.ECCUBE_ADMIN_LOGIN_ID || 'admin';
const ADMIN_PASSWORD = process.env.ECCUBE_ADMIN_PASSWORD || 'password';

test.describe('B2B パスキーフロー', () => {
  test.skip(
    !process.env.ECAUTH_E2E_ENABLED,
    'ECAUTH_E2E_ENABLED=1 を設定し、設定済みプラグインがある環境で実行してください',
  );

  test('パスキー登録 → ログアウト → パスキーログイン', async ({ page, context }) => {
    // 仮想認証器をセットアップ
    const cdp = await context.newCDPSession(page);
    await cdp.send('WebAuthn.enable');
    const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
      options: {
        protocol: 'ctap2',
        transport: 'internal',
        hasResidentKey: true,
        hasUserVerification: true,
        isUserVerified: true,
      },
    });

    try {
      // 管理画面ログイン
      await page.goto(`${ADMIN_BASE}`);
      await page.fill('input[name="login_id"]', ADMIN_LOGIN_ID);
      await page.fill('input[name="password"]', ADMIN_PASSWORD);
      await page.click('input[type="submit"], button[type="submit"]');
      await page.waitForURL(/\/admin\/(home\.php|index\.php|$)/);

      // パスキー管理画面へ
      await page.goto(`${ADMIN_BASE}ecauth/passkey.php`);

      // 「パスキーを追加」
      page.once('dialog', (d) => d.accept());
      await page.click('#ecauth-passkey-add');

      // パスワード再認証モーダル
      await page.fill('#ecauth-password-input', ADMIN_PASSWORD);
      await page.click('#ecauth-password-confirm');

      // WebAuthn register が実行され、登録完了アラートが出る
      page.once('dialog', (d) => {
        expect(d.message()).toContain('パスキーを登録');
        return d.accept();
      });
      await page.waitForURL(/\/admin\/ecauth\/passkey\.php/);

      // 一覧に1件以上のパスキーが表示される
      await expect(page.locator('table.list tbody tr')).toHaveCount(1);

      // ログアウト
      await page.goto(`${ADMIN_BASE}logout.php`);
      await page.waitForURL(/\/admin\//);

      // ログイン画面で「パスキーでログイン」
      await page.goto(`${ADMIN_BASE}`);
      const passkeyButton = page.locator('#ecauth-passkey-login');
      await expect(passkeyButton).toBeVisible();
      await passkeyButton.click();

      // ログイン後ホームに遷移
      await page.waitForURL(/\/admin\/(home\.php|index\.php|$)/, { timeout: 15000 });
    } finally {
      await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
    }
  });
});
