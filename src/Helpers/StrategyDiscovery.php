<?php

namespace SimpleTrader\Helpers;

use SimpleTrader\BaseStrategy;
use ReflectionClass;
use ReflectionMethod;

/**
 * Strategy Discovery Helper
 *
 * Discovers all available strategy classes that extend BaseStrategy
 */
class StrategyDiscovery
{
    private static ?array $strategies = null;

    /**
     * Get all available strategy classes
     *
     * @return array Array of class names that extend BaseStrategy
     */
    public static function getAvailableStrategies(): array
    {
        if (self::$strategies !== null) {
            return self::$strategies;
        }

        $strategies = [];
        $srcPath = __DIR__ . '/..';

        // Scan the src directory for PHP files
        $files = glob($srcPath . '/*Strategy.php');

        foreach ($files as $file) {
            $filename = basename($file, '.php');

            // Skip the base strategy itself
            if ($filename === 'BaseStrategy') {
                continue;
            }

            $className = 'SimpleTrader\\' . $filename;

            // Check if class exists and extends BaseStrategy
            if (class_exists($className)) {
                $reflection = new ReflectionClass($className);

                if ($reflection->isSubclassOf(BaseStrategy::class) && !$reflection->isAbstract()) {
                    $strategies[] = $filename;
                }
            }
        }

        // Cache the results
        self::$strategies = $strategies;

        return $strategies;
    }

    /**
     * Get detailed information about a strategy
     *
     * @param string $strategyClass The strategy class name (e.g., 'TestStrategy')
     * @return array|null Strategy information or null if not found
     */
    public static function getStrategyInfo(string $strategyClass): ?array
    {
        $strategies = self::getAvailableStrategies();

        if (!in_array($strategyClass, $strategies)) {
            return null;
        }

        $className = 'SimpleTrader\\' . $strategyClass;
        $reflection = new ReflectionClass($className);

        // Try to get strategy name and description from properties
        $strategyName = null;
        $strategyDescription = null;
        try {
            $instance = $reflection->newInstanceWithoutConstructor();

            // Get strategy name
            $nameProperty = $reflection->getProperty('strategyName');
            $nameProperty->setAccessible(true);
            $strategyName = $nameProperty->getValue($instance);

            // Get strategy description
            try {
                $descProperty = $reflection->getProperty('strategyDescription');
                $descProperty->setAccessible(true);
                $strategyDescription = $descProperty->getValue($instance);
            } catch (\Exception $e) {
                // Description property doesn't exist, use null
                $strategyDescription = null;
            }
        } catch (\Exception $e) {
            // Fall back to class name
            $strategyName = $strategyClass;
        }

        // Get overridden methods
        $overriddenMethods = self::getOverriddenMethods($reflection);

        return [
            'class_name' => $strategyClass,
            'full_class_name' => $className,
            'strategy_name' => $strategyName,
            'strategy_description' => $strategyDescription,
            'overridden_methods' => $overriddenMethods,
            'file_path' => $reflection->getFileName(),
            'doc_comment' => $reflection->getDocComment() ?: null
        ];
    }

    /**
     * Get methods that are overridden from BaseStrategy
     *
     * @param ReflectionClass $reflection Strategy class reflection
     * @return array Array of overridden method names with details
     */
    private static function getOverriddenMethods(ReflectionClass $reflection): array
    {
        $overridden = [];
        $baseStrategyClass = BaseStrategy::class;

        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED);

        foreach ($methods as $method) {
            // Skip constructor and magic methods
            if ($method->isConstructor() || strpos($method->getName(), '__') === 0) {
                continue;
            }

            // Check if this method is declared in BaseStrategy
            if (method_exists($baseStrategyClass, $method->getName())) {
                // Check if it's actually overridden (not just inherited)
                if ($method->getDeclaringClass()->getName() !== $baseStrategyClass) {
                    $overridden[] = [
                        'name' => $method->getName(),
                        'visibility' => $method->isPublic() ? 'public' : ($method->isProtected() ? 'protected' : 'private'),
                        'is_static' => $method->isStatic(),
                        'doc_comment' => $method->getDocComment() ?: null,
                        'parameters' => self::getMethodParameters($method)
                    ];
                }
            }
        }

        return $overridden;
    }

    /**
     * Get method parameters as readable strings
     *
     * @param ReflectionMethod $method
     * @return array
     */
    private static function getMethodParameters(ReflectionMethod $method): array
    {
        $params = [];
        foreach ($method->getParameters() as $param) {
            $paramStr = '';

            // Type hint
            if ($param->hasType()) {
                $type = $param->getType();
                $paramStr .= $type->getName() . ' ';
            }

            $paramStr .= '$' . $param->getName();

            // Default value
            if ($param->isDefaultValueAvailable()) {
                $default = $param->getDefaultValue();
                if (is_bool($default)) {
                    $paramStr .= ' = ' . ($default ? 'true' : 'false');
                } elseif (is_null($default)) {
                    $paramStr .= ' = null';
                } elseif (is_string($default)) {
                    $paramStr .= ' = "' . $default . '"';
                } else {
                    $paramStr .= ' = ' . $default;
                }
            }

            $params[] = $paramStr;
        }
        return $params;
    }

    /**
     * Check if a strategy class exists and is valid
     *
     * @param string $strategyClass The strategy class name
     * @return bool
     */
    public static function isValidStrategy(string $strategyClass): bool
    {
        return in_array($strategyClass, self::getAvailableStrategies());
    }

    /**
     * Get the fully qualified class name for a strategy
     *
     * @param string $strategyClass The strategy class name (e.g., 'TestStrategy')
     * @return string The fully qualified class name
     */
    public static function getStrategyClassName(string $strategyClass): string
    {
        return 'SimpleTrader\\' . $strategyClass;
    }
}
