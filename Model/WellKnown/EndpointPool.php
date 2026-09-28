<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\WellKnown;

use MageOS\Seo\Api\WellKnownEndpointInterface;

/**
 * Registry of /.well-known/ endpoints, keyed by path segment.
 *
 * Built-ins (ucp, security.txt) are wired in etc/di.xml; bridge modules add their
 * own endpoints by appending to the "endpoints" argument from their di.xml.
 */
class EndpointPool
{
    /**
     * @var array<string, WellKnownEndpointInterface>
     */
    private array $byName = [];

    /**
     * @param WellKnownEndpointInterface[] $endpoints
     */
    public function __construct(array $endpoints = [])
    {
        foreach ($endpoints as $endpoint) {
            $this->byName[$endpoint->getName()] = $endpoint;
        }
    }

    /**
     * Whether an endpoint is registered for the given path segment.
     *
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    /**
     * Return the endpoint for the given path segment, or null when none is registered.
     *
     * @param string $name
     * @return WellKnownEndpointInterface|null
     */
    public function get(string $name): ?WellKnownEndpointInterface
    {
        return $this->byName[$name] ?? null;
    }
}
