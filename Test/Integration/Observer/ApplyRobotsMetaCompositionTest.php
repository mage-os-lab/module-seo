<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\RobotsMeta\Resolver;
use MageOS\Seo\Observer\ApplyRobotsMeta;
use PHPUnit\Framework\TestCase;

/**
 * The robots observer against the real PageConfig and the real store configuration.
 *
 * The unit tests prove the composition rule with the page value handed in. What they cannot show
 * is that the value this observer treats as "core's default" is the one PageConfig itself seeds
 * the page with — read from design/search_engine_robots/default_robots at store scope. If those
 * two ever disagreed, core's own default would be mistaken for another module's restriction and
 * this module's overrides would stop working.
 *
 * Another module's restriction is simulated by writing to PageConfig directly, rather than by
 * installing MageOS_MetaRobotsTag: that module ships with Mage-OS but not with the Magento
 * releases in the CI matrix, and what matters here is the page value, not who wrote it.
 *
 * @magentoAppArea frontend
 */
class ApplyRobotsMetaCompositionTest extends TestCase
{
    /**
     * @magentoConfigFixture current_store design/search_engine_robots/default_robots INDEX,FOLLOW
     * @return void
     */
    public function testARestrictionAnotherModuleAddedSurvives(): void
    {
        $pageConfig = $this->pageConfig();
        $pageConfig->setRobots('NOINDEX,FOLLOW');

        $this->observer($pageConfig, 'INDEX,FOLLOW')->execute(new Observer());

        $this->assertSame('NOINDEX,FOLLOW', $pageConfig->getRobots());
    }

    /**
     * @magentoConfigFixture current_store design/search_engine_robots/default_robots NOINDEX,NOFOLLOW
     * @return void
     */
    public function testCoresOwnDefaultIsStillOverridden(): void
    {
        // Nothing written to the page: PageConfig seeds it from the staging-style default, and an
        // override here must still lift it.
        $pageConfig = $this->pageConfig();

        $this->observer($pageConfig, 'INDEX,FOLLOW')->execute(new Observer());

        $this->assertSame('INDEX,FOLLOW', $pageConfig->getRobots());
    }

    /**
     * A page config of its own, so no test inherits another's robots value.
     *
     * @return PageConfig
     */
    private function pageConfig(): PageConfig
    {
        return Bootstrap::getObjectManager()->create(PageConfig::class);
    }

    /**
     * The real observer, with only the resolver pinned to a known directive.
     *
     * @param PageConfig $pageConfig
     * @param string $resolved
     * @return ApplyRobotsMeta
     */
    private function observer(PageConfig $pageConfig, string $resolved): ApplyRobotsMeta
    {
        $resolver = $this->createStub(Resolver::class);
        $resolver->method('resolve')->willReturn($resolved);

        return Bootstrap::getObjectManager()->create(ApplyRobotsMeta::class, [
            'resolver'   => $resolver,
            'pageConfig' => $pageConfig,
        ]);
    }
}
