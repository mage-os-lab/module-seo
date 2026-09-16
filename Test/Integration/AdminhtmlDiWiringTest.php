<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration;

use Magento\Catalog\Model\Category\DataProvider as CategoryFormDataProvider;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\Eav as CoreEavModifier;
use Magento\Catalog\Ui\DataProvider\Product\Form\ProductDataProvider;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\Ui\DataProvider\Modifier\PoolInterface;
use Magento\Ui\DataProvider\ModifierPoolDataProvider;
use MageOS\Seo\Ui\DataProvider\Category\Form\Modifier\SeoModifier as CategorySeoModifier;
use MageOS\Seo\Ui\DataProvider\Product\Form\Modifier\SeoModifier as ProductSeoModifier;
use PHPUnit\Framework\TestCase;

/**
 * Admin-area wiring of the catalog form modifiers.
 *
 * Both form modifier pools are virtual types. Registering a modifier with <type> on a
 * virtual-type name replaces the virtual type's definition in the merged DI config: the
 * pool stops resolving (ReflectionException — the product and category edit pages return
 * 500) and every other module's modifiers are dropped with it. The tests build the real
 * form data providers so both failure modes are caught, not just the exception.
 *
 * @magentoAppArea adminhtml
 */
class AdminhtmlDiWiringTest extends TestCase
{
    private const AUTOMATIC_TRANSLATION_MODIFIER =
        'MageOS\AutomaticTranslation\Ui\DataProvider\Category\Form\Modifier\TranslationStores';

    /**
     * The product form pool resolves, keeps core's modifiers and carries the SEO modifier.
     *
     * @return void
     */
    public function testProductFormPoolKeepsCoreModifiersAndRegistersSeoModifier(): void
    {
        $provider = $this->createFormDataProvider(ProductDataProvider::class, 'product_form_data_source');
        $classes  = $this->modifierClasses($this->poolOf($provider, ProductDataProvider::class));

        $this->assertContains(CoreEavModifier::class, $classes);
        $this->assertContains(ProductSeoModifier::class, $classes);
    }

    /**
     * The category form pool resolves and carries the SEO modifier.
     *
     * Magento core wires no pool into the category form data provider, so the module
     * declares it; this must hold whether or not another module declares it too.
     *
     * @return void
     */
    public function testCategoryFormPoolRegistersSeoModifier(): void
    {
        $classes = $this->modifierClasses($this->categoryFormPool());

        $this->assertContains(CategorySeoModifier::class, $classes);
    }

    /**
     * Mage-OS's AutomaticTranslation module declares the same category pool; both modules'
     * modifiers must survive the merge.
     *
     * @return void
     */
    public function testCategoryFormPoolKeepsAutomaticTranslationModifier(): void
    {
        $moduleManager = Bootstrap::getObjectManager()->get(ModuleManager::class);
        if (!$moduleManager->isEnabled('MageOS_AutomaticTranslation')) {
            $this->markTestSkipped('MageOS_AutomaticTranslation is not enabled.');
        }

        $classes = $this->modifierClasses($this->categoryFormPool());

        $this->assertContains(self::AUTOMATIC_TRANSLATION_MODIFIER, $classes);
        $this->assertContains(CategorySeoModifier::class, $classes);
    }

    /**
     * Build the category form data provider and return its modifier pool.
     *
     * @return PoolInterface
     */
    private function categoryFormPool(): PoolInterface
    {
        $provider = $this->createFormDataProvider(CategoryFormDataProvider::class, 'category_form_data_source');

        return $this->poolOf($provider, ModifierPoolDataProvider::class);
    }

    /**
     * Instantiate a UI form data provider through the ObjectManager, as the form component does.
     *
     * @param string $class
     * @param string $name
     * @return object
     */
    private function createFormDataProvider(string $class, string $name): object
    {
        return Bootstrap::getObjectManager()->create($class, [
            'name'             => $name,
            'primaryFieldName' => 'entity_id',
            'requestFieldName' => 'id',
        ]);
    }

    /**
     * Read the private modifier pool a data provider was constructed with.
     *
     * @param object $provider
     * @param string $declaringClass Class that declares the private $pool property
     * @return PoolInterface
     */
    private function poolOf(object $provider, string $declaringClass): PoolInterface
    {
        $pool = (new \ReflectionProperty($declaringClass, 'pool'))->getValue($provider);
        $this->assertInstanceOf(PoolInterface::class, $pool);

        return $pool;
    }

    /**
     * List the modifier class names registered in a pool.
     *
     * @param PoolInterface $pool
     * @return string[]
     */
    private function modifierClasses(PoolInterface $pool): array
    {
        return array_column($pool->getModifiers(), 'class');
    }
}
