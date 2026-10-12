<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Rebuild;

use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Rebuild\BuildFreshness;
use PHPUnit\Framework\TestCase;

/**
 * Module-aeo #9: a long-running consumer builds every message from current data.
 */
class BuildFreshnessTest extends TestCase
{
    public function testTheListedServicesAreResetBeforeEachBuild(): void
    {
        $service = $this->createMock(ResetAfterRequestInterface::class);
        $service->expects($this->exactly(2))->method('_resetState');

        $freshness = $this->freshness(['', ''], $this->createStub(ReinitableConfigInterface::class), [$service]);
        $freshness->refresh();
        $freshness->refresh();
    }

    public function testConfigurationIsReloadedOnlyWhenAChangeWasRecordedSinceTheLastReload(): void
    {
        // The first build reloads once, as the process may have read its configuration before the
        // change; the same mark again changes nothing; a new mark reloads again.
        $config = $this->createMock(ReinitableConfigInterface::class);
        $config->expects($this->exactly(2))->method('reinit');

        $freshness = $this->freshness(['change-1', 'change-1', 'change-2'], $config);
        $freshness->refresh();
        $freshness->refresh();
        $freshness->refresh();
    }

    public function testNothingIsReloadedWhileNoChangeWasEverRecorded(): void
    {
        $config = $this->createMock(ReinitableConfigInterface::class);
        $config->expects($this->never())->method('reinit');

        $this->freshness([''], $config)->refresh();
    }

    /**
     * Freshness over a flag that reads the given values in turn.
     *
     * @param string[] $marks
     * @param ReinitableConfigInterface $config
     * @param array<int, mixed> $services
     * @return BuildFreshness
     */
    private function freshness(array $marks, ReinitableConfigInterface $config, array $services = []): BuildFreshness
    {
        $flagManager = $this->createStub(FlagManager::class);
        $flagManager->method('getFlagData')->willReturnOnConsecutiveCalls(...array_values($marks));

        return new BuildFreshness(
            $flagManager,
            $config,
            $this->createStub(StoreManagerInterface::class),
            $services
        );
    }
}
