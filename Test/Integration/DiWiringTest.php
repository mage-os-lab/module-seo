<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration;

use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use MageOS\Seo\Api\OrganisationRepositoryInterface;
use MageOS\Seo\Model\LlmsTxt\LlmsTxtBuilder;
use MageOS\Seo\Model\MetaTag\Compositor as MetaTagCompositor;
use MageOS\Seo\Model\PageTitle\Compositor as PageTitleCompositor;
use MageOS\Seo\Model\Product\SchemaBuilderPool;
use MageOS\Seo\Model\StructuredData\Compositor as StructuredDataCompositor;

/**
 * Smoke-test that key services are correctly wired in the DI container.
 * Each test verifies that the ObjectManager can instantiate a class with
 * its full dependency tree, catching misconfigured di.xml entries early.
 *
 * @magentoAppArea frontend
 */
class DiWiringTest extends TestCase
{
    /**
     * @param class-string $class
     * @dataProvider serviceClassProvider
     */
    public function testServiceIsInstantiableViaDi(string $class): void
    {
        $instance = Bootstrap::getObjectManager()->get($class);
        $this->assertInstanceOf($class, $instance);
    }

    /**
     * @return array<string, array<string>>
     */
    public function serviceClassProvider(): array
    {
        return [
            'OrganisationRepository'    => [OrganisationRepositoryInterface::class],
            'StructuredDataCompositor'  => [StructuredDataCompositor::class],
            'MetaTagCompositor'         => [MetaTagCompositor::class],
            'PageTitleCompositor'       => [PageTitleCompositor::class],
            'SchemaBuilderPool'         => [SchemaBuilderPool::class],
            'LlmsTxtBuilder'            => [LlmsTxtBuilder::class],
        ];
    }
}
