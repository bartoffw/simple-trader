<?php

namespace SimpleTrader\Helpers;

use ReflectionClass;
use ReflectionMethod;

/**
 * Strategy Code Parser
 *
 * Parses and generates PHP code for strategy classes
 */
class StrategyCodeParser
{
    private string $strategyDir;

    public function __construct()
    {
        $this->strategyDir = dirname(__DIR__);
    }

    /**
     * Get the strategy directory path
     */
    public function getStrategyDirectory(): string
    {
        return $this->strategyDir;
    }

    /**
     * Check if the strategy directory is writable
     */
    public function isWritable(): bool
    {
        return is_writable($this->strategyDir);
    }

    /**
     * Check if a specific strategy file is writable
     */
    public function isStrategyFileWritable(string $className): bool
    {
        $filePath = $this->strategyDir . '/' . $className . '.php';
        if (!file_exists($filePath)) {
            return $this->isWritable();
        }
        return is_writable($filePath);
    }

    /**
     * Get the file path for a strategy class
     */
    public function getStrategyFilePath(string $className): string
    {
        return $this->strategyDir . '/' . $className . '.php';
    }

    /**
     * Parse a strategy file to extract editable components
     */
    public function parseStrategy(string $className): ?array
    {
        $filePath = $this->getStrategyFilePath($className);
        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        $fullClassName = 'SimpleTrader\\' . $className;

        if (!class_exists($fullClassName)) {
            require_once $filePath;
        }

        if (!class_exists($fullClassName)) {
            return null;
        }

        $reflection = new ReflectionClass($fullClassName);

        // Ensure it extends BaseStrategy
        if ($reflection->getParentClass()->getName() !== 'SimpleTrader\\BaseStrategy') {
            return null;
        }

        // Extract strategy name
        $strategyNameProp = $reflection->getProperty('strategyName');
        $strategyNameProp->setAccessible(true);
        $strategyName = $strategyNameProp->getValue($reflection->newInstanceWithoutConstructor());

        // Extract strategy description
        $strategyDescProp = $reflection->getProperty('strategyDescription');
        $strategyDescProp->setAccessible(true);
        $strategyDescription = $strategyDescProp->getValue($reflection->newInstanceWithoutConstructor());

        // Extract strategy parameters
        $strategyParamsProp = $reflection->getProperty('strategyParameters');
        $strategyParamsProp->setAccessible(true);
        $strategyParameters = $strategyParamsProp->getValue($reflection->newInstanceWithoutConstructor());

        // Extract custom properties
        $customProperties = $this->extractCustomProperties($reflection, $content);

        // Extract overridden methods with their bodies
        $methods = $this->extractMethodBodies($reflection, $content);

        return [
            'class_name' => $className,
            'file_path' => $filePath,
            'strategy_name' => $strategyName,
            'strategy_description' => $strategyDescription,
            'strategy_parameters' => $strategyParameters,
            'custom_properties' => $customProperties,
            'methods' => $methods,
            'is_writable' => $this->isStrategyFileWritable($className)
        ];
    }

    /**
     * Extract custom properties (private/protected variables)
     */
    private function extractCustomProperties(ReflectionClass $reflection, string $content): array
    {
        $customProps = [];
        $baseReflection = new ReflectionClass('SimpleTrader\\BaseStrategy');
        $baseProperties = array_map(fn($p) => $p->getName(), $baseReflection->getProperties());

        // Get properties defined in the strategy class (not parent)
        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() === $reflection->getName()) {
                $propName = $property->getName();
                // Skip if it's a known base property override
                if (in_array($propName, ['strategyName', 'strategyDescription', 'strategyParameters'])) {
                    continue;
                }

                // Extract the property definition from source
                $pattern = '/^\s*(protected|private)\s+(?:(?:string|int|float|bool|array|mixed|null|\?[a-zA-Z]+)\|?)+\s+\$' . preg_quote($propName, '/') . '\s*=\s*([^;]+);/m';
                if (preg_match($pattern, $content, $matches)) {
                    $customProps[$propName] = [
                        'visibility' => $matches[1],
                        'type' => $this->extractPropertyType($content, $propName),
                        'default' => trim($matches[2])
                    ];
                } else {
                    // Try without default value
                    $pattern = '/^\s*(protected|private)\s+((?:(?:string|int|float|bool|array|mixed|null|\?[a-zA-Z]+)\|?)+)\s+\$' . preg_quote($propName, '/') . '\s*;/m';
                    if (preg_match($pattern, $content, $matches)) {
                        $customProps[$propName] = [
                            'visibility' => $matches[1],
                            'type' => $matches[2],
                            'default' => 'null'
                        ];
                    }
                }
            }
        }

        return $customProps;
    }

    /**
     * Extract property type from source code
     */
    private function extractPropertyType(string $content, string $propName): string
    {
        $pattern = '/^\s*(?:protected|private)\s+((?:(?:string|int|float|bool|array|mixed|null|\?[a-zA-Z]+)\|?)+)\s+\$' . preg_quote($propName, '/') . '/m';
        if (preg_match($pattern, $content, $matches)) {
            return $matches[1];
        }
        return 'mixed';
    }

    /**
     * Extract method bodies for overridden methods
     */
    private function extractMethodBodies(ReflectionClass $reflection, string $content): array
    {
        $methods = [];
        $baseReflection = new ReflectionClass('SimpleTrader\\BaseStrategy');

        // List of methods we allow editing
        $allowedMethods = [
            'getMaxLookbackPeriod',
            'onOpen',
            'onClose',
            'onStrategyEnd'
        ];

        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === $reflection->getName() &&
                in_array($method->getName(), $allowedMethods)) {

                $methodName = $method->getName();

                // Get method signature from BaseStrategy
                $baseMethod = $baseReflection->getMethod($methodName);
                $signature = $this->getMethodSignature($baseMethod);

                // Extract method body from source
                $body = $this->extractMethodBody($content, $methodName);

                $methods[$methodName] = [
                    'signature' => $signature,
                    'body' => $body,
                    'parameters' => $this->getMethodParameters($baseMethod)
                ];
            }
        }

        return $methods;
    }

    /**
     * Get method signature string
     */
    private function getMethodSignature(ReflectionMethod $method): string
    {
        $params = [];
        foreach ($method->getParameters() as $param) {
            $paramStr = '';
            if ($param->hasType()) {
                $typeName = $param->getType()->getName();
                // Use short class name for SimpleTrader namespace
                $typeName = $this->getShortClassName($typeName);
                $paramStr .= $typeName . ' ';
            }
            $paramStr .= '$' . $param->getName();
            if ($param->isDefaultValueAvailable()) {
                $default = $param->getDefaultValue();
                if (is_bool($default)) {
                    $paramStr .= ' = ' . ($default ? 'true' : 'false');
                } elseif (is_string($default)) {
                    $paramStr .= " = '{$default}'";
                } elseif (is_null($default)) {
                    $paramStr .= ' = null';
                } else {
                    $paramStr .= ' = ' . $default;
                }
            }
            $params[] = $paramStr;
        }

        $returnType = '';
        if ($method->hasReturnType()) {
            $returnType = ': ' . $method->getReturnType()->getName();
        }

        $visibility = $method->isPublic() ? 'public' : ($method->isProtected() ? 'protected' : 'private');

        return "{$visibility} function {$method->getName()}(" . implode(', ', $params) . "){$returnType}";
    }

    /**
     * Get short class name, removing namespace prefixes
     */
    private function getShortClassName(string $fullName): string
    {
        // Handle known namespaces
        $prefixes = [
            'SimpleTrader\\' => '',
            'Carbon\\' => ''
        ];

        foreach ($prefixes as $prefix => $replacement) {
            if (str_starts_with($fullName, $prefix)) {
                return substr($fullName, strlen($prefix));
            }
        }

        return $fullName;
    }

    /**
     * Get method parameter names
     */
    private function getMethodParameters(ReflectionMethod $method): array
    {
        $params = [];
        foreach ($method->getParameters() as $param) {
            $params[] = [
                'name' => $param->getName(),
                'type' => $param->hasType() ? $param->getType()->getName() : 'mixed'
            ];
        }
        return $params;
    }

    /**
     * Extract method body from source code
     */
    private function extractMethodBody(string $content, string $methodName): string
    {
        // Match the method and its body
        $pattern = '/public\s+function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)(?:\s*:\s*[a-zA-Z]+)?\s*\{/s';

        if (!preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $startPos = $matches[0][1] + strlen($matches[0][0]);
        $braceCount = 1;
        $bodyStart = $startPos;
        $bodyEnd = $startPos;

        // Find matching closing brace
        for ($i = $startPos; $i < strlen($content) && $braceCount > 0; $i++) {
            if ($content[$i] === '{') {
                $braceCount++;
            } elseif ($content[$i] === '}') {
                $braceCount--;
            }
            if ($braceCount === 0) {
                $bodyEnd = $i;
            }
        }

        $body = substr($content, $bodyStart, $bodyEnd - $bodyStart);

        // Remove leading/trailing whitespace but preserve indentation structure
        $lines = explode("\n", $body);
        $trimmedLines = [];

        foreach ($lines as $line) {
            // Remove common indentation (8 spaces for method body)
            if (preg_match('/^        (.*)$/', $line, $m)) {
                $trimmedLines[] = $m[1];
            } else {
                $trimmedLines[] = $line;
            }
        }

        // Remove empty first/last lines
        while (!empty($trimmedLines) && trim($trimmedLines[0]) === '') {
            array_shift($trimmedLines);
        }
        while (!empty($trimmedLines) && trim($trimmedLines[count($trimmedLines) - 1]) === '') {
            array_pop($trimmedLines);
        }

        return implode("\n", $trimmedLines);
    }

    /**
     * Generate PHP code for a strategy class
     */
    public function generateStrategyCode(array $data): string
    {
        $className = $data['class_name'];
        $strategyName = $data['strategy_name'];
        $strategyDescription = $data['strategy_description'];
        $parameters = $data['strategy_parameters'] ?? [];
        $customProperties = $data['custom_properties'] ?? '';
        $methods = $data['methods'] ?? [];

        // Build use statements
        $useStatements = [
            'use Carbon\\Carbon;',
            'use SimpleTrader\\Exceptions\\StrategyException;'
        ];

        // Check if Side or Position are used in methods
        $allMethodBodies = implode("\n", array_column($methods, 'body'));
        if (strpos($allMethodBodies, 'Side::') !== false) {
            $useStatements[] = 'use SimpleTrader\\Helpers\\Side;';
        }
        if (strpos($allMethodBodies, 'Position') !== false) {
            $useStatements[] = 'use SimpleTrader\\Helpers\\Position;';
        }

        sort($useStatements);
        $useBlock = implode("\n", $useStatements);

        // Build parameters array
        $paramsCode = '';
        if (!empty($parameters)) {
            $paramLines = [];
            foreach ($parameters as $name => $value) {
                if (is_string($value)) {
                    $paramLines[] = "        '{$name}' => '{$value}'";
                } elseif (is_bool($value)) {
                    $paramLines[] = "        '{$name}' => " . ($value ? 'true' : 'false');
                } elseif (is_array($value)) {
                    $paramLines[] = "        '{$name}' => " . $this->arrayToPhpCode($value);
                } else {
                    $paramLines[] = "        '{$name}' => {$value}";
                }
            }
            $paramsCode = "\n" . implode(",\n", $paramLines) . "\n    ";
        }

        // Build custom properties section
        $customPropsCode = '';
        if (!empty($customProperties)) {
            if (is_array($customProperties)) {
                // Convert array format back to PHP code
                $propLines = [];
                foreach ($customProperties as $name => $prop) {
                    $propLines[] = "    {$prop['visibility']} {$prop['type']} \${$name} = {$prop['default']};";
                }
                $customPropsCode = "\n\n" . implode("\n", $propLines);
            } else {
                // String format from form submission
                $customPropsCode = "\n\n" . rtrim($customProperties);
            }
        }

        // Build methods
        $methodsCode = '';
        foreach ($methods as $methodName => $methodData) {
            $signature = $methodData['signature'];
            $body = $methodData['body'];

            // Indent body properly
            $bodyLines = explode("\n", $body);
            $indentedBody = array_map(fn($line) => "        " . $line, $bodyLines);
            $bodyCode = implode("\n", $indentedBody);

            $methodsCode .= "\n\n    {$signature}\n    {\n{$bodyCode}\n    }";
        }

        // Escape strings for PHP
        $escapedName = addslashes($strategyName);
        $escapedDesc = addslashes($strategyDescription);

        return <<<PHP
<?php

namespace SimpleTrader;

{$useBlock}

class {$className} extends BaseStrategy
{
    protected string \$strategyName = '{$escapedName}';
    protected string \$strategyDescription = '{$escapedDesc}';

    protected array \$strategyParameters = [{$paramsCode}];{$customPropsCode}{$methodsCode}
}

PHP;
    }

    /**
     * Convert array to PHP code representation
     */
    private function arrayToPhpCode(array $arr): string
    {
        $items = [];
        $isAssoc = array_keys($arr) !== range(0, count($arr) - 1);

        foreach ($arr as $key => $value) {
            $itemCode = '';
            if ($isAssoc) {
                $itemCode = is_string($key) ? "'{$key}' => " : "{$key} => ";
            }

            if (is_string($value)) {
                $itemCode .= "'{$value}'";
            } elseif (is_bool($value)) {
                $itemCode .= $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $itemCode .= $this->arrayToPhpCode($value);
            } else {
                $itemCode .= $value;
            }

            $items[] = $itemCode;
        }

        return '[' . implode(', ', $items) . ']';
    }

    /**
     * Save strategy code to file
     */
    public function saveStrategy(string $className, string $code): bool
    {
        $filePath = $this->getStrategyFilePath($className);

        // Validate PHP syntax before saving
        $tempFile = tempnam(sys_get_temp_dir(), 'strategy_');
        file_put_contents($tempFile, $code);
        exec("php -l {$tempFile} 2>&1", $output, $returnCode);
        unlink($tempFile);

        if ($returnCode !== 0) {
            return false;
        }

        return file_put_contents($filePath, $code) !== false;
    }

    /**
     * Rename strategy (both class and file)
     */
    public function renameStrategy(string $oldClassName, string $newClassName): bool
    {
        if (!$this->isValidClassName($newClassName)) {
            return false;
        }

        $oldPath = $this->getStrategyFilePath($oldClassName);
        $newPath = $this->getStrategyFilePath($newClassName);

        if (!file_exists($oldPath) || file_exists($newPath)) {
            return false;
        }

        // Parse existing strategy
        $strategy = $this->parseStrategy($oldClassName);
        if (!$strategy) {
            return false;
        }

        // Update class name
        $strategy['class_name'] = $newClassName;

        // Generate new code
        $newCode = $this->generateStrategyCode($strategy);

        // Write to new file
        if (!$this->saveStrategy($newClassName, $newCode)) {
            return false;
        }

        // Remove old file
        unlink($oldPath);

        return true;
    }

    /**
     * Delete a strategy file
     */
    public function deleteStrategy(string $className): bool
    {
        $filePath = $this->getStrategyFilePath($className);

        if (!file_exists($filePath)) {
            return false;
        }

        return unlink($filePath);
    }

    /**
     * Check if class name is valid PHP identifier
     */
    public function isValidClassName(string $name): bool
    {
        // PHP class name must start with letter or underscore, followed by letters, numbers, or underscores
        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) === 1;
    }

    /**
     * Check if strategy class already exists
     */
    public function strategyExists(string $className): bool
    {
        return file_exists($this->getStrategyFilePath($className));
    }

    /**
     * Get BaseStrategy methods that can be overridden
     */
    public function getOverridableMethods(): array
    {
        $baseReflection = new ReflectionClass('SimpleTrader\\BaseStrategy');

        $methods = [];
        $allowedMethods = [
            'getMaxLookbackPeriod',
            'onOpen',
            'onClose',
            'onStrategyEnd'
        ];

        foreach ($allowedMethods as $methodName) {
            $method = $baseReflection->getMethod($methodName);
            $methods[$methodName] = [
                'signature' => $this->getMethodSignature($method),
                'parameters' => $this->getMethodParameters($method),
                'default_body' => $this->getDefaultMethodBody($methodName)
            ];
        }

        return $methods;
    }

    /**
     * Get default body for a method
     */
    private function getDefaultMethodBody(string $methodName): string
    {
        return match ($methodName) {
            'getMaxLookbackPeriod' => "// Return the number of bars needed for calculations\nreturn 0;",
            'onOpen' => "parent::onOpen(\$assets, \$dateTime, \$isLive);\n\n// Add your open event logic here",
            'onClose' => "parent::onClose(\$assets, \$dateTime, \$isLive);\n\n// Add your close event logic here",
            'onStrategyEnd' => "parent::onStrategyEnd(\$assets, \$dateTime, \$isLive);\n\$this->closeAll('Strategy end');",
            default => ''
        };
    }
}
