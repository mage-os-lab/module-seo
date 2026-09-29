<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller;

use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * A search results page's robots meta: the Search Results Pages Default when one is set, core's
 * Design → Search Engine Robots when it is empty — as for product, category and CMS pages.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 */
class SearchResultsRobotsOutputTest extends AbstractController
{
    private const SEARCH_DEFAULT = 'mageos_seo_general/robots_meta/search_default';

    /**
     * @return void
     */
    #[Config(self::SEARCH_DEFAULT, 'NOINDEX,FOLLOW', ScopeInterface::SCOPE_STORE, 'default')]
    public function testTheSearchResultsDefaultIsApplied(): void
    {
        $this->assertSame('NOINDEX,FOLLOW', $this->robotsOfASearch());
    }

    /**
     * @return void
     */
    public function testWithNoDefaultCoreDecides(): void
    {
        // Core's Design → Search Engine Robots default in the test install.
        $this->assertSame('INDEX,FOLLOW', $this->robotsOfASearch());
    }

    /**
     * Run a quick search and return the page's robots meta content.
     *
     * @return string
     */
    private function robotsOfASearch(): string
    {
        $this->getRequest()->setParam('q', 'shirt');
        $this->dispatch('catalogsearch/result/index');

        $body = (string) $this->getResponse()->getBody();

        return preg_match('#<meta name="robots" content="([^"]*)"#', $body, $match) === 1 ? $match[1] : '';
    }
}
