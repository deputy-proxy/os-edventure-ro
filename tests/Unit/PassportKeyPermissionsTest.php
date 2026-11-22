<?php

namespace Tests\Unit;

use App\Support\PassportKeyPermissions;
use PHPUnit\Framework\TestCase;

class PassportKeyPermissionsTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_existing_file_backed_keys_are_restricted_without_changing_key_material(): void
    {
        $privateKey = $this->temporaryKeyFile('private-key-material');
        $publicKey = $this->temporaryKeyFile('public-key-material');
        chmod($privateKey, 0644);
        chmod($publicKey, 0644);

        $permissions = new PassportKeyPermissions;
        $secured = $permissions->secureExisting([
            $privateKey,
            'file://'.$publicKey,
            $privateKey,
        ]);

        $this->assertSame([$privateKey, $publicKey], $secured);
        $this->assertSame([$privateKey, $publicKey], $permissions->secureExisting([$privateKey, $publicKey]));
        $this->assertSame(0600, fileperms($privateKey) & 0777);
        $this->assertSame(0600, fileperms($publicKey) & 0777);
        $this->assertSame('private-key-material', file_get_contents($privateKey));
        $this->assertSame('public-key-material', file_get_contents($publicKey));
    }

    public function test_missing_files_and_inline_pem_values_are_left_untouched(): void
    {
        $inlineKey = "-----BEGIN PUBLIC KEY-----\nexample\n-----END PUBLIC KEY-----";

        $secured = (new PassportKeyPermissions)->secureExisting([
            sys_get_temp_dir().'/missing-passport-key-'.uniqid('', true),
            $inlineKey,
        ]);

        $this->assertSame([], $secured);
    }

    private function temporaryKeyFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'passport-key-');
        $this->assertIsString($path);
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
