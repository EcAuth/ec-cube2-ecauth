/*
 * EcAuthLogin2 B2B パスキー登録〜ログインの E2E。
 *
 * 前提条件:
 *   - Docker で EC-CUBE が https://localhost:8081 で起動していること (docker compose up -d)
 *   - ECAUTH_BASE_URL / CLIENT_ID / CLIENT_SECRET に ecauth-staging-app の値が設定されていること
 *   - EcAuth 側の b2b_allowed_rp_ids に `localhost` が含まれていること
 *   - EcAuth 側の redirect_uri に https://localhost:8081/ecauth/callback.php が登録されていること
 *
 * フロー:
 *   1. 管理者ログイン → プラグイン設定画面でステージング接続情報を保存
 *   2. パスキー管理画面から新規パスキー登録（ecauth_subject を JIT 生成）
 *   3. 管理画面からログアウト
 *   4. 管理ログイン画面でパスキーボタンをクリックし、コールバック経由で /admin/home.php までリダイレクトされることを検証
 */

import { test, expect, BrowserContext, Page, CDPSession } from '@playwright/test';

const ADMIN_LOGIN_ID = process.env.ECCUBE_ADMIN_LOGIN_ID || 'admin';
const ADMIN_PASSWORD = process.env.ECCUBE_ADMIN_PASSWORD || 'password';
const PLUGIN_ID = process.env.ECCUBE_PLUGIN_ID || '10000';

const ECAUTH_BASE_URL = process.env.ECAUTH_BASE_URL || '';
const CLIENT_ID = process.env.CLIENT_ID || '';
const CLIENT_SECRET = process.env.CLIENT_SECRET || '';
const RP_ID = process.env.RP_ID || 'localhost';

test.describe.serial('E2E: B2Bパスキー登録からログイン完了までのフロー', () => {
  test.skip(
    !ECAUTH_BASE_URL || !CLIENT_ID || !CLIENT_SECRET,
    'ECAUTH_BASE_URL / CLIENT_ID / CLIENT_SECRET が未設定のためスキップ（1Password: ecauth-staging-app 参照）',
  );

  let context: BrowserContext;
  let page: Page;
  let cdpSession: CDPSession;
  let authenticatorId: string;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext({ ignoreHTTPSErrors: true });

    // WebAuthn の timeout=0 をサーバから返されるケースに備えて、
    // ページ遷移後も常に有効な timeout に上書きする。
    // 併せて navigator.credentials.{create,get} の解決値/エラーを console にダンプして
    // CI で WebAuthn 側の失敗原因を可視化する（passkey スクリプト側の catch は
    // NotAllowedError を握り潰すためログに出てこない）。
    await context.addInitScript(() => {
      const originalCreate = navigator.credentials.create.bind(navigator.credentials);
      navigator.credentials.create = async (options?: CredentialCreationOptions) => {
        if (options?.publicKey && (!options.publicKey.timeout || options.publicKey.timeout === 0)) {
          options.publicKey.timeout = 60000;
        }
        try {
          const cred = await originalCreate(options);
          console.log('[E2E] credentials.create resolved: id=' + (cred as PublicKeyCredential | null)?.id);
          return cred;
        } catch (e) {
          const err = e as Error;
          console.log('[E2E] credentials.create rejected: name=' + err.name + ' message=' + err.message);
          throw e;
        }
      };
      const originalGet = navigator.credentials.get.bind(navigator.credentials);
      navigator.credentials.get = async (options?: CredentialRequestOptions) => {
        if (options?.publicKey && (!options.publicKey.timeout || options.publicKey.timeout === 0)) {
          options.publicKey.timeout = 60000;
        }
        try {
          const cred = await originalGet(options);
          console.log('[E2E] credentials.get resolved: id=' + (cred as PublicKeyCredential | null)?.id);
          return cred;
        } catch (e) {
          const err = e as Error;
          console.log('[E2E] credentials.get rejected: name=' + err.name + ' message=' + err.message);
          throw e;
        }
      };
      window.addEventListener('unhandledrejection', (e) => {
        console.log('[E2E] unhandledrejection: ' + String((e as PromiseRejectionEvent).reason));
      });
      window.addEventListener('error', (e) => {
        console.log('[E2E] window.error: ' + ((e as ErrorEvent).message || ''));
      });
    });

    page = await context.newPage();

    // ブラウザ console と pageerror を Playwright 側のログに流す。
    page.on('console', (msg) => console.log('[browser:' + msg.type() + '] ' + msg.text()));
    page.on('pageerror', (err) => console.log('[pageerror] ' + err.message));
    // EcAuth エンドポイントのレスポンス本文をダンプ（リクエスト時のタイミングで取得）。
    // 認証系レスポンスには code / id_token / WebAuthn 署名等の機微情報が含まれるため、
    // URL はクエリを除去し、body は JSON 構造を保ったまま機微キーのみ redact する。
    const SENSITIVE_KEYS = new Set([
      'code',
      'state',
      'id_token',
      'access_token',
      'refresh_token',
      'client_secret',
      'session_id',
      'challenge',
      'signature',
      'authenticatorData',
      'clientDataJSON',
      'attestationObject',
      'userHandle',
    ]);
    const redactSensitive = (value: unknown): unknown => {
      if (Array.isArray(value)) {
        return value.map((v) => redactSensitive(v));
      }
      if (value !== null && typeof value === 'object') {
        const out: Record<string, unknown> = {};
        for (const [k, v] of Object.entries(value as Record<string, unknown>)) {
          if (SENSITIVE_KEYS.has(k) && typeof v === 'string') {
            out[k] = `[redacted:${v.length} chars]`;
          } else {
            out[k] = redactSensitive(v);
          }
        }
        return out;
      }
      return value;
    };
    page.on('response', async (res) => {
      const u = res.url();
      if (
        u.includes('/ecauth/passkey/authenticate-options') ||
        u.includes('/ecauth/passkey/authenticate-verify') ||
        u.includes('/admin/ecauth/api/register-options') ||
        u.includes('/admin/ecauth/api/register-verify') ||
        u.includes('/admin/ecauth/api/verify-password') ||
        u.includes('/ecauth/callback.php')
      ) {
        let safePath = u;
        try {
          safePath = new URL(u).pathname;
        } catch {
          // URL parse 失敗時はフォールバックでフル URL を使う
        }
        try {
          const body = await res.text();
          let summary: string;
          try {
            summary = JSON.stringify(redactSensitive(JSON.parse(body))).substring(0, 2000);
          } catch {
            summary = `[non-json:${body.length} bytes]`;
          }
          console.log('[response ' + res.status() + '] ' + safePath + ' -> ' + summary);
        } catch {
          // body 取得失敗は無視
        }
      }
    });

    // CDP セッション作成前にページを 1 度開いておく
    await page.goto('/admin/');
    await page.waitForLoadState('domcontentloaded');

    cdpSession = await context.newCDPSession(page);
    await cdpSession.send('WebAuthn.enable');
    const result = await cdpSession.send('WebAuthn.addVirtualAuthenticator', {
      options: {
        protocol: 'ctap2',
        transport: 'internal',
        hasResidentKey: true,
        hasUserVerification: true,
        isUserVerified: true,
        automaticPresenceSimulation: true,
      },
    });
    authenticatorId = result.authenticatorId;
  });

  test.afterAll(async () => {
    // Best-effort で登録したパスキーを削除し、staging に残骸を残さない。
    // describe.serial では先行テストが失敗すると後続テストが skip されるため、
    // クリーンアップは独立 test ではなく afterAll に置く。
    try {
      if (page && !page.isClosed()) {
        await page.goto('/admin/ecauth/passkey.php');
        // /admin/ にリダイレクトされた場合は session が無いので削除 UI に到達できない
        if (!/\/admin\/?$/.test(page.url())) {
          const rows = page.locator('table.list tbody tr');
          const count = await rows.count();
          if (count > 0) {
            page.once('dialog', (dialog) => dialog.accept().catch(() => {}));
            await rows.first().locator('button[type="submit"]').click();
            await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
          }
        }
      }
    } catch (e) {
      console.log('[afterAll cleanup] failed: ' + (e as Error).message);
    }

    if (authenticatorId) {
      await cdpSession?.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId }).catch(() => {});
    }
    await cdpSession?.detach().catch(() => {});
    await context?.close().catch(() => {});
  });

  test('管理者ログインとプラグイン設定', async () => {
    await page.goto('/admin/');
    await page.fill('input[name="login_id"]', ADMIN_LOGIN_ID);
    await page.fill('input[name="password"]', ADMIN_PASSWORD);
    await Promise.all([
      page.waitForURL(/\/admin\/home\.php/, { timeout: 15000 }),
      page.click('a:has-text("LOGIN")'),
    ]);

    // プラグイン設定: 通常はオーナーズストア経由でポップアップだが、
    // /admin/load_plugin_config.php?plugin_id=<id> に直接アクセスして同じ画面を出せる
    await page.goto(`/admin/load_plugin_config.php?plugin_id=${PLUGIN_ID}`);
    await page.fill('input[name="client_id"]', CLIENT_ID);
    await page.fill('input[name="client_secret"]', CLIENT_SECRET);
    await page.fill('input[name="ecauth_base_url"]', ECAUTH_BASE_URL);
    await page.fill('input[name="rp_id"]', RP_ID);

    // 設定保存後は alert("設定を保存しました。") が出る
    page.once('dialog', (dialog) => {
      expect(dialog.message()).toContain('設定を保存しました');
      dialog.accept().catch(() => {});
    });
    await page.click('button:has-text("登録")');
    await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {});
  });

  test('パスキーを新規登録する', async () => {
    test.setTimeout(60000);

    await page.goto('/admin/ecauth/passkey.php');
    await expect(page.locator('span', { hasText: '登録済みパスキー' })).toBeVisible();

    // 登録成功/失敗時の alert ダイアログを accept しつつログに流す
    page.on('dialog', (dialog) => {
      console.log('[dialog] ' + dialog.type() + ': ' + dialog.message());
      dialog.accept().catch(() => {});
    });

    await page.click('#ecauth-passkey-add');
    await expect(page.locator('#ecauth-password-modal')).toBeVisible();
    await page.fill('#ecauth-password-input', ADMIN_PASSWORD);

    // register-verify が 200 で返るまで待つ。パスキー一覧自体は session の access_token が
    // 無いと取得できず（パスキーログイン成功後に初めて token が入る）、登録直後の一覧は
    // 常に空表示になるため、ここではサーバー側の登録完了だけを検証する。
    const verifyPromise = page.waitForResponse(
      (res) =>
        res.url().includes('/admin/ecauth/api/register-verify.php') &&
        res.request().method() === 'POST',
      { timeout: 30000 },
    );
    await page.click('#ecauth-password-confirm');
    const verifyRes = await verifyPromise;
    expect(verifyRes.status()).toBe(200);
    const verifyBody = await verifyRes.json();
    expect(verifyBody.success).toBe(true);
    expect(typeof verifyBody.credential_id).toBe('string');

    // 登録成功後、JS は alert を出してから window.location.reload() を呼ぶ。
    // この reload を待ってから次の test に移らないと、後続の page.goto と navigation
    // が競合して "Navigation interrupted" エラーになる。
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  });

  test('管理画面からログアウトする', async () => {
    await page.goto('/admin/logout.php');
    // セッションが無効化されログイン画面に戻ること
    await expect(page).toHaveURL(/\/admin\/?$/);
    await expect(page.locator('input[name="login_id"]')).toBeVisible();
  });

  test('パスキーボタンクリックでログインが完了し管理画面ホームに遷移する', async () => {
    test.setTimeout(60000);

    await page.goto('/admin/');
    const passkeyBtn = page.locator('#ecauth-passkey-login');
    await expect(passkeyBtn).toBeVisible();

    // ボタンクリック → authenticate-options → assertion → authenticate-verify → redirect_url → callback.php → /admin/home.php
    await Promise.all([
      page.waitForURL(/\/admin\/home\.php/, { timeout: 30000 }),
      passkeyBtn.click(),
    ]);

    await expect(page.locator('input[name="login_id"]')).toHaveCount(0);
    // ホームのヘッダ「ホーム」が見えればログイン完了とみなす
    await expect(page.locator('h1, h2', { hasText: 'ホーム' })).toBeVisible();
  });
});
