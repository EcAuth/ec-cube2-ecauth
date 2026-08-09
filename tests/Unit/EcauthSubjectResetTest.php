<?php

namespace EcAuthLogin2\Tests\Unit;

use EcAuthLogin2\Tests\Unit\Support\FakeQuery;
use PHPUnit\Framework\TestCase;
use SC_Helper_EcAuthLogin2;

/**
 * EcAuth/ec-cube2-ecauth#18: 接続先テナント（client_id）を差し替えたのに
 * dtb_member.ecauth_subject が残っていると、EcAuth 側の B2BUser.Subject が
 * Organization をまたいでグローバル一意なせいで register/options が必ず 400 になる。
 *
 * 「いつクリアするか」と「クリアが何を発行するか」を固定する。
 */
class EcauthSubjectResetTest extends TestCase
{
    // ------------------------------------------------------------------
    // いつクリアするか
    // ------------------------------------------------------------------

    public function testInitialSaveDoesNotReset()
    {
        // 初回登録。まだどのテナントにも subject を登録していないのでクリア不要。
        self::assertFalse(SC_Helper_EcAuthLogin2::hasClientIdChanged('', 'ec-shop-1111'));
        self::assertFalse(SC_Helper_EcAuthLogin2::hasClientIdChanged(null, 'ec-shop-1111'));
    }

    public function testUnchangedClientIdDoesNotReset()
    {
        // client_id 以外（rp_id 等）だけを変えて保存する方が普通の操作なので、
        // ここで誤ってクリアすると全管理者のパスキーを巻き添えにする。
        self::assertFalse(SC_Helper_EcAuthLogin2::hasClientIdChanged('ec-shop-1111', 'ec-shop-1111'));
    }

    public function testWhitespaceOnlyDifferenceDoesNotReset()
    {
        // 保存側は trim 済みの値を渡すが、旧値は trim せず保存された可能性がある。
        // 空白差だけで全管理者のパスキーを無効化してはいけない。
        self::assertFalse(SC_Helper_EcAuthLogin2::hasClientIdChanged(' ec-shop-1111 ', 'ec-shop-1111'));
        self::assertFalse(SC_Helper_EcAuthLogin2::hasClientIdChanged('ec-shop-1111', "ec-shop-1111\n"));
    }

    public function testChangedClientIdResets()
    {
        // #18 の本題。テスト用テナントから本番用テナントへの差し替え。
        self::assertTrue(SC_Helper_EcAuthLogin2::hasClientIdChanged('ec-shop-1111', 'ec-shop-2222'));
    }

    public function testClientIdIsComparedCaseSensitively()
    {
        // client_id は EcAuth が払い出す不透明な識別子。大文字小文字が違えば別テナント。
        self::assertTrue(SC_Helper_EcAuthLogin2::hasClientIdChanged('ec-shop-1111', 'EC-SHOP-1111'));
    }

    public function testBlankedOutClientIdResets()
    {
        // 入力チェック（EXIST_CHECK）があるため通常は起きないが、
        // 接続先が失われた状態で古い subject を残す理由も無い。
        self::assertTrue(SC_Helper_EcAuthLogin2::hasClientIdChanged('ec-shop-1111', ''));
    }

    // ------------------------------------------------------------------
    // 接続先が変わるとき、事前入力の Base URL を捨てるか
    //
    // 設定画面は Base URL 欄に保存済みの値を事前入力する。Client ID だけを
    // 書き換えて保存すると、欄に残った前の接続先の URL が「入力あり」と見なされ、
    // 「新しい client_id + 前の接続先の Base URL」が保存されてしまう。
    // ------------------------------------------------------------------

    public function testBaseUrlInputIsKeptWhenClientIdUnchanged()
    {
        // Client ID が同じなら、Base URL 欄の値は管理者の意思として尊重する。
        self::assertFalse(SC_Helper_EcAuthLogin2::shouldDiscardBaseUrlInput(
            false,
            'https://old.ec-auth.io',
            'https://old.ec-auth.io'
        ));
    }

    public function testUntouchedBaseUrlIsDiscardedWhenClientIdChanged()
    {
        // 事前入力のまま（保存済みの値と同一）＝ 触っていないので捨てて再解決する。
        self::assertTrue(SC_Helper_EcAuthLogin2::shouldDiscardBaseUrlInput(
            true,
            'https://old.ec-auth.io',
            'https://old.ec-auth.io'
        ));
        // 前後の空白差は「触った」うちに入らない。
        self::assertTrue(SC_Helper_EcAuthLogin2::shouldDiscardBaseUrlInput(
            true,
            ' https://old.ec-auth.io ',
            'https://old.ec-auth.io'
        ));
    }

    public function testExplicitlyTypedBaseUrlIsKeptWhenClientIdChanged()
    {
        // 保存済みと違う値を打っているなら、管理者が意図して指定したもの。
        // 開発・ステージングの手動指定を潰さないため、こちらは尊重する。
        self::assertFalse(SC_Helper_EcAuthLogin2::shouldDiscardBaseUrlInput(
            true,
            'https://new.ec-auth.io',
            'https://old.ec-auth.io'
        ));
    }

    public function testEmptyBaseUrlInputIsNotTreatedAsDiscardable()
    {
        // もともと未入力なら呼び出し側が従来どおり解決する。捨てるものが無い。
        self::assertFalse(SC_Helper_EcAuthLogin2::shouldDiscardBaseUrlInput(true, '', 'https://old.ec-auth.io'));
        self::assertFalse(SC_Helper_EcAuthLogin2::shouldDiscardBaseUrlInput(true, '', ''));
    }

    public function testFirstTimeSaveWithTypedBaseUrlIsKept()
    {
        // 初回登録は hasClientIdChanged() が false になるため、
        // 保存済みが空でも入力値は捨てられない。
        self::assertFalse(SC_Helper_EcAuthLogin2::shouldDiscardBaseUrlInput(false, 'https://new.ec-auth.io', ''));
    }

    // ------------------------------------------------------------------
    // クリアが何を発行するか
    // ------------------------------------------------------------------

    public function testClearIssuesNoUpdateWhenNothingToClear()
    {
        $query = new FakeQuery(0);
        $helper = new SC_Helper_EcAuthLogin2();

        self::assertSame(0, $helper->clearMemberEcauthSubjects($query));
        // 対象ゼロ件で UPDATE を撃つと、全 member の update_date を無意味に進めてしまう。
        self::assertSame(array(), $query->updateCalls);
    }

    public function testClearReturnsAffectedCount()
    {
        // 実物の COUNT(*) は文字列で返るため、フェイクも文字列を返す。
        $query = new FakeQuery('2');
        $helper = new SC_Helper_EcAuthLogin2();

        self::assertSame(2, $helper->clearMemberEcauthSubjects($query));
        self::assertCount(1, $query->updateCalls);
    }

    public function testClearTargetsOnlyRowsThatHaveSubject()
    {
        $query = new FakeQuery(3);
        $helper = new SC_Helper_EcAuthLogin2();
        $helper->clearMemberEcauthSubjects($query);

        $count = $query->countCalls[0];
        self::assertSame('dtb_member', $count['table']);
        self::assertSame('ecauth_subject IS NOT NULL', $count['where']);

        $update = $query->updateCalls[0];
        self::assertSame('dtb_member', $update['table']);
        // 既に NULL の行まで巻き込むと update_date だけが動いて差分調査を惑わせる。
        self::assertSame('ecauth_subject IS NOT NULL', $update['where']);
    }

    public function testClearDoesNotTouchCustomerTable()
    {
        // dtb_customer.ecauth_subject は B2C の sub で、発番するのは EcAuth 側。
        // テナントが変われば別の値が降ってきて衝突しないため、消すと紐付けを失うだけ。
        $query = new FakeQuery(1);
        $helper = new SC_Helper_EcAuthLogin2();
        $helper->clearMemberEcauthSubjects($query);

        foreach (array_merge($query->countCalls, $query->updateCalls) as $call) {
            self::assertNotSame('dtb_customer', $call['table']);
        }
    }

    public function testClearPassesNullAsRawSqlNotAsBoundValue()
    {
        // SC_Query::update() は $arrVal の各値を strcasecmp('Now()', $val) に通すため、
        // null を混ぜると PHP 8.1 以降で Deprecated が出る。NULL は $arrRawSql で渡す。
        $query = new FakeQuery(1);
        $helper = new SC_Helper_EcAuthLogin2();
        $helper->clearMemberEcauthSubjects($query);

        $update = $query->updateCalls[0];
        self::assertSame(array('ecauth_subject' => 'NULL'), $update['rawSql']);
        self::assertArrayNotHasKey('ecauth_subject', $update['values']);
        foreach ($update['values'] as $value) {
            self::assertNotNull($value);
        }
    }

    public function testClearBumpsUpdateDate()
    {
        $query = new FakeQuery(1);
        $helper = new SC_Helper_EcAuthLogin2();
        $helper->clearMemberEcauthSubjects($query);

        self::assertSame('CURRENT_TIMESTAMP', $query->updateCalls[0]['values']['update_date']);
    }
}
