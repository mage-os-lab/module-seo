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

    public function testThePublishedIdWithAnExplicitScopeUsesTheRepository(): void
    {
        $repository = $this->createMock(OrganizationRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('getForScope')->with(2, 3)->willReturn($this->organization('Acme Ltd', 'https://acme.com/'));

        $this->assertSame('https://acme.com/#organization', $this->organizationId($repository)->getPublishedId(2, 3));
    }

    public function testThePublishedIdResolvesTheScopeFromTheStoreManagerWhenNotGiven(): void
    {
        $store   = $this->createStub(StoreInterface::class);
        $website = $this->createStub(WebsiteInterface::class);
        $store->method('getId')->willReturn(5);
        $website->method('getId')->willReturn(7);
        $this->storeManager->method('getStore')->willReturn($store);
        $this->storeManager->method('getWebsite')->willReturn($website);

        $repository = $this->createMock(OrganizationRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('getForScope')->with(5, 7)->willReturn($this->organization('Acme Ltd', 'https://acme.com'));

        $this->assertSame('https://acme.com/#organization', $this->organizationId($repository)->getPublishedId());
    }

    /**
     * Decision (a), 2026-10-11: with no Organization published there is nothing to reference. The id
     * was "/#organization", which no node on the page has, wherever an article or event pointed at it.
     *
     * @return void
     */
    public function testThereIsNoIdWhileNoOrganizationIsPublished(): void
    {
        $this->repository->method('getForScope')->willReturn($this->organization('', ''));

        $this->assertNull($this->organizationId->getPublishedId(1, 1));
    }

    /**
     * An Organization is published once it has a name: OrganizationProvider writes no node without one.
     *
     * @return void
     */
    public function testAnOrganizationIsPublishedOnceItHasAName(): void
    {
        $this->assertTrue($this->organizationId->isPublished($this->organization('Acme Ltd', '')));
        $this->assertFalse($this->organizationId->isPublished($this->organization('', 'https://acme.com')));
    }

    /**
     * An Organization record with the given name and URL.
     *
     * @param string $name
     * @param string $url
     * @return OrganizationInterface
     */
    private function organization(string $name, string $url): OrganizationInterface
    {
        $org = $this->createStub(OrganizationInterface::class);
        $org->method('getName')->willReturn($name);
        $org->method('getUrl')->willReturn($url);

        return $org;
    }
}
