<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

/**
 * Supplies event data for Event structured data.
 *
 * The Seo module defines the contract and the schema provider; a bridge events module implements
 * this interface and registers it in the EventSchemaProvider pool via its own di.xml.
 *
 * @api
 */
interface EventDataProviderInterface
{
    /**
     * Layout handles that identify a page carrying events. ['*'] = every page.
     *
     * @return string[]
     */
    public function getHandles(): array;

    /**
     * Return events for the current page context, or an empty array.
     *
     * Each entry must contain at least name, startDate and location; description, endDate, image,
     * url, eventStatus and eventAttendanceMode are optional.
     *
     * @param int $storeId
     * @return array<int, array<string, mixed>>
     */
    public function getEvents(int $storeId): array;
}
