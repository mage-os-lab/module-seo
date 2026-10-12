<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller\Adminhtml;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Filesystem;
use Magento\Framework\FlagManager;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\TestCase\AbstractBackendController;
use MageOS\Seo\Model\Sitemap\RebuildableSitemaps;
use MageOS\Seo\Test\Integration\Model\Sitemap\GeneratesSitemaps;

/**
 * Issue #23 through the admin's product form: the form posts the whole gallery back, in its own
 * shape, on every save — so a save that changes nothing about the images must queue nothing, and
 * one that changes a caption or removes an image must queue the products.
 *
 * The gallery is posted as the form's gallery widget posts it: per image its position, file,
 * value ID, label, hidden flag, media type and an empty "removed", all strings
 * (Magento_Catalog's adminhtml template catalog/product/helper/gallery.phtml). One dispatch per
 * test: a second admin dispatch in one test does not survive.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation disabled
 */
class ProductGallerySitemapTest extends AbstractBackendController
{
    use GeneratesSitemaps;

    private const PENDING = 'mageos_seo_feed_pending_sitemap-products';

    /**
     * A 1×1 PNG.
     */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9a'
        . 'wAAAABJRU5ErkJggg==';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemaps();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeGeneratedSitemaps();
        $this->_objectManager->get(FlagManager::class)->deleteFlag(self::PENDING);

        parent::tearDown();
    }

    /**
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testSavingTheFormWithTheGalleryUnchangedQueuesNothing(): void
    {
        $this->saveForm($this->withAnImage(), []);

        $this->assertNull($this->pending(), 'An untouched gallery queued the products.');
    }

    /**
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testANewCaptionQueuesTheProducts(): void
    {
        $this->saveForm($this->withAnImage(), ['label' => 'A new caption']);

        $this->assertNotNull($this->pending());
    }

    /**
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testRemovingTheImageQueuesTheProducts(): void
    {
        $this->saveForm($this->withAnImage(), ['removed' => '1']);

        $this->assertNotNull($this->pending());
    }

    /**
     * Give the fixture product an image, captioned, in every role; make a sitemap rebuildable; and
     * forget what was queued so far. Returns the product as saved.
     *
     * @return ProductInterface
     */
    private function withAnImage(): ProductInterface
    {
        $media  = $this->_objectManager->get(Filesystem::class)->getDirectoryWrite(DirectoryList::MEDIA);
        $tmp    = $this->_objectManager->get(MediaConfig::class)->getBaseTmpMediaPath();
        $file   = $tmp . '/mageos_seo_' . uniqid() . '.png';
        $media->writeFile($file, (string) base64_decode(self::PNG));

        $repository = $this->_objectManager->get(ProductRepositoryInterface::class);
        $sku        = (string) DataFixtureStorageManager::getStorage()->get('product')->getSku();
        $product    = $repository->get($sku, true, 0, true);
        $product->addImageToMediaGallery($file, ['image', 'small_image', 'thumbnail'], true, false);
        $gallery = $product->getData('media_gallery');
        foreach ($gallery['images'] as $key => $image) {
            $gallery['images'][$key]['label'] = 'Front';
        }
        $product->setData('media_gallery', $gallery);
        $repository->save($product);

        $sitemap = $this->sitemapFor($this->defaultStoreId());
        $sitemap->setSitemapTime('2026-01-01 00:00:00');
        $sitemap->save();
        $this->_objectManager->get(RebuildableSitemaps::class)->_resetState();
        $this->_objectManager->get(FlagManager::class)->deleteFlag(self::PENDING);

        return $repository->get($sku, true, 0, true);
    }

    /**
     * Post the product form with its gallery as the widget posts it, each image changed as given.
     *
     * @param ProductInterface $product
     * @param array<string,string> $changes
     * @return void
     */
    private function saveForm(ProductInterface $product, array $changes): void
    {
        $images = [];
        foreach ((array) ($product->getData('media_gallery')['images'] ?? []) as $image) {
            $images['image_' . $image['value_id']] = $changes + [
                'position'   => (string) $image['position'],
                'file'       => (string) $image['file'],
                'value_id'   => (string) $image['value_id'],
                'label'      => (string) $image['label'],
                'disabled'   => (string) $image['disabled'],
                'media_type' => 'image',
                'removed'    => '',
            ];
        }
        $this->assertNotSame([], $images, 'The product has no image to post.');

        // The form always posts the product's websites and categories: missing, the save takes
        // them as none, and unassigns the product.
        $websiteIds = array_map('strval', (array) $product->getWebsiteIds());
        $this->getRequest()->setMethod(HttpRequest::METHOD_POST);
        $this->getRequest()->setPostValue(['product' => [
            'website_ids'   => array_combine($websiteIds, $websiteIds),
            'category_ids'  => array_map('strval', (array) $product->getCategoryIds()),
            'media_gallery' => ['images' => $images],
        ]]);
        $this->dispatch(\sprintf('backend/catalog/product/save/id/%d/store/0', (int) $product->getId()));
        $this->assertSessionMessages($this->isEmpty(), \Magento\Framework\Message\MessageInterface::TYPE_ERROR);
    }

    /**
     * When the products were queued, or null.
     *
     * @return mixed
     */
    private function pending(): mixed
    {
        return $this->_objectManager->get(FlagManager::class)->getFlagData(self::PENDING);
    }
}
