<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Rebuild;

/**
 * The handlers of the rebuild groups, one per group.
 *
 * A module adds its group by registering its GroupHandlerInterface in its own di.xml, under a name
 * of its own:
 *
 *     <type name="MageOS\Seo\Api\Rebuild\HandlerPoolInterface">
 *         <arguments>
 *             <argument name="handlers" xsi:type="array">
 *                 <item name="my_feed" xsi:type="object">Vendor\Module\Model\FeedHandler</item>
 *             </argument>
 *         </arguments>
 *     </type>
 *
 * @api
 */
interface HandlerPoolInterface
{
    /**
     * The handler that owns the group, or null when none does.
     *
     * @param string $group
     * @return GroupHandlerInterface|null
     */
    public function get(string $group): ?GroupHandlerInterface;

    /**
     * Every group a handler owns.
     *
     * @return string[]
     */
    public function getGroups(): array;

    /**
     * Every registered handler.
     *
     * @return GroupHandlerInterface[]
     */
    public function getHandlers(): array;
}
