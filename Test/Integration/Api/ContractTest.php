<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Api;

use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use MageOS\Seo\Api\CanonicalUrlManagerInterface;
use MageOS\Seo\Api\ContactEmailInterface;
use MageOS\Seo\Api\FaqSourcePoolInterface;
use MageOS\Seo\Api\Product\AvailabilityResolverInterface;
use MageOS\Seo\Api\Product\FinalPriceInterface;
use MageOS\Seo\Api\Product\OfferSummaryInterface;
use MageOS\Seo\Api\Product\SchemaTemplatesInterface;
use MageOS\Seo\Api\Product\VariantImageInterface;
use MageOS\Seo\Api\Rebuild\ChangeInspectorInterface;
use MageOS\Seo\Api\Rebuild\HandlerPoolInterface;
use MageOS\Seo\Api\Rebuild\InvalidatorInterface;
use MageOS\Seo\Api\Rebuild\ProblemLogInterface;
use MageOS\Seo\Api\Rebuild\RegenerationRequesterInterface;
use MageOS\Seo\Api\Robots\PageDirectivesInterface;
use MageOS\Seo\Api\Router\CanonicalPathRedirectInterface;
use MageOS\Seo\Api\Router\PublicPathsInterface;
use MageOS\Seo\Api\SchemaPriceInterface;
use MageOS\Seo\Api\StorefrontSettingsInterface;
use MageOS\Seo\Model\Canonical\CanonicalUrlManager;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Faq\SourcePool;
use MageOS\Seo\Model\Organization\ContactEmail;
use MageOS\Seo\Model\Price\SchemaPrice;
use MageOS\Seo\Model\Product\AvailabilityResolver;
use MageOS\Seo\Model\Product\FinalPrice;
use MageOS\Seo\Model\Product\OfferSummary;
use MageOS\Seo\Model\Product\SchemaBuilderPool;
use MageOS\Seo\Model\Product\Variant\VariantImage;
use MageOS\Seo\Model\Rebuild\ChangeInspector;
use MageOS\Seo\Model\Rebuild\HandlerPool;
use MageOS\Seo\Model\Rebuild\Invalidator;
use MageOS\Seo\Model\Rebuild\ProblemLog;
use MageOS\Seo\Model\Rebuild\RegenerationRequester;
use MageOS\Seo\Model\RobotsMeta\PageDirectives;
use MageOS\Seo\Model\Router\CanonicalPathRedirect;
use MageOS\Seo\Model\Router\PublicPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Review M7, decision D7b: what other modules use of this one is an interface in Api/, and each
 * resolves to the class that does the work.
 *
 * MageOS_Aeo, MageOS_Faq and MageOS_Agentic used about fifteen of this module's concrete classes,
 * none of them a promise: a refactor here could break them in any release, and nothing would say
 * so. They now take these interfaces, and this module keeps them stable until its next major
 * version.
 */
class ContractTest extends TestCase
{
    /**
     * @dataProvider preferences
     * @param class-string $interface
     * @param class-string $class
     * @return void
     */
    #[DataProvider('preferences')]
    public function testEachInterfaceResolvesToTheClassThatDoesTheWork(string $interface, string $class): void
    {
        $this->assertInstanceOf($class, Bootstrap::getObjectManager()->get($interface));
    }

    /**
     * Api interface => the class it is preferred to.
     *
     * @return array<string, array{class-string, class-string}>
     */
    public static function preferences(): array
    {
        return [
            'requester'        => [RegenerationRequesterInterface::class, RegenerationRequester::class],
            'invalidator'      => [InvalidatorInterface::class, Invalidator::class],
            'problem log'      => [ProblemLogInterface::class, ProblemLog::class],
            'change inspector' => [ChangeInspectorInterface::class, ChangeInspector::class],
            'handler pool'     => [HandlerPoolInterface::class, HandlerPool::class],
            'public paths'     => [PublicPathsInterface::class, PublicPaths::class],
            'canonical path'   => [CanonicalPathRedirectInterface::class, CanonicalPathRedirect::class],
            'availability'     => [AvailabilityResolverInterface::class, AvailabilityResolver::class],
            'final price'      => [FinalPriceInterface::class, FinalPrice::class],
            'templates'        => [SchemaTemplatesInterface::class, SchemaBuilderPool::class],
            'offer summary'    => [OfferSummaryInterface::class, OfferSummary::class],
            'variant image'    => [VariantImageInterface::class, VariantImage::class],
            'page directives'  => [PageDirectivesInterface::class, PageDirectives::class],
            'schema price'     => [SchemaPriceInterface::class, SchemaPrice::class],
            'faq sources'      => [FaqSourcePoolInterface::class, SourcePool::class],
            'contact email'    => [ContactEmailInterface::class, ContactEmail::class],
            'storefront'       => [StorefrontSettingsInterface::class, Config::class],
            'canonical url'    => [CanonicalUrlManagerInterface::class, CanonicalUrlManager::class],
        ];
    }

    /**
     * Each interface is the same shared instance as its class, so state a class keeps for the
     * request (a pool, a memoised lookup) is not split between two objects.
     *
     * @dataProvider preferences
     * @param class-string $interface
     * @param class-string $class
     * @return void
     */
    #[DataProvider('preferences')]
    public function testTheInterfaceAndTheClassAreOneSharedInstance(string $interface, string $class): void
    {
        $objectManager = Bootstrap::getObjectManager();

        $this->assertSame($objectManager->get($class), $objectManager->get($interface));
    }

    /**
     * Another module registers its public paths, rebuild handlers and FAQ sources in its di.xml.
     * Naming the interface there, rather than this module's class, must reach the class: Magento's
     * DI passes arguments configured on an interface to the class preferred for it.
     *
     * @magentoAppIsolation enabled
     * @return void
     */
    public function testArgumentsGivenToAnInterfaceReachItsPreferredClass(): void
    {
        /** @var ObjectManager $objectManager */
        $objectManager = Bootstrap::getObjectManager();
        $objectManager->configure([
            PublicPathsInterface::class => ['arguments' => ['paths' => ['contract-test.txt']]],
        ]);

        $this->assertTrue($objectManager->create(PublicPathsInterface::class)->isPublicPath('/contract-test.txt'));
    }
}
