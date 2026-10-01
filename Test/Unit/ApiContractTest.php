<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every interface under Api/ is a contract other modules implement or call, so each is marked
 * `@api`: Magento's backward-compatibility promise applies to it, and to nothing else in the module.
 *
 * Guards against a new interface landing in Api/ without the mark.
 */
class ApiContractTest extends TestCase
{
    public function testEveryInterfaceUnderApiIsMarkedApi(): void
    {
        $root    = \dirname(__DIR__, 2) . '/Api';
        $missing = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), \strlen($root) + 1, -4);
            $class    = 'MageOS\\Seo\\Api\\' . str_replace('/', '\\', $relative);

            if (!interface_exists($class)) {
                $missing[] = $class;
                continue;
            }
            $docComment = (string) (new \ReflectionClass($class))->getDocComment();
            if (preg_match('/^\s*\*\s*@api\b/m', $docComment) !== 1) {
                $missing[] = $class;
            }
        }
        sort($missing);

        $this->assertSame([], $missing, 'Every file under Api/ must be an interface marked @api.');
    }
}
