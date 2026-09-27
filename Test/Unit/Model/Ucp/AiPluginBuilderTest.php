<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Ucp;

use MageOS\Seo\Model\Organisation\ContactEmail;
use MageOS\Seo\Model\Ucp\AiPluginBuilder;
use MageOS\Seo\Model\Ucp\UcpConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AiPluginBuilderTest extends TestCase
{
    private UcpConfig&MockObject $config;
    private AiPluginBuilder $builder;

    protected function setUp(): void
    {
        $this->config = $this->createMock(UcpConfig::class);
        $contactEmail = $this->createStub(ContactEmail::class);
        $contactEmail->method('get')->willReturn('help@shop.test');
        $this->builder = new AiPluginBuilder($this->config, $contactEmail);
        $this->config->method('getBaseUrl')->willReturn('https://shop.test');
        $this->config->method('getAiPluginLegalUrl')->willReturn('https://shop.test/legal');
    }

    public function testManifestStructureAndModelNameSanitisation(): void
    {
        $this->config->method('getMerchantName')->willReturn('Completely Shropshire!');
        $this->config->method('getAiPluginDescription')->willReturn('Handmade goods.');

        $manifest = $this->builder->build();

        $this->assertSame('v1', $manifest['schema_version']);
        $this->assertSame('completely_shropshire', $manifest['name_for_model']);
        $this->assertSame('Completely Shropshire!', $manifest['name_for_human']);
        $this->assertSame('Handmade goods.', $manifest['description_for_model']);
        $this->assertSame('none', $manifest['auth']['type']);
        $this->assertStringStartsWith('https://shop.test/rest/', $manifest['api']['url']);
        $this->assertFalse($manifest['api']['is_user_authenticated']);
        $this->assertSame('help@shop.test', $manifest['contact_email']);
        $this->assertSame('https://shop.test/legal', $manifest['legal_info_url']);
    }

    public function testDescriptionFallsBackToMerchantNameAndTruncatesAt200(): void
    {
        $this->config->method('getMerchantName')->willReturn('Shop');
        $this->config->method('getAiPluginDescription')->willReturn(str_repeat('x', 250));

        $manifest = $this->builder->build();

        $this->assertSame(200, mb_strlen($manifest['description_for_model']));
    }

    public function testBlankDescriptionUsesMerchantName(): void
    {
        $this->config->method('getMerchantName')->willReturn('Shop');
        $this->config->method('getAiPluginDescription')->willReturn('');

        $this->assertSame('Shop', $this->builder->build()['description_for_human']);
    }
}
