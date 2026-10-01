<?php

declare(strict_types=1);

namespace MageOS\Seo\Ui\DataProvider\Category\Form\Modifier;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Ui\Component\Form\Element\DataType\Text;
use Magento\Ui\Component\Form\Element\Select;
use Magento\Ui\Component\Form\Element\Textarea;
use Magento\Ui\Component\Form\Field;
use Magento\Ui\Component\Form\Fieldset;
use Magento\Ui\DataProvider\Modifier\ModifierInterface;
use MageOS\Seo\Model\Category\ConfigRepository;
use MageOS\Seo\Model\Config\Source\RobotsMeta\CategoryOverride as CategoryRobotsMeta;
use MageOS\Seo\Model\Config\Source\SchemaTemplate\CategoryOverride as CategorySchemaTemplate;
use MageOS\Seo\Model\Product\SchemaBuilderPool;
use MageOS\Seo\Model\Product\SchemaTemplateResolver;

class SeoModifier implements ModifierInterface
{
    /**
     * @param RequestInterface $request
     * @param ConfigRepository $categoryConfigRepository
     * @param CategorySchemaTemplate $schemaTemplateSource
     * @param CategoryRobotsMeta $robotsMetaSource
     * @param CategoryRepositoryInterface $categoryRepository
     * @param SchemaTemplateResolver $templateResolver
     * @param SchemaBuilderPool $builderPool
     */
    public function __construct(
        private readonly RequestInterface            $request,
        private readonly ConfigRepository            $categoryConfigRepository,
        private readonly CategorySchemaTemplate      $schemaTemplateSource,
        private readonly CategoryRobotsMeta          $robotsMetaSource,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly SchemaTemplateResolver      $templateResolver,
        private readonly SchemaBuilderPool           $builderPool,
    ) {
    }

    /**
     * Inject the SEO fieldset into the category edit form meta.
     *
     * @param mixed[] $meta
     * @return mixed[]
     */
    public function modifyMeta(array $meta): array
    {
        $meta['mageos_seo'] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'label'       => __('SEO (Structured Data)'),
                        'collapsible' => true,
                        'opened'      => false,
                        'componentType' => Fieldset::NAME,
                        'dataScope'   => '',
                        'sortOrder'   => 400,
                    ],
                ],
            ],
            'children' => [
                'schema_template' => $this->buildSelectField(
                    'schema_template',
                    __('Product Schema Template'),
                    $this->schemaTemplateSource->toOptionArray(),
                    __('Schema template for products in this category.'
                        . ' Determines which structured data fields are available.'),
                    10
                ),
                'enabled_fields' => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'label'         => __('Enabled Optional Fields'),
                                'notice'        => __('Optional schema fields to output, from the template in effect.'
                                    . ' After changing the template, save the category to list its fields.'),
                                'componentType' => Field::NAME,
                                'formElement'   => 'multiselect',
                                'dataType'      => Text::NAME,
                                'dataScope'     => 'mageos_seo_enabled_fields',
                                'sortOrder'     => 20,
                            ],
                            'options' => $this->enabledFieldOptions(),
                        ],
                    ],
                ],
                'item_list_enabled' => $this->buildSelectField(
                    'item_list_enabled',
                    __('ItemList Schema on Category Pages'),
                    [
                        ['value' => '',  'label' => __('Use Global Setting')],
                        ['value' => '1', 'label' => __('Yes — output ItemList schema')],
                        ['value' => '0', 'label' => __('No — disable ItemList schema')],
                    ],
                    __('Override the global setting for this category.'),
                    30
                ),
                'robots_meta' => $this->buildSelectField(
                    'robots_meta',
                    __('Robots Meta'),
                    $this->robotsMetaSource->toOptionArray(),
                    null,
                    40
                ),
                'override_fields' => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'label'         => __('Field Value Overrides (JSON)'),
                                'notice'        => __('JSON key/value pairs to hard-code for products in this category.'
                                    . ' Example: {"gender":"Female","countryOfOrigin":"GB"}'),
                                'componentType' => Field::NAME,
                                'formElement'   => Textarea::NAME,
                                'dataType'      => Text::NAME,
                                'dataScope'     => 'mageos_seo_override_fields',
                                'sortOrder'     => 50,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return $meta;
    }

    /**
     * Inject saved SEO config values into the form data.
     *
     * @param mixed[] $data
     * @return mixed[]
     */
    public function modifyData(array $data): array
    {
        $categoryId = (int) $this->request->getParam('id');
        if ($categoryId <= 0) {
            return $data;
        }

        $config = $this->resolvedConfig();

        // Nothing configured on the category or its ancestors: the fields keep their empty
        // defaults ("Use Global Setting", none selected), which is what this would set them to.
        if ($config === []) {
            return $data;
        }

        $data[$categoryId]['mageos_seo_schema_template']  = $config['schema_template'] ?? '';
        $data[$categoryId]['mageos_seo_enabled_fields']   = $config['enabled_fields'] ?? [];
        $data[$categoryId]['mageos_seo_item_list_enabled'] = $config['item_list_enabled'] ?? '';
        $data[$categoryId]['mageos_seo_robots_meta']       = $config['robots_meta'] ?? '';
        $data[$categoryId]['mageos_seo_override_fields']   = !empty($config['override_fields'])
            ? json_encode($config['override_fields'], JSON_PRETTY_PRINT)
            : '';

        return $data;
    }

    /**
     * The optional fields of the template in effect, as multiselect options.
     *
     * The template is the one the storefront uses for this category and store view: the category's
     * own or inherited one, else the store view's default. A code the category has selected that
     * this template does not offer, kept from a template it used before, is listed after them so it
     * can be seen and cleared. It is not inert: ProductGroupBuilder reads gtin13 whatever the
     * template.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function enabledFieldOptions(): array
    {
        $config   = $this->resolvedConfig();
        $template = $this->templateResolver->resolve((string) ($config['schema_template'] ?? ''), $this->storeId());
        $fields   = $this->builderPool->getAvailableFields($template);

        $options = [];
        foreach ($fields as $code => $label) {
            $options[] = ['value' => (string) $code, 'label' => (string) $label];
        }
        foreach (array_unique(array_map('strval', $config['enabled_fields'] ?? [])) as $code) {
            if (!isset($fields[$code])) {
                $options[] = ['value' => $code, 'label' => (string) __('%1 (not a field of this template)', $code)];
            }
        }

        return $options;
    }

    /**
     * The category's SEO settings as the storefront resolves them, in the store view being edited.
     *
     * Inherited values are included (the nearest configured ancestor), so the form shows what the
     * storefront will use. Empty for a new category, and for one with nothing configured on its
     * path.
     *
     * @return mixed[]
     */
    private function resolvedConfig(): array
    {
        $categoryId = (int) $this->request->getParam('id');
        if ($categoryId <= 0) {
            return [];
        }

        $categoryPath = [];
        try {
            $categoryPath = explode('/', (string) $this->categoryRepository->get($categoryId)->getPath());
        } catch (NoSuchEntityException) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        }

        return $this->categoryConfigRepository->getForCategory($categoryId, $categoryPath, $this->storeId());
    }

    /**
     * The store view being edited.
     *
     * Admin category pages pass it as the "store" request parameter; the adminhtml current store is
     * always store 0, so the store manager would always give the default scope.
     *
     * @return int
     */
    private function storeId(): int
    {
        return max(0, (int) $this->request->getParam('store', 0));
    }

    /**
     * Build a simple select field config node.
     *
     * @param string $name
     * @param mixed $label
     * @param mixed[] $options
     * @param mixed|null $notice
     * @param int $sortOrder
     * @return mixed[]
     */
    private function buildSelectField(string $name, mixed $label, array $options, mixed $notice, int $sortOrder): array
    {
        $config = [
            'label'         => $label,
            'componentType' => Field::NAME,
            'formElement'   => Select::NAME,
            'dataType'      => Text::NAME,
            'dataScope'     => 'mageos_seo_' . $name,
            'sortOrder'     => $sortOrder,
        ];

        if ($notice !== null) {
            $config['notice'] = $notice;
        }

        return [
            'arguments' => [
                'data' => [
                    'config' => $config,
                    'options' => $options,
                ],
            ],
        ];
    }
}
