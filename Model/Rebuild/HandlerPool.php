<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Rebuild;

use MageOS\Seo\Api\Rebuild\GroupHandlerInterface;
use MageOS\Seo\Model\Sitemap\RebuildGroup;

/**
 * The registered rebuild group handlers, found by the group they own.
 *
 * Empty in this module: the sitemap groups are this module's own and handled directly. Another
 * module registers a handler for its groups in the `handlers` argument of its di.xml. A group
 * claimed twice, or named like a sitemap group, is refused when the pool is first asked — a message
 * for it could only reach one handler, so the other would never rebuild.
 */
class HandlerPool
{
    /**
     * Handler per group, built on first use.
     *
     * @var array<string,GroupHandlerInterface>|null
     */
    private ?array $byGroup = null;

    /**
     * @param GroupHandlerInterface[] $handlers
     */
    public function __construct(
        private readonly array $handlers = []
    ) {
    }

    /**
     * The handler that owns the group, or null when none does.
     *
     * @param string $group
     * @return GroupHandlerInterface|null
     */
    public function get(string $group): ?GroupHandlerInterface
    {
        return $this->byGroup()[$group] ?? null;
    }

    /**
     * Every registered group, in registration order.
     *
     * @return string[]
     */
    public function getGroups(): array
    {
        return array_keys($this->byGroup());
    }

    /**
     * The registered handlers.
     *
     * @return GroupHandlerInterface[]
     */
    public function getHandlers(): array
    {
        return array_values($this->handlers);
    }

    /**
     * Index the handlers by group, refusing a group claimed twice or named like a sitemap group.
     *
     * @throws \LogicException
     * @return array<string,GroupHandlerInterface>
     */
    private function byGroup(): array
    {
        if ($this->byGroup !== null) {
            return $this->byGroup;
        }

        $byGroup = [];
        foreach ($this->handlers as $handler) {
            foreach ($handler->getGroups() as $group) {
                if (str_starts_with($group, RebuildGroup::PREFIX) || $group === RebuildGroup::MISSING) {
                    throw new \LogicException(\sprintf(
                        'Rebuild group "%s" of %s is named like a sitemap group.',
                        $group,
                        $handler::class
                    ));
                }
                if (isset($byGroup[$group])) {
                    throw new \LogicException(\sprintf(
                        'Rebuild group "%s" is claimed by both %s and %s.',
                        $group,
                        $byGroup[$group]::class,
                        $handler::class
                    ));
                }
                $byGroup[$group] = $handler;
            }
        }

        return $this->byGroup = $byGroup;
    }
}
