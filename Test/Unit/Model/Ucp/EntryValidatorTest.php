<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Ucp;

use MageOS\Seo\Model\Ucp\EntryValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * UCP 2026-08-25 business-profile rules for a service or capability entry. The fixtures are the
 * spec's own business-profile example (ucp.dev/2026-08-25/specification/overview).
 */
class EntryValidatorTest extends TestCase
{
    private const CHECKOUT = [
        'version' => '2026-08-25',
        'spec'    => 'https://ucp.dev/2026-08-25/specification/shopping/checkout',
        'schema'  => 'https://ucp.dev/2026-08-25/schemas/shopping/checkout.json',
    ];

    private const REST = [
        'version'   => '2026-08-25',
        'spec'      => 'https://ucp.dev/2026-08-25/specification/overview/',
        'transport' => 'rest',
        'endpoint'  => 'https://business.example.com/ucp/v1',
        'schema'    => 'https://ucp.dev/2026-08-25/services/shopping/rest.openapi.json',
    ];

    private const A2A = [
        'version'   => '2026-08-25',
        'spec'      => 'https://ucp.dev/2026-08-25/specification/overview/',
        'transport' => 'a2a',
        'endpoint'  => 'https://business.example.com/.well-known/agent-card.json',
    ];

    /**
     * @return void
     */
    public function testTheSpecsCapabilitiesAreValid(): void
    {
        $validator = new EntryValidator();

        $this->assertSame([], $validator->validateCapability('dev.ucp.shopping.checkout', self::CHECKOUT));
        $this->assertSame([], $validator->validateCapability('dev.ucp.shopping.fulfillment', [
            'version' => '2026-08-25',
            'spec'    => 'https://ucp.dev/2026-08-25/specification/shopping/extensions/fulfillment',
            'schema'  => 'https://ucp.dev/2026-08-25/schemas/shopping/fulfillment.json',
            'extends' => 'dev.ucp.shopping.checkout',
        ]));
        $this->assertSame([], $validator->validateCapability('dev.ucp.common.identity_linking', [
            'version' => '2026-08-25',
            'schema'  => 'https://ucp.dev/2026-08-25/schemas/common/identity_linking.json',
            'config'  => [
                'providers' => [
                    'com.example.idp' => [['type' => 'oauth2', 'auth_url' => 'https://accounts.example.com/']],
                ],
            ],
        ]));
        $this->assertSame([], $validator->validateCapability('com.example.loyalty', [
            'version' => '2026-08-25',
            'schema'  => 'https://example.com/ucp/loyalty.json',
            'id'      => 'loyalty',
            'extends' => ['dev.ucp.shopping.checkout', 'dev.ucp.shopping.cart'],
        ]));
    }

    /**
     * The four transport bindings of the spec's dev.ucp.shopping service.
     *
     * @return array<string, array{0: array<string, string>}>
     */
    public static function specServiceBindings(): array
    {
        return [
            'rest'     => [self::REST],
            'mcp'      => [[
                'version'   => '2026-08-25',
                'spec'      => 'https://ucp.dev/2026-08-25/specification/overview/',
                'transport' => 'mcp',
                'endpoint'  => 'https://business.example.com/ucp/mcp',
                'schema'    => 'https://ucp.dev/2026-08-25/services/shopping/mcp.openrpc.json',
            ]],
            'a2a, no schema' => [self::A2A],
            'embedded, no endpoint' => [[
                'version'   => '2026-08-25',
                'spec'      => 'https://ucp.dev/2026-08-25/specification/overview/',
                'transport' => 'embedded',
                'schema'    => 'https://ucp.dev/2026-08-25/services/shopping/embedded.openrpc.json',
            ]],
        ];
    }

    /**
     * @dataProvider specServiceBindings
     * @param array<string, string> $entry
     * @return void
     */
    #[DataProvider('specServiceBindings')]
    public function testTheSpecsServiceBindingsAreValid(array $entry): void
    {
        $this->assertSame([], (new EntryValidator())->validateService('dev.ucp.shopping', $entry));
    }

    /**
     * The reverse-domain names the schema lists as examples, and names it does not match.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function names(): array
    {
        return [
            'dev.ucp.shopping.checkout'   => ['dev.ucp.shopping.checkout', true],
            'underscore'                  => ['dev.ucp.common.identity_linking', true],
            'interior hyphen'             => ['com.example-shop.checkout', true],
            'segment starting with digit' => ['com.2example.cart', true],
            'multi-label domain'          => ['uk.co.example-shop.checkout', true],
            'punycode tld'                => ['xn--p1ai.example.checkout', true],
            'one segment'                 => ['shopping', false],
            'upper case'                  => ['Dev.ucp.shopping', false],
            'leading hyphen'              => ['com.-example.cart', false],
            'trailing hyphen'             => ['com.example-.cart', false],
            'empty segment'               => ['com..example', false],
            'trailing dot'                => ['com.example.', false],
            'first segment is a digit'    => ['1com.example', false],
        ];
    }

    /**
     * @dataProvider names
     * @param string $name
     * @param bool $valid
     * @return void
     */
    #[DataProvider('names')]
    public function testAnEntryNameMustBeAReverseDomainName(string $name, bool $valid): void
    {
        // An a2a binding carries no schema, so only the name is in question.
        $reasons = (new EntryValidator())->validateService($name, self::A2A);

        if ($valid) {
            $this->assertSame([], $reasons);
            return;
        }
        $this->assertStringContainsString('reverse-domain name', implode('; ', $reasons));
    }

    /**
     * The spec's namespace-binding table, plus a prefix that is not label-aligned.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function namespaceBindings(): array
    {
        return [
            'prefix'                 => ['dev.ucp.shopping.checkout', 'https://ucp.dev/c.json', true],
            'subdomain prefix'       => ['dev.ucp.shopping.checkout', 'https://shopping.ucp.dev/c.json', true],
            'vendor prefix'          => ['com.example.payments.installments', 'https://example.com/i.json', true],
            'exact'                  => ['com.example.pay', 'https://pay.example.com/p.json', true],
            'upper-case host'        => ['com.example.pay', 'https://PAY.Example.com/p.json', true],
            'another domain'         => ['com.example.pay', 'https://evil.example/p.json', false],
            'not label-aligned'      => ['com.examplepay.cart', 'https://example.com/c.json', false],
            'deeper than the name'   => ['com.example', 'https://pay.example.com/p.json', false],
        ];
    }

    /**
     * @dataProvider namespaceBindings
     * @param string $name
     * @param string $schema
     * @param bool $valid
     * @return void
     */
    #[DataProvider('namespaceBindings')]
    public function testTheSchemaMustBePublishedUnderTheEntrysNamespace(string $name, string $schema, bool $valid): void
    {
        $reasons = (new EntryValidator())->validateCapability(
            $name,
            ['version' => '2026-08-25', 'schema' => $schema]
        );

        if ($valid) {
            $this->assertSame([], $reasons);
            return;
        }
        $this->assertStringContainsString('namespace', implode('; ', $reasons));
    }

    /**
     * URLs are http or https, in either case, with a host; anything else is refused.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function urls(): array
    {
        return [
            'https'                    => ['https://business.example.com/ucp/v1', true],
            'http, for a dev store'    => ['http://localhost:8380/ucp/v1', true],
            'upper-case scheme'        => ['HTTPS://business.example.com/ucp/v1', true],
            'another scheme, a host'   => ['ftp://business.example.com/ucp/v1', false],
            'no host'                  => ['https:///ucp/v1', false],
            'relative'                 => ['/ucp/v1', false],
        ];
    }

    /**
     * @dataProvider urls
     * @param string $url
     * @param bool $valid
     * @return void
     */
    #[DataProvider('urls')]
    public function testAnEndpointMustBeAnAbsoluteHttpUrl(string $url, bool $valid): void
    {
        $reasons = (new EntryValidator())->validateService('dev.ucp.shopping', ['endpoint' => $url] + self::A2A);

        $this->assertSame($valid ? [] : ['endpoint must be an absolute http(s) URL'], $reasons);
    }

    /**
     * Every rule an entry breaks is named, in full, so one log line says everything to fix.
     *
     * @return void
     */
    public function testEachBrokenRuleIsNamed(): void
    {
        $validator = new EntryValidator();

        $this->assertSame(
            [
                '"Checkout" is not a reverse-domain name',
                'version is required, as a YYYY-MM-DD date',
                'schema is required',
            ],
            $validator->validateCapability('Checkout', [])
        );
        $this->assertSame(
            ['version is required, as a YYYY-MM-DD date', 'transport must be one of rest, mcp, a2a, embedded'],
            $validator->validateService('dev.ucp.shopping', ['transport' => 'grpc'])
        );
        $this->assertSame(
            ['endpoint is required for the mcp transport'],
            $validator->validateService('dev.ucp.shopping', ['version' => '2026-08-25', 'transport' => 'mcp'])
        );
        $this->assertSame(
            ['spec must be an absolute http(s) URL'],
            $validator->validateCapability('dev.ucp.shopping.checkout', ['spec' => 'checkout'] + self::CHECKOUT)
        );
    }

    /**
     * A capability entry the business schema refuses, and the reason it names.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function refusedCapabilities(): array
    {
        $with = static fn (array $changes): array => array_merge(self::CHECKOUT, $changes);
        $without = static function (string $key): array {
            $entry = self::CHECKOUT;
            unset($entry[$key]);
            return $entry;
        };

        return [
            'no version'            => [$without('version'), 'version'],
            'version not a date'    => [$with(['version' => '2026-8-25']), 'version'],
            'version not a string'  => [$with(['version' => 20260825]), 'version'],
            'no schema'             => [$without('schema'), 'schema'],
            'schema not a URL'      => [$with(['schema' => 'checkout.json']), 'schema'],
            'spec not a URL'        => [$with(['spec' => 'javascript:alert(1)']), 'spec'],
            'extends not a name'    => [$with(['extends' => 'Checkout']), 'extends'],
            'extends an empty list' => [$with(['extends' => []]), 'extends'],
            'extends a bad member'  => [$with(['extends' => ['dev.ucp.shopping.cart', 'cart']]), 'extends'],
            'config a list'         => [$with(['config' => ['a', 'b']]), 'config'],
            'config empty'          => [$with(['config' => []]), 'config'],
            'config not an array'   => [$with(['config' => 'on']), 'config'],
            'id not a string'       => [$with(['id' => 5]), 'id'],
        ];
    }

    /**
     * @dataProvider refusedCapabilities
     * @param array<string, mixed> $entry
     * @param string $reason
     * @return void
     */
    #[DataProvider('refusedCapabilities')]
    public function testACapabilityEntryOutsideTheSchemaIsRefused(array $entry, string $reason): void
    {
        $reasons = (new EntryValidator())->validateCapability('dev.ucp.shopping.checkout', $entry);

        $this->assertNotSame([], $reasons);
        $this->assertStringContainsString($reason, implode('; ', $reasons));
    }

    /**
     * A service binding the business schema refuses, and the reason it names.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function refusedServices(): array
    {
        $with = static fn (array $changes): array => array_merge(self::REST, $changes);
        $without = static function (string $key): array {
            $entry = self::REST;
            unset($entry[$key]);
            return $entry;
        };

        return [
            'no version'              => [$without('version'), 'version'],
            'no transport'            => [$without('transport'), 'transport'],
            'unknown transport'       => [$with(['transport' => 'grpc']), 'transport'],
            'rest without endpoint'   => [$without('endpoint'), 'endpoint'],
            'mcp without endpoint'    => [array_diff_key($with(['transport' => 'mcp']), ['endpoint' => 1]), 'endpoint'],
            'a2a without endpoint'    => [array_diff_key(self::A2A, ['endpoint' => 1]), 'endpoint'],
            'endpoint not a URL'      => [$with(['endpoint' => '/ucp/v1']), 'endpoint'],
            'schema outside dev.ucp'  => [$with(['schema' => 'https://example.com/rest.json']), 'namespace'],
            'config a list'           => [$with(['config' => ['a']]), 'config'],
        ];
    }

    /**
     * @dataProvider refusedServices
     * @param array<string, mixed> $entry
     * @param string $reason
     * @return void
     */
    #[DataProvider('refusedServices')]
    public function testAServiceBindingOutsideTheSchemaIsRefused(array $entry, string $reason): void
    {
        $reasons = (new EntryValidator())->validateService('dev.ucp.shopping', $entry);

        $this->assertNotSame([], $reasons);
        $this->assertStringContainsString($reason, implode('; ', $reasons));
    }
}
