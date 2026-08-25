/*
 * 管理画面のパスワード認証を無効化した状態の E2E。
 *
 * 前提条件:
 *   - ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN=1 を渡して docker compose up していること
 *     （docker-entrypoint.sh が data/config/config.php へ define を追記する）
 *   - EcAuth テナントは不要。ここで検証するのは「パスワード認証を止められているか」
 *     だけで、パスキーの成立自体は admin-passkey-flow.spec.ts が担保する。
 *     そのため 1Password のシークレットが無い fork PR でも実行できる。
 *
 * 無効化されていない環境で走らせると全て失敗するため、明示的にスキップする。
 */

import { test, expect } from '@playwright/test';

const ADMIN_LOGIN_ID = process.env.ECCUBE_ADMIN_LOGIN_ID || 'admin';
const ADMIN_PASSWORD = process.env.ECCUBE_ADMIN_PASSWORD || 'password';
// 形式は前後にスラッシュ付き（例: '/admin/' または '/admin_a1b2c3d4/'）。
const ADMIN_BASE = process.env.ECCUBE_ADMIN_BASE || '/admin/';

const NOTICE = '#ecauth-password-login-disabled';
const PASSKEY_BUTTON = '#ecauth-passkey-login';

test.describe('E2E: 管理画面のパスワード認証を無効化した状態', () => {
  test.skip(
    process.env.E2E_ADMIN_PASSWORD_LOGIN_DISABLED !== '1',
    'ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN が有効な環境でのみ実行する',
  );

  test('ログイン画面に ID / パスワード欄が表示されない', async ({ page }) => {
    await page.goto(ADMIN_BASE);

    // 要素は DOM に残す設計（本体 login.tpl 末尾の
    // document.form1.login_id.focus() が動かなくなるため）。
    // 「消えていること」ではなく「見えていないこと」を検証する。
    await expect(page.locator('input[name="login_id"]')).toBeHidden();
    await expect(page.locator('input[name="password"]')).toBeHidden();
    await expect(page.locator('a.btn-tool-format', { hasText: 'LOGIN' })).toBeHidden();
  });

  test('無効化されている旨の案内が表示される', async ({ page }) => {
    await page.goto(ADMIN_BASE);

    await expect(page.locator(NOTICE)).toBeVisible();
    await expect(page.locator(NOTICE)).toContainText('パスワードでのログインを受け付けていません');
  });

  test('パスキーでログインするボタンは表示される', async ({ page }) => {
    await page.goto(ADMIN_BASE);

    // 締め出しの案内だけ出してログイン手段が無い、という状態にしない。
    await expect(page.locator(PASSKEY_BUTTON)).toBeVisible();
  });

  test('正しい ID とパスワードを送信してもログインできない', async ({ page }) => {
    await page.goto(ADMIN_BASE);

    // フォームは隠れているので、古いタブや直接 POST を模して
    // JS で値を入れて submit する。認証情報自体は正しいものを使う
    // （「無効化しているから落ちた」のか「単に間違えた」のかを切り分けるため）。
    await page.evaluate(
      ({ loginId, password }) => {
        const form = document.querySelector<HTMLFormElement>('form[name="form1"]');
        if (!form) { throw new Error('login form not found'); }
        (form.querySelector('input[name="login_id"]') as HTMLInputElement).value = loginId;
        (form.querySelector('input[name="password"]') as HTMLInputElement).value = password;
        form.submit();
      },
      { loginId: ADMIN_LOGIN_ID, password: ADMIN_PASSWORD },
    );
    await page.waitForLoadState('load');

    // 管理画面ホームへ入れていないこと。
    expect(page.url()).not.toContain('home.php');
    // ログイン画面に留まり、理由が示されていること。
    await expect(page.locator(NOTICE)).toBeVisible();
    await expect(page.locator(NOTICE)).toContainText('確認していません');

    // 直接ホームを開いても弾かれること（セッションが張られていない証明）。
    // 未認証だと本体が LC_Page_Error_DispError（ACCESS_ERROR）を返すため、
    // ログイン画面ではなくエラー画面になる。ログイン済みなら必ず出る
    // ログアウト導線の有無で判定する。
    await page.goto(`${ADMIN_BASE}home.php`);
    await expect(page.locator('a[href*="logout.php"]')).toHaveCount(0);
  });

  test('フロントの会員ログインは影響を受けない', async ({ page }) => {
    // 無効化するのは管理画面だけ。EC サイトの会員ログインを巻き込むと
    // 店舗が営業できなくなる。
    await page.goto('/mypage/login.php');

    // フロントのログインページは、ヘッダのログインブロックとマイページの
    // ログインフォームの両方に同名の入力欄を持つ。どちらでもよいので first() で取る。
    await expect(page.locator('input[name="login_email"]').first()).toBeVisible();
    await expect(page.locator('input[name="login_pass"]').first()).toBeVisible();
    await expect(page.locator(NOTICE)).toHaveCount(0);
  });
});
