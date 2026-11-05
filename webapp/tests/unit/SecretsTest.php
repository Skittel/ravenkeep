<?php

use App\Libraries\Secrets;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SecretsTest extends CIUnitTestCase
{
    public function testRoundTripNonceAndWrongKey(): void
    {
        $secrets = new Secrets(random_bytes(32));
        $a       = $secrets->encrypt('geheim-123');
        $this->assertNotSame($a, $secrets->encrypt('geheim-123')); // random nonce
        $this->assertSame('geheim-123', $secrets->decrypt($a));

        $this->expectException(RuntimeException::class);
        (new Secrets(random_bytes(32)))->decrypt($a);
    }

    public function testKeyFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'rk');
        file_put_contents($file, base64_encode(random_bytes(32)) . "\n");
        putenv('RAVENKEEP_SECRET_KEY_FILE=' . $file);
        try {
            $this->assertSame('x', Secrets::fromConfig()->decrypt(Secrets::fromConfig()->encrypt('x')));
        } finally {
            putenv('RAVENKEEP_SECRET_KEY_FILE');
            unlink($file);
        }
    }
}
