/*
 * EcAuthLogin2 プラグインの Web アップデート経路 E2E テスト。
 *
 * 目的:
 *   管理画面「オーナーズストア > プラグイン管理 > アップデート」から tar.gz を
 *   投入する経路が機能することを検証する。インストール経路 (web-install.spec.ts)
 *   と違い、EC-CUBE 本体はファイルを配置してくれない。plugin_update.php が
 *   自前で配置しなければ「バージョンだけ上がって中身は古い」状態になるため、
 *   バージョン表示だけでなく実際に配信される中身まで確認する。
 *
 * 前提条件:
 *   - SKIP_PLUGIN_INSTALL=true で起動された素の EC-CUBE 2 環境
 *   - ECCUBE_BASE_ARCHIVE  : アップデート元となる旧バージョンの tar.gz
 *   - ECCUBE_UPDATE_ARCHIVE: アップデート先となる新バージョンの tar.gz
 *     （未指定時は dist/ の最新を新バージョンとして使う）
 *
 * 検証フロー:
 *   1. 旧バージョンをインストールして有効化
 *   2. 「アップデート」から新バージョンの tar.gz を投入
 *   3. エラーが表示されず、バージョン表示が新バージョンになる
 *   4. 有効状態が維持される
 *   5. 設定画面 / パスキー画面が引き続き開ける（= ファイル再配置が成功している）
 *   6. 管理画面ログイン画面のパスキーボタンが新テンプレートの内容になる
 *      （= Smarty コンパイルキャッシュのクリアが効いている）
 */

import { test, expect, Page } from '@playwright/test';
import * as path from 'path';
import * as fs from 'fs';

const ADMIN_BASE = process.env.ECCUBE_ADMIN_BASE || '/admin/';
const ADMIN_BASE_RE = ADMIN_BASE.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const ADMIN_LOGIN_ID = process.env.ECCUBE_ADMIN_LOGIN_ID || 'admin';
const ADMIN_PASSWORD = process.env.ECCUBE_ADMIN_PASSWORD || 'password';
const PLUGIN_NAME_MARKER = 'EcAuth Login';

function latestArchiveInDist(): string {
  const distDir = path.resolve(__dirname, '..', 'dist');
  const candidates = fs.existsSync(distDir)
    ? fs
        .readdirSync(distDir)
        .filter((f) => /^EcAuthLogin2-.+\.tar\.gz$/.test(f))
        .map((f) => path.join(distDir, f))
    : [];
  if (candidates.length === 0) {
    throw new Error('No EcAuthLogin2-*.tar.gz found in dist/; run ./tools/build-archive.sh');
  }
  candidates.sort((a, b) => fs.statSync(b).mtimeMs - fs.statSync(a).mtimeMs);
  return candidates[0];
}

function requireArchive(envName: string): string {
  const value = process.env[envName];
  if (!value) {
    throw new Error(`${envName} is required for the update test`);
  }
  const resolved = path.resolve(value);
  if (!fs.existsSync(resolved)) {
    throw new Error(`${envName} points to a missing file: ${resolved}`);
  }
  return resolved;
}

const BASE_ARCHIVE = requireArchive('ECCUBE_BASE_ARCHIVE');
const UPDATE_ARCHIVE = process.env.ECCUBE_UPDATE_ARCHIVE
  ? requireArchive('ECCUBE_UPDATE_ARCHIVE')
  : latestArchiveInDist();

/** ワークツリーの plugin_info.php が宣言しているバージョン（= 新バージョン） */
function expectedVersion(): string {
  if (process.env.ECCUBE_EXPECTED_VERSION) {
    return process.env.ECCUBE_EXPECTED_VERSION;
  }
  const src = fs.readFileSync(
    path.resolve(__dirname, '..', 'plugin', 'EcAuthLogin2', 'plugin_info.php'),
    'utf8',
  );
  const matched = src.match(/\$PLUGIN_VERSION\s*=\s*'([^']+)'/);
  if (!matched) {
    throw new Error('PLUGIN_VERSION not found in plugin_info.php');
  }
  return matched[1];
}

const EXPECTED_VERSION = expectedVersion();

async function login(page: Page) {
  await page.goto(ADMIN_BASE);
  await page.fill('input[name="login_id"]', ADMIN_LOGIN_ID);
  await page.fill('input[name="password"]', ADMIN_PASSWORD);
  await Promise.all([
    page.waitForURL(new RegExp(`${ADMIN_BASE_RE}(home\\.php|index\\.php|$)`), { timeout: 15000 }),
    page.click('a:has-text("LOGIN")'),
  ]);
}

/** プラグイン管理画面の該当行を返す */
function pluginRow(page: Page) {
  return page.locator('tr').filter({ hasText: PLUGIN_NAME_MARKER }).first();
}

test.describe.serial('Web アップデート経路', () => {
  test('旧バージョンをインストールして有効化する', async ({ page }) => {
    test.setTimeout(90000);
    await login(page);

    await page.goto(`${ADMIN_BASE}ownersstore/`);
    await expect(page.locator('h2', { hasText: 'プラグイン登録' })).toBeVisible();

    const alreadyInstalled = await page.locator('body').filter({ hasText: PLUGIN_NAME_MARKER }).count();
    if (alreadyInstalled === 0) {
      await page.locator('input[type="file"][name="plugin_file"]').setInputFiles(BASE_ARCHIVE);
      page.once('dialog', (dialog) => dialog.accept().catch(() => {}));
      await Promise.all([
        page.waitForLoadState('networkidle', { timeout: 30000 }),
        page.locator('a.btn-action:has-text("インストール")').click(),
      ]);
      await expect(page.locator('body')).toContainText(PLUGIN_NAME_MARKER, { timeout: 15000 });
    }

    const row = pluginRow(page);
    const isAlreadyEnabled = (await row.locator('input[type="checkbox"][name="disable"]').count()) > 0;
    if (!isAlreadyEnabled) {
      page.once('dialog', (dialog) => dialog.accept().catch(() => {}));
      await Promise.all([
        page.waitForLoadState('networkidle', { timeout: 30000 }),
        row.locator('input[type="checkbox"][name="enable"]').click(),
      ]);
    }

    await expect(pluginRow(page).locator('input[type="checkbox"][name="disable"]')).toBeVisible({
      timeout: 10000,
    });
  });

  test('アップデートでファイルとバージョンが更新され、有効状態が維持される', async ({ page }) => {
    test.setTimeout(90000);
    await login(page);
    await page.goto(`${ADMIN_BASE}ownersstore/`);

    // アップデートリンクの name 属性が plugin_id。
    // dtb_plugin の採番に依存せず DOM から取る。
    const updateLink = pluginRow(page).locator('a.update_link');
    await expect(updateLink).toBeVisible();
    const pluginId = await updateLink.getAttribute('name');
    expect(pluginId).toBeTruthy();

    const versionBefore = (await pluginRow(page).innerText()).includes(EXPECTED_VERSION);
    console.log(`[web-update] 新バージョン ${EXPECTED_VERSION} 表示: before=${versionBefore}`);

    // リンク押下でファイル選択欄が slideToggle で開く
    await updateLink.click();
    const fileInput = page.locator(`#update_file_${pluginId}`);
    await expect(fileInput).toBeVisible();
    await fileInput.setInputFiles(UPDATE_ARCHIVE);

    page.once('dialog', (dialog) => {
      expect(dialog.message()).toContain('アップデート');
      dialog.accept().catch(() => {});
    });
    await Promise.all([
      page.waitForLoadState('networkidle', { timeout: 60000 }),
      page.locator(`#plugin_update_${pluginId} a.btn-action`).click(),
    ]);

    // 本体は execPlugin() の戻り値を見ずに registerData() を呼ぶため、
    // update が失敗してもバージョン表示だけは新しくなりうる。
    // まずエラーが出ていないことを確かめる。
    const body = await page.locator('body').innerText();
    expect(body).not.toContain('plugin_update.php の読み込みに失敗');
    expect(body).not.toContain('が見つかりません');
    expect(body).not.toContain('アップデートに失敗しました');
    expect(body).not.toContain('解凍に失敗しました');

    // dtb_plugin が更新されている（plugin_info.php の値が反映される）
    const row = pluginRow(page);
    await expect(row).toContainText(EXPECTED_VERSION);
    await expect(row).toContainText('対応EC-CUBEバージョン ：2.17 / 2.25');

    // registerData() は update モードで enable を除外するので有効のまま
    await expect(row.locator('input[type="checkbox"][name="disable"]')).toBeVisible();
  });

  test('アップデート後も設定画面とパスキー画面が開ける', async ({ page }) => {
    await login(page);

    await page.goto(`${ADMIN_BASE}ownersstore/`);
    const pluginId = await pluginRow(page).locator('a.update_link').getAttribute('name');

    // 設定画面 = LC_Page_Admin_EcAuthLogin2_Config の再配置が成功している
    await page.goto(`${ADMIN_BASE}load_plugin_config.php?plugin_id=${pluginId}`);
    await expect(page.locator('body')).toContainText('EcAuth Login プラグイン設定');
    await expect(page.locator('input[name="client_id"]')).toBeVisible();

    // パスキー画面 = html/admin/ecauth/passkey.php の再配置が成功している
    await page.goto(`${ADMIN_BASE}ecauth/passkey.php`);
    await expect(page.locator('span', { hasText: '登録済みパスキー' })).toBeVisible();
  });

  test('アップデート後のログイン画面に新テンプレートのパスキーボタンが出る', async ({ page }) => {
    // prefilterTransform はテンプレートのコンパイル時にしか走らないため、
    // plugin_update が Smarty のコンパイル済みテンプレートを消していないと
    // 旧バージョンのボタン（インラインスタイルの <button>）が残り続ける。
    // 新テンプレートは本体の LOGIN と同じ <a class="btn-tool-format"><span>。
    await page.goto(ADMIN_BASE);

    const passkeyButton = page.locator('#ecauth-passkey-login');
    await expect(passkeyButton).toBeVisible({ timeout: 10000 });

    // 旧実装は <button>、新実装は <a>
    expect(await passkeyButton.evaluate((el) => el.tagName)).toBe('A');
    await expect(passkeyButton).toHaveClass(/btn-tool-format/);

    // 本体の LOGIN ボタンと同じ見た目になっていること。
    // クラスを当てただけでは CSS が効いていない可能性があるので、
    // 実際の計算済みスタイルが一致することまで確認する。
    const loginButton = page.locator('a.btn-tool-format', { hasText: 'LOGIN' }).first();
    const pick = (el: Element) => {
      const s = window.getComputedStyle(el);
      return {
        backgroundColor: s.backgroundColor,
        color: s.color,
        borderTopWidth: s.borderTopWidth,
        borderTopColor: s.borderTopColor,
        borderRadius: s.borderTopLeftRadius,
        fontWeight: s.fontWeight,
      };
    };
    expect(await passkeyButton.evaluate(pick)).toEqual(await loginButton.evaluate(pick));
  });
});
