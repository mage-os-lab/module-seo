<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

/**
 * Request-scoped registry of FAQ group identifiers rendered on the current page.
 *
 * Visible FAQ elements (widget, Page Builder, any AbstractFaqElement) register their group
 * identifier as they render in the body; a late head/end-of-body block re-resolves those
 * identifiers to emit FAQPage structured data that always matches what is actually shown. Storing
 * identifiers (not resolved FAQs) keeps the schema correct even when an element's HTML is block-cached.
 *
 * @api
 */
interface FaqCollectorInterface
{
    /**
     * Record that a FAQ group identifier has been rendered on the page.
     *
     * @param string $identifier
     * @return void
     */
    public function collect(string $identifier): void;

    /**
     * Return the distinct collected group identifiers, in first-seen order.
     *
     * @return string[]
     */
    public function getIdentifiers(): array;
}
