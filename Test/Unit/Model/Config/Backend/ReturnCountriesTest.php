<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use MageOS\Seo\Model\Config\Backend\ReturnCountries;
use MageOS\Seo\Model\Product\OfferEnricher\CountryList;
use PHPUnit\Framework\TestCase;

/**
 * Saving the return policy's countries: every selection is saved, and above Google's 50 the admin
 * is told which are output.
 */
class ReturnCountriesTest extends TestCase
{
    public function testFiftyCountriesSaveWithoutANotice(): void
    {
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects($this->never())->method('addNoticeMessage');

        $this->backend(implode(',', $this->codes(50)), $messages)->afterSave();
    }

    public function testMoreThanFiftySaveWithANoticeSayingWhichAreOutput(): void
    {
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects($this->once())->method('addNoticeMessage')->with(
            'Google reads at most 50 return countries. 51 are selected, so only the first 50 in the list'
            . ' are output. Choose Applies Worldwide to cover every country.'
        );

        $backend = $this->backend(implode(',', $this->codes(51)), $messages);
        $backend->afterSave();

        $this->assertSame(implode(',', $this->codes(51)), $backend->getValue());
    }

    /**
     * The admin posts a multiselect as an array; the resource model joins it before the save.
     */
    public function testASelectionStillAnArrayIsCountedToo(): void
    {
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects($this->once())->method('addNoticeMessage');

        $this->backend($this->codes(51), $messages)->afterSave();
    }

    /**
     * The backend model over a saved selection.
     *
     * @param string|string[] $value
     * @param ManagerInterface $messages
     * @return ReturnCountries
     */
    private function backend(string|array $value, ManagerInterface $messages): ReturnCountries
    {
        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createStub(EventManager::class));

        $backend = new ReturnCountries(
            $context,
            $this->createStub(Registry::class),
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(TypeListInterface::class),
            $messages,
            new CountryList()
        );
        $backend->setValue($value);

        return $backend;
    }

    /**
     * Distinct country-like codes.
     *
     * @param int $count
     * @return string[]
     */
    private function codes(int $count): array
    {
        return array_map(static fn (int $i): string => \sprintf('C%02d', $i), range(1, $count));
    }
}
