<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Sitemap\ItemProvider;

use Magento\Catalog\Model\Product\Image\UrlBuilder;
use Magento\Framework\DataObject;
use MageOS\Seo\Model\Sitemap\ItemProvider\ProductRowMapper;
use PHPUnit\Framework\TestCase;

/**
 * Rows to the objects core's sitemap product resource model builds. That the result writes what
 * core's generator writes, on a real install, is covered by
 * Test/Integration/Model/Sitemap/ProductStreamTest.
 */
class ProductRowMapperTest extends TestCase
{
    public function testARowBecomesCoresProductObject(): void
    {
        $product = $this->only($this->mapper()->map(
            [['entity_id' => '7', 'row_id' => '7', 'updated_at' => '2026-09-01', 'url' => 'shirt.html']],
            [],
            'entity_id',
            'row_id',
            'none'
        ));

        $this->assertSame('7', $product->getData('id'));
        $this->assertSame('shirt.html', $product->getData('url'));
        $this->assertSame('2026-09-01', $product->getData('updated_at'));
        $this->assertNull($product->getData('images'));
    }

    public function testAProductWithoutARewriteIsListedByItsCatalogUrl(): void
    {
        $product = $this->only($this->mapper()->map(
            [['entity_id' => '7', 'url' => null]],
            [],
            'entity_id',
            'entity_id',
            'none'
        ));

        $this->assertSame('catalog/product/view/id/7', $product->getData('url'));
    }

    public function testAProductWithTwoRewriteRowsComesOutOnceFromItsFirst(): void
    {
        $products = $this->mapper()->map(
            [
                ['entity_id' => '7', 'url' => 'first.html'],
                ['entity_id' => '7', 'url' => 'second.html'],
                ['entity_id' => '8', 'url' => 'other.html'],
            ],
            [],
            'entity_id',
            'entity_id',
            'none'
        );

        $this->assertSame(['first.html', 'other.html'], array_map(fn ($p) => $p->getData('url'), $products));
    }

    public function testAllImagesComeFromTheGalleryWithCaptionsFallingBackToTheDefaultLabel(): void
    {
        $product = $this->only($this->mapper()->map(
            [['entity_id' => '7', 'url' => 'shirt.html', 'name' => 'Shirt', 'thumbnail' => '/t/h/thumb.jpg']],
            ['7' => [
                $this->image(1, '/f/r/front.jpg', 'Front', 'Default front'),
                $this->image(2, '/b/a/back.jpg', null, 'Default back'),
            ]],
            'entity_id',
            'entity_id',
            'all'
        ));

        $images = $product->getData('images');
        $this->assertInstanceOf(DataObject::class, $images);
        $this->assertSame('Shirt', $images->getData('title'));
        $this->assertSame('media:/t/h/thumb.jpg', $images->getData('thumbnail'));
        $this->assertSame(
            [
                ['url' => 'media:/f/r/front.jpg', 'caption' => 'Front'],
                ['url' => 'media:/b/a/back.jpg', 'caption' => 'Default back'],
            ],
            array_map(static fn (DataObject $image) => $image->getData(), $images->getData('collection'))
        );
    }

    public function testWithoutAThumbnailTheFirstImageIsTheThumbnail(): void
    {
        foreach ([null, 'no_selection'] as $thumbnail) {
            $product = $this->only($this->mapper()->map(
                [['entity_id' => '7', 'url' => 'a.html', 'thumbnail' => $thumbnail]],
                ['7' => [$this->image(1, '/f/r/front.jpg', 'Front', null)]],
                'entity_id',
                'entity_id',
                'all'
            ));

            $this->assertSame('media:/f/r/front.jpg', $product->getData('images')->getData('thumbnail'));
        }
    }

    /**
     * The same file under two value IDs is listed once, as core's per-product gallery read lists
     * it. The same value ID twice is not a duplicate there, and is not here.
     */
    public function testAFileRepeatedUnderAnotherValueIdIsListedOnce(): void
    {
        $product = $this->only($this->mapper()->map(
            [['entity_id' => '7', 'url' => 'a.html']],
            ['7' => [
                $this->image(1, '/f/r/front.jpg', 'Front', null),
                $this->image(2, '/f/r/front.jpg', 'Copy', null),
                $this->image(1, '/f/r/front.jpg', 'Same row again', null),
            ]],
            'entity_id',
            'entity_id',
            'all'
        ));

        $this->assertSame(
            ['Front', 'Same row again'],
            array_map(
                static fn (DataObject $image) => $image->getData('caption'),
                $product->getData('images')->getData('collection')
            )
        );
    }

    public function testTheGalleryIsMatchedByTheLinkField(): void
    {
        // With content staging the gallery is keyed by row_id, not entity_id.
        $product = $this->only($this->mapper()->map(
            [['entity_id' => '7', 'row_id' => '70', 'url' => 'a.html']],
            [
                '70' => [$this->image(1, '/f/r/front.jpg', 'Front', null)],
                '7'  => [$this->image(2, '/w/r/ong.jpg', 'Wrong', null)],
            ],
            'entity_id',
            'row_id',
            'all'
        ));

        $this->assertSame('Front', $product->getData('images')->getData('collection')[0]->getData('caption'));
    }

    public function testTheBaseImageAloneWhenThePolicyIsBase(): void
    {
        $product = $this->only($this->mapper()->map(
            [['entity_id' => '7', 'url' => 'a.html', 'name' => 'Shirt', 'image' => '/b/a/base.jpg']],
            ['7' => [$this->image(1, '/f/r/front.jpg', 'Front', null)]],
            'entity_id',
            'entity_id',
            'base'
        ));

        $images = $product->getData('images');
        $this->assertSame([['url' => 'media:/b/a/base.jpg']], array_map(
            static fn (DataObject $image) => $image->getData(),
            $images->getData('collection')
        ));
        $this->assertSame('media:/b/a/base.jpg', $images->getData('thumbnail'));
    }

    public function testNoBaseImageMeansNoImages(): void
    {
        foreach ([null, '', 'no_selection'] as $image) {
            $product = $this->only($this->mapper()->map(
                [['entity_id' => '7', 'url' => 'a.html', 'image' => $image]],
                [],
                'entity_id',
                'entity_id',
                'base'
            ));

            $this->assertNull($product->getData('images'));
        }
    }

    public function testNoImagesWhenThePolicyIsNone(): void
    {
        $product = $this->only($this->mapper()->map(
            [['entity_id' => '7', 'url' => 'a.html', 'image' => '/b/a/base.jpg']],
            ['7' => [$this->image(1, '/f/r/front.jpg', 'Front', null)]],
            'entity_id',
            'entity_id',
            'none'
        ));

        $this->assertNull($product->getData('images'));
    }

    /**
     * @return ProductRowMapper
     */
    private function mapper(): ProductRowMapper
    {
        $urlBuilder = $this->createStub(UrlBuilder::class);
        $urlBuilder->method('getUrl')->willReturnCallback(static fn (string $file): string => 'media:' . $file);

        return new ProductRowMapper($urlBuilder);
    }

    /**
     * A gallery row as core's batch gallery select returns it.
     *
     * @param int $valueId
     * @param string $file
     * @param string|null $label
     * @param string|null $labelDefault
     * @return array<string,mixed>
     */
    private function image(int $valueId, string $file, ?string $label, ?string $labelDefault): array
    {
        return ['value_id' => $valueId, 'file' => $file, 'label' => $label, 'label_default' => $labelDefault];
    }

    /**
     * @param DataObject[] $products
     * @return DataObject
     */
    private function only(array $products): DataObject
    {
        $this->assertCount(1, $products);

        return $products[0];
    }
}
