<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Ucp;

use MageOS\Seo\Model\Ucp\SecurityTxtBuilder;
use MageOS\Seo\Model\Ucp\UcpConfig;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class SecurityTxtBuilderTest extends TestCase
{
    private UcpConfig&Stub $config;
    private SecurityTxtBuilder $builder;

    protected function setUp(): void
    {
        $this->config = $this->createStub(UcpConfig::class);
        $this->builder = new SecurityTxtBuilder($this->config);
    }

    public function testBareEmailIsNormalisedToMailto(): void
    {
        $this->config->method('getSecurityContactEmail')->willReturn('security@shop.test');
        $this->config->method('getSecurityExpires')->willReturn('2027-01-01T00:00:00.000Z');
        $this->config->method('getSecurityPolicyUrl')->willReturn('https://shop.test/security');

        $output = $this->builder->build();

        $this->assertStringContainsString('Contact: mailto:security@shop.test', $output);
        $this->assertStringContainsString('Expires: 2027-01-01T00:00:00.000Z', $output);
        $this->assertStringContainsString('Preferred-Languages: en', $output);
        $this->assertStringContainsString('Policy: https://shop.test/security', $output);
        $this->assertStringEndsWith("\n", $output);
    }

    public function testANewlineInAConfiguredValueCannotForgeADirective(): void
    {
        // Review finding S5: the document is one directive per line (RFC 9116), so a newline in
        // a field an admin controls would otherwise add directives of their choosing.
        $this->config->method('getSecurityContactEmail')
            ->willReturn("security@shop.test\r\nEncryption: https://evil.test/key.asc");
        $this->config->method('getSecurityExpires')
            ->willReturn("2027-01-01T00:00:00.000Z\nContact: mailto:evil@evil.test");
        $this->config->method('getSecurityPolicyUrl')
            ->willReturn("https://shop.test/security\u{2028}Acknowledgments: https://evil.test");

        $output = $this->builder->build();

        $this->assertStringNotContainsString('Encryption:', $output);
        $this->assertStringNotContainsString('evil.test', $output);
        $this->assertStringNotContainsString('Acknowledgments:', $output);
        // One line per directive, every line one we wrote, and each value only its first line.
        $this->assertSame(
            [
                'Contact: mailto:security@shop.test',
                'Expires: 2027-01-01T00:00:00.000Z',
                'Preferred-Languages: en',
                'Policy: https://shop.test/security',
            ],
            explode("\n", trim($output))
        );
    }

    /**
     * The admin stores a date. RFC 9116 asks for an RFC 3339 date-time, and the file stays valid
     * through the day chosen, so it expires at that day's last second, UTC.
     */
    public function testADateExpiresAtTheEndOfThatDayInUtc(): void
    {
        $this->config->method('getSecurityContactEmail')->willReturn('');
        $this->config->method('getSecurityExpires')->willReturn('2027-01-01');
        $this->config->method('getSecurityPolicyUrl')->willReturn('');

        $this->assertStringContainsString("Expires: 2027-01-01T23:59:59Z\n", $this->builder->build());
    }

    /**
     * The admin's date field cannot save anything else, but config:set and env.php can. Writing it
     * as configured keeps the problem visible in the file rather than hiding it.
     */
    public function testAValueThatIsNotADateIsWrittenAsConfigured(): void
    {
        $this->config->method('getSecurityContactEmail')->willReturn('');
        $this->config->method('getSecurityExpires')->willReturn('2027-01-01T00:00:00.000Z');
        $this->config->method('getSecurityPolicyUrl')->willReturn('');

        $this->assertStringContainsString("Expires: 2027-01-01T00:00:00.000Z\n", $this->builder->build());
    }

    public function testADateWithTextAroundItIsWrittenAsConfigured(): void
    {
        $this->config->method('getSecurityContactEmail')->willReturn('');
        $this->config->method('getSecurityExpires')->willReturn('until 2027-01-01');
        $this->config->method('getSecurityPolicyUrl')->willReturn('');

        $this->assertStringContainsString("Expires: until 2027-01-01\n", $this->builder->build());
    }

    public function testAlreadyQualifiedContactUriIsKept(): void
    {
        $this->config->method('getSecurityContactEmail')->willReturn('https://shop.test/contact');
        $this->config->method('getSecurityExpires')->willReturn('');
        $this->config->method('getSecurityPolicyUrl')->willReturn('');

        $output = $this->builder->build();

        $this->assertStringContainsString('Contact: https://shop.test/contact', $output);
        $this->assertStringNotContainsString('mailto:', $output);
    }

    public function testOptionalFieldsOmittedWhenBlank(): void
    {
        $this->config->method('getSecurityContactEmail')->willReturn('');
        $this->config->method('getSecurityExpires')->willReturn('');
        $this->config->method('getSecurityPolicyUrl')->willReturn('');

        $output = $this->builder->build();

        $this->assertStringNotContainsString('Contact:', $output);
        $this->assertStringNotContainsString('Expires:', $output);
        $this->assertStringNotContainsString('Policy:', $output);
        // Preferred-Languages is always present.
        $this->assertStringContainsString('Preferred-Languages: en', $output);
    }
}
