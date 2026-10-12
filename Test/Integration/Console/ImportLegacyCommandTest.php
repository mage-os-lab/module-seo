<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Console;

use Magento\Framework\Console\CommandListInterface;
use Magento\Framework\FlagManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Console\Command\ImportLegacyCommand;
use MageOS\Seo\Model\Migration\LegacyImport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Issue #27, through the real wiring: the data patches record each import in LegacyImport's flag
 * when setup runs them, and `bin/magento seo:import-legacy` reaches both imports.
 */
class ImportLegacyCommandTest extends TestCase
{
    /**
     * The test installation ran both data patches; with nothing to fail, both imports finished.
     *
     * @return void
     */
    public function testSetupRecordedBothImportsAsFinished(): void
    {
        $legacyImport = Bootstrap::getObjectManager()->get(LegacyImport::class);

        $this->assertSame([LegacyImport::META_ROBOTS_TAG, LegacyImport::HREFLANG], $legacyImport->modules());
        $this->assertNotNull($legacyImport->finishedAt(LegacyImport::META_ROBOTS_TAG));
        $this->assertNotNull($legacyImport->finishedAt(LegacyImport::HREFLANG));
        $this->assertSame([], $legacyImport->pending());
    }

    /**
     * @return void
     */
    public function testTheCommandIsRegistered(): void
    {
        $commands = Bootstrap::getObjectManager()->get(CommandListInterface::class)->getCommands();

        $this->assertArrayHasKey('mageos_seo_import_legacy', $commands);
        $this->assertInstanceOf(ImportLegacyCommand::class, $commands['mageos_seo_import_legacy']);
        $this->assertSame('seo:import-legacy', $commands['mageos_seo_import_legacy']->getName());
    }

    /**
     * @magentoAppArea adminhtml
     * @return void
     */
    public function testADryRunReachesBothImportsAndRecordsNothing(): void
    {
        $flagManager = Bootstrap::getObjectManager()->get(FlagManager::class);
        $before      = $flagManager->getFlagData(LegacyImport::FLAG);

        $tester = new CommandTester(Bootstrap::getObjectManager()->create(ImportLegacyCommand::class));
        $status = $tester->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $this->assertStringContainsString("MageOS_MetaRobotsTag\n", $tester->getDisplay());
        $this->assertStringContainsString("MageOS_Hreflang\n", $tester->getDisplay());
        $this->assertStringEndsWith("Dry run: nothing was written.\n", $tester->getDisplay());
        $this->assertSame($before, $flagManager->getFlagData(LegacyImport::FLAG));
    }
}
