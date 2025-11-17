<?php

namespace SimpleTrader\Controllers;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SimpleTrader\Database\BacktestRepository;
use Slim\Views\Twig;
use SimpleTrader\Helpers\StrategyDiscovery;
use SimpleTrader\Helpers\StrategyCodeParser;

/**
 * Strategy Editor Controller
 *
 * Handles editing, creation, and management of strategy PHP files
 */
class StrategyEditorController
{
    private ContainerInterface $container;
    private Twig $view;
    private $flash;
    private StrategyCodeParser $codeParser;
    private BacktestRepository $backtestRepository;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->view = $container->get('view');
        $this->flash = $container->get('flash');
        $this->codeParser = new StrategyCodeParser();
        $this->backtestRepository = $container->get('backtestRepository');
    }

    /**
     * Display list of all strategies available for editing
     *
     * GET /strategies/editor
     */
    public function index(Request $request, Response $response): Response
    {
        // Check write permissions
        $isWritable = $this->codeParser->isWritable();

        // Get all available strategies
        $strategyClasses = StrategyDiscovery::getAvailableStrategies();

        $strategies = [];
        foreach ($strategyClasses as $strategyClass) {
            $info = StrategyDiscovery::getStrategyInfo($strategyClass);
            if ($info) {
                $className = $info['class_name'];
                $strategies[] = [
                    'class_name' => $className,
                    'strategy_name' => $info['strategy_name'],
                    'strategy_description' => $info['strategy_description'] ?? '',
                    'overridden_count' => count($info['overridden_methods']),
                    'is_writable' => $this->codeParser->isStrategyFileWritable($className),
                    'file_path' => $info['file_path']
                ];
            }
        }

        return $this->view->render($response, 'strategies/editor/index.twig', [
            'strategies' => $strategies,
            'strategy_dir' => $this->codeParser->getStrategyDirectory(),
            'is_writable' => $isWritable,
            'flash' => $this->flash->all()
        ]);
    }

    /**
     * Show form to edit a strategy
     *
     * GET /strategies/editor/{className}/edit
     */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $className = $args['className'];

        // Parse the strategy
        $strategy = $this->codeParser->parseStrategy($className);

        if (!$strategy) {
            $this->flash->setFlash('error', "Strategy '{$className}' not found or cannot be parsed.");
            return $response
                ->withHeader('Location', '/strategies/editor')
                ->withStatus(302);
        }

        if (!$strategy['is_writable']) {
            $this->flash->setFlash('warning', "Strategy file is not writable. You can view but not save changes.");
        }

        // Get overridable methods from BaseStrategy
        $overridableMethods = $this->codeParser->getOverridableMethods();

        // Merge with existing methods (add missing ones with default body)
        $methodsToDisplay = [];
        foreach ($overridableMethods as $methodName => $methodInfo) {
            if (isset($strategy['methods'][$methodName])) {
                $methodsToDisplay[$methodName] = $strategy['methods'][$methodName];
            } else {
                $methodsToDisplay[$methodName] = [
                    'signature' => $methodInfo['signature'],
                    'body' => '',
                    'parameters' => $methodInfo['parameters']
                ];
            }
        }

        return $this->view->render($response, 'strategies/editor/edit.twig', [
            'strategy' => $strategy,
            'methods' => $methodsToDisplay,
            'overridable_methods' => $overridableMethods,
            'flash' => $this->flash->all()
        ]);
    }

    /**
     * Update strategy code
     *
     * POST /strategies/editor/{className}
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $className = $args['className'];
        $data = $request->getParsedBody();

        // Validate permissions
        if (!$this->codeParser->isStrategyFileWritable($className)) {
            $this->flash->setFlash('error', 'Strategy file is not writable.');
            return $response
                ->withHeader('Location', "/strategies/editor/{$className}/edit")
                ->withStatus(302);
        }

        // Parse current strategy to preserve structure
        $existingStrategy = $this->codeParser->parseStrategy($className);
        if (!$existingStrategy) {
            $this->flash->setFlash('error', 'Could not parse existing strategy.');
            return $response
                ->withHeader('Location', '/strategies/editor')
                ->withStatus(302);
        }

        // Update strategy data
        $strategyData = [
            'class_name' => $className,
            'strategy_name' => $data['strategy_name'] ?? $existingStrategy['strategy_name'],
            'strategy_description' => $data['strategy_description'] ?? $existingStrategy['strategy_description'],
            'strategy_parameters' => [],
            'custom_properties' => $data['custom_properties'] ?? '',
            'methods' => []
        ];

        // Parse strategy parameters
        if (isset($data['param_names']) && is_array($data['param_names'])) {
            for ($i = 0; $i < count($data['param_names']); $i++) {
                $paramName = trim($data['param_names'][$i] ?? '');
                $paramValue = $data['param_values'][$i] ?? '';

                if (!empty($paramName)) {
                    $strategyData['strategy_parameters'][$paramName] = $this->parseParameterValue($paramValue);
                }
            }
        }

        // Parse methods
        $overridableMethods = $this->codeParser->getOverridableMethods();
        foreach ($overridableMethods as $methodName => $methodInfo) {
            $bodyKey = "method_{$methodName}";
            if (isset($data[$bodyKey]) && !empty(trim($data[$bodyKey]))) {
                $strategyData['methods'][$methodName] = [
                    'signature' => $methodInfo['signature'],
                    'body' => $data[$bodyKey],
                    'parameters' => $methodInfo['parameters']
                ];
            }
        }

        // Generate and save code
        $code = $this->codeParser->generateStrategyCode($strategyData);

        if (!$this->codeParser->saveStrategy($className, $code)) {
            $this->flash->setFlash('error', 'Failed to save strategy. Check PHP syntax and file permissions.');
            return $response
                ->withHeader('Location', "/strategies/editor/{$className}/edit")
                ->withStatus(302);
        }

        // Clear opcache if available
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->codeParser->getStrategyFilePath($className), true);
        }

        $this->flash->setFlash('success', "Strategy '{$strategyData['strategy_name']}' updated successfully.");
        return $response
            ->withHeader('Location', "/strategies/editor/{$className}/edit")
            ->withStatus(302);
    }

    /**
     * Show form to create a new strategy
     *
     * GET /strategies/editor/create
     */
    public function create(Request $request, Response $response): Response
    {
        if (!$this->codeParser->isWritable()) {
            $this->flash->setFlash('error', 'Strategy directory is not writable. Cannot create new strategies.');
            return $response
                ->withHeader('Location', '/strategies/editor')
                ->withStatus(302);
        }

        $overridableMethods = $this->codeParser->getOverridableMethods();

        return $this->view->render($response, 'strategies/editor/create.twig', [
            'overridable_methods' => $overridableMethods,
            'flash' => $this->flash->all()
        ]);
    }

    /**
     * Store new strategy
     *
     * POST /strategies/editor
     */
    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        $className = trim($data['class_name'] ?? '');

        // Validate class name
        if (empty($className)) {
            $this->flash->setFlash('error', 'Class name is required.');
            return $response
                ->withHeader('Location', '/strategies/editor/create')
                ->withStatus(302);
        }

        if (!$this->codeParser->isValidClassName($className)) {
            $this->flash->setFlash('error', 'Invalid class name. Use only letters, numbers, and underscores. Must start with a letter or underscore.');
            return $response
                ->withHeader('Location', '/strategies/editor/create')
                ->withStatus(302);
        }

        if ($this->codeParser->strategyExists($className)) {
            $this->flash->setFlash('error', "Strategy '{$className}' already exists.");
            return $response
                ->withHeader('Location', '/strategies/editor/create')
                ->withStatus(302);
        }

        // Build strategy data
        $strategyData = [
            'class_name' => $className,
            'strategy_name' => $data['strategy_name'] ?? $className . ' Strategy',
            'strategy_description' => $data['strategy_description'] ?? '',
            'strategy_parameters' => [],
            'custom_properties' => $data['custom_properties'] ?? '',
            'methods' => []
        ];

        // Parse strategy parameters
        if (isset($data['param_names']) && is_array($data['param_names'])) {
            for ($i = 0; $i < count($data['param_names']); $i++) {
                $paramName = trim($data['param_names'][$i] ?? '');
                $paramValue = $data['param_values'][$i] ?? '';

                if (!empty($paramName)) {
                    $strategyData['strategy_parameters'][$paramName] = $this->parseParameterValue($paramValue);
                }
            }
        }

        // Parse methods
        $overridableMethods = $this->codeParser->getOverridableMethods();
        foreach ($overridableMethods as $methodName => $methodInfo) {
            $bodyKey = "method_{$methodName}";
            if (isset($data[$bodyKey]) && !empty(trim($data[$bodyKey]))) {
                $strategyData['methods'][$methodName] = [
                    'signature' => $methodInfo['signature'],
                    'body' => $data[$bodyKey],
                    'parameters' => $methodInfo['parameters']
                ];
            } else {
                // Add default implementation for required methods
                $strategyData['methods'][$methodName] = [
                    'signature' => $methodInfo['signature'],
                    'body' => $methodInfo['default_body'],
                    'parameters' => $methodInfo['parameters']
                ];
            }
        }

        // Generate and save code
        $code = $this->codeParser->generateStrategyCode($strategyData);

        if (!$this->codeParser->saveStrategy($className, $code)) {
            $this->flash->setFlash('error', 'Failed to save strategy. Check PHP syntax and file permissions.');
            return $response
                ->withHeader('Location', '/strategies/editor/create')
                ->withStatus(302);
        }

        $this->flash->setFlash('success', "Strategy '{$strategyData['strategy_name']}' created successfully.");
        return $response
            ->withHeader('Location', "/strategies/editor/{$className}/edit")
            ->withStatus(302);
    }

    /**
     * Show rename form
     *
     * GET /strategies/editor/{className}/rename
     */
    public function showRename(Request $request, Response $response, array $args): Response
    {
        $className = $args['className'];

        $strategy = $this->codeParser->parseStrategy($className);
        if (!$strategy) {
            $this->flash->setFlash('error', "Strategy '{$className}' not found.");
            return $response
                ->withHeader('Location', '/strategies/editor')
                ->withStatus(302);
        }

        if (!$strategy['is_writable']) {
            $this->flash->setFlash('error', 'Strategy file is not writable.');
            return $response
                ->withHeader('Location', '/strategies/editor')
                ->withStatus(302);
        }

        // Check if strategy is used in any backtests
        $backtests = $this->backtestRepository->getBacktestsByStrategy('SimpleTrader\\' . $className);

        return $this->view->render($response, 'strategies/editor/rename.twig', [
            'strategy' => $strategy,
            'backtests_count' => count($backtests),
            'flash' => $this->flash->all()
        ]);
    }

    /**
     * Rename strategy
     *
     * POST /strategies/editor/{className}/rename
     */
    public function rename(Request $request, Response $response, array $args): Response
    {
        $oldClassName = $args['className'];
        $data = $request->getParsedBody();
        $newClassName = trim($data['new_class_name'] ?? '');

        if (empty($newClassName)) {
            $this->flash->setFlash('error', 'New class name is required.');
            return $response
                ->withHeader('Location', "/strategies/editor/{$oldClassName}/rename")
                ->withStatus(302);
        }

        if (!$this->codeParser->isValidClassName($newClassName)) {
            $this->flash->setFlash('error', 'Invalid class name. Use only letters, numbers, and underscores.');
            return $response
                ->withHeader('Location', "/strategies/editor/{$oldClassName}/rename")
                ->withStatus(302);
        }

        if ($oldClassName === $newClassName) {
            $this->flash->setFlash('info', 'No changes made. Class name is the same.');
            return $response
                ->withHeader('Location', "/strategies/editor/{$oldClassName}/edit")
                ->withStatus(302);
        }

        if ($this->codeParser->strategyExists($newClassName)) {
            $this->flash->setFlash('error', "Strategy '{$newClassName}' already exists.");
            return $response
                ->withHeader('Location', "/strategies/editor/{$oldClassName}/rename")
                ->withStatus(302);
        }

        // Perform rename
        if (!$this->codeParser->renameStrategy($oldClassName, $newClassName)) {
            $this->flash->setFlash('error', 'Failed to rename strategy.');
            return $response
                ->withHeader('Location', "/strategies/editor/{$oldClassName}/rename")
                ->withStatus(302);
        }

        $this->flash->setFlash('success', "Strategy renamed from '{$oldClassName}' to '{$newClassName}' successfully.");
        return $response
            ->withHeader('Location', "/strategies/editor/{$newClassName}/edit")
            ->withStatus(302);
    }

    /**
     * Delete strategy confirmation
     *
     * GET /strategies/editor/{className}/delete
     */
    public function confirmDelete(Request $request, Response $response, array $args): Response
    {
        $className = $args['className'];

        $strategy = $this->codeParser->parseStrategy($className);
        if (!$strategy) {
            $this->flash->setFlash('error', "Strategy '{$className}' not found.");
            return $response
                ->withHeader('Location', '/strategies/editor')
                ->withStatus(302);
        }

        // Check if strategy is used in any backtests
        $backtests = $this->backtestRepository->getBacktestsByStrategy('SimpleTrader\\' . $className);

        return $this->view->render($response, 'strategies/editor/delete.twig', [
            'strategy' => $strategy,
            'backtests_count' => count($backtests),
            'flash' => $this->flash->all()
        ]);
    }

    /**
     * Delete strategy
     *
     * POST /strategies/editor/{className}/delete
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $className = $args['className'];

        if (!$this->codeParser->isStrategyFileWritable($className)) {
            $this->flash->setFlash('error', 'Strategy file is not writable.');
            return $response
                ->withHeader('Location', '/strategies/editor')
                ->withStatus(302);
        }

        // Delete the file
        if (!$this->codeParser->deleteStrategy($className)) {
            $this->flash->setFlash('error', 'Failed to delete strategy file.');
            return $response
                ->withHeader('Location', '/strategies/editor')
                ->withStatus(302);
        }

        $this->flash->setFlash('success', "Strategy '{$className}' deleted successfully.");
        return $response
            ->withHeader('Location', '/strategies/editor')
            ->withStatus(302);
    }

    /**
     * API: Validate PHP code syntax
     *
     * POST /strategies/editor/validate
     */
    public function validateSyntax(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $code = $data['code'] ?? '';

        if (empty($code)) {
            $result = ['valid' => false, 'error' => 'No code provided'];
        } else {
            $tempFile = tempnam(sys_get_temp_dir(), 'strategy_validate_');
            file_put_contents($tempFile, "<?php\n" . $code);
            exec("php -l {$tempFile} 2>&1", $output, $returnCode);
            unlink($tempFile);

            if ($returnCode === 0) {
                $result = ['valid' => true];
            } else {
                $errorMsg = implode("\n", $output);
                // Clean up the error message
                $errorMsg = str_replace($tempFile, 'code', $errorMsg);
                $result = ['valid' => false, 'error' => $errorMsg];
            }
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Parse parameter value to appropriate PHP type
     */
    private function parseParameterValue(string $value): mixed
    {
        $trimmed = trim($value);

        // Boolean
        if (strtolower($trimmed) === 'true') {
            return true;
        }
        if (strtolower($trimmed) === 'false') {
            return false;
        }

        // Integer
        if (preg_match('/^-?\d+$/', $trimmed)) {
            return (int)$trimmed;
        }

        // Float
        if (preg_match('/^-?\d+\.\d+$/', $trimmed)) {
            return (float)$trimmed;
        }

        // Array (JSON format)
        if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // Default to string
        return $value;
    }
}
