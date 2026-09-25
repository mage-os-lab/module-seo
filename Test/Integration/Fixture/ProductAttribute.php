<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Fixture;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Api\Data\ProductAttributeInterfaceFactory;
use Magento\Catalog\Api\ProductAttributeManagementInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\TestFramework\Fixture\Data\ProcessorInterface;
use Magento\TestFramework\Fixture\RevertibleDataFixtureInterface;

/**
 * A product attribute in the Default attribute set, created the way the admin creates one.
 *
 * Core's `Catalog\Test\Fixture\Attribute` (and the configurable and select fixtures built on it)
 * fills the attribute through `DataObjectHelper::populateWithArray()`. On PHP 8.4, reflection on the
 * generated attribute Interceptor lists Catalog's `EavAttributeInterface` before Eav's
 * `AttributeInterface`, so `ExtensionAttributesFactory` builds a Catalog extension object and the
 * attribute's typed `setExtensionAttributes()` throws a TypeError — core's own tests that use those
 * fixtures fail the same way. This fixture sets the model's data and saves it through the
 * repository instead (core's legacy `configurable_attribute.php` path), which never goes there.
 *
 * Data, all optional:
 *  - `attribute_code`: default `mageos_seo_attr%uniqid%`
 *  - `frontend_label`: default the code
 *  - `frontend_input`: `select` (default) or `text`
 *  - `options`: option labels, in order (select only); default `['option_1', 'option_2']`
 *  - `used_in_product_listing`: default false
 *  - `reuse`: default false. When true and an attribute with the code already exists, it is used as
 *    it is — its own options, labels and settings — and never deleted. For codes an install may
 *    already have: Magento's sample data, for one, creates `size`, `material`, `pattern` and
 *    `gender`. A test that reuses must read option labels and IDs from the database, not assume them.
 *
 * The attribute ends up in the Default attribute set (a reused one is added for the test and taken
 * out again on revert if it was not there) and a created one is global, so it can be a configurable
 * product's option.
 */
class ProductAttribute implements RevertibleDataFixtureInterface
{
    private const DEFAULT_DATA = [
        'attribute_code'          => 'mageos_seo_attr%uniqid%',
        'frontend_label'          => null,
        'frontend_input'          => 'select',
        'options'                 => ['option_1', 'option_2'],
        'used_in_product_listing' => false,
        'reuse'                   => false,
    ];

    // Markers the fixture sets on the attribute it returns, for revert().
    private const REUSED   = '_mageos_seo_fixture_reused';
    private const ASSIGNED = '_mageos_seo_fixture_assigned';

    /**
     * @param ProductAttributeInterfaceFactory $attributeFactory
     * @param ProductAttributeRepositoryInterface $attributeRepository
     * @param ProductAttributeManagementInterface $attributeManagement
     * @param EavSetup $eavSetup
     * @param EavConfig $eavConfig
     * @param ProcessorInterface $dataProcessor
     */
    public function __construct(
        private readonly ProductAttributeInterfaceFactory $attributeFactory,
        private readonly ProductAttributeRepositoryInterface $attributeRepository,
        private readonly ProductAttributeManagementInterface $attributeManagement,
        private readonly EavSetup $eavSetup,
        private readonly EavConfig $eavConfig,
        private readonly ProcessorInterface $dataProcessor
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply(array $data = []): ?DataObject
    {
        $data   = $this->dataProcessor->process($this, array_merge(self::DEFAULT_DATA, $data));
        $code   = (string) $data['attribute_code'];
        $select = $data['frontend_input'] === 'select';

        // Reuse the attribute if there is one; none yet, create it below.
        if ($data['reuse']) {
            try {
                /** @var ProductAttributeInterface&DataObject $existing */
                $existing = $this->attributeRepository->get($code);
                $existing->setData(self::REUSED, true);
                $existing->setData(self::ASSIGNED, $this->assignToDefaultSet($code, (int) $existing->getAttributeId()));

                return $existing;
            } catch (NoSuchEntityException) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
            }
        }

        $attributeData = [
            'attribute_code'          => $code,
            'entity_type_id'          => $this->eavSetup->getEntityTypeId(Product::ENTITY),
            'is_global'               => 1,
            'is_user_defined'         => 1,
            'frontend_input'          => $select ? 'select' : 'text',
            'backend_type'            => $select ? 'int' : 'varchar',
            'is_unique'               => 0,
            'is_required'             => 0,
            'is_searchable'           => 0,
            'is_visible_in_advanced_search' => 0,
            'is_comparable'           => 0,
            'is_filterable'           => 0,
            'is_filterable_in_search' => 0,
            'is_used_for_promo_rules' => 0,
            'is_html_allowed_on_front' => 1,
            'is_visible_on_front'     => 0,
            'used_in_product_listing' => (int) $data['used_in_product_listing'],
            'used_for_sort_by'        => 0,
            'frontend_label'          => [(string) ($data['frontend_label'] ?? $code)],
        ];
        if ($select) {
            $values = [];
            $order  = [];
            foreach (array_values($data['options']) as $index => $label) {
                $values['option_' . $index] = [(string) $label];
                $order['option_' . $index]  = $index + 1;
            }
            $attributeData['option'] = ['value' => $values, 'order' => $order];
        }

        /** @var ProductAttributeInterface&DataObject $attribute */
        $attribute = $this->attributeFactory->create();
        $attribute->setData($attributeData);
        $attribute = $this->attributeRepository->save($attribute);
        $this->assignToDefaultSet($code, (int) $attribute->getAttributeId());

        /** @var ProductAttributeInterface&DataObject $saved */
        $saved = $this->attributeRepository->get($code);
        $saved->setData(self::REUSED, false);

        return $saved;
    }

    /**
     * @inheritdoc
     */
    public function revert(DataObject $data): void
    {
        $code = (string) $data->getData('attribute_code');
        if (!$data->getData(self::REUSED)) {
            $this->attributeRepository->deleteById($code);
        } elseif ($data->getData(self::ASSIGNED)) {
            $this->attributeManagement->unassign((string) $this->defaultSetId(), $code);
        }
        $this->eavConfig->clear();
    }

    /**
     * Put the attribute in the Default set's default group, unless it is in the set already.
     *
     * @param string $code
     * @param int $attributeId
     * @return bool Whether it was added
     */
    private function assignToDefaultSet(string $code, int $attributeId): bool
    {
        $setId = $this->defaultSetId();
        foreach ($this->attributeManagement->getAttributes((string) $setId) as $inSet) {
            if ($inSet->getAttributeCode() === $code) {
                return false;
            }
        }

        $this->eavSetup->addAttributeToGroup(
            Product::ENTITY,
            $setId,
            $this->eavSetup->getDefaultAttributeGroupId(Product::ENTITY, $setId),
            $attributeId
        );
        $this->eavConfig->clear();

        return true;
    }

    /**
     * @return int
     */
    private function defaultSetId(): int
    {
        return (int) $this->eavSetup->getAttributeSetId(Product::ENTITY, 'Default');
    }
}
