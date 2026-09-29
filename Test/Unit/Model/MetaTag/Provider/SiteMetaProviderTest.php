<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\MetaTag\Provider;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\Data\OrganizationInterface;
use MageOS\Seo\Api\OrganizationRepositoryInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\MetaTag\Provider\SiteMetaProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SiteMetaProviderTest extends TestCase
{
    /**
     * @var Config&MockObject
     */
    private Config&MockObject $config;

    /**
     * @var StoreManagerInterface&MockObject
     */
    private StoreManagerInterface&MockObject $storeManager;

    /**
     * @var OrganizationRepositoryInterface&MockObject
     */
    private OrganizationRepositoryInterface&MockObject $repository;

    /**
     * @var StoreInterface&MockObject
     */
    private StoreInterface&MockObject $store;

    /**
     * @var SiteMetaProvider
     */
    private SiteMetaProvider $provider;

    protected function setUp(): void
    {
        $this->config       = $this->createMock(Config::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->repository   = $this->createMock(OrganizationRepositoryInterface::class);

        $this->store = $this->createMock(StoreInterface::class);
        $this->store->method('getId')->willReturn(1);
        $this->store->method('getName')->willReturn('Store Name');
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($this->store);
        $this->storeManager->method('getWebsite')->willReturn($website);

        $this->provider = new SiteMetaProvider(
            $this->config,
            $this->storeManager,
            $this->repository
        );
    }

    private function withOrgName(string $name): void
    {
        $org = $this->createMock(OrganizationInterface::class);
        $org->method('getName')->willReturn($name);
        $this->repository->method('getForScope')->willReturn($org);
    }

    public function testHandlesEveryPage(): void
    {
        $this->assertSame(['*'], $this->provider->getHandles());
    }

    public function testReturnsEmptyWhenOgDisabled(): void
    {
        $this->config->method('isOgTagsEnabled')->willReturn(false);
        $this->assertSame([], $this->provider->getMetaTags());
    }

    public function testEmitsSiteNameAndLocale(): void
    {
        $this->config->method('isOgTagsEnabled')->willReturn(true);
        $this->withOrgName('Acme Ltd');
        $this->config->method('getLocaleCode')->willReturn('en_GB');

        $this->assertSame(
            [
                ['property' => 'og:site_name', 'content' => 'Acme Ltd'],
                ['property' => 'og:locale', 'content' => 'en_GB'],
            ],
            $this->provider->getMetaTags()
        );
    }

    public function testFallsBackToStoreNameWhenOrgNameEmpty(): void
    {
        $this->config->method('isOgTagsEnabled')->willReturn(true);
        $this->withOrgName('');
        $this->config->method('getLocaleCode')->willReturn('en_GB');

        $tags = $this->provider->getMetaTags();

        $this->assertContains(['property' => 'og:site_name', 'content' => 'Store Name'], $tags);
    }

    public function testOmitsLocaleWhenEmpty(): void
    {
        $this->config->method('isOgTagsEnabled')->willReturn(true);
        $this->withOrgName('Acme Ltd');
        $this->config->method('getLocaleCode')->willReturn('');

        $tags       = $this->provider->getMetaTags();
        $properties = array_column($tags, 'property');

        $this->assertNotContains('og:locale', $properties);
    }

    public function testLeavesTheXCardToTheCompositor(): void
    {
        // The card type depends on the page's image, which only MetaTag\Compositor sees.
        $this->config->method('isOgTagsEnabled')->willReturn(true);
        $this->withOrgName('Acme Ltd');
        $this->config->method('getLocaleCode')->willReturn('en_GB');

        $names = array_column($this->provider->getMetaTags(), 'name');

        $this->assertNotContains('twitter:card', $names);
    }
}
