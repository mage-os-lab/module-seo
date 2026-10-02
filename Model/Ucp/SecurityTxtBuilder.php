<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Ucp;

/**
 * Builds the /.well-known/security.txt document (RFC 9116).
 *
 * Emits Contact, Expires, Preferred-Languages and (optionally) Policy fields from config.
 */
class SecurityTxtBuilder
{
    /**
     * @param UcpConfig $config
     */
    public function __construct(
        private readonly UcpConfig $config
    ) {
    }

    /**
     * Build the security.txt body.
     *
     * @return string
     */
    public function build(): string
    {
        $lines = [];

        $contact = $this->singleLine($this->config->getSecurityContactEmail());
        if ($contact !== '') {
            // A bare email is normalised to a mailto: URI; an already-qualified URI is kept as-is.
            $lines[] = 'Contact: ' . (preg_match('/^[a-z][a-z0-9+.-]*:/i', $contact) ? $contact : 'mailto:' . $contact);
        }

        $expires = $this->singleLine($this->config->getSecurityExpires());
        if ($expires !== '') {
            $lines[] = 'Expires: ' . $this->expiresAt($expires);
        }

        $lines[] = 'Preferred-Languages: en';

        $policy = $this->singleLine($this->config->getSecurityPolicyUrl());
        if ($policy !== '') {
            $lines[] = 'Policy: ' . $policy;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The first line of a configured value, so one field cannot become several.
     *
     * Each of these values is written as `Field: <value>` into a document whose format is one
     * directive per line (RFC 9116). A line break in a configured value would otherwise forge
     * further directives — an `Encryption:` or `Contact:` of someone else's choosing — from a
     * field that only looks like free text.
     *
     * Everything after the first break is dropped rather than joined onto the value: these
     * fields hold a single address, so the remainder is not content that belongs in the output.
     * Vertical tab, form feed and the Unicode line separators count as breaks too.
     *
     * @param string $value
     * @return string
     */
    private function singleLine(string $value): string
    {
        $lines = preg_split('/[\r\n\x0B\x0C\x{0085}\x{2028}\x{2029}]/u', $value, 2);

        return trim($lines[0] ?? '');
    }

    /**
     * The Expires value: a configured date as the last second of that day, UTC.
     *
     * RFC 9116 asks for an RFC 3339 date-time, and the file is meant to stay valid through the day
     * chosen. The admin's date field can save nothing but a date; a value set any other way
     * (config:set, env.php) is written as configured, so a problem shows in the file rather than
     * being hidden.
     *
     * @param string $value
     * @return string
     */
    private function expiresAt(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value . 'T23:59:59Z' : $value;
    }
}
