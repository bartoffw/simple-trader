<?php

namespace SimpleTrader\Helpers;

use SimpleTrader\Loaders\SourceInterface;

/**
 * Source Discovery Helper
 *
 * Discovers all available data source classes that implement SourceInterface
 */
class SourceDiscovery
{
    private static ?array $sources = null;

    /**
     * Get all available source classes
     *
     * @return array Array of class names that implement SourceInterface
     */
    public static function getAvailableSources(): array
    {
        if (self::$sources !== null) {
            return self::$sources;
        }

        $sources = [];
        $loadersPath = __DIR__ . '/../Loaders';

        // Scan the Loaders directory for PHP files
        $files = glob($loadersPath . '/*.php');

        foreach ($files as $file) {
            $filename = basename($file, '.php');

            // Skip interfaces and base classes
            if (in_array($filename, ['SourceInterface', 'LoaderInterface', 'BaseLoader'])) {
                continue;
            }

            $className = 'SimpleTrader\\Loaders\\' . $filename;

            // Check if class exists and implements SourceInterface
            if (class_exists($className)) {
                $reflection = new \ReflectionClass($className);

                if ($reflection->implementsInterface(SourceInterface::class) && !$reflection->isAbstract()) {
                    $sources[] = $filename;
                }
            }
        }

        // Cache the results
        self::$sources = $sources;

        return $sources;
    }

    /**
     * Get sources as options for dropdown (value => label)
     *
     * @return array
     */
    public static function getSourceOptions(): array
    {
        $sources = self::getAvailableSources();
        $options = [];

        foreach ($sources as $source) {
            // Convert class name to friendly label
            // TradingViewSource -> Trading View Source
            $label = preg_replace('/(?<!^)([A-Z])/', ' $1', str_replace('Source', '', $source));
            $label = trim($label) . ' Source';

            $options[$source] = $label;
        }

        return $options;
    }

    /**
     * Check if a source class exists and is valid
     *
     * @param string $sourceClass The source class name
     * @return bool
     */
    public static function isValidSource(string $sourceClass): bool
    {
        return in_array($sourceClass, self::getAvailableSources());
    }

    /**
     * Get the fully qualified class name for a source
     *
     * @param string $sourceClass The source class name (e.g., 'TradingViewSource')
     * @return string The fully qualified class name
     */
    public static function getSourceClassName(string $sourceClass): string
    {
        return 'SimpleTrader\\Loaders\\' . $sourceClass;
    }

    /**
     * Create an instance of a source class
     *
     * @param string $sourceClass The source class name
     * @return SourceInterface
     * @throws \InvalidArgumentException If source is not valid
     */
    public static function createSourceInstance(string $sourceClass): SourceInterface
    {
        if (!self::isValidSource($sourceClass)) {
            throw new \InvalidArgumentException("Invalid source class: {$sourceClass}");
        }

        $className = self::getSourceClassName($sourceClass);

        return new $className();
    }
}
