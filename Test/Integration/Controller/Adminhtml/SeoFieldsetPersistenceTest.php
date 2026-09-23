<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller\Adminhtml;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category\DataProvider as CategoryFormDataProvider;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Page as CmsPage;
use Magento\Cms\Model\Page\DataProvider as CmsPageFormDataProvider;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\MessageInterface;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\PageCache\Model\Cache\Type as FullPageCache;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\TestCase\AbstractBackendController;
use MageOS\Seo\Model\Category\ConfigRepository;
use MageOS\Seo\Model\Category\ProductOverrideRepository;
use MageOS\Seo\Model\Cms\ConfigRepository as CmsConfigRepository;

/**
 * Round trip of the category "SEO (Structured Data)", product "Advanced SEO" and CMS page
 * "Search Engine Optimization" fieldsets through the real admin save controllers.
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
     * @var int[]|null
     */
    private ?array $productIds = [];

    /**
     * @var int[]|null
     */
    private ?array $categoryIds = [];

    /**
     * @var int[]|null
     */
    private ?array $createdCategoryIds = [];

    /**
     * @var int[]|null
     */
    private ?array $cmsPageIds = [];

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

        // Deleting a page takes its SEO rows with it (Observer\RemoveCmsPageConfigOnDelete); the
        // explicit delete covers a page the test failed before deleting.
        $this->_objectManager->get(CmsConfigRepository::class)->deleteForPages($this->cmsPageIds);
        $pageRepository = $this->_objectManager->get(PageRepositoryInterface::class);
        foreach ($this->cmsPageIds as $pageId) {
            try {
                $pageRepository->deleteById($pageId);
            } catch (NoSuchEntityException) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
            }
        }

        $this->productIds = $this->categoryIds = $this->createdCategoryIds = $this->cmsPageIds = [];
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
     * The product form offers one way to set no directive, and says where the product then goes.
     *
     * It used to offer two — its own "Use Category / Global Default" and the option source's
     * "Use Magento Default" — and the first was wrong: a product never reads its category's
     * directive.
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testProductRobotsFieldOffersOneEmptyOptionNamingTheProductDefault(): void
    {
        $this->dispatch('backend/catalog/product/edit/id/' . $this->fixtureId('product'));
        $components = $this->renderedUiComponents($this->getResponse()->getBody());

        $this->assertSame(
            ["Use the store's Product Pages default"],
            $this->emptyOptionLabels($this->findNode($components, 'mageos_seo_robots_meta'))
        );
    }

    /**
     * The category form offers one way to set no directive, and says it inherits.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testCategoryRobotsFieldOffersOneEmptyOptionThatInherits(): void
    {
        $this->dispatch('backend/catalog/category/edit/id/' . $this->fixtureId('category'));
        $components = $this->renderedUiComponents($this->getResponse()->getBody());

        $this->assertSame(
            ["Inherit (parent category, then the store's Category Pages default)"],
            $this->emptyOptionLabels($this->findNode($this->findNode($components, 'mageos_seo') ?? [], 'robots_meta'))
        );
    }

    /**
     * The CMS page form offers one way to set no directive, and names the CMS default.
     *
     * @return void
     */
    public function testCmsPageRobotsFieldOffersOneEmptyOptionNamingTheCmsDefault(): void
    {
        $pageId = $this->createCmsPage([$this->defaultStoreViewId()]);

        $this->dispatch('backend/cms/page/edit/page_id/' . $pageId);
        $components = $this->renderedUiComponents($this->getResponse()->getBody());

        $this->assertSame(
            ["Use the store's CMS Pages default"],
            $this->emptyOptionLabels($this->findNode($components, 'mageos_seo_robots_meta'))
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
     * The CMS page form shows the directive a save stored, for a page in a single store view.
     *
     * The CMS page form has no store switcher — its only scope is the page's store assignment — so
     * whatever row the save writes must be the row the form reads back.
     *
     * @return void
     */
    public function testCmsPageFormShowsTheStoredDirectiveForASingleStoreViewPage(): void
    {
        $storeId = $this->defaultStoreViewId();
        $pageId  = $this->createCmsPage([$storeId]);

        $this->dispatchCmsPageSave($pageId, [$storeId], 'NOINDEX,FOLLOW');

        $this->assertSame('NOINDEX,FOLLOW', $this->cmsPageFormData($pageId)['mageos_seo_robots_meta'] ?? null);
    }

    /**
     * Saving the CMS page form again, untouched, keeps the directive.
     *
     * Every save posts the field back as the form loaded it, so a form that loads the wrong row
     * turns an unrelated content edit into "Use Magento Default".
     *
     * @return void
     */
    public function testResavingAnUntouchedCmsPageFormKeepsTheDirective(): void
    {
        $storeId = $this->defaultStoreViewId();
        $pageId  = $this->createCmsPage([$storeId]);

        $this->dispatchCmsPageSave($pageId, [$storeId], 'NOINDEX,FOLLOW');
        $loaded = (string) ($this->cmsPageFormData($pageId)['mageos_seo_robots_meta'] ?? '');
        $this->resetRequest();
        $this->dispatchCmsPageSave($pageId, [$storeId], $loaded);

        $this->assertSame(
            'NOINDEX,FOLLOW',
            $this->_objectManager->create(CmsConfigRepository::class)->getForPage($pageId, $storeId)['robots_meta']
                ?? null
        );
    }

    /**
     * The translation group round-trips through the form, stored in its canonical form.
     *
     * @return void
     */
    public function testCmsPageTranslationGroupIsSavedNormalisedAndShownInTheForm(): void
    {
        $storeId = $this->defaultStoreViewId();
        $pageId  = $this->createCmsPage([$storeId]);

        $this->dispatchCmsPageSave($pageId, [$storeId], '', ' About-Us ');

        $this->assertSame(
            'about-us',
            $this->_objectManager->create(CmsConfigRepository::class)->getHreflangGroup($pageId)
        );
        $this->assertSame('about-us', $this->cmsPageFormData($pageId)['mageos_seo_hreflang_group'] ?? null);
    }

    /**
     * Joining a group changes the alternates of every page already in it, and those pages are
     * cached with their old head: their cached copies have to go.
     *
     * @magentoCache full_page enabled
     * @return void
     */
    public function testJoiningAGroupPurgesTheOtherTranslationsFromTheFullPageCache(): void
    {
        $storeId = $this->defaultStoreViewId();
        $member  = $this->createCmsPage([$storeId]);
        $this->_objectManager->get(CmsConfigRepository::class)->save($member, ['hreflang_group' => 'about-us']);
        $joiner  = $this->createCmsPage([$storeId]);

        $cache = $this->_objectManager->get(FullPageCache::class);
        $cache->save('<html>old alternates</html>', 'mageos_seo_group_member', ['cms_p_' . $member]);

        $this->dispatchCmsPageSave($joiner, [$storeId], '', 'about-us');

        $this->assertFalse($cache->load('mageos_seo_group_member'));
    }

    /**
     * Leaving a group purges the group left behind, whose pages listed this one.
     *
     * @magentoCache full_page enabled
     * @return void
     */
    public function testLeavingAGroupPurgesTheTranslationsLeftBehind(): void
    {
        $storeId    = $this->defaultStoreViewId();
        $repository = $this->_objectManager->get(CmsConfigRepository::class);
        $member     = $this->createCmsPage([$storeId]);
        $leaver     = $this->createCmsPage([$storeId]);
        $repository->save($member, ['hreflang_group' => 'about-us']);
        $repository->save($leaver, ['hreflang_group' => 'about-us']);

        $cache = $this->_objectManager->get(FullPageCache::class);
        $cache->save('<html>old alternates</html>', 'mageos_seo_group_member', ['cms_p_' . $member]);

        $this->dispatchCmsPageSave($leaver, [$storeId], '', '');

        $this->assertFalse($cache->load('mageos_seo_group_member'));
    }

    /**
     * Re-saving a page without touching its group leaves the other translations' cache alone.
     *
     * @magentoCache full_page enabled
     * @return void
     */
    public function testAnUnchangedGroupPurgesNothing(): void
    {
        $storeId    = $this->defaultStoreViewId();
        $repository = $this->_objectManager->get(CmsConfigRepository::class);
        $member     = $this->createCmsPage([$storeId]);
        $saved      = $this->createCmsPage([$storeId]);
        $repository->save($member, ['hreflang_group' => 'about-us']);
        $repository->save($saved, ['hreflang_group' => 'about-us']);

        $cache = $this->_objectManager->get(FullPageCache::class);
        $cache->save('<html>current alternates</html>', 'mageos_seo_group_member', ['cms_p_' . $member]);

        $this->dispatchCmsPageSave($saved, [$storeId], '', 'about-us');

        $this->assertSame('<html>current alternates</html>', $cache->load('mageos_seo_group_member'));
    }

    /**
     * A group that cannot be stored is reported, and the rest of the fieldset is still saved.
     *
     * @return void
     */
    public function testAnInvalidCmsPageTranslationGroupIsAWarning(): void
    {
        $storeId = $this->defaultStoreViewId();
        $pageId  = $this->createCmsPage([$storeId]);

        $this->dispatchCmsPageSave($pageId, [$storeId], 'NOINDEX,FOLLOW', 'about us!');

        // Session messages come back HTML-escaped, so match the part without the quoted example.
        $this->assertSessionMessages(
            $this->callback(static fn (array $messages): bool => str_contains(
                implode("\n", $messages),
                'The page was saved, but its hreflang translation group was not'
            )),
            MessageInterface::TYPE_WARNING
        );
        $repository = $this->_objectManager->create(CmsConfigRepository::class);
        $this->assertNull($repository->getHreflangGroup($pageId));
        $this->assertSame('NOINDEX,FOLLOW', $repository->getForPage($pageId)['robots_meta'] ?? null);
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
     * Labels of a rendered select field's empty-value options.
     *
     * @param array<mixed>|null $field
     * @return string[]
     */
    private function emptyOptionLabels(?array $field): array
    {
        $this->assertNotNull($field, 'The robots field was not rendered.');
        $options = $field['options'] ?? $field['config']['options'] ?? null;
        $this->assertIsArray($options, 'The robots field carries no options.');

        $labels = [];
        foreach ($options as $option) {
            if ((string) ($option['value'] ?? '') === '') {
                $labels[] = (string) ($option['label'] ?? '');
            }
        }

        return $labels;
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
     * A saved CMS page in the given store views, tracked for cleanup.
     *
     * @param int[] $storeIds
     * @return int
     */
    private function createCmsPage(array $storeIds): int
    {
        /** @var CmsPage $page */
        $page = $this->_objectManager->create(CmsPage::class);
        $page->setTitle('MageOS SEO fieldset')
            ->setIdentifier('mageos-seo-fieldset-' . uniqid('', false))
            ->setIsActive(true)
            ->setContent('<p>Test</p>')
            ->setPageLayout('1column')
            ->setStores($storeIds);

        $pageId = (int) $this->_objectManager->get(PageRepositoryInterface::class)->save($page)->getId();
        $this->cmsPageIds[] = $pageId;

        return $pageId;
    }

    /**
     * Post the CMS page edit form for an existing page.
     *
     * @param int $pageId
     * @param int[] $storeIds The page's store assignment, as the form's multiselect posts it
     * @param string $robotsMeta
     * @param string $hreflangGroup
     * @return void
     */
    private function dispatchCmsPageSave(
        int $pageId,
        array $storeIds,
        string $robotsMeta,
        string $hreflangGroup = ''
    ): void {
        $page = $this->_objectManager->get(PageRepositoryInterface::class)->getById($pageId);

        $this->getRequest()->setMethod(HttpRequest::METHOD_POST);
        $this->getRequest()->setPostValue([
            'page_id'                   => $pageId,
            'title'                     => $page->getTitle(),
            'identifier'                => $page->getIdentifier(),
            'is_active'                 => '1',
            'page_layout'               => '1column',
            'content'                   => $page->getContent(),
            'store_id'                  => array_map('strval', $storeIds),
            'mageos_seo_robots_meta'    => $robotsMeta,
            'mageos_seo_hreflang_group' => $hreflangGroup,
        ]);
        $this->dispatch('backend/cms/page/save/page_id/' . $pageId);

        $this->assertSessionMessages(
            $this->containsEqual('You saved the page.'),
            MessageInterface::TYPE_SUCCESS
        );
    }

    /**
     * What the CMS page form loads for a page.
     *
     * @param int $pageId
     * @return array<string, mixed>
     */
    private function cmsPageFormData(int $pageId): array
    {
        $this->getRequest()->setParam('page_id', $pageId);
        $provider = $this->_objectManager->create(CmsPageFormDataProvider::class, [
            'name'             => 'cms_page_form_data_source',
            'primaryFieldName' => 'page_id',
            'requestFieldName' => 'page_id',
        ]);

        return $provider->getData()[$pageId] ?? [];
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
