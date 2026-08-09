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
 *   4. 管理ログイン画面でパスキーボタンをクリックし、コールバック経由で <ADMIN_DIR>/home.php までリダイレクトされることを検証
 *   5. 端末側にパスキーが無い状態でボタンを押し、案内ダイアログが出ることを検証（#26）
 */

import { test, expect, BrowserContext, Page, CDPSession } from '@playwright/test';

const ADMIN_LOGIN_ID = process.env.ECCUBE_ADMIN_LOGIN_ID || 'admin';
const ADMIN_PASSWORD = process.env.ECCUBE_ADMIN_PASSWORD || 'password';
const PLUGIN_ID = process.env.ECCUBE_PLUGIN_ID || '10000';
// EC-CUBE 2 の ADMIN_DIR はインストール時に推測困難な値に変更可能（管理画面 URL の秘匿）。
// CI ではランダム文字列を渡してテストするため、ハードコードせず env から読む。
// 形式は前後にスラッシュ付き（例: '/admin/' または '/admin_a1b2c3d4/'）。
const ADMIN_BASE = process.env.ECCUBE_ADMIN_BASE || '/admin/';
const ADMIN_BASE_RE = ADMIN_BASE.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const ECAUTH_BASE_URL = process.env.ECAUTH_BASE_URL || '';
const CLIENT_ID = process.env.CLIENT_ID || '';
const CLIENT_SECRET = process.env.CLIENT_SECRET || '';
const RP_ID = process.env.RP_ID || 'localhost';

// 設定画面の導線 URL は SC_Helper_EcAuthLogin2 の定数が既定値で、環境変数で上書きできる。
// テスト側も同じ解決順にしておく（CI では環境変数未設定なので既定値が使われる）。
const SIGNUP_URL = process.env.ECAUTH_SIGNUP_URL || 'https://ec-auth.io/signup/';
const MYPAGE_URL = process.env.ECAUTH_MYPAGE_URL || 'https://ec-auth.io/mypage/';

test.describe.serial('E2E: B2Bパスキー登録からログイン完了までのフロー', () => {
  test.skip(
    !ECAUTH_BASE_URL || !CLIENT_ID || !CLIENT_SECRET,
    'ECAUTH_BASE_URL / CLIENT_ID / CLIENT_SECRET が未設定のためスキップ（1Password: ecauth-staging-app 参照）',
  );

  let context: BrowserContext;
  let page: Page;
  let cdpSession: CDPSession;
  let authenticatorId: string;
  // afterAll で「自分が登録した行」だけを安全に削除するための識別子
  let createdCredentialId: string | null = null;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext({ ignoreHTTPSErrors: true });

    // WebAuthn の timeout=0 をサーバから返されるケースに備えて、
    // ページ遷移後も常に有効な timeout に上書きする。
    // 併せて navigator.credentials.{create,get} の解決値/エラーを console にダンプして
    // CI で WebAuthn 側の失敗原因を可視化する（passkey スクリプト側は NotAllowedError を
    // alert で案内するだけで、エラーオブジェクトの詳細までは console に出さないため）。
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
        u.includes(`${ADMIN_BASE}ecauth/api/register-options`) ||
        u.includes(`${ADMIN_BASE}ecauth/api/register-verify`) ||
        u.includes(`${ADMIN_BASE}ecauth/api/verify-password`) ||
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
    await page.goto(ADMIN_BASE);
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
    //
    // パスキー一覧ページ (passkey.php) はサーバー側 PHP から EcAuth の
    // /v1/b2b/passkey/list を同期で叩くため、staging の応答が遅いと goto が
    // そのまま待たされる。既定のフックタイムアウト 30 秒だとこれだけで
    // afterAll ごと失敗し、テスト自体は全部通っているのに run が赤くなる。
    // クリーンアップは best-effort なので、
    //   - フックには余裕を持たせる
    //   - 個々の goto は明示タイムアウトで縛り、超えたら catch でログに落とす
    // という方針にする（残骸は残るが、run を落とすよりは良い）。
    test.setTimeout(90000);
    const gotoOptions = { timeout: 20000 };

    try {
      if (page && !page.isClosed() && createdCredentialId) {
        await page.goto(`${ADMIN_BASE}ecauth/passkey.php`, gotoOptions);
        // ADMIN_BASE 直下にリダイレクトされた = セッション切れでログイン画面表示
        const loginPageRe = new RegExp(`${ADMIN_BASE_RE.replace(/\/$/, '')}/?$`);
        // セッション切れの場合は password 再ログインしてからクリーンアップを試みる
        // （テスト失敗で afterAll に到達する場合、ログアウト状態の可能性が高いため、
        //  ここで再ログインしないと credential_id が staging に残骸として残ってしまう）
        if (loginPageRe.test(new URL(page.url()).pathname)) {
          await page.fill('input[name="login_id"]', ADMIN_LOGIN_ID);
          await page.fill('input[name="password"]', ADMIN_PASSWORD);
          await Promise.all([
            page.waitForURL(new RegExp(`${ADMIN_BASE_RE}home\\.php`), { timeout: 15000 }),
            page.click('a:has-text("LOGIN")'),
          ]);
          await page.goto(`${ADMIN_BASE}ecauth/passkey.php`, gotoOptions);
        }
        // 再ログイン失敗時など、依然としてログイン画面にいる場合は諦める
        if (!loginPageRe.test(new URL(page.url()).pathname)) {
          // 共有 staging で他者のパスキー（先頭行）を誤削除しないよう、
          // 登録時に取得した credential_id に一致する行のみを対象にする
          const target = page.locator(
            `table.list tbody tr:has(input[name="credential_id"][value="${createdCredentialId}"])`,
          );
          if ((await target.count()) > 0) {
            page.once('dialog', (dialog) => dialog.accept().catch(() => {}));
            await target.first().locator('button[type="submit"]').click();
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
    await page.goto(ADMIN_BASE);
    await page.fill('input[name="login_id"]', ADMIN_LOGIN_ID);
    await page.fill('input[name="password"]', ADMIN_PASSWORD);
    await Promise.all([
      page.waitForURL(new RegExp(`${ADMIN_BASE_RE}home\\.php`), { timeout: 15000 }),
      page.click('a:has-text("LOGIN")'),
    ]);

    // プラグイン設定: 通常はオーナーズストア経由でポップアップだが、
    // <ADMIN_BASE>load_plugin_config.php?plugin_id=<id> に直接アクセスして同じ画面を出せる
    await page.goto(`${ADMIN_BASE}load_plugin_config.php?plugin_id=${PLUGIN_ID}`);

    // 申込・マイページ導線（ec-auth.io）が設定画面に出ていること。
    // 別タブで開く（target=_blank）ため rel の付与も併せて検証する。
    const signupLink = page.locator('#ecauth-signup-link');
    await expect(signupLink).toBeVisible();
    await expect(signupLink).toHaveAttribute('href', SIGNUP_URL);
    await expect(signupLink).toHaveAttribute('target', '_blank');
    await expect(signupLink).toHaveAttribute('rel', /noopener/);
    const mypageLink = page.locator('#ecauth-mypage-link');
    await expect(mypageLink).toBeVisible();
    await expect(mypageLink).toHaveAttribute('href', MYPAGE_URL);
    await expect(mypageLink).toHaveAttribute('target', '_blank');
    await expect(mypageLink).toHaveAttribute('rel', /noopener/);

    // EcAuthDocs #101: Base URL はトークン交換先かつ JWKS 取得先になるため、
    // 許可リスト外のホストは保存段階で弾く。正しい設定を保存する前に確認しておく
    // （後続テストの前提を壊さないよう、この時点では設定は未保存のまま）。
    await page.fill('input[name="client_id"]', CLIENT_ID);
    await page.fill('input[name="client_secret"]', CLIENT_SECRET);
    await page.fill('input[name="ecauth_base_url"]', 'https://auth.example.com');
    await page.fill('input[name="rp_id"]', RP_ID);
    await page.click('button:has-text("登録")');
    await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {});
    await expect(page.locator('text=許可されていないホスト')).toBeVisible();

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

    await page.goto(`${ADMIN_BASE}ecauth/passkey.php`);
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
        res.url().includes(`${ADMIN_BASE}ecauth/api/register-verify.php`) &&
        res.request().method() === 'POST',
      { timeout: 30000 },
    );
    await page.click('#ecauth-password-confirm');
    const verifyRes = await verifyPromise;
    expect(verifyRes.status()).toBe(200);
    const verifyBody = await verifyRes.json();
    expect(verifyBody.success).toBe(true);
    expect(typeof verifyBody.credential_id).toBe('string');
    // afterAll クリーンアップで自分の行だけを安全に削除するために保存
    createdCredentialId = verifyBody.credential_id;

    // 登録成功後、JS は alert を出してから window.location.reload() を呼ぶ。
    // この reload を待ってから次の test に移らないと、後続の page.goto と navigation
    // が競合して "Navigation interrupted" エラーになる。
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  });

  test('管理画面からログアウトする', async () => {
    await page.goto(`${ADMIN_BASE}logout.php`);
    // セッションが無効化されログイン画面に戻ること
    await expect(page).toHaveURL(new RegExp(`${ADMIN_BASE_RE.replace(/\/$/, '')}/?$`));
    await expect(page.locator('input[name="login_id"]')).toBeVisible();
  });

  test('パスキーボタンクリックでログインが完了し管理画面ホームに遷移する', async () => {
    test.setTimeout(60000);

    await page.goto(ADMIN_BASE);
    const passkeyBtn = page.locator('#ecauth-passkey-login');
    await expect(passkeyBtn).toBeVisible();

    // ボタンクリック → authenticate-options → assertion → authenticate-verify → redirect_url → callback.php → <ADMIN_BASE>home.php
    await Promise.all([
      page.waitForURL(new RegExp(`${ADMIN_BASE_RE}home\\.php`), { timeout: 30000 }),
      passkeyBtn.click(),
    ]);

    await expect(page.locator('input[name="login_id"]')).toHaveCount(0);
    // ホームのヘッダ「ホーム」が見えればログイン完了とみなす
    await expect(page.locator('h1, h2', { hasText: 'ホーム' })).toBeVisible();
  });

  test('端末にパスキーが無い場合は案内ダイアログが表示される (#26)', async () => {
    test.setTimeout(90000);

    // #26 の再現。ログイン画面は誰がログインするか未確定なので b2b_subject を送らず、
    // EcAuth は Organization 内の全クレデンシャルを allowCredentials に詰めて返す。
    // その結果「他人のパスキーだけが許可された」状態で credentials.get() が呼ばれ、
    // 当人の端末に該当クレデンシャルが無いため NotAllowedError になる。
    // サーバー側の登録は残したまま仮想オーセンティケータの資格情報だけを消すと、
    // まさにこの状況（allowCredentials は非空／端末には無い）が作れる。
    await cdpSession.send('WebAuthn.clearCredentials', { authenticatorId });

    // ログアウトはしない。EC-CUBE 2 の LC_Page_Admin_Index はログイン済みでも
    // 常に login.tpl を描画する（ログイン済みならリダイレクト、という分岐が無い）ため、
    // セッションを保ったままログイン画面のパスキーボタンを操作できる。
    // ここでログアウトすると afterAll のクリーンアップが session の
    // ecauth_access_token を失い、staging にパスキーの残骸を残してしまう。
    await page.goto(ADMIN_BASE);

    const passkeyBtn = page.locator('#ecauth-passkey-login');
    await expect(passkeyBtn).toBeVisible();

    // 初回利用者向けの常設案内はボタンの title（ツールチップ）で出す
    await expect(passkeyBtn).toHaveAttribute('title', /パスキー管理/);

    // NotAllowedError を握り潰していた頃は、ここで何も表示されずラベルが戻るだけだった。
    // キャンセルと「該当パスキー無し」はブラウザ API 上区別できないため、
    // 双方に当てはまる文面かつ復旧手順を含むことを確認する。
    //
    // 先行テストが登録済みの永続 dialog ハンドラ（全 accept）より後に呼ばれるが、
    // dialog.message() は受信済みペイロードから読めるので順序に依存しない。
    const dialogMessage = new Promise<string>((resolve) => {
      page.once('dialog', (dialog) => {
        resolve(dialog.message());
        dialog.accept().catch(() => {});
      });
    });

    await passkeyBtn.click();

    const message = await dialogMessage;
    expect(message).toContain('パスキーが見つからないか');
    expect(message).toContain('パスキー管理');

    // ラベルが「認証中...」のまま固まらず、押し直せる状態に戻ること
    await expect(passkeyBtn).toHaveText('パスキーでログイン');
  });
});
