<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Migration;

/**
 * Turns MageOS_MetaRobotsTag's three flags into the directive this module stores.
 *
 * That module never stored a directive. It stored booleans and flipped tokens in whatever the
 * store's default was at render time, so a page flagged only `no_archive` kept whatever index and
 * follow the default gave it. Reproducing the directive the visitor was served therefore means
 * resolving the flags **against that default**, not assuming INDEX,FOLLOW.
 */
class FlagsToDirective
{
    /**
     * The flags, in the order they appear in a directive.
     */
    public const FLAGS = ['no_index', 'no_follow', 'no_archive'];

    /**
     * The directive a set of flags resolves to, or null when none of them is set.
     *
     * Null matters: an entity with no flags was never overridden, and writing a directive for it
     * would freeze today's store default into a per-entity setting that stops following the store.
     *
     * @param mixed[] $data The entity's data, holding any of the flags
     * @param string $storeDefault The store's configured default robots directive
     * @return string|null
     */
    public function convert(array $data, string $storeDefault): ?string
    {
        $flagged = array_values(array_filter(
            self::FLAGS,
            static fn (string $flag): bool => !empty($data[$flag])
        ));

        if ($flagged === []) {
            return null;
        }

        $directives = [
            $this->resolve($flagged, 'no_index', 'INDEX', 'NOINDEX', $storeDefault),
            $this->resolve($flagged, 'no_follow', 'FOLLOW', 'NOFOLLOW', $storeDefault),
        ];

        if (\in_array('no_archive', $flagged, true)) {
            $directives[] = 'noarchive';
        }

        return implode(',', $directives);
    }

    /**
     * One token: the restriction when its flag is set, otherwise whatever the default already had.
     *
     * @param string[] $flagged
     * @param string $flag
     * @param string $permissive
     * @param string $restrictive
     * @param string $storeDefault
     * @return string
     */
    private function resolve(
        array $flagged,
        string $flag,
        string $permissive,
        string $restrictive,
        string $storeDefault
    ): string {
        if (\in_array($flag, $flagged, true)) {
            return $restrictive;
        }

        $tokens = array_map('strtoupper', array_map('trim', explode(',', $storeDefault)));

        return \in_array($restrictive, $tokens, true) ? $restrictive : $permissive;
    }
}
