<?php

namespace EcAuthLogin2\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SC_Helper_EcAuthLogin2;

/**
 * EcAuthDocs#110: register/options に渡す external_id の形式を固定する。
 *
 * EcAuth はこの値をハッシュ化して identity として保持するため、形式が変わると
 * 既存の管理者が別人として扱われる。4 系プラグイン（Service/B2BExternalId）と同じ形式にする。
 */
class ExternalIdTest extends TestCase
{
    public function testFormatIsMemberPrefixFollowedByMemberId()
    {
        self::assertSame('member:2', SC_Helper_EcAuthLogin2::buildExternalId(2));
        self::assertSame('member:1234567', SC_Helper_EcAuthLogin2::buildExternalId(1234567));
    }

    public function testAcceptsNumericStringFromSCQuery()
    {
        // SC_Query::getRow() は member_id を文字列で返すことがある（PostgreSQL）。
        self::assertSame('member:2', SC_Helper_EcAuthLogin2::buildExternalId('2'));
    }

    public function testPrefixDoesNotCollideWithNumericLoginId()
    {
        // 旧バージョンは login_id をそのまま送っていた。数字のみの login_id "7" と
        // member_id = 7 が同じ値にならないことが接頭辞の存在理由。
        self::assertNotSame('7', SC_Helper_EcAuthLogin2::buildExternalId(7));
        self::assertStringStartsWith(SC_Helper_EcAuthLogin2::EXTERNAL_ID_PREFIX, SC_Helper_EcAuthLogin2::buildExternalId(7));
    }

    /**
     * @dataProvider invalidMemberIds
     */
    public function testRejectsInvalidMemberId($memberId)
    {
        $this->expectException(\InvalidArgumentException::class);
        SC_Helper_EcAuthLogin2::buildExternalId($memberId);
    }

    public function invalidMemberIds()
    {
        return array(
            'zero' => array(0),
            'negative' => array(-1),
            'empty string' => array(''),
            'non numeric' => array('admin'),
            'not an integer' => array('1.5'),
        );
    }
}
