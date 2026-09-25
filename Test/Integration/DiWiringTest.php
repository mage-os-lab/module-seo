<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration;

use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Api\FaqCollectorInterface;
use MageOS\Seo\Api\OrganisationRepositoryInterface;
use MageOS\Seo\Model\Faq\SourcePool as FaqSourcePool;
use MageOS\Seo\Model\Hreflang\ResolverPool as HreflangResolverPool;
use MageOS\Seo\Model\LlmsJsonl\JsonlBuilder;
use MageOS\Seo\Model\LlmsTxt\LlmsTxtBuilder;
use MageOS\Seo\Model\MetaTag\Compositor as MetaTagCompositor;
use MageOS\Seo\Model\PageTitle\Compositor as PageTitleCompositor;
use MageOS\Seo\Model\Product\Builder\AbstractBuilder;
use MageOS\Seo\Model\Product\Builder\GenericProductBuilder;
use MageOS\Seo\Model\Product\OfferBuilder;
use MageOS\Seo\Model\Product\OfferEnricher\Pool as OfferEnricherPool;
use MageOS\Seo\Model\Product\SchemaBuilderPool;
use MageOS\Seo\Model\Product\Variant\ProductGroupBuilder;
use MageOS\Seo\Model\Review\AggregateRatingResolver;
use MageOS\Seo\Model\RobotsMeta\Resolver as RobotsMetaResolver;
use MageOS\Seo\Model\StructuredData\Compositor as StructuredDataCompositor;
use MageOS\Seo\Model\WellKnown\EndpointPool as WellKnownEndpointPool;
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

    public function testRobotsMetaResolverIsInstantiableViaDi(): void
    {
        $instance = Bootstrap::getObjectManager()->get(RobotsMetaResolver::class);
        $this->assertInstanceOf(RobotsMetaResolver::class, $instance);
    }

    public function testOfferEnricherPoolIsInstantiableViaDi(): void
    {
        $instance = Bootstrap::getObjectManager()->get(OfferEnricherPool::class);
        $this->assertInstanceOf(OfferEnricherPool::class, $instance);
    }

    public function testAggregateRatingResolverIsInstantiableViaDi(): void
    {
        $instance = Bootstrap::getObjectManager()->get(AggregateRatingResolver::class);
        $this->assertInstanceOf(AggregateRatingResolver::class, $instance);
    }

    public function testHreflangResolverPoolIsInstantiableViaDi(): void
    {
        $instance = Bootstrap::getObjectManager()->get(HreflangResolverPool::class);
        $this->assertInstanceOf(HreflangResolverPool::class, $instance);
    }

    public function testFaqSourcePoolIsInstantiableViaDi(): void
    {
        $instance = Bootstrap::getObjectManager()->get(FaqSourcePool::class);
        $this->assertInstanceOf(FaqSourcePool::class, $instance);
    }

    public function testFaqCollectorIsInstantiableViaDi(): void
    {
        $instance = Bootstrap::getObjectManager()->get(FaqCollectorInterface::class);
        $this->assertInstanceOf(FaqCollectorInterface::class, $instance);
    }

    public function testLlmsJsonlBuilderIsInstantiableViaDi(): void
    {
        $instance = Bootstrap::getObjectManager()->get(JsonlBuilder::class);
        $this->assertInstanceOf(JsonlBuilder::class, $instance);
    }

    public function testWellKnownEndpointPoolIsWiredWithBuiltinEndpoints(): void
    {
        /** @var WellKnownEndpointPool $pool */
        $pool = Bootstrap::getObjectManager()->get(WellKnownEndpointPool::class);
        $this->assertTrue($pool->has('ucp'));
        $this->assertTrue($pool->has('ai-plugin.json'));
        $this->assertTrue($pool->has('security.txt'));
    }

    /**
     * Guards against optional-constructor-argument regressions: the ObjectManager passes
     * the default for optional args unless di.xml configures them per consumer, so a
     * builder whose pool argument is optional silently loses every configured enricher.
     * This asserts the DI-built builder actually holds the di.xml-configured pools — the
     * enrichers through the offer builder, which a configurable's variants share.
     */
    public function testDiBuiltProductBuilderReceivesConfiguredEnrichersAndRatingProviders(): void
    {
        $builder = Bootstrap::getObjectManager()->get(GenericProductBuilder::class);

        $this->assertConfiguredEnrichers(
            (new \ReflectionProperty(AbstractBuilder::class, 'offerBuilder'))->getValue($builder)
        );
        $this->assertConfiguredEnrichers(
            (new \ReflectionProperty(ProductGroupBuilder::class, 'offerBuilder'))
                ->getValue(Bootstrap::getObjectManager()->get(ProductGroupBuilder::class))
        );

        $resolver = (new \ReflectionProperty(AbstractBuilder::class, 'aggregateRatingResolver'))
            ->getValue($builder);
        $this->assertInstanceOf(AggregateRatingResolver::class, $resolver);
        $providers = (new \ReflectionProperty(AggregateRatingResolver::class, 'providers'))
            ->getValue($resolver);
        $this->assertArrayHasKey('native', $providers);
    }

    /**
     * Assert an offer builder holds the offer enrichers di.xml configures.
     *
     * @param mixed $offerBuilder
     * @return void
     */
    private function assertConfiguredEnrichers(mixed $offerBuilder): void
    {
        $this->assertInstanceOf(OfferBuilder::class, $offerBuilder);
        $pool = (new \ReflectionProperty(OfferBuilder::class, 'offerEnricherPool'))->getValue($offerBuilder);
        $this->assertInstanceOf(OfferEnricherPool::class, $pool);
        $enrichers = (new \ReflectionProperty(OfferEnricherPool::class, 'enrichers'))->getValue($pool);
        $this->assertArrayHasKey('itemCondition', $enrichers);
        $this->assertArrayHasKey('returnPolicy', $enrichers);
        $this->assertArrayHasKey('shippingDetails', $enrichers);
    }
}
