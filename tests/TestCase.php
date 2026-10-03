<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Passport's key pair as PEM strings, made once per process.
     *
     * @var array{private: string, public: string}|null
     */
    private static ?array $passportKeys = null;

    /**
     * Give Passport a key pair of its own, so the suite never reads storage/oauth-*.key: the MCP URLs name Passport's
     * guard, which needs the public key even to turn a request without a token away.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->usePassportKeys();
    }

    /**
     * Point Passport at the suite's key pair; a test that boots the app again calls this once more.
     */
    protected function usePassportKeys(): void
    {
        config(['passport.private_key' => self::passportKeys()['private'], 'passport.public_key' => self::passportKeys()['public']]);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * A 2048-bit RSA key pair for Passport.
     *
     * @return array{private: string, public: string}
     */
    private static function passportKeys(): array
    {
        if (self::$passportKeys === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);

            self::$passportKeys = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
        }

        return self::$passportKeys;
    }
}
