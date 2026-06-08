<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Canonical;

use Magento\Framework\View\Asset\GroupedCollection;
use Magento\Framework\View\Page\Config as PageConfig;
use MageOS\Seo\Model\Canonical\CanonicalUrlManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CanonicalUrlManagerTest extends TestCase
{
    /**
     * @var PageConfig&MockObject
     */
    private PageConfig&MockObject $pageConfig;

    /**
     * @var GroupedCollection&MockObject
     */
    private GroupedCollection&MockObject $assetCollection;

    /**
     * @var CanonicalUrlManager
     */
    private CanonicalUrlManager $manager;

    protected function setUp(): void
    {
        $this->pageConfig      = $this->createMock(PageConfig::class);
        $this->assetCollection = $this->createMock(GroupedCollection::class);
        $this->pageConfig->method('getAssetCollection')->willReturn($this->assetCollection);
        $this->manager = new CanonicalUrlManager();
    }

    public function testSetCanonicalCallsAddRemotePageAsset(): void
    {
        $this->assetCollection->method('getAll')->willReturn([]);
        $this->pageConfig
            ->expects($this->once())
            ->method('addRemotePageAsset')
            ->with(
                'https://example.com/my-product',
                'canonical',
                ['attributes' => ['rel' => 'canonical']]
            );

        $this->manager->setCanonical('https://example.com/my-product', $this->pageConfig);
    }

    public function testSetCanonicalWithUrlKeyRemovesMatchingAsset(): void
    {
        $existingAssets = [
            'https://example.com/my-product' => 'asset',
            'styles.css' => 'css-asset',
        ];
        $this->assetCollection->method('getAll')->willReturn($existingAssets);
        $this->assetCollection
            ->expects($this->once())
            ->method('remove')
            ->with('https://example.com/my-product');

        $this->pageConfig->method('addRemotePageAsset')->willReturnSelf();

        $this->manager->setCanonical(
            'https://example.com/my-product?variant=red',
            $this->pageConfig,
            'my-product'
        );
    }

    public function testSetCanonicalWithUrlKeyRemovesHtmlSuffixVariant(): void
    {
        $existingAssets = [
            'https://example.com/my-product.html' => 'asset',
        ];
        $this->assetCollection->method('getAll')->willReturn($existingAssets);
        $this->assetCollection
            ->expects($this->once())
            ->method('remove')
            ->with('https://example.com/my-product.html');

        $this->pageConfig->method('addRemotePageAsset')->willReturnSelf();

        $this->manager->setCanonical(
            'https://example.com/my-product',
            $this->pageConfig,
            'my-product'
        );
    }

    public function testSetCanonicalWithEmptyUrlKeySkipsRemoval(): void
    {
        $this->assetCollection->expects($this->never())->method('getAll');
        $this->assetCollection->expects($this->never())->method('remove');
        $this->pageConfig->method('addRemotePageAsset')->willReturnSelf();

        $this->manager->setCanonical('https://example.com/page', $this->pageConfig, '');
    }

    public function testSetCanonicalWithoutUrlKeyDefaultSkipsRemoval(): void
    {
        $this->assetCollection->expects($this->never())->method('getAll');
        $this->assetCollection->expects($this->never())->method('remove');
        $this->pageConfig->method('addRemotePageAsset')->willReturnSelf();

        $this->manager->setCanonical('https://example.com/page', $this->pageConfig);
    }

    public function testRemovalDoesNotAffectNonMatchingAssets(): void
    {
        $existingAssets = [
            'https://example.com/other-product' => 'asset',
            'https://example.com/my-product-extended' => 'asset2',
        ];
        $this->assetCollection->method('getAll')->willReturn($existingAssets);
        $this->assetCollection->expects($this->never())->method('remove');
        $this->pageConfig->method('addRemotePageAsset')->willReturnSelf();

        $this->manager->setCanonical(
            'https://example.com/my-product',
            $this->pageConfig,
            'my-product'
        );
    }

    public function testSetCanonicalAlwaysAddsNewCanonicalEvenAfterRemoval(): void
    {
        $existingAssets = ['https://example.com/product.html' => 'asset'];
        $this->assetCollection->method('getAll')->willReturn($existingAssets);
        $this->assetCollection->method('remove');

        $this->pageConfig
            ->expects($this->once())
            ->method('addRemotePageAsset')
            ->with('https://example.com/product?variant=blue', 'canonical', $this->anything());

        $this->manager->setCanonical(
            'https://example.com/product?variant=blue',
            $this->pageConfig,
            'product'
        );
    }
}
