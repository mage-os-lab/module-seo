<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use MageOS\Seo\Model\Config\Backend\ShippingRate;
use MageOS\Seo\Model\Product\OfferEnricher\ShippingRules;
use PHPUnit\Framework\TestCase;

/**
 * The shipping rate is an amount of 0 or more, and required while shipping details are on: no rate
 * would otherwise be published as free shipping.
 */
class ShippingRateTest extends TestCase
{
    /**
     * @return void
     */
    public function testAnAmountIsStoredTrimmed(): void
    {
        $backend = $this->backend(' 4.95 ', ['enabled' => '1']);
        $backend->beforeSave();
        $this->assertSame('4.95', $backend->getValue());

        $this->backend('0.00', ['enabled' => '1'])->beforeSave();
    }

    /**
     * @return void
     */
    public function testANegativeOrNonNumericRateIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Shipping Rate must be an amount of 0 or more');

        $this->backend('-1', ['enabled' => '0'])->beforeSave();
    }

    /**
     * @return void
     */
    public function testNoRateIsRefusedWhileShippingDetailsAreOn(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Enter a Shipping Rate while Enable Shipping Details Schema is Yes.');

        $this->backend('', ['enabled' => '1'])->beforeSave();
    }

    /**
     * Saved on its own, the rate is checked against whether shipping details are stored as on.
     *
     * @return void
     */
    public function testSavedAloneTheStoredSwitchDecides(): void
    {
        $this->backend('', [], '0')->beforeSave();

        $this->expectException(LocalizedException::class);
        $this->backend('', [], '1')->beforeSave();
    }

    /**
     * Saved for a store view, the switch is the one that store view has.
     *
     * @return void
     */
    public function testTheStoredSwitchIsTheScopeBeingSaved(): void
    {
        $this->expectException(LocalizedException::class);

        $this->backend('', [], '1', 'stores', 'default')->beforeSave();
    }

    /**
     * The rate, with the group's values saved with it, and the stored switch.
     *
     * @param string $value
     * @param array<string,string> $savedWith
     * @param string|null $storedEnabled The switch stored at the scope saved
     * @param string|null $scope The scope saved; the default scope when null
     * @param string|null $scopeCode
     * @return ShippingRate
     */
    private function backend(
        string $value,
        array $savedWith,
        ?string $storedEnabled = null,
        ?string $scope = null,
        ?string $scopeCode = null
    ): ShippingRate {
        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createStub(ManagerInterface::class));
        // Answers only for the switch's exact path, and only at the scope saved.
        $savedScope = $scope ?? ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        $config     = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(
            static fn (string $path, ?string $type = null, ?string $code = null): ?string
                => $path === 'mageos_seo_merchant/shipping/enabled' && $type === $savedScope && $code === $scopeCode
                    ? $storedEnabled
                    : null
        );

        $backend = new ShippingRate(
            $context,
            $this->createStub(Registry::class),
            $config,
            $this->createStub(TypeListInterface::class),
            new ShippingRules()
        );
        $backend->setPath('mageos_seo_merchant/shipping/rate');
        $backend->setValue($value);
        if ($scope !== null) {
            $backend->setScope($scope);
            $backend->setScopeCode($scopeCode);
        }
        if ($savedWith !== []) {
            $backend->setData('fieldset_data', $savedWith);
        }

        return $backend;
    }
}
