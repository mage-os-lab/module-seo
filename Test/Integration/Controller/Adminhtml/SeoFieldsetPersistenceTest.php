<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller\Adminhtml;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category\DataProvider as CategoryFormDataProvider;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\MessageInterface;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\TestCase\AbstractBackendController;
use MageOS\Seo\Model\Category\ConfigRepository;
use MageOS\Seo\Model\Category\ProductOverrideRepository;

/**
 * Round trip of the category "SEO (Structured Data)" and product "Advanced SEO" fieldsets
 * through the real admin save controllers.
 *
 * Core's admin save controllers call the model's save() rather than the repositories, so
 * persistence must hook the save path the forms actually use. The category form data
 * provider also skips pool modifiers when loading data, so saved values must be injected
 * separately — otherwise every save would post the empty fields back over them.
 *
 * Database isolation is disabled: category SEO config is written from a commit callback,
 * and commit callbacks only run once the outermost transaction commits. Rows written here
 * are removed in tearDown(); data fixtures revert themselves.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation disabled
 */
class SeoFieldsetPersistenceTest extends AbstractBackendController
{
    private const OVERRIDE_JSON = '{"gtin13":"0123456789012","color":"Midnight Blue"}';

    /**
     * @var int[]
     */
    private static array $productIds = [];

    /**
     * @var int[]
     */
    private array $categoryIds = [];

    /**
     * @var int[]
     */
    private array $createdCategoryIds = [];

    /**
     * @var callable|null
     */
    private $errorHandlerBefore = null;

    /**
     * @var callable|null
     */
    private $exceptionHandlerBefore = null;

    /**
     * Remember the PHP error/exception handlers in place before the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->errorHandlerBefore     = $this->currentErrorHandler();
        $this->exceptionHandlerBefore = $this->currentExceptionHandler();
    }

    /**
     * Remove SEO rows and categories created by the tests.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->restoreHandlersRegisteredDuringTest();

        $resource   = $this->_objectManager->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        if ($this->productIds !== []) {
            $connection->delete(
                $resource->getTableName('mageos_seo_product_override'),
                ['product_id IN (?)' => $this->productIds]
            );
        }
        $categoryIds = array_merge($this->categoryIds, $this->createdCategoryIds);
        if ($categoryIds !== []) {
            $connection->delete(
                $resource->getTableName('mageos_seo_category_config'),
                ['category_id IN (?)' => $categoryIds]
            );
        }

        $categoryRepository = $this->_objectManager->get(CategoryRepositoryInterface::class);
        $registry           = $this->_objectManager->get(Registry::class);
        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);
        foreach ($this->createdCategoryIds as $categoryId) {
            try {
                $categoryRepository->deleteByIdentifier($categoryId);
            } catch (NoSuchEntityException) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
            }
        }
        $registry->unregister('isSecureArea');

        $this->productIds = $this->categoryIds = $this->createdCategoryIds = [];
        parent::tearDown();
    }

    /**
     * The product form's Advanced SEO fieldset is persisted at the default scope.
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testProductFieldsetIsSavedAtDefaultScope(): void
    {
        $productId = $this->fixtureId('product');

        $this->dispatchProductSave($productId, [
            'mageos_seo_override_fields' => self::OVERRIDE_JSON,
            'mageos_seo_robots_meta'     => 'NOINDEX,FOLLOW',
        ]);

        $this->assertSessionMessages(
            $this->containsEqual('You saved the product.'),
            MessageInterface::TYPE_SUCCESS
        );
        $this->assertSame(
            [
                'override_fields' => ['gtin13' => '0123456789012', 'color' => 'Midnight Blue'],
                'robots_meta'     => 'NOINDEX,FOLLOW',
            ],
            $this->freshProductOverrides()->getForProduct($productId, 0)
        );
    }

    /**
     * Saving the product form in a store view writes that store view's row only.
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testProductFieldsetIsSavedForTheSelectedStoreView(): void
    {
        $productId = $this->fixtureId('product');
        $storeId   = $this->defaultStoreViewId();

        $this->dispatchProductSave($productId, [
            'mageos_seo_robots_meta' => 'NOINDEX,NOFOLLOW',
        ], $storeId);

        $repository = $this->freshProductOverrides();
        $this->assertSame('NOINDEX,NOFOLLOW', $repository->getForProduct($productId, $storeId)['robots_meta']);
        $this->assertNull($repository->getForProduct($productId, 0)['robots_meta']);
    }

    /**
     * The rendered product edit form binds the Advanced SEO fieldset to the form's "data" branch
     * and carries the stored values there, so they are shown and submitted back on save.
     *
     * product_form.xml declares no form-level dataScope; with an empty fieldset scope the fields
     * bind outside "data", the form shows nothing and every save posts the untouched loaded values.
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testProductEditFormCarriesStoredSeoOverridesInItsDataBranch(): void
    {
        $productId = $this->fixtureId('product');
        $this->productIds[] = $productId;
        $this->_objectManager->get(ProductOverrideRepository::class)->save($productId, 0, [
            'override_fields' => ['color' => 'Midnight Blue'],
            'robots_meta'     => 'NOINDEX,FOLLOW',
        ]);

        $this->dispatch('backend/catalog/product/edit/id/' . $productId);
        $components = $this->renderedUiComponents($this->getResponse()->getBody());

        $fieldset = $this->findNode($components, 'mageos_seo_advanced');
        $this->assertNotNull($fieldset, 'The Advanced SEO fieldset was not rendered.');
        $this->assertSame('data', $fieldset['dataScope'] ?? $fieldset['config']['dataScope'] ?? null);

        $formData = $this->findNode($components, 'product_form_data_source')['config']['data'] ?? [];
        $this->assertSame('NOINDEX,FOLLOW', $formData['mageos_seo_robots_meta'] ?? null);
        $this->assertSame(
            ['color' => 'Midnight Blue'],
            json_decode((string) ($formData['mageos_seo_override_fields'] ?? ''), true)
        );
    }

    /**
     * The category form's SEO fieldset is persisted for an existing category.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testCategoryFieldsetIsSavedAtDefaultScope(): void
    {
        $categoryId = $this->fixtureId('category');

        $response = $this->dispatchCategorySave([
            'entity_id'                    => $categoryId,
            'store_id'                     => 0,
            'mageos_seo_schema_template'   => 'GenericProduct',
            'mageos_seo_enabled_fields'    => ['color', 'material'],
            'mageos_seo_item_list_enabled' => '0',
            'mageos_seo_robots_meta'       => 'NOINDEX,FOLLOW',
            'mageos_seo_override_fields'   => '{"gender":"Female"}',
        ]);

        $this->assertFalse($response['error'], 'Response messages: ' . $response['messages']);
        $config = $this->storedCategoryConfig($categoryId, 0);
        $this->assertNotSame([], $config, 'No SEO config row was stored for the category.');
        $this->assertSame('GenericProduct', $config['schema_template']);
        $this->assertSame(['color', 'material'], $config['enabled_fields']);
        $this->assertSame(0, (int) $config['item_list_enabled']);
        $this->assertSame('NOINDEX,FOLLOW', $config['robots_meta']);
        $this->assertSame(['gender' => 'Female'], $config['override_fields']);
    }

    /**
     * Saving the category form in a store view writes that store view's row only.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testCategoryFieldsetIsSavedForTheSelectedStoreView(): void
    {
        $categoryId = $this->fixtureId('category');
        $storeId    = $this->defaultStoreViewId();

        $response = $this->dispatchCategorySave([
            'entity_id'                  => $categoryId,
            'store_id'                   => $storeId,
            'mageos_seo_schema_template' => 'GenericProduct',
        ]);

        $this->assertFalse($response['error'], 'Response messages: ' . $response['messages']);
        $this->assertSame(
            'GenericProduct',
            $this->storedCategoryConfig($categoryId, $storeId)['schema_template'] ?? null
        );
        $this->assertSame([], $this->storedCategoryConfig($categoryId, 0));
    }

    /**
     * A category created from the form gets its SEO config under the new category's ID.
     *
     * @return void
     */
    public function testCategoryFieldsetIsSavedForANewCategory(): void
    {
        $response = $this->dispatchCategorySave([
            'parent'                     => 2,
            'name'                       => 'SEO fieldset new category',
            'is_active'                  => '1',
            'include_in_menu'            => '1',
            'mageos_seo_schema_template' => 'GenericProduct',
        ]);

        $this->assertFalse($response['error'], 'Response messages: ' . $response['messages']);
        $categoryId = (int) $response['category']['entity_id'];
        $this->createdCategoryIds[] = $categoryId;
        $this->assertSame('GenericProduct', $this->storedCategoryConfig($categoryId, 0)['schema_template'] ?? null);
    }

    /**
     * Invalid override JSON is reported as a warning, not an error: the category itself was
     * saved, and an error would make the controller treat the save as failed. The remaining
     * SEO fields are still stored.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testInvalidCategoryOverrideJsonIsAWarningAndOtherFieldsAreStillSaved(): void
    {
        $categoryId = $this->fixtureId('category');

        $response = $this->dispatchCategorySave([
            'entity_id'                  => $categoryId,
            'store_id'                   => 0,
            'mageos_seo_robots_meta'     => 'INDEX,NOFOLLOW',
            'mageos_seo_override_fields' => '{not json',
        ]);

        $this->assertFalse($response['error'], 'Response messages: ' . $response['messages']);
        $this->assertStringContainsString(
            'SEO override fields were not saved: the value is not valid JSON.',
            $response['messages']
        );
        $config = $this->storedCategoryConfig($categoryId, 0);
        $this->assertSame('INDEX,NOFOLLOW', $config['robots_meta'] ?? null);
        $this->assertSame([], $config['override_fields'] ?? null);
    }

    /**
     * The category form data provider loads the stored SEO config into the form.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testCategoryFormDataContainsStoredSeoConfig(): void
    {
        $categoryId = $this->fixtureId('category');
        $this->categoryIds[] = $categoryId;
        $this->_objectManager->get(ConfigRepository::class)->save($categoryId, [
            'schema_template' => 'GenericProduct',
            'robots_meta'     => 'NOINDEX,FOLLOW',
            'override_fields' => ['gender' => 'Female'],
        ]);

        $this->getRequest()->setParam('id', $categoryId);
        $provider = $this->_objectManager->create(CategoryFormDataProvider::class, [
            'name'             => 'category_form_data_source',
            'primaryFieldName' => 'entity_id',
            'requestFieldName' => 'id',
        ]);
        $formData = $provider->getData()[$categoryId] ?? [];

        $this->assertSame('GenericProduct', $formData['mageos_seo_schema_template'] ?? null);
        $this->assertSame('NOINDEX,FOLLOW', $formData['mageos_seo_robots_meta'] ?? null);
        $this->assertSame(
            ['gender' => 'Female'],
            json_decode((string) ($formData['mageos_seo_override_fields'] ?? ''), true)
        );
    }

    /**
     * Unwind error/exception handlers that the dispatched requests registered and left behind.
     *
     * Dispatching goes through App\Http::launch(), and plugins on it may register handlers for
     * the request without removing them (Mage-OS ships Swissup_Ignition, which does exactly
     * that). PHPUnit reports a test that leaves handlers behind as risky.
     *
     * @return void
     */
    private function restoreHandlersRegisteredDuringTest(): void
    {
        // Bounded: never unwind further than a handful of leftover registrations.
        $guard = 20;
        while ($guard-- > 0 && $this->currentErrorHandler() !== $this->errorHandlerBefore) {
            restore_error_handler();
        }
        $guard = 20;
        while ($guard-- > 0 && $this->currentExceptionHandler() !== $this->exceptionHandlerBefore) {
            restore_exception_handler();
        }
    }

    /**
     * The active PHP error handler (PHP < 8.5 has no getter).
     *
     * @return callable|null
     */
    private function currentErrorHandler(): ?callable
    {
        $handler = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        return $handler;
    }

    /**
     * The active PHP exception handler (PHP < 8.5 has no getter).
     *
     * @return callable|null
     */
    private function currentExceptionHandler(): ?callable
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }

    /**
     * Decode the UI component configuration embedded in a rendered admin page.
     *
     * @param string $html
     * @return array<mixed>
     */
    private function renderedUiComponents(string $html): array
    {
        preg_match_all('#<script type="text/x-magento-init">(.*?)</script>#s', $html, $matches);
        $components = [];
        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true);
            if (\is_array($decoded)) {
                $components[] = $decoded;
            }
        }

        return $components;
    }

    /**
     * Find the first array stored under the given key anywhere in a nested structure.
     *
     * @param array<mixed> $tree
     * @param string $key
     * @return array<mixed>|null
     */
    private function findNode(array $tree, string $key): ?array
    {
        if (isset($tree[$key]) && \is_array($tree[$key])) {
            return $tree[$key];
        }
        foreach ($tree as $value) {
            if (\is_array($value) && ($found = $this->findNode($value, $key)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Post the product edit form for an existing product.
     *
     * @param int $productId
     * @param array<string, mixed> $seoFields
     * @param int $storeId
     * @return void
     */
    private function dispatchProductSave(int $productId, array $seoFields, int $storeId = 0): void
    {
        $this->productIds[] = $productId;
        $this->getRequest()->setMethod(HttpRequest::METHOD_POST);
        $this->getRequest()->setPostValue($seoFields);
        $this->dispatch(\sprintf('backend/catalog/product/save/id/%d/store/%d', $productId, $storeId));
    }

    /**
     * Post the category edit form and return the controller's JSON response.
     *
     * @param array<string, mixed> $postData
     * @return array<string, mixed>
     */
    private function dispatchCategorySave(array $postData): array
    {
        if (isset($postData['entity_id'])) {
            $this->categoryIds[] = (int) $postData['entity_id'];
        }
        $postData += [
            // Satisfy the required sort/price attributes without depending on fixture data.
            'use_config' => [
                'available_sort_by'  => 'true',
                'default_sort_by'    => 'true',
                'filter_price_range' => 'true',
            ],
            'return_session_messages_only' => true,
        ];

        $this->getRequest()->setMethod(HttpRequest::METHOD_POST);
        $this->getRequest()->setPostValue($postData);
        $this->dispatch('backend/catalog/category/save');

        return $this->_objectManager->get(SerializerInterface::class)
            ->unserialize($this->getResponse()->getBody());
    }

    /**
     * Read the stored (not inherited) category SEO config for one store view, decoded.
     *
     * @param int $categoryId
     * @param int $storeId
     * @return mixed[]
     */
    private function storedCategoryConfig(int $categoryId, int $storeId): array
    {
        $resource   = $this->_objectManager->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        $row        = $connection->fetchRow(
            $connection->select()
                ->from($resource->getTableName('mageos_seo_category_config'))
                ->where('category_id = ?', $categoryId)
                ->where('store_id = ?', $storeId)
        );

        return \is_array($row) ? $this->_objectManager->get(ConfigRepository::class)->decode($row) : [];
    }

    /**
     * A product override repository without memoised rows from earlier reads.
     *
     * @return ProductOverrideRepository
     */
    private function freshProductOverrides(): ProductOverrideRepository
    {
        return $this->_objectManager->create(ProductOverrideRepository::class);
    }

    /**
     * ID of the default store view.
     *
     * @return int
     */
    private function defaultStoreViewId(): int
    {
        return (int) $this->_objectManager->get(StoreManagerInterface::class)->getStore('default')->getId();
    }

    /**
     * ID of an entity created by a data fixture.
     *
     * @param string $name
     * @return int
     */
    private function fixtureId(string $name): int
    {
        return (int) DataFixtureStorageManager::getStorage()->get($name)->getId();
    }
}
