<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\NonTransactionableInterface;
use MageOS\Seo\Model\Migration\LegacyImport;
use MageOS\Seo\Setup\Patch\Data\MigrateHreflang;
use MageOS\Seo\Setup\Patch\Data\MigrateMetaRobotsTag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #27: each legacy data patch tries its import once, through LegacyImport, which records
 * whether it finished; and it runs outside the patch applier's transaction, where a failed save
 * would fail the whole setup:upgrade.
 */
class LegacyImportPatchesTest extends TestCase
{
    /**
     * @dataProvider patches
     * @param class-string<DataPatchInterface> $patchClass
     * @param string $module
     * @return void
     */
    #[DataProvider('patches')]
    public function testThePatchTriesItsImportOnceOutsideATransaction(string $patchClass, string $module): void
    {
        $legacyImport = $this->createMock(LegacyImport::class);
        $legacyImport->expects($this->once())->method('runIfUnfinished')->with($module);
        $legacyImport->expects($this->never())->method('run');

        $patch = new $patchClass($legacyImport);

        $this->assertInstanceOf(NonTransactionableInterface::class, $patch);
        $this->assertSame($patch, $patch->apply());
        $this->assertSame([], $patchClass::getDependencies());
        $this->assertSame([], $patch->getAliases());
    }

    /**
     * @return array<string, array{0: class-string<DataPatchInterface>, 1: string}>
     */
    public static function patches(): array
    {
        return [
            'MageOS_MetaRobotsTag' => [MigrateMetaRobotsTag::class, LegacyImport::META_ROBOTS_TAG],
            'MageOS_Hreflang'      => [MigrateHreflang::class, LegacyImport::HREFLANG],
        ];
    }
}
