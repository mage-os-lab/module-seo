<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Ucp;

use MageOS\Seo\Api\UcpCapabilityProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Collect-all pool of the UCP capabilities the /.well-known/ucp profile declares.
 *
 * Empty by default: MageOS_Seo serves the discovery document, and a module that implements a UCP
 * capability appends its provider via di.xml. Each entry is checked against the UCP business
 * schema; one that fails is logged with its provider and the reasons, and left out, so a
 * misconfigured provider cannot put an entry in the profile that platforms would reject.
 */
class CapabilityPool
{
    /**
     * @param EntryValidator $validator
     * @param LoggerInterface $logger
     * @param UcpCapabilityProviderInterface[] $providers
     */
    public function __construct(
        private readonly EntryValidator $validator,
        private readonly LoggerInterface $logger,
        private readonly array $providers = []
    ) {
    }

    /**
     * The profile's capability registry: each enabled capability name maps to its list of entries.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function getEnabledCapabilities(): array
    {
        $capabilities = [];
        foreach ($this->providers as $provider) {
            if (!$provider->isEnabled()) {
                continue;
            }

            $name    = $provider->getCapabilityKey();
            $entry   = $provider->getCapabilityData();
            $reasons = $this->validator->validateCapability($name, $entry);
            if ($reasons !== []) {
                $this->logger->error(\sprintf(
                    'MageOS_Seo: UCP capability "%s" from %s left out of /.well-known/ucp: %s.',
                    $name,
                    $provider::class,
                    implode('; ', $reasons)
                ));
                continue;
            }

            $capabilities[$name][] = $entry;
        }

        return $capabilities;
    }
}
