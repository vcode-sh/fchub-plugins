<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Package;

use CartShift\Domain\Transfer\Package\LoadedSourceInstanceFingerprint;
use CartShift\Tests\Unit\PluginTestCase;

final class LoadedSourceInstanceFingerprintTest extends PluginTestCase
{
    public function testEveryCloneBoundaryChangesIdentityWhileEquivalentUrlSpellingDoesNot(): void
    {
        $facts = [
            'url' => 'HTTPS://SHOP.TEST:443/base',
            'database' => 'wordpress',
            'prefix' => 'wp_',
            'path' => '/srv/wordpress',
            'salts' => ['AUTH_KEY' => 'one', 'NONCE_SALT' => 'two'],
        ];
        $fingerprint = $this->provider($facts)->fingerprint();
        $facts['url'] = 'https://shop.test/base/';
        self::assertSame($fingerprint, $this->provider($facts)->fingerprint());

        foreach (['url', 'database', 'prefix', 'path'] as $fact) {
            $changed = $facts;
            $changed[$fact] .= '-clone';
            self::assertNotSame($fingerprint, $this->provider($changed)->fingerprint(), $fact);
        }
        $changed = $facts;
        $changed['salts']['NONCE_SALT'] = 'different';
        self::assertNotSame($fingerprint, $this->provider($changed)->fingerprint());
    }

    public function testSecretsAreNeverRecoverableFromTheFingerprintDocument(): void
    {
        $facts = [
            'url' => 'https://shop.test/',
            'database' => 'private-database-name',
            'prefix' => 'wp_',
            'path' => '/srv/secret-path',
            'salts' => ['AUTH_KEY' => 'private-auth-secret'],
        ];
        $fingerprint = $this->provider($facts)->fingerprint();

        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $fingerprint);
        self::assertStringNotContainsString('private', $fingerprint);
        self::assertStringNotContainsString('/srv', $fingerprint);
    }

    /** @param array{url:string,database:string,prefix:string,path:string,salts:array<string,string>} $facts */
    private function provider(array $facts): LoadedSourceInstanceFingerprint
    {
        return new LoadedSourceInstanceFingerprint(
            static fn (): string => $facts['url'],
            static fn (): string => $facts['database'],
            static fn (): string => $facts['prefix'],
            static fn (): string => $facts['path'],
            static fn (): array => $facts['salts'],
        );
    }
}
