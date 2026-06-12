<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration;

use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Api\OrganisationRepositoryInterface;
use MageOS\Seo\Model\LlmsTxt\LlmsTxtBuilder;
use MageOS\Seo\Model\MetaTag\Compositor as MetaTagCompositor;
use MageOS\Seo\Model\PageTitle\Compositor as PageTitleCompositor;
use MageOS\Seo\Model\Product\SchemaBuilderPool;
use MageOS\Seo\Model\StructuredData\Compositor as StructuredDataCompositor;
use PHPUnit\Framework\TestCase;

/**
 * Smoke-test that key services are correctly wired in the DI container.
 * Each test verifies that the ObjectManager can instantiate a class with
 * its full dependency tree, catching misconfigured di.xml entries early.
 *
 * @magentoAppArea frontend
 */
class DiWiringTest extends TestCase
{
    public function testOrganisationRepositoryIsInstantiableViaDi(): void
    {
        $instance = Bootstrap::getObjectManager()->get(OrganisationRepositoryInterface::class);
        $this->assertInstanceOf(OrganisationRepositoryInterface::class, $instance);
    }

    public function testStructuredDataCompositorIsInstantiableViaDi(): void
    {
        $instance = Bootstrap::getObjectManager()->get(StructuredDataCompositor::class);
        $this->assertInstanceOf(StructuredDataCompositor::class, $instance);
    }

    public function testMetaTagCompositorIsInstantiableViaDi(): void
    {
        $instance = Bootstrap::getObjectManager()->get(MetaTagCompositor::class);
        $this->assertInstanceOf(MetaTagCompositor::class, $instance);
    }

    public function testPageTitleCompositorIsInstantiableViaDi(): void
    {
        $instance = Bootstrap::getObjectManager()->get(PageTitleCompositor::class);
        $this->assertInstanceOf(PageTitleCompositor::class, $instance);
    }

    public function testSchemaBuilderPoolIsInstantiableViaDi(): void
    {
        $instance = Bootstrap::getObjectManager()->get(SchemaBuilderPool::class);
        $this->assertInstanceOf(SchemaBuilderPool::class, $instance);
    }

    public function testLlmsTxtBuilderIsInstantiableViaDi(): void
    {
        $instance = Bootstrap::getObjectManager()->get(LlmsTxtBuilder::class);
        $this->assertInstanceOf(LlmsTxtBuilder::class, $instance);
    }
}
