<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Config\Source;

use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\SchemaBuilderPool;
use PHPUnit\Framework\TestCase;

/**
 * The store's Default Product Schema Template is chosen from the registered templates.
 *
 * It was a text field, so a mistyped code was saved as given and the storefront quietly built
 * GenericProduct instead.
 *
 * @magentoAppArea adminhtml
 */
class SchemaTemplateTest extends TestCase
{
    public function testTheDefaultTemplateIsASelectOfTheRegisteredTemplates(): void
    {
        $field = Bootstrap::getObjectManager()->get(Structure::class)
            ->getElement(Config::XML_SD_DEFAULT_TEMPLATE);
        $this->assertInstanceOf(Field::class, $field);

        $templates = array_keys(
            Bootstrap::getObjectManager()->get(SchemaBuilderPool::class)->getAvailableTemplates()
        );
        $values    = array_column($field->getOptions(), 'value');

        $this->assertSame('select', $field->getType());
        $this->assertSame('GenericProduct', $values[0]);
        $this->assertEqualsCanonicalizing($templates, $values);
    }
}
