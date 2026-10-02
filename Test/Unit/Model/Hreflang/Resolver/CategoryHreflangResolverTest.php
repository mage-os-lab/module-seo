<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Hreflang\Resolver;

use Magento\Catalog\Api\Data\CategoryInterface;
use MageOS\Seo\Model\Catalog\CurrentEntity;
use MageOS\Seo\Model\Hreflang\LinkBuilder;
use MageOS\Seo\Model\Hreflang\Resolver\CategoryHreflangResolver;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class CategoryHreflangResolverTest extends TestCase
{
    /**
     * @var CurrentEntity&Stub
     */
    private CurrentEntity&Stub $currentEntity;

    /**
     * @var LinkBuilder&Stub
     */
    private LinkBuilder&Stub $linkBuilder;

    /**
     * @var CategoryHreflangResolver
     */
    private CategoryHreflangResolver $resolver;

    protected function setUp(): void
    {
        $this->currentEntity    = $this->createStub(CurrentEntity::class);
        $this->linkBuilder = $this->createStub(LinkBuilder::class);
        $this->resolver    = $this->resolver();
    }

    /**
     * The resolver under test, over the given link builder or this test's stub.
     *
     * @param LinkBuilder|null $linkBuilder
     * @return CategoryHreflangResolver
     */
    private function resolver(?LinkBuilder $linkBuilder = null): CategoryHreflangResolver
    {
        return new CategoryHreflangResolver($this->currentEntity, $linkBuilder ?? $this->linkBuilder);
    }

    public function testHandlesCategoryView(): void
    {
        $this->assertSame(['catalog_category_view'], $this->resolver->getHandles());
    }

    public function testReturnsEmptyWhenNoCurrentCategory(): void
    {
        $this->currentEntity->method('getCategory')->willReturn(null);
        $this->assertSame([], $this->resolver->getLinks());
    }

    public function testDelegatesToLinkBuilderWithCategoryId(): void
    {
        $category = $this->createStub(CategoryInterface::class);
        $category->method('getId')->willReturn(7);
        $this->currentEntity->method('getCategory')->willReturn($category);

        $links = [['hreflang' => 'de-DE', 'url' => 'https://de/c', 'store_id' => 2]];
        $linkBuilder = $this->createMock(LinkBuilder::class);
        $linkBuilder->method('build')->with('category', 7)->willReturn($links);

        $this->assertSame($links, $this->resolver($linkBuilder)->getLinks());
    }
}
