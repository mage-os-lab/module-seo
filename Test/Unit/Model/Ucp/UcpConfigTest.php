<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Ucp;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use MageOS\Seo\Model\Ucp\UcpConfig;
use PHPUnit\Framework\TestCase;

class UcpConfigTest extends TestCase
{
    /**
     * Each flag reads its own path at store scope.
     *
     * @return void
     */
    public function testEnabledFlagsReadTheirOwnPath(): void
    {
        $config = $this->config([], [
            UcpConfig::XML_UCP_ENABLED          => true,
            UcpConfig::XML_SECURITY_TXT_ENABLED => false,
        ]);
        $this->assertTrue($config->isUcpEnabled());
        $this->assertFalse($config->isSecurityTxtEnabled());

        $config = $this->config([], [
            UcpConfig::XML_UCP_ENABLED          => false,
            UcpConfig::XML_SECURITY_TXT_ENABLED => true,
        ]);
        $this->assertFalse($config->isUcpEnabled());
        $this->assertTrue($config->isSecurityTxtEnabled());
    }

    /**
     * Text settings are read trimmed, and '' when unset.
     *
     * @return void
     */
    public function testTextSettingsAreTrimmed(): void
    {
        $config = $this->config([
            UcpConfig::XML_UCP_SIGNING_JWK      => " {\"kid\":\"k\"}\n",
            UcpConfig::XML_SECURITY_TXT_CONTACT => ' security@shop.test ',
            UcpConfig::XML_SECURITY_TXT_EXPIRES => '2027-01-01T00:00:00Z',
        ]);

        $this->assertSame('{"kid":"k"}', $config->getPublicKeyJwk());
        $this->assertSame('security@shop.test', $config->getSecurityContactEmail());
        $this->assertSame('2027-01-01T00:00:00Z', $config->getSecurityExpires());
        $this->assertSame('', $config->getSecurityPolicyUrl());
    }

    /**
     * @param array<string,string> $values
     * @param array<string,bool> $flags
     * @return UcpConfig
     */
    private function config(array $values, array $flags = []): UcpConfig
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path, string $scope): ?string
                => $scope === ScopeInterface::SCOPE_STORE ? ($values[$path] ?? null) : null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path, string $scope): bool
                => $scope === ScopeInterface::SCOPE_STORE && ($flags[$path] ?? false)
        );

        return new UcpConfig($scopeConfig);
    }
}
