<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Rebuild;

/**
 * Says that a group's documents are out of date: queues their rebuild, unless nothing can build the
 * group (it is switched off in every store view).
 *
 * The documents already served stay in place until the rebuild replaces them.
 *
 * @api
 */
interface InvalidatorInterface
{
    /**
     * Queue the group's rebuild, when anything can build it.
     *
     * @param string $group A group a GroupHandlerInterface owns
     * @return void
     */
    public function invalidate(string $group): void;
}
