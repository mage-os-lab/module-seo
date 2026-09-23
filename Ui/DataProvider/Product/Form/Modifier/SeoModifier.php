<?php

declare(strict_types=1);

namespace MageOS\Seo\Ui\DataProvider\Product\Form\Modifier;

use Magento\Framework\App\RequestInterface;
use Magento\Ui\Component\Form\Element\DataType\Text;
use Magento\Ui\Component\Form\Element\Select;
use Magento\Ui\Component\Form\Element\Textarea;
use Magento\Ui\Component\Form\Field;
use Magento\Ui\Component\Form\Fieldset;
use Magento\Ui\DataProvider\Modifier\ModifierInterface;
use MageOS\Seo\Model\Category\ProductOverrideRepository;
use MageOS\Seo\Model\Config\Source\RobotsMeta\ProductOverride as ProductRobotsMeta;

class SeoModifier implements ModifierInterface
{
    /**
     * Data scope of the Advanced SEO fieldset.
     *
     * product_form.xml declares no form-level dataScope, and an empty fieldset scope inherits
     * the parent's, so an empty value would bind the fields outside the provider's "data"
     * branch: modifyData() values would never show, and the form would post the untouched
     * loaded values instead of the edited ones. With "data" the fields bind to
     * data.mageos_seo_* and are submitted as top-level POST fields.
     */
    public const DATA_SCOPE = 'data';

    /**
     * @param RequestInterface $request
     * @param ProductOverrideRepository $productOverrideRepository
     * @param ProductRobotsMeta $robotsMetaSource
     */
    public function __construct(
        private readonly RequestInterface          $request,
        private readonly ProductOverrideRepository $productOverrideRepository,
        private readonly ProductRobotsMeta         $robotsMetaSource
    ) {
    }

    /**
     * Inject the Advanced SEO fieldset into the product edit form meta.
     *
     * @param mixed[] $meta
     * @return mixed[]
     */
    public function modifyMeta(array $meta): array
    {
        $meta['mageos_seo_advanced'] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'label'         => __('Advanced SEO'),
                        'collapsible'   => true,
                        'opened'        => false,
                        'componentType' => Fieldset::NAME,
                        'dataScope'     => self::DATA_SCOPE,
                        'sortOrder'     => 500,
                    ],
                ],
            ],
            'children' => [
                'mageos_seo_override_notice' => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'label'         => '',
                                'notice'        => __(
                                    'Per-product overrides take precedence over category and attribute values.'
                                    . ' Leave empty to use the category or global setting.'
                                ),
                                'componentType' => Field::NAME,
                                'formElement'   => 'hidden',
                                'dataType'      => Text::NAME,
                                'dataScope'     => 'mageos_seo_override_notice',
                                'sortOrder'     => 5,
                                'visible'       => true,
                            ],
                        ],
                    ],
                ],
                'mageos_seo_override_fields' => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'label'         => __('Field Value Overrides (JSON)'),
                                'notice'        => __('JSON key/value pairs overriding schema fields for this product.'
                                    . ' Example: {"gtin13":"0123456789012","color":"Midnight Blue"}'),
                                'componentType' => Field::NAME,
                                'formElement'   => Textarea::NAME,
                                'dataType'      => Text::NAME,
                                'dataScope'     => 'mageos_seo_override_fields',
                                'sortOrder'     => 10,
                                'rows'          => 6,
                            ],
                        ],
                    ],
                ],
                'mageos_seo_robots_meta' => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'label'         => __('Robots Meta Override'),
                                'notice'        => __('Override robots meta for this specific product page.'),
                                'componentType' => Field::NAME,
                                'formElement'   => Select::NAME,
                                'dataType'      => Text::NAME,
                                'dataScope'     => 'mageos_seo_robots_meta',
                                'sortOrder'     => 20,
                            ],
                            'options' => $this->robotsMetaSource->toOptionArray(),
                        ],
                    ],
                ],
            ],
        ];

        return $meta;
    }

    /**
     * Inject saved product SEO overrides into form data.
     *
     * @param mixed[] $data
     * @return mixed[]
     */
    public function modifyData(array $data): array
    {
        $productId = (int) $this->request->getParam('id');
        if ($productId <= 0) {
            return $data;
        }

        // Admin product pages pass the selected store view as the "store" request
        // parameter; the adminhtml current store is always store 0, so reading the
        // store manager here would always show the default-scope values.
        $storeId = max(0, (int) $this->request->getParam('store', 0));
        $overrideRow = $this->productOverrideRepository->getForProduct($productId, $storeId);

        $overrideFields = $overrideRow['override_fields'] ?? [];
        $data[$productId]['mageos_seo_override_fields'] = !empty($overrideFields)
            ? json_encode($overrideFields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            : '';

        $data[$productId]['mageos_seo_robots_meta'] = $overrideRow['robots_meta'] ?? '';

        return $data;
    }
}
