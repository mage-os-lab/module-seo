<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap\ItemProvider;

use Magento\Catalog\Model\Product\Image\UrlBuilder;
use Magento\Framework\DataObject;
use Magento\Sitemap\Model\ResourceModel\Catalog\Product as CoreProductResource;
use Magento\Sitemap\Model\Source\Product\Image\IncludeImage;

/**
 * Turns a page of product rows into the objects core's sitemap product resource model builds.
 *
 * The same shape, built the same way, so the rows written from them match core's: an `id`, the URL
 * key (or `catalog/product/view/id/N` where the product has no rewrite), the row's other columns,
 * and — as the store's image policy says — an `images` object with the image collection, the
 * product name as title and a thumbnail.
 *
 * Two rules core applies per product are applied per page here:
 *
 * - A product with two matching URL rewrite rows comes out once, from its first row. (Core keeps
 *   whichever row its unordered query returns last; two such rows are a data fault either way.)
 * - A gallery listing the same file under two different value IDs lists it once, as core's
 *   per-product gallery read does — without the write that read makes: core's read deletes the
 *   duplicate gallery row as a side effect. Generating a sitemap must not change the catalogue.
 */
class ProductRowMapper
{
    /**
     * The image size core writes into the sitemap.
     */
    private const IMAGE_DISPLAY_AREA = 'product_page_image_large';

    /**
     * @param UrlBuilder $imageUrlBuilder
     */
    public function __construct(
        private readonly UrlBuilder $imageUrlBuilder
    ) {
    }

    /**
     * Map one page of rows, ordered by entity ID, to product objects.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<int|string,array<int,array<string,mixed>>> $gallery Link field value => the product's
     *        gallery rows in position order; used for the "all" image policy only
     * @param string $idField The entity ID column
     * @param string $linkField The column gallery rows are keyed by (row_id with content staging)
     * @param string $imagePolicy One of IncludeImage::INCLUDE_*
     * @return DataObject[]
     */
    public function map(array $rows, array $gallery, string $idField, string $linkField, string $imagePolicy): array
    {
        $products = [];
        foreach ($rows as $row) {
            $id = (string) $row[$idField];
            if (isset($products[$id])) {
                continue;
            }

            $product = new DataObject();
            $product->setData('id', $row[$idField]);
            if (empty($row['url'])) {
                $row['url'] = 'catalog/product/view/id/' . $row[$idField];
            }
            $product->addData($row);

            $this->addImages($product, $gallery[(string) ($row[$linkField] ?? '')] ?? [], $imagePolicy);

            $products[$id] = $product;
        }

        return array_values($products);
    }

    /**
     * Attach the images the policy asks for, as core's `_loadProductImages()` does.
     *
     * @param DataObject $product
     * @param array<int,array<string,mixed>> $gallery
     * @param string $imagePolicy
     * @return void
     */
    private function addImages(DataObject $product, array $gallery, string $imagePolicy): void
    {
        $images = [];
        if ($imagePolicy === IncludeImage::INCLUDE_ALL) {
            $images = $this->galleryImages($gallery);
        } elseif ($imagePolicy === IncludeImage::INCLUDE_BASE && $this->isSelected($product->getData('image'))) {
            $images = [new DataObject(['url' => $this->url((string) $product->getData('image'))])];
        }

        if ($images === []) {
            return;
        }

        $thumbnail = $product->getData('thumbnail');
        $product->setData('images', new DataObject([
            'collection' => $images,
            'title'      => $product->getData('name'),
            'thumbnail'  => $this->isSelected($thumbnail)
                ? $this->url((string) $thumbnail)
                : $images[0]->getData('url'),
        ]));
    }

    /**
     * The gallery's images, each file once.
     *
     * @param array<int,array<string,mixed>> $gallery
     * @return DataObject[]
     */
    private function galleryImages(array $gallery): array
    {
        $valueIdOf = [];
        $images    = [];
        foreach ($gallery as $image) {
            $file = (string) $image['file'];
            if (isset($valueIdOf[$file]) && $valueIdOf[$file] != $image['value_id']) {
                continue;
            }
            $valueIdOf[$file] ??= $image['value_id'];

            $images[] = new DataObject([
                'url'     => $this->url($file),
                'caption' => $image['label'] ?: $image['label_default'],
            ]);
        }

        return $images;
    }

    /**
     * Whether an image attribute names an image.
     *
     * @param mixed $image
     * @return bool
     */
    private function isSelected(mixed $image): bool
    {
        return !empty($image) && $image !== CoreProductResource::NOT_SELECTED_IMAGE;
    }

    /**
     * The image's URL at the size core writes.
     *
     * @param string $file
     * @return string
     */
    private function url(string $file): string
    {
        return $this->imageUrlBuilder->getUrl($file, self::IMAGE_DISPLAY_AREA);
    }
}
