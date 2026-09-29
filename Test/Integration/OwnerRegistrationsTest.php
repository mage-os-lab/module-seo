<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration;

use Magento\Backend\Model\Menu\Config as MenuConfig;
use Magento\Config\Model\Config\Structure;
use Magento\Cron\Model\ConfigInterface as CronConfig;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Console\CommandListInterface;
use Magento\PageBuilder\Model\ConfigInterface as PageBuilderConfig;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\Widget\Model\Widget;
use MageOS\Seo\Cron\RegenerateFeeds;
use MageOS\Seo\Model\Faq;
use MageOS\Seo\Model\ResourceModel\Faq as FaqResource;
use PHPUnit\Framework\TestCase;

/**
 * The identifiers each future module owns, as Magento sees them once the configuration is merged:
 * the FAQ under `mageos_faq`, the llms documents and AI crawler directives under `mageos_aeo`, and
 * the agentic commerce profile under `mageos_agentic`. MageOS_Seo keeps `mageos_seo`.
 *
 * These are what a store's data, admin roles, deployment configuration and other modules refer to,
 * so each is pinned here rather than left to the code that happens to use it. At the split, each
 * assertion moves with its owner.
 *
 * @magentoAppArea adminhtml
 */
class OwnerRegistrationsTest extends TestCase
{
    /**
     * @return void
     */
    public function testTheAiInformationSettingsHaveASectionOfTheirOwn(): void
    {
        $structure = Bootstrap::getObjectManager()->get(Structure::class);
        $paths     = array_keys($structure->getFieldPaths());

        foreach ([
            'llms_txt/enabled',
            'llms_txt/full_enabled',
            'llms_txt/jsonl_enabled',
            'llms_txt/faq_groups',
            'feeds/storage_dir',
            'ai_robots/enabled',
            'ai_robots/disallowed',
        ] as $field) {
            $this->assertContains('mageos_aeo/' . $field, $paths);
            $this->assertNotContains('mageos_seo_general/' . $field, $paths);
        }

        $section = $structure->getElement('mageos_aeo');
        $this->assertSame('AI Information & Crawlers', (string) $section->getLabel());
        $this->assertSame('MageOS_Aeo::config', $section->getAttribute('resource'));
    }

    /**
     * @return void
     */
    public function testTheAgenticCommerceSettingsHaveASectionOfTheirOwn(): void
    {
        $structure = Bootstrap::getObjectManager()->get(Structure::class);
        $paths     = array_keys($structure->getFieldPaths());

        foreach ([
            'general/enabled',
            'signing/private_key',
            'signing/public_key_jwk',
            'security_txt/enabled',
            'security_txt/contact_email',
            'security_txt/expires',
            'security_txt/policy_url',
        ] as $field) {
            $this->assertContains('mageos_agentic/' . $field, $paths);
            $this->assertNotContains('mageos_seo_ucp/' . $field, $paths);
        }

        $section = $structure->getElement('mageos_agentic');
        $this->assertSame('Agentic Commerce (UCP)', (string) $section->getLabel());
        $this->assertSame('MageOS_Agentic::config', $section->getAttribute('resource'));
    }

    /**
     * Each owner's resources sit under the SEO resource, where admin roles already find them.
     *
     * @return void
     */
    public function testEachOwnersAclResourcesAreRegistered(): void
    {
        $acl = Bootstrap::getObjectManager()->get(AclBuilder::class)->getAcl();

        foreach (['MageOS_Faq::faq', 'MageOS_Aeo::config', 'MageOS_Agentic::config'] as $resource) {
            $this->assertTrue($acl->hasResource($resource), $resource);
            $this->assertTrue($acl->inheritsResource($resource, 'MageOS_Seo::seo'), $resource);
        }
        $this->assertFalse($acl->hasResource('MageOS_Seo::faq'));
    }

    /**
     * @return void
     */
    public function testTheFaqMenuItemOpensTheFaqAdmin(): void
    {
        $menu = Bootstrap::getObjectManager()->get(MenuConfig::class)->getMenu();

        $this->assertSame('mageos_faq/faq/index', $menu->get('MageOS_Faq::faq')?->getAction());
        $this->assertNull($menu->get('MageOS_Seo::faq'));
    }

    /**
     * @return void
     */
    public function testTheFaqTableModelEventsAndCacheTagCarryTheFaqPrefix(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $resource      = $objectManager->get(ResourceConnection::class);

        $this->assertSame(
            $resource->getTableName('mageos_faq'),
            $objectManager->get(FaqResource::class)->getMainTable()
        );
        $this->assertTrue($resource->getConnection()->isTableExists($resource->getTableName('mageos_faq')));

        /** @var Faq $faq */
        $faq = $objectManager->create(Faq::class);
        $this->assertSame('mageos_faq', $faq->getEventPrefix());
        $this->assertSame(['mageos_faq'], $faq->getIdentities());
    }

    /**
     * @return void
     */
    public function testTheFaqWidgetIsRegisteredUnderTheFaqPrefix(): void
    {
        $widgets = Bootstrap::getObjectManager()->get(Widget::class)->getWidgets();

        $this->assertArrayHasKey('mageos_faq_list', $widgets);
        $this->assertArrayNotHasKey('mageos_seo_faq_list', $widgets);
    }

    /**
     * The FAQ element sits with core's content elements; this module adds no menu section.
     *
     * @return void
     */
    public function testTheFaqPageBuilderElementIsInAddContent(): void
    {
        if (!interface_exists(PageBuilderConfig::class)) {
            $this->markTestSkipped('Magento_PageBuilder is not installed.');
        }
        $config = Bootstrap::getObjectManager()->get(PageBuilderConfig::class);
        $types  = $config->getContentTypes();

        $this->assertSame('add_content', $types['mageos_faq']['menu_section'] ?? null);
        $this->assertArrayNotHasKey('mageos_seo_faq', $types);
        $this->assertArrayNotHasKey('mageos_seo', $config->getMenuSections());
    }

    /**
     * @return void
     */
    public function testTheNightlyFeedRebuildIsAnAeoCronJob(): void
    {
        $jobs = Bootstrap::getObjectManager()->get(CronConfig::class)->getJobs()['default'] ?? [];

        $this->assertSame(RegenerateFeeds::class, $jobs['mageos_aeo_regenerate_feeds']['instance'] ?? null);
        $this->assertArrayNotHasKey('mageos_seo_regenerate_feeds', $jobs);
    }

    /**
     * The rebuild command stays MageOS_Seo's: the rebuild layer it drives is shared. Both names keep
     * to one colon.
     *
     * @return void
     */
    public function testTheKeygenCommandIsAgenticsAndTheRebuildCommandStaysSeos(): void
    {
        $names = array_map(
            static fn ($command): string => (string) $command->getName(),
            Bootstrap::getObjectManager()->get(CommandListInterface::class)->getCommands()
        );

        $this->assertContains('ucp:keygen', $names);
        $this->assertNotContains('mageos:seo:ucp:keygen', $names);
        $this->assertContains('seo:rebuild', $names);
        $this->assertNotContains('mageos:seo:feeds:regenerate', $names);
    }
}
