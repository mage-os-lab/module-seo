<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\OfferEnricher;

/**
 * The rules shipping details must meet to be published: one place for the admin's check and the
 * output alike, so a value the admin accepts is a value the page publishes.
 *
 * Handling and transit times follow Google's shipping-policy reference: "you must provide either
 * `value` (for a fixed handling time) or `maxValue` (for an upper bound …)", "`minValue` can
 * optionally be provided to specify a lower bound", and "the value must be a non-negative, whole
 * number" (0 is the same day). So a time is a maximum number of days, with a minimum or without
 * one; a minimum alone promises nothing and is refused rather than read as anything (issue #32).
 *
 * The rate is an amount of 0 or more, in the base currency (issue #33). 0 is free shipping; no
 * rate is not.
 */
class ShippingRules
{
    /**
     * A bound that is not a whole number of days, 0 or more.
     */
    public const NOT_DAYS = 'not_days';

    /**
     * A minimum with no maximum.
     */
    public const MIN_WITHOUT_MAX = 'min_without_max';

    /**
     * A minimum above the maximum.
     */
    public const MIN_ABOVE_MAX = 'min_above_max';

    /**
     * No rate.
     */
    public const NO_RATE = 'no_rate';

    /**
     * A rate that is not an amount of 0 or more.
     */
    public const NOT_AN_AMOUNT = 'not_an_amount';

    /**
     * What is wrong with a time's bounds, or null when nothing is — including when neither is set.
     *
     * @param string $min
     * @param string $max
     * @return string|null One of the constants above
     */
    public function daysProblem(string $min, string $max): ?string
    {
        $min = trim($min);
        $max = trim($max);

        if (($min !== '' && !$this->isDays($min)) || ($max !== '' && !$this->isDays($max))) {
            return self::NOT_DAYS;
        }
        if ($min !== '' && $max === '') {
            return self::MIN_WITHOUT_MAX;
        }
        if ($min !== '' && (int) $min > (int) $max) {
            return self::MIN_ABOVE_MAX;
        }

        return null;
    }

    /**
     * Whether a bound, trimmed, is a whole number of days, 0 or more.
     *
     * @param string $value
     * @return bool
     */
    public function isDays(string $value): bool
    {
        return preg_match('/^\d+$/', $value) === 1;
    }

    /**
     * The bounds to publish, or null when the time is not set or breaks a rule.
     *
     * The maximum, and the minimum when there is one.
     *
     * @param string $min
     * @param string $max
     * @return array{minValue?: int, maxValue: int}|null
     */
    public function dayBounds(string $min, string $max): ?array
    {
        $min = trim($min);
        $max = trim($max);
        if ($max === '' || $this->daysProblem($min, $max) !== null) {
            return null;
        }

        $bounds = [];
        if ($min !== '') {
            $bounds['minValue'] = (int) $min;
        }
        $bounds['maxValue'] = (int) $max;

        return $bounds;
    }

    /**
     * What is wrong with a rate, or null when nothing is.
     *
     * @param string $rate
     * @return string|null One of the constants above
     */
    public function rateProblem(string $rate): ?string
    {
        $rate = trim($rate);
        if ($rate === '') {
            return self::NO_RATE;
        }
        if (!is_numeric($rate) || (float) $rate < 0) {
            return self::NOT_AN_AMOUNT;
        }

        return null;
    }
}
