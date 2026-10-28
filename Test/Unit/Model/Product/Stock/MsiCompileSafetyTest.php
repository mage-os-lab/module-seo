<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\Stock;

use PHPUnit\Framework\TestCase;

/**
 * MSI is optional (composer `suggest`): with its packages removed, `setup:di:compile` must still
 * compile this module.
 *
 * Compile reflects the class of every constructor parameter of every class of an enabled module,
 * loads each class's parents and interfaces, and resolves the classes di.xml names; a missing MSI
 * class in any of them fails it. So MSI's services are reached only from method bodies, as
 * Model\Product\Stock\MsiStockReader does, and no di.xml names them.
 *
 * Nothing else catches a regression here: where MSI is installed, as on the rig and in CI, its
 * classes exist and everything compiles.
 */
class MsiCompileSafetyTest extends TestCase
{
    /**
     * MSI's namespaces: Magento\Inventory, Magento\InventoryApi, Magento\InventorySalesApi, …
     */
    private const MSI = '/^\\\\?Magento\\\\Inventory/';

    /**
     * Top-level directories with no classes compile reads.
     */
    private const NOT_SCANNED = ['Test', 'docs', 'etc', 'i18n', 'view', 'vendor'];

    public function testNoClassNamesAnMsiTypeInItsConstructorParentsOrInterfaces(): void
    {
        $found = [];
        foreach ($this->classes() as $class) {
            $reflection = new \ReflectionClass($class);
            $hierarchy  = $reflection->getInterfaceNames();
            for ($parent = $reflection->getParentClass(); $parent; $parent = $parent->getParentClass()) {
                $hierarchy[] = $parent->getName();
            }
            foreach ($hierarchy as $type) {
                if (preg_match(self::MSI, $type)) {
                    $found[] = \sprintf('%s extends or implements %s', $class, $type);
                }
            }

            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                foreach ($this->typeNames($parameter->getType()) as $type) {
                    if (preg_match(self::MSI, $type)) {
                        $found[] = \sprintf('%s::__construct() takes $%s as %s', $class, $parameter->getName(), $type);
                    }
                }
            }
        }

        $this->assertSame([], $found);
    }

    public function testNoDiXmlNamesAnMsiType(): void
    {
        $found = [];
        foreach ($this->diXmlFiles() as $file) {
            $dom = new \DOMDocument();
            $this->assertTrue($dom->load($file), $file);
            // Attributes and text: preferences, types, plugins, virtual types and arguments. Not
            // comments.
            foreach ((new \DOMXPath($dom))->query('//@* | //text()') ?: [] as $node) {
                $value = trim((string) $node->nodeValue);
                if (preg_match(self::MSI, $value)) {
                    $found[] = \sprintf('%s: %s', substr($file, \strlen($this->root()) + 1), $value);
                }
            }
        }

        $this->assertSame([], $found);
    }

    /**
     * Whether the scan below sees this module's classes at all, so the tests above can't pass on
     * nothing.
     */
    public function testTheScanFindsTheModulesClasses(): void
    {
        $classes = iterator_to_array($this->classes(), false);

        $this->assertContains(\MageOS\Seo\Model\Product\Stock\MsiStockReader::class, $classes);
        $this->assertContains(\MageOS\Seo\Model\Product\AvailabilityResolver::class, $classes);
        $this->assertNotEmpty($this->diXmlFiles());
    }

    /**
     * Every class, interface and trait of this module outside the directories compile does not read.
     *
     * @return \Generator<int, class-string>
     */
    private function classes(): \Generator
    {
        $root = $this->root();
        foreach (new \DirectoryIterator($root) as $directory) {
            if ($directory->isDot() || !$directory->isDir() || str_starts_with($directory->getFilename(), '.')
                || \in_array($directory->getFilename(), self::NOT_SCANNED, true)
            ) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory->getPathname()));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $class = 'MageOS\\Seo\\'
                    . str_replace('/', '\\', substr($file->getPathname(), \strlen($root) + 1, -4));
                if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
                    $this->fail(\sprintf('%s declares no %s', $file->getPathname(), $class));
                }
                yield $class;
            }
        }
    }

    /**
     * @return string[]
     */
    private function diXmlFiles(): array
    {
        $root = $this->root();

        return array_merge(glob($root . '/etc/di.xml') ?: [], glob($root . '/etc/*/di.xml') ?: []);
    }

    /**
     * The class names a parameter type names: one, or each of a union or intersection.
     *
     * @param \ReflectionType|null $type
     * @return string[]
     */
    private function typeNames(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            return $type->isBuiltin() ? [] : [$type->getName()];
        }
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            return array_merge(...array_map($this->typeNames(...), $type->getTypes()));
        }

        return [];
    }

    /**
     * @return string The module's root directory
     */
    private function root(): string
    {
        return \dirname(__DIR__, 5);
    }
}
