<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Config;

use Magento\Config\Model\Config;
use Magento\Framework\Exception\LocalizedException;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Issue #32: saving Shipping Details in the admin refuses a time the storefront could not publish,
 * through the backend models the configuration declares.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation enabled
 */
class ShippingDetailsConfigTest extends TestCase
{
    /**
     * @return void
     */
    public function testAnInvertedTimeIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Transit Time Min (days) cannot be more than Transit Time Max (days).');

        $this->save(['enabled' => '1', 'rate' => '0', 'transit_min' => '5', 'transit_max' => '2']);
    }

    /**
     * @return void
     */
    public function testNoRateIsRefusedWhileShippingDetailsAreOn(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Enter a Shipping Rate while Enable Shipping Details Schema is Yes.');

        $this->save(['enabled' => '1', 'rate' => '']);
    }

    /**
     * @return void
     */
    public function testAValidGroupIsSaved(): void
    {
        $this->save([
            'enabled'      => '1',
            'rate'         => '4.95',
            'transit_max'  => '3',
            'handling_min' => '0',
            'handling_max' => '1',
        ]);

        $this->assertSame(
            '3',
            Bootstrap::getObjectManager()->get(\Magento\Framework\App\Config\ScopeConfigInterface::class)
                ->getValue('mageos_seo_merchant/shipping/transit_max')
        );
    }

    /**
     * Save the Shipping Details group at the default scope, as the admin's configuration page does.
     *
     * @param array<string,string> $values
     * @return void
     */
    private function save(array $values): void
    {
        $fields = [];
        foreach ($values as $field => $value) {
            $fields[$field] = ['value' => $value];
        }

        $config = Bootstrap::getObjectManager()->create(Config::class);
        $config->setSection('mageos_seo_merchant');
        $config->setGroups(['shipping' => ['fields' => $fields]]);
        $config->save();
    }
}
