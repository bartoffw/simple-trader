<?php

declare(strict_types=1);

namespace SimpleTrader\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Parsedown;

class DocumentationController
{
    private Twig $view;
    private string $projectRoot;
    private Parsedown $parsedown;

    /**
     * Available documentation files organized by category
     */
    private array $documentationFiles = [
        'Getting Started' => [
            'overview' => [
                'title' => 'Project Overview',
                'file' => 'README.md',
                'icon' => 'home'
            ],
            'user-guide' => [
                'title' => 'User Guide',
                'file' => 'USER_GUIDE.md',
                'icon' => 'book'
            ],
        ],
        'Technical Documentation' => [
            'developer-guide' => [
                'title' => 'Developer Guide',
                'file' => 'DEVELOPER_GUIDE.md',
                'icon' => 'code'
            ],
            'daily-updates' => [
                'title' => 'Daily Update Research',
                'file' => 'docs/DAILY_UPDATE_RESEARCH.md',
                'icon' => 'calendar-alt'
            ],
            'server-setup' => [
                'title' => 'Server Setup Guide',
                'file' => 'docs/SERVER_SETUP.md',
                'icon' => 'server'
            ],
        ],
        'Reference' => [
            'cli-commands' => [
                'title' => 'CLI Commands',
                'file' => 'commands/README.md',
                'icon' => 'terminal'
            ],
            'database' => [
                'title' => 'Database Documentation',
                'file' => 'database/README.md',
                'icon' => 'database'
            ],
        ],
    ];

    public function __construct(Twig $view, string $projectRoot)
    {
        $this->view = $view;
        $this->projectRoot = $projectRoot;
        $this->parsedown = new Parsedown();
        $this->parsedown->setSafeMode(false); // Allow HTML in markdown
    }

    /**
     * Display documentation index page
     */
    public function index(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'documentation/index.twig', [
            'documentationFiles' => $this->documentationFiles,
            'pageTitle' => 'Documentation',
            'active_menu' => 'docs'
        ]);
    }

    /**
     * Display a specific documentation file
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';

        // Find the documentation file
        $docInfo = $this->findDocumentationBySlug($slug);

        if ($docInfo === null) {
            // Document not found
            $response->getBody()->write('Documentation not found');
            return $response->withStatus(404);
        }

        // Read the markdown file
        $filePath = $this->projectRoot . '/' . $docInfo['file'];

        if (!file_exists($filePath)) {
            $response->getBody()->write('Documentation file not found: ' . $docInfo['file']);
            return $response->withStatus(404);
        }

        $markdown = file_get_contents($filePath);
        $html = $this->parsedown->text($markdown);

        // Process the HTML to add styling classes
        $html = $this->enhanceHtml($html);

        return $this->view->render($response, 'documentation/show.twig', [
            'title' => $docInfo['title'],
            'content' => $html,
            'slug' => $slug,
            'documentationFiles' => $this->documentationFiles,
            'pageTitle' => $docInfo['title'],
            'active_menu' => 'docs'
        ]);
    }

    /**
     * Find documentation information by slug
     */
    private function findDocumentationBySlug(string $slug): ?array
    {
        foreach ($this->documentationFiles as $category => $docs) {
            foreach ($docs as $docSlug => $docInfo) {
                if ($docSlug === $slug) {
                    return array_merge($docInfo, ['category' => $category]);
                }
            }
        }
        return null;
    }

    /**
     * Enhance HTML with Bootstrap/AdminLTE classes
     */
    private function enhanceHtml(string $html): string
    {
        // Add table classes
        $html = preg_replace(
            '/<table>/',
            '<div class="table-responsive"><table class="table table-bordered table-striped">',
            $html
        );
        $html = preg_replace(
            '/<\/table>/',
            '</table></div>',
            $html
        );

        // Add code block styling
        $html = preg_replace(
            '/<pre><code class="language-(\w+)">/',
            '<pre class="language-$1"><code class="language-$1">',
            $html
        );

        $html = preg_replace(
            '/<pre><code>/',
            '<pre class="bg-light p-3 border rounded"><code>',
            $html
        );

        // Add alert styling to blockquotes
        $html = preg_replace(
            '/<blockquote>/',
            '<blockquote class="alert alert-info">',
            $html
        );

        // Add heading anchor links
        $html = preg_replace_callback(
            '/<h([2-6])>(.*?)<\/h\1>/',
            function ($matches) {
                $level = $matches[1];
                $text = $matches[2];
                $id = $this->generateId($text);
                return sprintf(
                    '<h%d id="%s" class="mt-4 mb-3">%s <a href="#%s" class="text-muted" style="font-size: 0.7em; text-decoration: none;">#</a></h%d>',
                    $level,
                    $id,
                    $text,
                    $id,
                    $level
                );
            },
            $html
        );

        return $html;
    }

    /**
     * Generate ID from heading text
     */
    private function generateId(string $text): string
    {
        // Remove HTML tags
        $text = strip_tags($text);
        // Convert to lowercase
        $text = strtolower($text);
        // Replace spaces and special chars with hyphens
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        // Remove leading/trailing hyphens
        $text = trim($text, '-');
        return $text;
    }

    /**
     * Get documentation menu structure (for sidebar)
     */
    public function getMenuStructure(): array
    {
        return $this->documentationFiles;
    }
}
