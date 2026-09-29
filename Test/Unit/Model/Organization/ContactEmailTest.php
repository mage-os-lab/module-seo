<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Organization;

use Magento\Framework\App\Config\Initial as InitialConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\Data\OrganizationInterface;
use MageOS\Seo\Api\OrganizationRepositoryInterface;
use MageOS\Seo\Model\Organization\ContactEmail;
use PHPUnit\Framework\TestCase;

/**
 * The published contact: the Organization's, else the store's support email unless it is still the
 * shipped placeholder, else none.
 */
class ContactEmailTest extends TestCase
{
    private const SHIPPED = 'support@example.com';

    public function testTheOrganizationContactWinsOverTheSupportEmail(): void
    {
        $this->assertSame('ai@shop.test', $this->contactEmail(['email' => 'ai@shop.test'], 'help@shop.test')->get());
    }

    public function testWithoutAnOrganizationContactTheConfiguredSupportEmail(): void
    {
        $this->assertSame('help@shop.test', $this->contactEmail([], 'help@shop.test')->get());
        $this->assertSame('help@shop.test', $this->contactEmail(['email' => ''], 'help@shop.test')->get());
    }

    public function testASupportEmailStillAtTheShippedDefaultIsNoContact(): void
    {
        $this->assertSame('', $this->contactEmail([], self::SHIPPED)->get());
    }

    public function testNeitherIsNoContact(): void
    {
        $this->assertSame('', $this->contactEmail([], null)->get());
    }

    public function testBothAreTrimmed(): void
    {
        $this->assertSame('ai@shop.test', $this->contactEmail(['email' => ' ai@shop.test '], null)->get());
        $this->assertSame('help@shop.test', $this->contactEmail([], " help@shop.test\n")->get());
        $this->assertSame('', $this->contactEmail([], ' ' . self::SHIPPED . ' ')->get());
    }

    /**
     * The contact email for store view 1, with the given contact point and configured support email.
     *
     * @param array<string,string> $contactPoint
     * @param string|null $supportEmail
     * @return ContactEmail
     */
    private function contactEmail(array $contactPoint, ?string $supportEmail): ContactEmail
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $storeManager->method('getWebsite')->willReturn($website);

        $organization = $this->createStub(OrganizationInterface::class);
        $organization->method('getContactPoint')->willReturn($contactPoint);
        $repository = $this->createStub(OrganizationRepositoryInterface::class);
        $repository->method('getForScope')->willReturn($organization);

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($supportEmail);

        $initialConfig = $this->createStub(InitialConfig::class);
        $initialConfig->method('getData')->willReturnMap([
            ['default', ['trans_email' => ['ident_support' => ['email' => self::SHIPPED]]]],
        ]);

        return new ContactEmail($repository, $storeManager, $scopeConfig, $initialConfig);
    }
}
