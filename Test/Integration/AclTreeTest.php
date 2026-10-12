<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration;

use Magento\Framework\Acl\AclResource\ProviderInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Review low item, decision D11a: this module's permissions sit where its admin pages are, as
 * core's do.
 *
 * The menu was already Marketing → SEO, but the permissions were a top-level "SEO" node beside
 * Marketing and Stores. Organization now sits under Marketing → SEO, like its menu item, and the
 * configuration section's permission under Stores → Settings → Configuration, with every other
 * section's. The resource IDs do not change, so the grants roles already hold keep working.
 *
 * @magentoAppArea adminhtml
 */
class AclTreeTest extends TestCase
{
    /**
     * @return void
     */
    public function testSeoSitsUnderMarketingLikeItsMenu(): void
    {
        $this->assertSame(
            ['Magento_Backend::admin', 'Magento_Backend::marketing', 'MageOS_Seo::seo'],
            $this->ancestry('MageOS_Seo::seo')
        );
        $this->assertSame(
            ['Magento_Backend::admin', 'Magento_Backend::marketing', 'MageOS_Seo::seo', 'MageOS_Seo::organization'],
            $this->ancestry('MageOS_Seo::organization')
        );
    }

    /**
     * @return void
     */
    public function testTheConfigurationSectionSitsWithEveryOtherSection(): void
    {
        $this->assertSame(
            [
                'Magento_Backend::admin',
                'Magento_Backend::stores',
                'Magento_Backend::stores_settings',
                'Magento_Config::config',
                'MageOS_Seo::config',
            ],
            $this->ancestry('MageOS_Seo::config')
        );
    }

    /**
     * The resource IDs from the root down to the one given, as the merged ACL tree has them.
     *
     * @param string $resourceId
     * @return string[]
     */
    private function ancestry(string $resourceId): array
    {
        $tree = Bootstrap::getObjectManager()->get(ProviderInterface::class)->getAclResources();

        return $this->find($tree, $resourceId, []) ?? [];
    }

    /**
     * Depth-first search for a resource, carrying the path to it.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param string $resourceId
     * @param string[] $path
     * @return string[]|null
     */
    private function find(array $nodes, string $resourceId, array $path): ?array
    {
        foreach ($nodes as $node) {
            $here   = $path;
            $here[] = (string) $node['id'];
            if ($node['id'] === $resourceId) {
                return $here;
            }
            $found = $this->find($node['children'] ?? [], $resourceId, $here);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
