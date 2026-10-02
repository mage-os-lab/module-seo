<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Canonical;

use Magento\Framework\View\Asset\AssetInterface;
use Magento\Framework\View\Asset\GroupedCollection;
use Magento\Framework\View\Page\Config as PageConfig;
use MageOS\Seo\Model\Canonical\CanonicalUrlManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class CanonicalUrlManagerTest extends TestCase
{
    /**
     * @var GroupedCollection&Stub
     */
    private GroupedCollection&Stub $assetCollection;

    /**
     * @var CanonicalUrlManager
     */
    private CanonicalUrlManager $manager;

    protected function setUp(): void
    {
        $this->assetCollection = $this->createStub(GroupedCollection::class);
        $this->manager = new CanonicalUrlManager();
    }

    /**
     * Build an asset mock reporting the given content type.
     */
    private function makeAsset(string $contentType): AssetInterface&Stub
    {
        $asset = $this->createStub(AssetInterface::class);
        $asset->method('getContentType')->willReturn($contentType);
        return $asset;
    }

    /**
     * A page config whose asset collection is the given one.
     *
     * @param GroupedCollection $assetCollection
     * @return PageConfig&Stub
     */
    private function pageConfig(GroupedCollection $assetCollection): PageConfig&Stub
    {
        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getAssetCollection')->willReturn($assetCollection);

        return $pageConfig;
    }

    /**
     * The same page config as a mock, for a test that verifies what is added to the page.
     *
     * @param GroupedCollection $assetCollection
     * @return PageConfig&MockObject
     */
    private function pageConfigMock(GroupedCollection $assetCollection): PageConfig&MockObject
    {
        $pageConfig = $this->createMock(PageConfig::class);
        $pageConfig->method('getAssetCollection')->willReturn($assetCollection);

        return $pageConfig;
    }

    public function testSetCanonicalCallsAddRemotePageAsset(): void
    {
        $this->assetCollection->method('getAll')->willReturn([]);
        $pageConfig = $this->pageConfigMock($this->assetCollection);
        $pageConfig
            ->expects($this->once())
            ->method('addRemotePageAsset')
            ->with(
                'https://example.com/my-product',
                'canonical',
                ['attributes' => ['rel' => 'canonical']]
            );

        $this->manager->setCanonical('https://example.com/my-product', $pageConfig);
    }

    public function testSetCanonicalRemovesExistingCanonicalAssets(): void
    {
        $assetCollection = $this->createMock(GroupedCollection::class);
        $assetCollection->method('getAll')->willReturn([
            'https://example.com/my-product' => $this->makeAsset('canonical'),
            'css/styles.css'                 => $this->makeAsset('css'),
        ]);
        $assetCollection
            ->expects($this->once())
            ->method('remove')
            ->with('https://example.com/my-product');

        $pageConfig = $this->pageConfig($assetCollection);
        $pageConfig->method('addRemotePageAsset')->willReturnSelf();

        $this->manager->setCanonical('https://example.com/my-product?variant=red', $pageConfig);
    }

    public function testRemovalIgnoresNonCanonicalAssetsWhoseIdentifierMatchesUrlKey(): void
    {
        // Regression: identifier-pattern matching removed css/print.css for a product
        // with url_key "print" — only content type "canonical" may be removed.
        $assetCollection = $this->createMock(GroupedCollection::class);
        $assetCollection->method('getAll')->willReturn([
            'css/print.css' => $this->makeAsset('css'),
            'js/print.js'   => $this->makeAsset('js'),
        ]);
        $assetCollection->expects($this->never())->method('remove');
        $pageConfig = $this->pageConfig($assetCollection);
        $pageConfig->method('addRemotePageAsset')->willReturnSelf();

        $this->manager->setCanonical('https://example.com/print', $pageConfig, 'print');
    }

    public function testSetCanonicalAlwaysAddsNewCanonicalEvenAfterRemoval(): void
    {
        $this->assetCollection->method('getAll')->willReturn([
            'https://example.com/product.html' => $this->makeAsset('canonical'),
        ]);
        $this->assetCollection->method('remove');

        $pageConfig = $this->pageConfigMock($this->assetCollection);
        $pageConfig
            ->expects($this->once())
            ->method('addRemotePageAsset')
            ->with('https://example.com/product?variant=blue', 'canonical', $this->anything());

        $this->manager->setCanonical('https://example.com/product?variant=blue', $pageConfig, 'product');
    }
}
