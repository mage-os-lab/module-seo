<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Console\Command;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use MageOS\Seo\Console\Command\UcpKeygenCommand;
use MageOS\Seo\Model\Ucp\UcpConfig;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class UcpKeygenCommandTest extends TestCase
{
    private EncryptorInterface&Stub $encryptor;
    private WriterInterface&Stub $writer;
    /** @var array<string, array{value:string, scope:string, scopeId:int}> */
    private array $saved = [];

    protected function setUp(): void
    {
        if (!\extension_loaded('openssl')) {
            $this->markTestSkipped('OpenSSL extension required.');
        }

        $this->encryptor = $this->createStub(EncryptorInterface::class);
        $this->encryptor->method('encrypt')->willReturnCallback(static fn (string $v): string => 'ENC:' . $v);

        $this->writer = $this->createStub(WriterInterface::class);
        $this->writer->method('save')->willReturnCallback(
            function (string $path, $value, string $scope, $scopeId): void {
                $this->saved[$path] = ['value' => (string) $value, 'scope' => $scope, 'scopeId' => (int) $scopeId];
            }
        );
    }

    /**
     * @param array<string, string> $input
     */
    private function runKeygen(array $input): CommandTester
    {
        $command = new UcpKeygenCommand($this->encryptor, $this->writer);
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    public function testGeneratesPublicJwkWithoutPrivateComponent(): void
    {
        $tester = $this->runKeygen(['--website' => '1']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertArrayHasKey(UcpConfig::XML_UCP_SIGNING_JWK, $this->saved);

        $jwk = json_decode($this->saved[UcpConfig::XML_UCP_SIGNING_JWK]['value'], true);
        $this->assertSame('EC', $jwk['kty']);
        $this->assertSame('P-256', $jwk['crv']);
        $this->assertNotSame('', $jwk['x']);
        $this->assertNotSame('', $jwk['y']);
        $this->assertArrayNotHasKey('d', $jwk, 'Stored public JWK must never contain the private key.');
        $this->assertStringStartsWith('ucp-key-', $jwk['kid']);
    }

    public function testPrivateKeyStoredEncryptedAtWebsiteScope(): void
    {
        $this->runKeygen(['--website' => '2']);

        $private = $this->saved[UcpConfig::XML_UCP_SIGNING_PRIVATE];
        $this->assertStringStartsWith('ENC:', $private['value']);
        $this->assertStringContainsString('PRIVATE KEY', $private['value']);
        $this->assertSame(ScopeInterface::SCOPE_WEBSITES, $private['scope']);
        $this->assertSame(2, $private['scopeId']);
    }

    public function testDefaultWebsiteUsesDefaultScope(): void
    {
        $this->runKeygen(['--website' => '0']);

        $this->assertSame('default', $this->saved[UcpConfig::XML_UCP_SIGNING_JWK]['scope']);
    }
}
