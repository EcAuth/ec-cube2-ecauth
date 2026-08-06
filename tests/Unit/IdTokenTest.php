<?php

namespace EcAuthLogin2\Tests\Unit;

use EcAuthLogin2\Tests\Unit\Support\RsaKeyFixture;
use PHPUnit\Framework\TestCase;
use SC_Helper_EcAuthLogin2_IdToken;

/**
 * EcAuthDocs #101 の回帰テスト。
 *
 * id_token は管理者セッション確立の起点になるため、署名・iss・aud・exp の
 * いずれかが欠けたトークンを 1 つでも通すと管理者なりすましが成立する。
 * ここでは「通ってはいけないトークン」を網羅的に落とすことを主眼に置く。
 *
 * 4 系プラグインの Tests/Unit/IdTokenVerifierTest.php と同じケースを揃えている。
 */
class IdTokenTest extends TestCase
{
    const ISSUER = 'https://tenant.example.com';
    const AUDIENCE = 'ec-test-client-id';
    const NOW = 1700000000;

    /** @var RsaKeyFixture */
    private static $key;

    /** @var RsaKeyFixture 別テナント（攻撃者）の鍵 */
    private static $otherKey;

    public static function setUpBeforeClass(): void
    {
        // 鍵生成は遅いのでクラス単位で 1 度だけ行う
        self::$key = new RsaKeyFixture('kid-primary');
        self::$otherKey = new RsaKeyFixture('kid-attacker');
    }

    public function testValidTokenIsAccepted()
    {
        $payload = $this->createVerifier()->verify(
            self::$key->sign($this->claims()),
            self::ISSUER,
            self::AUDIENCE
        );

        self::assertIsArray($payload);
        self::assertSame('b2b-subject-uuid', $payload['sub']);
    }

    public function testTrailingSlashInConfiguredIssuerIsTolerated()
    {
        $payload = $this->createVerifier()->verify(
            self::$key->sign($this->claims()),
            self::ISSUER . '/',
            self::AUDIENCE
        );

        self::assertIsArray($payload);
    }

    public function testTamperedSignatureIsRejected()
    {
        $tampered = RsaKeyFixture::tamperSignature(self::$key->sign($this->claims()));

        self::assertNull($this->createVerifier()->verify($tampered, self::ISSUER, self::AUDIENCE));
    }

    public function testTamperedPayloadIsRejected()
    {
        // 正規のトークンの sub だけを別の管理者のものに差し替える（署名は元のまま）
        $token = self::$key->sign($this->claims());
        $parts = explode('.', $token);
        $payload = json_decode(RsaKeyFixture::base64UrlDecode($parts[1]), true);
        $payload['sub'] = 'another-admin-subject';
        $tampered = $parts[0] . '.' . RsaKeyFixture::base64UrlEncode(json_encode($payload)) . '.' . $parts[2];

        self::assertNull($this->createVerifier()->verify($tampered, self::ISSUER, self::AUDIENCE));
    }

    public function testTokenSignedByAnotherKeyIsRejected()
    {
        // 攻撃者が自分の鍵で署名し、kid だけ正規のものに偽装したケース
        $token = self::$otherKey->sign($this->claims(), array('kid' => 'kid-primary'));

        self::assertNull($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testAlgNoneIsRejected()
    {
        $token = self::$key->forge($this->claims(), array('alg' => 'none'), '');

        self::assertNull($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testHmacAlgorithmIsRejected()
    {
        // 公開鍵を HMAC の鍵として使う alg confusion の典型パターン
        $jwk = self::$key->jwk();
        $payloadSegment = RsaKeyFixture::base64UrlEncode(json_encode($this->claims()));
        $headerSegment = RsaKeyFixture::base64UrlEncode(json_encode(array(
            'alg' => 'HS256',
            'typ' => 'JWT',
            'kid' => 'kid-primary',
        )));
        $signature = hash_hmac('sha256', $headerSegment . '.' . $payloadSegment, $jwk['n'], true);
        $token = $headerSegment . '.' . $payloadSegment . '.' . RsaKeyFixture::base64UrlEncode($signature);

        self::assertNull($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testIssuerMismatchIsRejected()
    {
        $token = self::$key->sign($this->claims(array('iss' => 'https://evil.example.com')));

        self::assertNull($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testAudienceMismatchIsRejected()
    {
        $token = self::$key->sign($this->claims(array('aud' => 'another-client-id')));

        self::assertNull($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testAudienceAsArrayIsAcceptedWhenAzpIdentifiesThisClient()
    {
        $token = self::$key->sign($this->claims(array(
            'aud' => array('another-client-id', self::AUDIENCE),
            'azp' => self::AUDIENCE,
        )));

        self::assertIsArray($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testMultipleAudiencesWithoutAzpIsRejected()
    {
        // OIDC Core 3.1.3.7 (4): aud が複数なら azp の存在確認が必要
        $token = self::$key->sign($this->claims(array('aud' => array('another-client-id', self::AUDIENCE))));

        self::assertNull($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testAzpForAnotherClientIsRejected()
    {
        // 別クライアント向けに発行された（署名は正当な）トークンの使い回しを防ぐ
        $token = self::$key->sign($this->claims(array(
            'aud' => array('another-client-id', self::AUDIENCE),
            'azp' => 'another-client-id',
        )));

        self::assertNull($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testAzpIsCheckedEvenForSingleAudience()
    {
        // OIDC Core 3.1.3.7 (5): azp があれば aud が単一でも一致を要求する
        $token = self::$key->sign($this->claims(array('azp' => 'another-client-id')));

        self::assertNull($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testMatchingAzpWithSingleAudienceIsAccepted()
    {
        $token = self::$key->sign($this->claims(array('azp' => self::AUDIENCE)));

        self::assertIsArray($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testIssuerDifferingOnlyByTrailingSlashIsRejected()
    {
        // OIDC Core 3.1.3.7 (2): iss は完全一致で比較する
        $token = self::$key->sign($this->claims(array('iss' => self::ISSUER . '/')));

        self::assertNull($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testMissingExpIsRejected()
    {
        $claims = $this->claims();
        unset($claims['exp']);

        // 修正前は exp が無いトークンが通っていた（EcAuthDocs #101）
        self::assertNull($this->createVerifier()->verify(
            self::$key->sign($claims),
            self::ISSUER,
            self::AUDIENCE
        ));
    }

    public function testExpiredTokenIsRejected()
    {
        $token = self::$key->sign($this->claims(array('exp' => self::NOW - 3600)));

        self::assertNull($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testTokenWithFutureNbfIsRejected()
    {
        $token = self::$key->sign($this->claims(array('nbf' => self::NOW + 3600)));

        self::assertNull($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testTokenIssuedInTheFutureIsRejected()
    {
        $token = self::$key->sign($this->claims(array('iat' => self::NOW + 3600)));

        self::assertNull($this->createVerifier()->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testMissingSubIsRejected()
    {
        $claims = $this->claims();
        unset($claims['sub']);

        self::assertNull($this->createVerifier()->verify(
            self::$key->sign($claims),
            self::ISSUER,
            self::AUDIENCE
        ));
    }

    public function testMalformedTokenIsRejected()
    {
        $verifier = $this->createVerifier();

        self::assertNull($verifier->verify('not-a-jwt', self::ISSUER, self::AUDIENCE));
        self::assertNull($verifier->verify('a.b', self::ISSUER, self::AUDIENCE));
        self::assertNull($verifier->verify('', self::ISSUER, self::AUDIENCE));
    }

    public function testVerificationIsSkippedWhenIssuerOrAudienceIsNotConfigured()
    {
        $token = self::$key->sign($this->claims());
        $verifier = $this->createVerifier();

        self::assertNull($verifier->verify($token, '', self::AUDIENCE));
        self::assertNull($verifier->verify($token, self::ISSUER, ''));
    }

    public function testUnavailableJwksIsRejected()
    {
        $verifier = new TestableIdToken(function ($forceRefresh) {
            return null;
        });

        self::assertNull($verifier->verify(
            self::$key->sign($this->claims()),
            self::ISSUER,
            self::AUDIENCE
        ));
    }

    public function testRotatedKeyIsPickedUpByForcedRefresh()
    {
        $rotatedKey = new RsaKeyFixture('kid-rotated');
        $calls = array();

        // キャッシュ済み JWKS には新しい kid が無く、再取得で見つかる状況
        $verifier = new TestableIdToken(function ($forceRefresh) use ($rotatedKey, &$calls) {
            $calls[] = $forceRefresh;

            return $forceRefresh ? array($rotatedKey->jwk()) : array(self::$key->jwk());
        });

        self::assertIsArray($verifier->verify(
            $rotatedKey->sign($this->claims()),
            self::ISSUER,
            self::AUDIENCE
        ));
        self::assertSame(array(false, true), $calls);
    }

    public function testSignatureFailureDoesNotTriggerAnotherFetch()
    {
        // 鍵は見つかるが署名が不正なケースでは再取得しない（無駄な外部リクエストを避ける）
        $calls = array();
        $verifier = new TestableIdToken(function ($forceRefresh) use (&$calls) {
            $calls[] = $forceRefresh;

            return array(self::$key->jwk());
        });

        $token = self::$otherKey->sign($this->claims(), array('kid' => 'kid-primary'));

        self::assertNull($verifier->verify($token, self::ISSUER, self::AUDIENCE));
        self::assertSame(array(false), $calls);
    }

    public function testKeyWithoutKidIsUsedOnlyWhenUnambiguous()
    {
        $token = self::$key->signWithExactHeader($this->claims(), array('alg' => 'RS256', 'typ' => 'JWT'));

        $jwk = self::$key->jwk();
        unset($jwk['kid']);
        $verifier = new TestableIdToken(function ($forceRefresh) use ($jwk) {
            return array($jwk);
        });
        self::assertIsArray($verifier->verify($token, self::ISSUER, self::AUDIENCE));

        // 候補が複数ある場合は kid 無しでは鍵を特定できないので拒否する
        $otherJwk = self::$otherKey->jwk();
        unset($otherJwk['kid']);
        $ambiguous = new TestableIdToken(function ($forceRefresh) use ($jwk, $otherJwk) {
            return array($jwk, $otherJwk);
        });
        self::assertNull($ambiguous->verify($token, self::ISSUER, self::AUDIENCE));
    }

    /**
     * @param array $override
     * @return array
     */
    private function claims(array $override = array())
    {
        return array_merge(array(
            'sub' => 'b2b-subject-uuid',
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'iat' => self::NOW - 60,
            'nbf' => self::NOW - 60,
            'exp' => self::NOW + 3600,
            'jti' => 'token-id',
        ), $override);
    }

    /**
     * @return TestableIdToken
     */
    private function createVerifier()
    {
        return new TestableIdToken(function ($forceRefresh) {
            return array(self::$key->jwk());
        });
    }
}

/**
 * 時刻を固定して検証するためのサブクラス。
 */
class TestableIdToken extends SC_Helper_EcAuthLogin2_IdToken
{
    protected function now()
    {
        return IdTokenTest::NOW;
    }
}
