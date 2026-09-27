<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Feed;

use Magento\Config\Model\Config as ConfigModel;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\FlagManager;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Which configuration saves queue a rebuild of /llms.txt and /llms-full.txt.
 *
 * Only the `llms` group is checked: several of these paths also change the sitemaps, which
 * Model/Sitemap/SitemapInvalidationRulesTest covers.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation enabled
 */
class LlmsConfigInvalidationTest extends TestCase
{
    private const FLAG_LLMS = 'mageos_seo_feed_pending_llms';

    /**
     * A changed value on each path the documents read queues the rebuild.
     *
     * @return void
     */
    public function testConfigurationTheDocumentsReadQueuesTheLlmsRebuild(): void
    {
        $changes = [
            'general/locale/code'                    => 'en_GB',
            'trans_email/ident_support/email'        => 'help@shop.test',
            'mageos_seo_general/llms_txt/faq_groups' => 'global,shipping',
            // Flipped: installs differ on whether rewrites are on.
            'web/seo/use_rewrites'                   => $this->current('web/seo/use_rewrites') === '1' ? '0' : '1',
            'catalog/seo/category_url_suffix'        => '.htm',
        ];

        foreach ($changes as $path => $value) {
            $this->assertNotSame($this->current($path), $value, "The test value for {$path} is not a change.");
            $this->assertTrue($this->queuesLlms($path, $value), "Saving {$path} queued no llms rebuild.");
        }
    }

    /**
     * Configuration the documents do not read, and unchanged values, queue nothing.
     *
     * @return void
     */
    public function testOtherConfigurationDoesNotQueueIt(): void
    {
        $this->assertFalse(
            $this->queuesLlms('contact/email/recipient_email', 'x@shop.test'),
            'An unrelated path queued an llms rebuild.'
        );
        $this->assertFalse(
            $this->queuesLlms('general/locale/code', $this->current('general/locale/code')),
            'An unchanged locale queued an llms rebuild.'
        );
    }

    /**
     * Save a value as `bin/magento config:set` does and report whether an llms rebuild was queued.
     *
     * The original value is saved back afterwards. The transaction rolls the rows back, but the
     * config model re-initialises the configuration on save, so without it the changed value would
     * stay in memory for later tests.
     *
     * @param string $path
     * @param string $value
     * @return bool
     */
    private function queuesLlms(string $path, string $value): bool
    {
        $original = $this->current($path);
        $flags    = Bootstrap::getObjectManager()->get(FlagManager::class);
        $flags->deleteFlag(self::FLAG_LLMS);

        try {
            $this->saveConfig($path, $value);

            return $flags->getFlagData(self::FLAG_LLMS) !== null;
        } finally {
            $this->saveConfig($path, $original);
            $flags->deleteFlag(self::FLAG_LLMS);
        }
    }

    /**
     * The value at the default scope.
     *
     * @param string $path
     * @return string
     */
    private function current(string $path): string
    {
        return (string) Bootstrap::getObjectManager()->get(ScopeConfigInterface::class)->getValue($path);
    }

    /**
     * Save one value at the default scope as `bin/magento config:set` does.
     *
     * @param string $path
     * @param string $value
     * @return void
     */
    private function saveConfig(string $path, string $value): void
    {
        $config = Bootstrap::getObjectManager()->create(
            ConfigModel::class,
            ['data' => ['scope' => 'default', 'scope_code' => null]]
        );
        $config->setDataByPath($path, $value);
        $config->save();
    }
}
