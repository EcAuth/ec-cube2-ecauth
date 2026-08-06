<?php

namespace EcAuthLogin2\Tests\Unit\Support;

/**
 * テスト用の RSA 鍵ペアを生成し、JWK と署名済み JWT を組み立てるヘルパー。
 *
 * 鍵生成は遅いのでテストクラスごとに 1 回だけ生成して使い回すこと。
 *
 * 4 系プラグインの Tests/Unit/Support/RsaKeyFixture.php と同等。
 */
class RsaKeyFixture
{
    /** @var resource|\OpenSSLAsymmetricKey */
    private $privateKey;

    /** @var string */
    private $modulus;

    /** @var string */
    private $exponent;

    /** @var string */
    private $kid;

    /**
     * @param string $kid
     */
    public function __construct($kid = 'test-kid')
    {
        $key = openssl_pkey_new(array(
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ));
        if ($key === false) {
            throw new \RuntimeException('Failed to generate an RSA key pair for tests');
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false || !isset($details['rsa']['n']) || !isset($details['rsa']['e'])) {
            throw new \RuntimeException('Failed to read RSA key details for tests');
        }

        $this->privateKey = $key;
        $this->modulus = $details['rsa']['n'];
        $this->exponent = $details['rsa']['e'];
        $this->kid = $kid;
    }

    /**
     * @return string
     */
    public function kid()
    {
        return $this->kid;
    }

    /**
     * EcAuth の JwksController が返すのと同じ形の JWK を組み立てる。
     *
     * @return array
     */
    public function jwk()
    {
        return array(
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $this->kid,
            'n' => self::base64UrlEncode($this->modulus),
            'e' => self::base64UrlEncode($this->exponent),
        );
    }

    /**
     * RS256 で署名した JWT を組み立てる。
     *
     * @param array $payload
     * @param array $headerOverride
     * @return string
     */
    public function sign(array $payload, array $headerOverride = array())
    {
        return $this->signWithExactHeader($payload, array_merge(array(
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => $this->kid,
        ), $headerOverride));
    }

    /**
     * ヘッダをそのまま使って RS256 署名する（kid を持たないトークンの生成用）。
     *
     * @param array $payload
     * @param array $header
     * @return string
     */
    public function signWithExactHeader(array $payload, array $header)
    {
        $signingInput = self::base64UrlEncode(json_encode($header))
            . '.' . self::base64UrlEncode(json_encode($payload));

        $signature = '';
        openssl_sign($signingInput, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return $signingInput . '.' . self::base64UrlEncode($signature);
    }

    /**
     * 署名を持たない（あるいは任意の文字列を署名として置いた）JWT を組み立てる。
     *
     * @param array $payload
     * @param array $headerOverride
     * @param string $signature
     * @return string
     */
    public function forge(array $payload, array $headerOverride, $signature)
    {
        $header = array_merge(array(
            'typ' => 'JWT',
            'kid' => $this->kid,
        ), $headerOverride);

        return self::base64UrlEncode(json_encode($header))
            . '.' . self::base64UrlEncode(json_encode($payload))
            . '.' . self::base64UrlEncode($signature);
    }

    /**
     * @param string $raw
     * @return string
     */
    public static function base64UrlEncode($raw)
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @param string $input
     * @return string
     */
    public static function base64UrlDecode($input)
    {
        $remainder = strlen($input) % 4;
        if ($remainder !== 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($input, '-_', '+/'), true);
    }

    /**
     * 署名バイト列の先頭 1 バイトを反転させた JWT を返す。
     *
     * base64url 文字列の末尾 1 文字を書き換える方法だと、末尾文字が持つ
     * パディングビットしか変わらずデコード結果が同一になる場合があり、
     * 署名が有効なままになってしまう（PHP バージョンや鍵によって再現が変わる）。
     * バイト列側を確実に変える。
     *
     * @param string $token
     * @return string
     */
    public static function tamperSignature($token)
    {
        $parts = explode('.', $token);
        $signature = self::base64UrlDecode($parts[2]);
        $signature[0] = chr(ord($signature[0]) ^ 0xff);

        return $parts[0] . '.' . $parts[1] . '.' . self::base64UrlEncode($signature);
    }
}
