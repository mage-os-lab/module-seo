<?php

declare(strict_types=1);

namespace MageOS\Seo\Observer;

use Magento\Framework\App\Config\Value as ConfigValue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\FlagManager;
use MageOS\Seo\Model\Rebuild\BuildFreshness;

/**
 * Records a configuration change, so a running rebuild consumer reloads its configuration before
 * its next build (Model\Rebuild\BuildFreshness).
 *
 * Any changed or deleted value counts: what a rebuild reads spans core's settings and every module
 * with a rebuild group. The flag is written in the same transaction as the value, so a save that
 * rolls back records nothing.
 */
class MarkConfigChanged implements ObserverInterface
{
    /**
     * @param FlagManager $flagManager
     */
    public function __construct(
        private readonly FlagManager $flagManager
    ) {
    }

    /**
     * Record the change when the value differs from the stored one, or was deleted.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $value = $event->getData('data_object');
        if (!$value instanceof ConfigValue) {
            return;
        }

        if (str_ends_with((string) $event->getName(), '_delete_after') || $value->isValueChanged()) {
            // A new value each time: BuildFreshness compares it with the last one it reloaded for.
            $this->flagManager->saveFlag(BuildFreshness::CONFIG_CHANGED_FLAG, uniqid('', true));
        }
    }
}
