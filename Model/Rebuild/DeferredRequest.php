<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Rebuild;

/**
 * A rebuild request made inside a transaction, waiting for the commit.
 *
 * Registered by RegenerationRequester as a commit callback: it runs once the outermost transaction
 * commits, and core drops it when the transaction rolls back, so a change that never committed
 * queues nothing. A class of its own rather than a closure, so a test that runs inside an
 * isolation transaction can find it in core's callback pool and run it as the commit would.
 */
class DeferredRequest
{
    /**
     * @param RegenerationRequester $regenerationRequester
     * @param string $group
     */
    public function __construct(
        private readonly RegenerationRequester $regenerationRequester,
        private readonly string                $group
    ) {
    }

    /**
     * Queue the rebuild, now that the change is committed.
     *
     * @return void
     */
    public function __invoke(): void
    {
        $this->regenerationRequester->queueNow($this->group);
    }

    /**
     * The group to rebuild.
     *
     * @return string
     */
    public function group(): string
    {
        return $this->group;
    }
}
