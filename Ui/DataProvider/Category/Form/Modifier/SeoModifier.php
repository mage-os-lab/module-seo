<?php

declare(strict_types=1);

namespace MageOS\Seo\Ui\DataProvider\Category\Form\Modifier;

use Magento\Framework\App\RequestInterface;
use Magento\Ui\Component\Form\Element\DataType\Text;
use Magento\Ui\Component\Form\Element\Select;
use Magento\Ui\Component\Form\Element\Textarea;
use Magento\Ui\Component\Form\Field;
use Magento\Ui\Component\Form\Fieldset;
use Magento\Ui\DataProvider\Modifier\ModifierInterface;
use MageOS\Seo\Model\Category\ConfigRepository;
use MageOS\Seo\Model\Config\Source\RobotsMeta;
use MageOS\Seo\Model\Config\Source\SchemaTemplate;

class SeoModifier implements ModifierInterface
{
    /**
     * @param RequestInterface $request
     * @param ConfigRepository $categoryConfigRepository
     * @param SchemaTemplate $schemaTemplateSource
     * @param RobotsMeta $robotsMetaSource
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ConfigRepository $categoryConfigRepository,
        private readonly SchemaTemplate   $schemaTemplateSource,
        private readonly RobotsMeta       $robotsMetaSource,
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
        $meta['rs_seo'] = [
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
                                'notice'        => __('Optional schema fields to output.'
                                    . ' Available fields change based on the selected template.'),
                                'componentType' => Field::NAME,
                                'formElement'   => 'multiselect',
                                'dataType'      => Text::NAME,
                                'dataScope'     => 'rs_seo_enabled_fields',
                                'sortOrder'     => 20,
                            ],
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
                    array_merge(
                        [['value' => '', 'label' => __('Use Global Default')]],
                        $this->robotsMetaSource->toOptionArray()
                    ),
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
                                'dataScope'     => 'rs_seo_override_fields',
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

        $config = $this->categoryConfigRepository->getForCategory($categoryId);
        $config = $this->categoryConfigRepository->decode($config);

        if (empty($config)) {
            return $data;
        }

        $data[$categoryId]['rs_seo_schema_template']  = $config['schema_template'] ?? '';
        $data[$categoryId]['rs_seo_enabled_fields']   = $config['enabled_fields'] ?? [];
        $data[$categoryId]['rs_seo_item_list_enabled'] = $config['item_list_enabled'] ?? '';
        $data[$categoryId]['rs_seo_robots_meta']       = $config['robots_meta'] ?? '';
        $data[$categoryId]['rs_seo_override_fields']   = !empty($config['override_fields'])
            ? json_encode($config['override_fields'], JSON_PRETTY_PRINT)
            : '';

        return $data;
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
            'dataScope'     => 'rs_seo_' . $name,
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
