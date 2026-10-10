<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use MageOS\Seo\Model\Config\Backend\ShippingDays;
use MageOS\Seo\Model\Product\OfferEnricher\ShippingRules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #32: a handling or transit time the storefront could not publish is refused when saved,
 * checked against the other bound saved with it or already stored.
 */
class ShippingDaysTest extends TestCase
{
    private const PATH = 'mageos_seo_merchant/shipping/';

    /**
     * @return void
     */
    public function testAMaximumAloneOrWithALowerMinimumIsStoredTrimmed(): void
    {
        $maximum = $this->backend('handling_max', ' 3 ', ['handling_min' => '']);
        $maximum->beforeSave();
        $this->assertSame('3', $maximum->getValue());

        $minimum = $this->backend('handling_min', '1', ['handling_max' => '3']);
        $minimum->beforeSave();
        $this->assertSame('1', $minimum->getValue());

        $this->backend('transit_min', '', ['transit_max' => ''])->beforeSave();
    }

    /**
     * @return void
     */
    public function testAValueThatIsNotWholeDaysIsRefusedByItsLabel(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Transit Time Max (days) must be a whole number of days, 0 or more.');

        $this->backend('transit_max', '2.5', ['transit_min' => ''])->beforeSave();
    }

    /**
     * @return void
     */
    public function testAMinimumWithoutAMaximumIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Enter Transit Time Max (days) as well');

        $this->backend('transit_min', '2', ['transit_max' => ''])->beforeSave();
    }

    /**
     * Saving the maximum below a minimum already stored is refused too.
     *
     * @return void
     */
    public function testAMinimumAboveTheMaximumIsRefusedWhicheverIsSaved(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Handling Time Min (days) cannot be more than Handling Time Max (days).');

        $this->backend('handling_max', '1', [], ['handling_min' => '4'])->beforeSave();
    }

    /**
     * A stored other bound that is not whole days is named, rather than the one being saved.
     *
     * @return void
     */
    public function testABadStoredOtherBoundIsNamed(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Handling Time Min (days) must be a whole number of days, 0 or more.');

        $this->backend('handling_max', '3', [], ['handling_min' => '-1'])->beforeSave();
    }

    /**
     * Saved for a store view, the other bound is the one that store view has.
     *
     * @return void
     */
    public function testTheStoredOtherBoundIsTheScopeBeingSaved(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Transit Time Min (days) cannot be more than Transit Time Max (days).');

        $this->backend('transit_max', '1', [], ['transit_min' => '4'], 'stores', 'default')->beforeSave();
    }

    /**
     * The field being saved, with the group's other values saved with it, and those stored.
     *
     * @param string $field
     * @param string $value
     * @param array<string,string> $savedWith The group's fields saved with it
     * @param array<string,string> $stored Values stored at the scope saved, by field
     * @param string|null $scope The scope saved; the default scope when null
     * @param string|null $scopeCode
     * @return ShippingDays
     */
    private function backend(
        string $field,
        string $value,
        array $savedWith,
        array $stored = [],
        ?string $scope = null,
        ?string $scopeCode = null
    ): ShippingDays {
        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createStub(ManagerInterface::class));
        // Answers only for a field's exact path, and only at the scope saved.
        $byPath = [];
        foreach ($stored as $storedField => $storedValue) {
            $byPath[self::PATH . $storedField] = $storedValue;
        }
        $savedScope = $scope ?? ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        $config     = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(
            static fn (string $path, ?string $type = null, ?string $code = null): ?string
                => $type === $savedScope && $code === $scopeCode ? ($byPath[$path] ?? null) : null
        );

        $backend = new ShippingDays(
            $context,
            $this->createStub(Registry::class),
            $config,
            $this->createStub(TypeListInterface::class),
            new ShippingRules()
        );
        $backend->setPath(self::PATH . $field);
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
