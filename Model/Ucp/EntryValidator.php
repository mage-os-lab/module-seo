<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Ucp;

/**
 * Checks a service or capability entry against the UCP 2026-08-25 business-profile schema
 * (source/schemas/{ucp,service,capability}.json at the ucp v2026-08-25 tag).
 *
 * Returns the reasons an entry would be rejected, so a pool can say which provider sent what; an
 * empty list means the entry conforms. Beyond the field rules, an entry carrying a `schema` URL must
 * be published under its own namespace: the URL's host, reversed, must equal the entry's name or be
 * a label-aligned prefix of it (dev.ucp.shopping.checkout on ucp.dev). Platforms reject an entry
 * that fails this binding, so declaring one would only mislead.
 *
 * URLs must be absolute http(s). UCP requires HTTPS for everything it publishes; http is accepted so
 * a development store on plain http can still declare its own endpoints.
 */
class EntryValidator
{
    /**
     * common/types/reverse_domain_name.json
     */
    private const NAME_PATTERN = '/^[a-z](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9_-]*[a-z0-9_])?)+$/';

    /**
     * ucp.json#/$defs/version
     */
    private const VERSION_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    private const TRANSPORTS = ['rest', 'mcp', 'a2a', 'embedded'];

    /**
     * Transports whose business binding must name an endpoint; an embedded binding has none.
     */
    private const TRANSPORTS_WITH_ENDPOINT = ['rest', 'mcp', 'a2a'];

    /**
     * The reasons a capability entry breaks the business schema; empty when it conforms.
     *
     * @param string $name
     * @param array<string,mixed> $entry
     * @return list<string>
     */
    public function validateCapability(string $name, array $entry): array
    {
        $reasons = $this->validateEntity($name, $entry);

        if (!\array_key_exists('schema', $entry)) {
            $reasons[] = 'schema is required';
        }

        if (\array_key_exists('extends', $entry) && !$this->isExtends($entry['extends'])) {
            $reasons[] = 'extends must be a reverse-domain name or a non-empty list of them';
        }

        return $reasons;
    }

    /**
     * The reasons a service binding breaks the business schema; empty when it conforms.
     *
     * @param string $name
     * @param array<string,mixed> $entry
     * @return list<string>
     */
    public function validateService(string $name, array $entry): array
    {
        $reasons = $this->validateEntity($name, $entry);

        $transport = $entry['transport'] ?? null;
        if (!\in_array($transport, self::TRANSPORTS, true)) {
            $reasons[] = 'transport must be one of ' . implode(', ', self::TRANSPORTS);
        } elseif (\in_array($transport, self::TRANSPORTS_WITH_ENDPOINT, true)
            && !\array_key_exists('endpoint', $entry)
        ) {
            $reasons[] = 'endpoint is required for the ' . $transport . ' transport';
        }

        if (\array_key_exists('endpoint', $entry) && !$this->isUrl($entry['endpoint'])) {
            $reasons[] = 'endpoint must be an absolute http(s) URL';
        }

        return $reasons;
    }

    /**
     * The rules every UCP entity shares: its name, version, URLs, id and config.
     *
     * @param string $name
     * @param array<string,mixed> $entry
     * @return list<string>
     */
    private function validateEntity(string $name, array $entry): array
    {
        $reasons = [];

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            $reasons[] = \sprintf('"%s" is not a reverse-domain name', $name);
        }

        $version = $entry['version'] ?? null;
        if (!\is_string($version) || preg_match(self::VERSION_PATTERN, $version) !== 1) {
            $reasons[] = 'version is required, as a YYYY-MM-DD date';
        }

        foreach (['spec', 'schema'] as $field) {
            if (\array_key_exists($field, $entry) && !$this->isUrl($entry[$field])) {
                $reasons[] = $field . ' must be an absolute http(s) URL';
            }
        }

        $schema = $entry['schema'] ?? null;
        if ($this->isUrl($schema) && !$this->isInNamespace($name, $schema)) {
            $reasons[] = \sprintf('schema %s is not published under the %s namespace', $schema, $name);
        }

        if (\array_key_exists('id', $entry) && !\is_string($entry['id'])) {
            $reasons[] = 'id must be a string';
        }

        // A JSON object: an empty or list-shaped PHP array would encode as [].
        if (\array_key_exists('config', $entry)
            && (!\is_array($entry['config']) || array_is_list($entry['config']))
        ) {
            $reasons[] = 'config must be a non-empty JSON object; leave it out when there is none';
        }

        return $reasons;
    }

    /**
     * Whether the schema URL's host, reversed, is the name or a label-aligned prefix of it.
     *
     * @param string $name
     * @param string $schemaUrl
     * @return bool
     */
    private function isInNamespace(string $name, string $schemaUrl): bool
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $host = strtolower((string) parse_url($schemaUrl, PHP_URL_HOST));
        $authority = implode('.', array_reverse(explode('.', $host)));

        return $name === $authority || str_starts_with($name, $authority . '.');
    }

    /**
     * Whether `extends` is one reverse-domain name or a non-empty list of them.
     *
     * @param mixed $extends
     * @return bool
     */
    private function isExtends(mixed $extends): bool
    {
        $parents = \is_array($extends) ? $extends : [$extends];
        if ($parents === [] || !array_is_list($parents)) {
            return false;
        }

        foreach ($parents as $parent) {
            if (!\is_string($parent) || preg_match(self::NAME_PATTERN, $parent) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a value is an absolute http(s) URL with a host.
     *
     * @param mixed $value
     * @return bool
     */
    private function isUrl(mixed $value): bool
    {
        if (!\is_string($value)) {
            return false;
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $parts = parse_url($value);

        return \is_array($parts)
            && \in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '';
    }
}
