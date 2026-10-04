<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration;

use Magento\Framework\Console\CommandListInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The identifiers this module owns, as Magento sees them once the configuration is merged. A store's
 * data, admin roles, deployment configuration and other modules refer to these, so each is pinned
 * here rather than left to the code that happens to use it.
 *
 * @magentoAppArea adminhtml
 */
class OwnerRegistrationsTest extends TestCase
{
    /**
     * The rebuild command is MageOS_Seo's: the rebuild layer it drives is shared. Its name keeps to
     * one colon.
     *
     * @return void
     */
    public function testTheRebuildCommandIsSeos(): void
    {
        $names = array_map(
            static fn ($command): string => (string) $command->getName(),
            Bootstrap::getObjectManager()->get(CommandListInterface::class)->getCommands()
        );

        $this->assertContains('seo:rebuild', $names);
        $this->assertNotContains('mageos:seo:feeds:regenerate', $names);
    }
}
