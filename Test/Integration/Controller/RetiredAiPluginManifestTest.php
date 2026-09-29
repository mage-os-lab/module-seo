<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller;

use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * `/.well-known/ai-plugin.json` is retired: OpenAI ended the plugins beta whose manifest it was, and
 * its `auth: none` pointed at a product API that needs an admin token. Nothing answers the path any
 * more — a plain 404, even where the old setting is still saved as enabled.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 */
class RetiredAiPluginManifestTest extends AbstractController
{
    /**
     * @return void
     */
    #[Config('mageos_seo_ucp/ai_plugin/enabled', '1', ScopeInterface::SCOPE_STORE, 'default')]
    public function testTheManifestIsNotFound(): void
    {
        $this->dispatch('/.well-known/ai-plugin.json');

        $this->assert404NotFound();
    }
}
