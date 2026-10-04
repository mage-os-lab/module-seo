<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Console;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\PageFactory;
use Magento\Framework\Console\CommandListInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Console\Command\RegenerateFeedsCommand;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Test\Integration\Model\Sitemap\GeneratesSitemaps;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The rebuild command, outside the queue. A module that registers a group in the handler pool
 * (MageOS_Aeo's llms documents, for one) tests its own group through it.
 *
 * @magentoAppArea global
 * @magentoDbIsolation enabled
 */
class RegenerateFeedsCommandTest extends TestCase
{
    use GeneratesSitemaps;

    /**
     * The command is registered with bin/magento.
     *
     * @return void
     */
    public function testCommandIsRegistered(): void
    {
        $commands = Bootstrap::getObjectManager()->get(CommandListInterface::class)->getCommands();

        $this->assertArrayHasKey('mageos_seo_rebuild', $commands);
        $this->assertInstanceOf(RegenerateFeedsCommand::class, $commands['mageos_seo_rebuild']);
    }

    /**
     * Unknown groups are rejected.
     *
     * @return void
     */
    public function testTheCommandRejectsUnknownGroups(): void
    {
        $tester = new CommandTester(
            Bootstrap::getObjectManager()->create(RegenerateFeedsCommand::class)
        );

        $this->assertSame(Command::INVALID, $tester->execute(['--group' => ['robots']]));
    }

    /**
     * A sitemap group rebuilds that type of page in a generated sitemap, in process — with Rebuild
     * on Change off too: that setting is about changes, not about a rebuild asked for by hand.
     *
     * @magentoAppIsolation enabled
     * @return void
     */
    public function testASitemapGroupRebuildsItsTypeWhateverRebuildOnChangeSays(): void
    {
        $this->setUpSitemaps();
        try {
            $sitemap = $this->sitemapFor($this->storeId());
            $this->generateSitemap($sitemap);
            $this->setStoreConfig(Config::XML_SITEMAP_REBUILD_ON_CHANGE, '0');
            $identifier = $this->newPage();

            $tester = new CommandTester(Bootstrap::getObjectManager()->create(RegenerateFeedsCommand::class));

            $this->assertSame(
                Command::SUCCESS,
                $tester->execute(['--group' => ['sitemap-pages']]),
                $tester->getDisplay()
            );
            $this->assertStringContainsString(
                $identifier,
                $this->pub()->readFile("media/sitemap/{$this->sitemapName}-{$this->storeId()}-pages-1.xml")
            );
        } finally {
            $this->removeGeneratedSitemaps();
        }
    }

    /**
     * A new CMS page in every store view; returns its identifier.
     *
     * @return string
     */
    private function newPage(): string
    {
        $identifier = 'mageos-seo-cli-' . uniqid();

        $page = Bootstrap::getObjectManager()->get(PageFactory::class)->create();
        $page->setData([
            PageInterface::IDENTIFIER => $identifier,
            PageInterface::TITLE      => 'MageOS SEO CLI',
            PageInterface::CONTENT    => '<p>CLI</p>',
            PageInterface::IS_ACTIVE  => 1,
            'stores'                  => [0],
        ]);
        Bootstrap::getObjectManager()->get(PageRepositoryInterface::class)->save($page);

        return $identifier;
    }

    /**
     * ID of the default store view.
     *
     * @return int
     */
    private function storeId(): int
    {
        return (int) Bootstrap::getObjectManager()
            ->get(StoreManagerInterface::class)
            ->getStore('default')
            ->getId();
    }
}
