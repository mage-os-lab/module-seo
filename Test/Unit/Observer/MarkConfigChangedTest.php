<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Observer;

use Magento\Framework\App\Config\Value as ConfigValue;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\FlagManager;
use MageOS\Seo\Model\Rebuild\BuildFreshness;
use MageOS\Seo\Observer\MarkConfigChanged;
use PHPUnit\Framework\TestCase;

/**
 * Module-aeo #9: a configuration change is recorded, so a running consumer reloads its configuration.
 */
class MarkConfigChangedTest extends TestCase
{
    public function testAChangedOrDeletedValueIsRecordedWithANewMarkEachTime(): void
    {
        $marks       = [];
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->expects($this->exactly(2))->method('saveFlag')->willReturnCallback(
            static function (string $code, string $mark) use (&$marks): bool {
                $marks[$code . ':' . $mark] = true;
                return true;
            }
        );
        $observer = new MarkConfigChanged($flagManager);

        $observer->execute($this->observer('config_data_save_after', $this->value(true)));
        $observer->execute($this->observer('config_data_delete_after', $this->value(false)));

        $this->assertCount(2, $marks, 'Each change writes a mark of its own.');
        foreach (array_keys($marks) as $mark) {
            $this->assertStringStartsWith(BuildFreshness::CONFIG_CHANGED_FLAG . ':', $mark);
        }
    }

    public function testAValueSavedUnchangedRecordsNothing(): void
    {
        // The admin saves every field of a section, changed or not.
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->expects($this->never())->method('saveFlag');

        (new MarkConfigChanged($flagManager))->execute($this->observer('config_data_save_after', $this->value(false)));
    }

    /**
     * @param string $eventName
     * @param ConfigValue $value
     * @return Observer
     */
    private function observer(string $eventName, ConfigValue $value): Observer
    {
        return new Observer(['event' => new Event(['name' => $eventName, 'data_object' => $value])]);
    }

    /**
     * A configuration value reporting whether the save changed it.
     *
     * @param bool $changed
     * @return ConfigValue
     */
    private function value(bool $changed): ConfigValue
    {
        $value = new class ($changed) extends ConfigValue {
            /**
             * @param bool $changed
             */
            public function __construct(private readonly bool $changed)
            {
            }

            /**
             * @inheritdoc
             */
            public function isValueChanged()
            {
                return $this->changed;
            }
        };
        $value->setData('path', 'web/unsecure/base_url');

        return $value;
    }
}
