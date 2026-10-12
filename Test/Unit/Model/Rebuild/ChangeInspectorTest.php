<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Rebuild;

use Magento\Catalog\Model\Product;
use MageOS\Seo\Model\Rebuild\ChangeInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #23: a gallery change is a change to what the sitemap reads of it — an image added or
 * removed, its caption, whether it is hidden, its place — not to the shape it was posted in.
 */
class ChangeInspectorTest extends TestCase
{
    /**
     * The gallery as loaded: one image with a caption of its own, one with the default's.
     */
    private const LOADED = ['images' => [
        7 => ['value_id' => 7, 'file' => '/s/h/front.jpg', 'label' => 'Front', 'label_default' => 'Front',
            'disabled' => 0, 'disabled_default' => 0, 'position' => 1, 'position_default' => 1],
        8 => ['value_id' => 8, 'file' => '/s/h/back.jpg', 'label' => null, 'label_default' => 'Back',
            'disabled' => null, 'disabled_default' => 0, 'position' => null, 'position_default' => 2],
    ]];

    /**
     * @dataProvider galleries
     * @param array<string,mixed>|null $saved The gallery as saved; null for none
     * @param bool $changed
     * @return void
     */
    #[DataProvider('galleries')]
    public function testOnlyWhatTheSitemapReadsCounts(?array $saved, bool $changed): void
    {
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $product->setOrigData('media_gallery', self::LOADED);
        $product->setData('media_gallery', $saved);

        $this->assertSame($changed, (new ChangeInspector())->galleryChanged($product));
    }

    /**
     * @return array<string,array{array<string,mixed>|null,bool}>
     */
    public static function galleries(): array
    {
        return [
            'as the admin posts it, unchanged' => [self::posted([]), false],
            'a caption changed'                => [self::posted(['front' => ['label' => 'Front side']]), true],
            'a caption given where the default was shown' => [self::posted(['back' => ['label' => 'Rear']]), true],
            'an image hidden'                  => [self::posted(['back' => ['disabled' => '1']]), true],
            'an image moved'                   => [self::posted(['front' => ['position' => '3']]), true],
            'an image removed'                 => [self::posted(['back' => ['removed' => '1']]), true],
            'an image added'                   => [self::posted([], ['file' => '/n/e/new.jpg', 'label' => '']), true],
            'the gallery left out of the save' => [null, false],
        ];
    }

    /**
     * The form posts the images in its own order, an image never captioned with an empty caption,
     * a store view's own caption as shown — not its default — and an image uploaded and removed
     * again before saving with "removed" set: none is a change.
     *
     * @return void
     */
    public function testWhatTheFormAddsOrReordersIsNoChange(): void
    {
        $loaded = ['images' => [
            7 => ['value_id' => 7, 'file' => '/s/h/front.jpg', 'label' => null, 'label_default' => null,
                'disabled' => 0, 'position' => 1],
            8 => ['value_id' => 8, 'file' => '/s/h/side.jpg', 'label' => '', 'label_default' => 'Side',
                'disabled' => 0, 'position' => 2],
            9 => ['value_id' => 9, 'file' => '/s/h/back.jpg', 'label' => 'Back, in this store view',
                'label_default' => 'Back', 'disabled' => 0, 'position' => 3],
        ]];
        $posted = ['images' => [
            'gone'  => ['file' => '/n/e/never-saved.jpg', 'label' => '', 'disabled' => '0', 'position' => '0',
                'removed' => '1'],
            'side'  => ['value_id' => '8', 'file' => '/s/h/side.jpg', 'label' => 'Side', 'disabled' => '0',
                'position' => '2', 'removed' => ''],
            'front' => ['value_id' => '7', 'file' => '/s/h/front.jpg', 'label' => '', 'disabled' => '0',
                'position' => '1', 'removed' => ''],
            'back'  => ['value_id' => '9', 'file' => '/s/h/back.jpg', 'label' => 'Back, in this store view',
                'disabled' => '0', 'position' => '3', 'removed' => ''],
        ]];

        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $product->setOrigData('media_gallery', $loaded);
        $product->setData('media_gallery', $posted);

        $this->assertFalse((new ChangeInspector())->galleryChanged($product));
    }

    /**
     * A product never loaded with its gallery has none to compare: one saved with images changed it.
     *
     * @return void
     */
    public function testAGalleryNotLoadedBeforeIsAChangeWhenOneIsSaved(): void
    {
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $product->setData('media_gallery', self::posted([]));

        $this->assertTrue((new ChangeInspector())->galleryChanged($product));
    }

    /**
     * The gallery as the admin's product form posts it — strings, its own keys, an empty "removed" —
     * with changes per image, and an image added.
     *
     * @param array<string,array<string,string>> $changes 'front' or 'back' => fields
     * @param array<string,string>|null $added
     * @return array<string,mixed>
     */
    private static function posted(array $changes, ?array $added = null): array
    {
        $images = [
            'front' => ['value_id' => '7', 'file' => '/s/h/front.jpg', 'label' => 'Front', 'disabled' => '0',
                'position' => '1', 'media_type' => 'image', 'removed' => ''],
            'back'  => ['value_id' => '8', 'file' => '/s/h/back.jpg', 'label' => 'Back', 'disabled' => '0',
                'position' => '2', 'media_type' => 'image', 'removed' => ''],
        ];
        foreach ($changes as $image => $fields) {
            $images[$image] = $fields + $images[$image];
        }
        if ($added !== null) {
            $images['new'] = $added + ['position' => '3', 'disabled' => '0', 'media_type' => 'image', 'removed' => ''];
        }

        return ['images' => $images];
    }
}
