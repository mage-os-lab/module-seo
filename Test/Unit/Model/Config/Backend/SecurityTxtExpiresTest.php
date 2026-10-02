<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use MageOS\Seo\Model\Config\Backend\SecurityTxtExpires;
use PHPUnit\Framework\TestCase;

/**
 * Saving security.txt's Expires: a date after today, required while security.txt is served.
 *
 * Today is 2026-10-01 (UTC) throughout.
 */
class SecurityTxtExpiresTest extends TestCase
{
    public function testAFutureDateIsStored(): void
    {
        $backend = $this->backend(' 2027-01-01 ', true);

        $this->assertSame($backend, $backend->beforeSave());
        $this->assertSame('2027-01-01', $backend->getValue());
    }

    /**
     * Today is the UTC date, the zone the file's Expires is written in. An admin east of Greenwich
     * may already be on 2026-10-02, but the 2nd still ends after now in UTC.
     */
    public function testTomorrowIsTheEarliestDateAccepted(): void
    {
        $backend = $this->backend('2026-10-02', true);
        $backend->beforeSave();

        $this->assertSame('2026-10-02', $backend->getValue());
    }

    public function testALeapDayIsADate(): void
    {
        $backend = $this->backend('2028-02-29', true);
        $backend->beforeSave();

        $this->assertSame('2028-02-29', $backend->getValue());
    }

    /**
     * After its Expires date a security.txt "is considered stale and should not be used" (RFC 9116
     * §2.5.5), so a date that has arrived would publish a file that tells researchers to ignore it.
     */
    public function testTodayIsRefused(): void
    {
        $this->expectRefusal(
            '2026-10-01',
            'The security.txt Expires date must be after today: after it, the file is stale and should'
            . ' not be used (RFC 9116).'
        );
    }

    public function testAPastDateIsRefused(): void
    {
        $this->expectRefusal(
            '2025-12-31',
            'The security.txt Expires date must be after today: after it, the file is stale and should'
            . ' not be used (RFC 9116).'
        );
    }

    /**
     * The admin's calendar gives YYYY-MM-DD; anything else was typed, and is refused rather than
     * guessed at.
     */
    public function testSomethingThatIsNotADateIsRefused(): void
    {
        $notDates = ['2027-13-01', '2027-02-30', '2027-02-29', 'next year', '01/01/2027', 'on 2027-01-01',
            '2027-01-01T00:00:00Z', '2027-1-1'];
        foreach ($notDates as $typed) {
            try {
                $this->backend($typed, true)->beforeSave();
                $this->fail(\sprintf('"%s" was accepted.', $typed));
            } catch (LocalizedException $e) {
                $this->assertSame(
                    \sprintf('The security.txt Expires date is not a date: %s. Choose one from the calendar.', $typed),
                    $e->getMessage()
                );
            }
        }
    }

    /**
     * RFC 9116 requires Expires; without it the file is not a valid security.txt.
     */
    public function testAnEmptyDateIsRefusedWhileSecurityTxtIsServed(): void
    {
        $this->expectRefusal(
            '',
            'security.txt needs an Expires date: RFC 9116 requires one.'
        );
    }

    public function testAnEmptyDateIsStoredWhileSecurityTxtIsOff(): void
    {
        $backend = $this->backend('', false);
        $backend->beforeSave();

        $this->assertSame('', $backend->getValue());
    }

    /**
     * Expect the value to be refused with the message, while security.txt is served.
     *
     * @param string $value
     * @param string $message
     * @return void
     */
    private function expectRefusal(string $value, string $message): void
    {
        $backend = $this->backend($value, true);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($message);

        $backend->beforeSave();
    }

    /**
     * The backend model over an entered date, saved alongside security.txt's Enabled.
     *
     * @param string $value
     * @param bool $enabled
     * @return SecurityTxtExpires
     */
    private function backend(string $value, bool $enabled): SecurityTxtExpires
    {
        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createStub(ManagerInterface::class));

        // 23:30 UTC on 1 October is already 2 October in the admin's zone (Amsterdam, here).
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('date')->willReturnCallback(
            static fn ($date = null, $locale = null, $useTimezone = true): \DateTime => $useTimezone
                ? new \DateTime('2026-10-02 01:30:00', new \DateTimeZone('Europe/Amsterdam'))
                : new \DateTime('2026-10-01 23:30:00', new \DateTimeZone('UTC'))
        );

        $backend = new SecurityTxtExpires(
            $context,
            $this->createStub(Registry::class),
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(TypeListInterface::class),
            $timezone
        );
        $backend->setValue($value);
        $backend->setData('fieldset_data', ['enabled' => $enabled ? '1' : '0']);

        return $backend;
    }
}
