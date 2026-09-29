<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\RobotsMeta\Provider;

use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\RobotsMeta\Provider\SearchResultsRobotsProvider;
use PHPUnit\Framework\TestCase;

/**
 * Search result pages: the Search Results Pages Default, or nothing when it is empty so that core's
 * Design → Search Engine Robots decides — as for product, category and CMS pages.
 */
class SearchResultsRobotsProviderTest extends TestCase
{
    public function testItCoversQuickAndAdvancedSearchResults(): void
    {
        $this->assertSame(
            ['catalogsearch_result_index', 'catalogsearch_advanced_result'],
            $this->provider('')->getHandles()
        );
    }

    public function testTheConfiguredDefaultForTheStoreView(): void
    {
        $config = $this->createMock(Config::class);
        $config->expects($this->once())->method('getRobotsSearchDefault')->with(3)->willReturn('NOINDEX,FOLLOW');

        $this->assertSame('NOINDEX,FOLLOW', (new SearchResultsRobotsProvider($config))->getRobots(3));
    }

    public function testNoOpinionWhenTheDefaultIsEmpty(): void
    {
        $this->assertNull($this->provider('')->getRobots(1));
    }

    public function testItRanksWithTheOtherPageTypeDefaults(): void
    {
        $this->assertSame(100, $this->provider('')->getSortOrder());
    }

    /**
     * @param string $default
     * @return SearchResultsRobotsProvider
     */
    private function provider(string $default): SearchResultsRobotsProvider
    {
        $config = $this->createStub(Config::class);
        $config->method('getRobotsSearchDefault')->willReturn($default);

        return new SearchResultsRobotsProvider($config);
    }
}
