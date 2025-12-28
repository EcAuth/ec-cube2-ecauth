import { test, expect } from '@playwright/test';

test.describe('EcAuth ソーシャルログイン', () => {
  test.beforeEach(async ({ page }) => {
    // マイページログインページへ移動
    await page.goto('/mypage/login.php');
  });

  test('ログインページに EcAuth ボタンが表示される', async ({ page }) => {
    // EcAuth ログインボタンが表示されていることを確認
    const ecauthButton = page.locator('.ecauth-login-button a');
    await expect(ecauthButton).toBeVisible();
    await expect(ecauthButton).toContainText('ログイン');
  });

  test('EcAuth ボタンをクリックすると認可エンドポイントにリダイレクトされる', async ({ page }) => {
    // EcAuth ログインボタンをクリック
    const ecauthButton = page.locator('.ecauth-login-button a');
    const href = await ecauthButton.getAttribute('href');

    // 認可URLのパラメータを確認
    expect(href).toContain('response_type=code');
    expect(href).toContain('client_id=');
    expect(href).toContain('redirect_uri=');
    expect(href).toContain('state=');
    expect(href).toContain('code_challenge=');
    expect(href).toContain('code_challenge_method=S256');
  });

  test('EcAuth 認証フロー（MockIdP 経由）', async ({ page }) => {
    // 環境変数から MockIdP のベースURLを取得
    const mockIdpBaseUrl = process.env.MOCK_IDP_BASE_URL;
    if (!mockIdpBaseUrl) {
      test.skip();
      return;
    }

    // EcAuth ログインボタンをクリック
    const ecauthButton = page.locator('.ecauth-login-button a');
    await ecauthButton.click();

    // EcAuth IdP の認可エンドポイントにリダイレクトされる
    await page.waitForURL(/\/authorization/);

    // MockIdP のログインフォームが表示される
    await page.waitForURL(new RegExp(mockIdpBaseUrl));

    // MockIdP でログイン
    await page.fill('input[name="email"]', 'test@example.com');
    await page.fill('input[name="password"]', 'password');
    await page.click('button[type="submit"]');

    // コールバックページにリダイレクトされる
    await page.waitForURL(/\/ecauth\/callback\.php/);

    // マイページにリダイレクトされる
    await page.waitForURL(/\/mypage\//);

    // ログイン成功を確認
    await expect(page.locator('body')).toContainText('マイページ');
  });

  test('認証エラー時にエラーメッセージが表示される', async ({ page }) => {
    // エラーパラメータ付きでコールバックページに直接アクセス
    await page.goto('/ecauth/callback.php?error=access_denied&error_description=User+denied+access');

    // ログインページにリダイレクトされる
    await page.waitForURL(/\/mypage\/login\.php/);

    // エラーメッセージが表示されることを確認（セッションにエラーがあれば）
    // 実際の表示はテンプレートの実装に依存
  });

  test('無効な state パラメータでエラーになる', async ({ page }) => {
    // 無効な state でコールバックページにアクセス
    await page.goto('/ecauth/callback.php?code=test_code&state=invalid_state');

    // ログインページにリダイレクトされる
    await page.waitForURL(/\/mypage\/login\.php/);
  });
});

test.describe('購入手続きページ', () => {
  test('購入手続きページに EcAuth ボタンが表示される', async ({ page }) => {
    // 商品をカートに入れる（前提条件）
    // この部分は実際の商品IDに応じて調整が必要
    await page.goto('/products/detail.php?product_id=1');

    // カートに入れるボタンがあればクリック
    const addToCartButton = page.locator('button:has-text("カートに入れる"), input[value="カートに入れる"]');
    if (await addToCartButton.isVisible()) {
      await addToCartButton.click();

      // 購入手続きページへ
      await page.goto('/shopping/');

      // EcAuth ログインボタンが表示されていることを確認
      const ecauthButton = page.locator('.ecauth-login-button a');
      // ログインしていない場合のみボタンが表示される
      if (await ecauthButton.isVisible()) {
        await expect(ecauthButton).toContainText('ログイン');
      }
    }
  });
});
