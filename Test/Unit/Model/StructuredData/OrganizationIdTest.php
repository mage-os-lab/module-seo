<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\StructuredData;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\Data\OrganizationInterface;
use MageOS\Seo\Api\OrganizationRepositoryInterface;
use MageOS\Seo\Model\StructuredData\OrganizationId;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class OrganizationIdTest extends TestCase
{
    /**
     * @var OrganizationRepositoryInterface&Stub
     */
    private OrganizationRepositoryInterface&Stub $repository;

    /**
     * @var StoreManagerInterface&Stub
     */
    private StoreManagerInterface&Stub $storeManager;

    /**
     * @var OrganizationId
     */
    private OrganizationId $organizationId;

    protected function setUp(): void
    {
        $this->repository     = $this->createStub(OrganizationRepositoryInterface::class);
        $this->storeManager   = $this->createStub(StoreManagerInterface::class);
        $this->organizationId = $this->organizationId();
    }

    /**
     * The class under test, over the given Organisation repository or this test's stub.
     *
     * @param OrganizationRepositoryInterface|null $repository
     * @return OrganizationId
     */
    private function organizationId(?OrganizationRepositoryInterface $repository = null): OrganizationId
    {
        return new OrganizationId($repository ?? $this->repository, $this->storeManager);
    }

    public function testFromUrlAppendsOrganizationFragment(): void
    {
        $this->assertSame('https://acme.com/#organization', $this->organizationId->fromUrl('https://acme.com'));
    }

    public function testFromUrlStripsTrailingSlash(): void
    {
        $this->assertSame('https://acme.com/#organization', $this->organizationId->fromUrl('https://acme.com/'));
    }

    public function testGetIdWithExplicitScopeUsesRepository(): void
    {
        $org = $this->createStub(OrganizationInterface::class);
        $org->method('getUrl')->willReturn('https://acme.com/');
        $repository = $this->createMock(OrganizationRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('getForScope')->with(2, 3)->willReturn($org);

        $this->assertSame('https://acme.com/#organization', $this->organizationId($repository)->getId(2, 3));
    }

    public function testGetIdResolvesScopeFromStoreManagerWhenNotGiven(): void
    {
        $store   = $this->createStub(StoreInterface::class);
        $website = $this->createStub(WebsiteInterface::class);
        $store->method('getId')->willReturn(5);
        $website->method('getId')->willReturn(7);
        $this->storeManager->method('getStore')->willReturn($store);
        $this->storeManager->method('getWebsite')->willReturn($website);

        $org = $this->createStub(OrganizationInterface::class);
        $org->method('getUrl')->willReturn('https://acme.com');
        $repository = $this->createMock(OrganizationRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('getForScope')->with(5, 7)->willReturn($org);

        $this->assertSame('https://acme.com/#organization', $this->organizationId($repository)->getId());
    }
}
