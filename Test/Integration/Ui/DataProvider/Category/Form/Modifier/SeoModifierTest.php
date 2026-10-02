<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Ui\DataProvider\Category\Form\Modifier;

use Magento\Catalog\Model\Category\DataProvider as CategoryFormDataProvider;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Category\ConfigRepository;
use MageOS\Seo\Model\Config as SeoConfig;
use MageOS\Seo\Model\Product\SchemaBuilderPool;
use MageOS\Seo\Model\ResourceModel\CategoryConfig\CollectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * The category form's Enabled Optional Fields lists the fields of the template in effect.
 *
 * The multiselect was built with no options at all, so no field could be chosen there: the only
 * way a template's optional field reached the storefront was an override value. The form is built
 * through core's category data provider, which applies the modifier pool as the edit page does.
 *
 * The store's default template is set on the admin store (ID 0), which is what the form reads when
 * it edits the default scope. A default-scope #[Config] would not reach it: the test framework
 * writes that into the default scope's data alone, after the stores' merged data was built.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation disabled
 */
class SeoModifierTest extends TestCase
{
    /**
     * Category IDs whose configuration this test wrote.
     *
     * @var int[]|null
     */
    private ?array $writtenCategoryIds = [];

    /**
     * Remove the rows the test wrote and the request parameters it set; the fixtures revert
     * themselves.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->editing(null, null);

        if ($this->writtenCategoryIds !== []) {
            $collection = Bootstrap::getObjectManager()->create(CollectionFactory::class)->create();
            $collection->addFieldToFilter('category_id', ['in' => $this->writtenCategoryIds]);
            foreach ($collection as $config) {
                $config->delete();
            }
        }
        $this->writtenCategoryIds = [];

        parent::tearDown();
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testACategorysOwnTemplateListsItsFields(): void
    {
        $categoryId = $this->categoryId('category');
        $this->configRepository()->save($categoryId, ['schema_template' => 'Apparel']);

        $this->assertSame($this->fieldsOf('Apparel'), $this->enabledFieldOptions($categoryId));
    }

    /**
     * @return void
     */
    #[Config(SeoConfig::XML_SD_DEFAULT_TEMPLATE, 'Book', ScopeInterface::SCOPE_STORE, 'admin')]
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testAnUnconfiguredCategoryListsTheDefaultTemplatesFields(): void
    {
        $this->assertSame($this->fieldsOf('Book'), $this->enabledFieldOptions($this->categoryId('category')));
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'parent')]
    #[DataFixture(CategoryFixture::class, ['parent_id' => '$parent.id$'], 'child')]
    public function testAChildListsTheFieldsOfTheTemplateItInherits(): void
    {
        $this->configRepository()->save($this->categoryId('parent'), ['schema_template' => 'Apparel']);

        $this->assertSame($this->fieldsOf('Apparel'), $this->enabledFieldOptions($this->categoryId('child')));
    }

    /**
     * A field chosen under an earlier template is still stored, and still read: ProductGroupBuilder
     * takes gtin13 from the enabled fields whatever the template. So it is listed, marked, where it
     * can be seen and cleared.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testAStoredFieldTheTemplateDoesNotOfferIsListedAndMarked(): void
    {
        $categoryId = $this->categoryId('category');
        $this->configRepository()->save($categoryId, [
            'schema_template' => 'Book',
            'enabled_fields'  => ['isbn', 'size'],
        ]);

        $this->assertSame(
            [...$this->fieldsOf('Book'), ['value' => 'size', 'label' => 'size (not a field of this template)']],
            $this->enabledFieldOptions($categoryId)
        );
    }

    /**
     * @return void
     */
    #[Config(SeoConfig::XML_SD_DEFAULT_TEMPLATE, 'Book', ScopeInterface::SCOPE_STORE, 'admin')]
    public function testANewCategoryListsTheDefaultTemplatesFields(): void
    {
        $this->assertSame($this->fieldsOf('Book'), $this->enabledFieldOptions(null));
    }

    /**
     * The form edits one store view at a time, so the default is that store view's.
     *
     * @return void
     */
    #[Config(SeoConfig::XML_SD_DEFAULT_TEMPLATE, 'Book', ScopeInterface::SCOPE_STORE, 'admin')]
    #[Config(SeoConfig::XML_SD_DEFAULT_TEMPLATE, 'Apparel', ScopeInterface::SCOPE_STORE, 'default')]
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testTheStoreViewBeingEditedSuppliesTheDefault(): void
    {
        $categoryId = $this->categoryId('category');
        $storeId    = (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)
            ->getStore('default')
            ->getId();

        $this->assertSame($this->fieldsOf('Book'), $this->enabledFieldOptions($categoryId));
        $this->assertSame($this->fieldsOf('Apparel'), $this->enabledFieldOptions($categoryId, $storeId));
    }

    /**
     * The multiselect's options, as the category form's data provider builds them.
     *
     * @param int|null $categoryId Null for a new category
     * @param int|null $storeId The store view being edited; null for the default scope
     * @return mixed
     */
    private function enabledFieldOptions(?int $categoryId, ?int $storeId = null): mixed
    {
        $this->editing($categoryId, $storeId);

        $meta = Bootstrap::getObjectManager()->create(CategoryFormDataProvider::class, [
            'name'             => 'category_form_data_source',
            'primaryFieldName' => 'entity_id',
            'requestFieldName' => 'id',
        ])->getMeta();

        return $meta['mageos_seo']['children']['enabled_fields']['arguments']['data']['options'] ?? null;
    }

    /**
     * Point the shared request at a category and store view, as the edit page's URL does.
     *
     * Null removes the parameter: setParams() only adds, so a parameter left by an earlier call
     * would otherwise still be there.
     *
     * @param int|null $categoryId
     * @param int|null $storeId
     * @return void
     */
    private function editing(?int $categoryId, ?int $storeId): void
    {
        /** @var HttpRequest $request */
        $request = Bootstrap::getObjectManager()->get(RequestInterface::class);
        $request->setParam('id', $categoryId);
        $request->setParam('store', $storeId);
    }

    /**
     * A template's optional fields as multiselect options, in the template's order.
     *
     * @param string $templateCode
     * @return array<int, array{value: string, label: string}>
     */
    private function fieldsOf(string $templateCode): array
    {
        $fields = Bootstrap::getObjectManager()->get(SchemaBuilderPool::class)->getAvailableFields($templateCode);
        $this->assertNotSame([], $fields, $templateCode . ' has optional fields to list');

        $options = [];
        foreach ($fields as $code => $label) {
            $options[] = ['value' => (string) $code, 'label' => $label];
        }

        return $options;
    }

    /**
     * @return ConfigRepository
     */
    private function configRepository(): ConfigRepository
    {
        // The shared instance, which the form's modifier reads through; save() clears its memo.
        return Bootstrap::getObjectManager()->get(ConfigRepository::class);
    }

    /**
     * The ID of a fixture category, remembered for cleanup.
     *
     * @param string $name
     * @return int
     */
    private function categoryId(string $name): int
    {
        $categoryId                 = (int) DataFixtureStorageManager::getStorage()->get($name)->getId();
        $this->writtenCategoryIds[] = $categoryId;

        return $categoryId;
    }
}
