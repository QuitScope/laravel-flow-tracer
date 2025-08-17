<?php

namespace LaravelFlowTracer\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ReflectionMethod;
use LaravelFlowTracer\Services\FlowParser;
use LaravelFlowTracer\Services\DependencyTracer;
use LaravelFlowTracer\Services\CodeAnalyzer;
use LaravelFlowTracer\Services\FlowVisualizer;

class FlowTraceCommand extends Command
{
    protected $signature = 'flow:trace 
                            {--action= : Trace specific controller action (e.g., MyController@method)}
                            {--route= : Trace specific named route}
                            {--url= : Trace specific URL path}
                            {--forward : Show forward dependencies}
                            {--backward : Show backward dependencies}
                            {--impact : Show impact analysis}
                            {--circular : Find circular dependencies}
                            {--depth=3 : Maximum depth for tracing}
                            {--format=table : Output format (table, json)}
                            {--export= : Export visual diagram (svg, mermaid, dot)}
                            {--no-png : Disable automatic PNG generation}
                            {--output= : Output file path for PNG}';

    protected $description = 'Trace Laravel application flow and automatically generate PNG diagrams';

    private FlowParser $flowParser;
    private DependencyTracer $dependencyTracer;
    private CodeAnalyzer $codeAnalyzer;
    private FlowVisualizer $visualizer;

    public function __construct()
    {
        parent::__construct();
        $this->flowParser = new FlowParser();
        $this->dependencyTracer = new DependencyTracer();
        $this->codeAnalyzer = new CodeAnalyzer();
        $this->visualizer = new FlowVisualizer();
    }

    public function handle()
    {
        $action = $this->option('action');
        $route = $this->option('route');
        $url = $this->option('url');
        $forward = $this->option('forward');
        $backward = $this->option('backward');
        $impact = $this->option('impact');
        $circular = $this->option('circular');
        $depth = (int) $this->option('depth');
        $format = $this->option('format');
        $export = $this->option('export');
        $noPng = $this->option('no-png');
        $output = $this->option('output');

        if (!$action && !$route && !$url) {
            $this->error('Please specify one of: --action, --route, or --url');
            return 1;
        }

        try {
            $flow = [];

            if ($action) {
                $flow = $this->traceAction($action);
            } elseif ($route) {
                $flow = $this->traceRoute($route);
            } elseif ($url) {
                $flow = $this->traceUrl($url);
            }

            if ($forward || $backward || $impact || $circular) {
                $targetClass = $flow['controller'] ?? null;
                if ($targetClass) {
                    if ($forward) {
                        $flow['forward_dependencies'] = $this->dependencyTracer->forwardTrace($targetClass, $depth);
                    }

                    if ($backward) {
                        $flow['backward_dependencies'] = $this->dependencyTracer->backwardTrace($targetClass, $depth);
                    }

                    if ($impact) {
                        $flow['impact_analysis'] = $this->dependencyTracer->getImpactAnalysis($targetClass);
                    }

                    if ($circular) {
                        $flow['circular_dependencies'] = $this->dependencyTracer->findCircularDependencies($targetClass);
                    }
                }
            }

            // Always show flow information
            $this->displayFlow($flow, $format);

            // Always generate PNG unless disabled
            if (!$noPng) {
                $this->newLine();
                $this->generateAutomaticPng($flow, $output);
            }

            // Handle additional exports
            if ($export) {
                $this->newLine();
                $this->handleVisualExport($flow, $export, null);
            }

        } catch (\Exception $e) {
            $this->error('Error tracing flow: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function traceAction(string $action): array
    {
        return $this->flowParser->parseFullFlow($action, 'controller');
    }

    private function traceRoute(string $routeName): array
    {
        return $this->flowParser->parseFullFlow($routeName, 'route');
    }

    private function traceUrl(string $url): array
    {
        return $this->flowParser->parseFullFlow($url, 'url');
    }

    private function findControllerInSrc(string $controller): ?string
    {
        $srcPath = base_path('src');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcPath)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getRealPath());
                if (preg_match("/class\s+{$controller}\s+/", $content)) {
                    $relativePath = str_replace($srcPath . DIRECTORY_SEPARATOR, '', $file->getRealPath());
                    $namespace = str_replace(['/', '\\'], '\\', dirname($relativePath));
                    return $namespace . '\\' . $controller;
                }
            }
        }

        return null;
    }

    private function analyzeController(string $controller, string $method): array
    {
        $reflection = new ReflectionClass($controller);
        $methodReflection = $reflection->getMethod($method);

        $flow = [
            'controller' => $controller,
            'method' => $method,
            'file' => $reflection->getFileName(),
            'line' => $methodReflection->getStartLine(),
            'dependencies' => [],
            'database_queries' => [],
            'events' => [],
            'jobs' => [],
        ];

        $methodSource = $this->getMethodSource($reflection->getFileName(), $methodReflection);
        $flow = array_merge($flow, $this->parseMethodDependencies($methodSource));

        return $flow;
    }

    private function getMethodSource(string $file, ReflectionMethod $method): string
    {
        $lines = file($file);
        $startLine = $method->getStartLine() - 1;
        $endLine = $method->getEndLine() - 1;
        
        return implode('', array_slice($lines, $startLine, $endLine - $startLine + 1));
    }

    private function parseMethodDependencies(string $source): array
    {
        $dependencies = [];
        $queries = [];
        $events = [];
        $jobs = [];

        if (preg_match_all('/new\s+([A-Z][a-zA-Z0-9_\\\\]+)/', $source, $matches)) {
            $dependencies = array_merge($dependencies, $matches[1]);
        }

        if (preg_match_all('/([A-Z][a-zA-Z0-9_\\\\]+)::/', $source, $matches)) {
            $dependencies = array_merge($dependencies, $matches[1]);
        }

        if (preg_match_all('/(DB::|Model::|\$this->|->where\(|->find\(|->create\(|->update\(|->delete\()/', $source, $matches)) {
            $queries[] = 'Database operations detected';
        }

        if (preg_match_all('/event\(|Event::/', $source, $matches)) {
            $events[] = 'Event dispatching detected';
        }

        if (preg_match_all('/dispatch\(|Job::/', $source, $matches)) {
            $jobs[] = 'Job dispatching detected';
        }

        return [
            'dependencies' => array_unique($dependencies),
            'database_queries' => $queries,
            'events' => $events,
            'jobs' => $jobs,
        ];
    }

    private function getForwardDependencies(array $flow): array
    {
        $dependencies = [];
        
        foreach ($flow['dependencies'] as $dependency) {
            if (class_exists($dependency)) {
                $reflection = new ReflectionClass($dependency);
                $dependencies[] = [
                    'class' => $dependency,
                    'file' => $reflection->getFileName(),
                    'type' => $this->classifyDependency($dependency),
                ];
            }
        }

        return $dependencies;
    }

    private function getBackwardDependencies(array $flow): array
    {
        $dependents = [];
        $srcPath = base_path('src');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcPath)
        );

        $targetClass = basename($flow['controller']);

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getRealPath());
                if (preg_match("/(use\s+.*{$targetClass}|new\s+{$targetClass}|{$targetClass}::)/", $content)) {
                    $dependents[] = [
                        'file' => $file->getRealPath(),
                        'type' => 'Usage dependency'
                    ];
                }
            }
        }

        return $dependents;
    }

    private function classifyDependency(string $class): string
    {
        if (str_contains($class, 'Controller')) return 'Controller';
        if (str_contains($class, 'Service')) return 'Service';
        if (str_contains($class, 'Action')) return 'Action';
        if (str_contains($class, 'Query')) return 'Query';
        if (str_contains($class, 'Model')) return 'Model';
        if (str_contains($class, 'Request')) return 'Request';
        if (str_contains($class, 'Resource')) return 'Resource';
        if (str_contains($class, 'Event')) return 'Event';
        if (str_contains($class, 'Job')) return 'Job';
        
        return 'Unknown';
    }

    private function displayFlow(array $flow, string $format): void
    {
        if ($format === 'json') {
            $this->line(json_encode($flow, JSON_PRETTY_PRINT));
            return;
        }

        $this->info("Laravel Flow Tracer Results");
        $this->line("============================");

        if (isset($flow['route'])) {
            $this->displayRouteInfo($flow['route']);
        }

        if (!empty($flow['middleware'])) {
            $this->displayMiddleware($flow['middleware']);
        }

        if ($flow['controller'] && $flow['action']) {
            $this->displayControllerInfo($flow);
        }

        if (!empty($flow['services'])) {
            $this->displayServices($flow['services']);
        }

        if (!empty($flow['models'])) {
            $this->displayModels($flow['models']);
        }

        if (!empty($flow['database_queries'])) {
            $this->displayDatabaseOperations($flow['database_queries']);
        }

        if (isset($flow['forward_dependencies'])) {
            $this->displayForwardDependencies($flow['forward_dependencies']);
        }

        if (isset($flow['backward_dependencies'])) {
            $this->displayBackwardDependencies($flow['backward_dependencies']);
        }

        if (isset($flow['impact_analysis'])) {
            $this->displayImpactAnalysis($flow['impact_analysis']);
        }

        if (isset($flow['circular_dependencies']) && !empty($flow['circular_dependencies'])) {
            $this->displayCircularDependencies($flow['circular_dependencies']);
        }
    }

    private function displayRouteInfo(array $route): void
    {
        $this->info("\n🔗 Route Information:");
        $this->table(['Property', 'Value'], [
            ['Name', $route['name'] ?? 'N/A'],
            ['URI', $route['uri'] ?? 'N/A'],
            ['Methods', implode(', ', $route['methods'] ?? [])],
            ['Parameters', implode(', ', $route['parameters'] ?? [])],
        ]);
    }

    private function displayMiddleware(array $middleware): void
    {
        $this->info("\n🛡️ Middleware Stack:");
        $tableData = [];
        foreach ($middleware as $mw) {
            $tableData[] = [$mw['name'], $mw['type']];
        }
        $this->table(['Middleware', 'Type'], $tableData);
    }

    private function displayControllerInfo(array $flow): void
    {
        $this->info("\n🎯 Controller Action:");
        $this->table(['Property', 'Value'], [
            ['Controller', $flow['controller']],
            ['Action', $flow['action']],
        ]);
    }

    private function displayServices(array $services): void
    {
        $this->info("\n⚙️ Services & Actions:");
        $tableData = [];
        foreach ($services as $service) {
            $tableData[] = [
                $service['class'] ?? 'N/A',
                $service['type'] ?? 'N/A',
                isset($service['methods']) ? implode(', ', $service['methods']) : 'N/A'
            ];
        }
        $this->table(['Service', 'Type', 'Methods'], $tableData);
    }

    private function displayModels(array $models): void
    {
        $this->info("\n📊 Models:");
        $tableData = [];
        foreach ($models as $model) {
            $tableData[] = [
                $model['class'] ?? 'N/A',
                isset($model['operations']) ? implode(', ', $model['operations']) : 'N/A'
            ];
        }
        $this->table(['Model', 'Operations'], $tableData);
    }

    private function displayDatabaseOperations(array $operations): void
    {
        $this->info("\n🗄️ Database Operations:");
        foreach ($operations as $op) {
            if (is_array($op)) {
                $this->line("  - {$op['type']}: {$op['pattern']}");
            } else {
                $this->line("  - $op");
            }
        }
    }

    private function displayForwardDependencies(array $deps): void
    {
        $this->info("\n➡️ Forward Dependencies:");
        $this->displayDependencyTree($deps, 0);
    }

    private function displayBackwardDependencies(array $deps): void
    {
        $this->info("\n⬅️ Backward Dependencies:");
        $this->displayDependencyTree($deps, 0);
    }

    private function displayDependencyTree($node, int $level): void
    {
        if (empty($node)) return;

        $indent = str_repeat('  ', $level);
        
        if (is_array($node) && isset($node['class'])) {
            $type = $node['type'] ?? 'Unknown';
            $this->line("{$indent}- {$node['class']} ({$type})");
            
            if (isset($node['dependencies'])) {
                foreach ($node['dependencies'] as $dep) {
                    $this->displayDependencyTree($dep, $level + 1);
                }
            }
            
            if (isset($node['dependents'])) {
                foreach ($node['dependents'] as $dep) {
                    $this->displayDependencyTree($dep, $level + 1);
                }
            }
        } elseif (is_array($node)) {
            foreach ($node as $item) {
                $this->displayDependencyTree($item, $level);
            }
        }
    }

    private function displayImpactAnalysis(array $analysis): void
    {
        $this->info("\n📈 Impact Analysis:");
        $this->table(['Metric', 'Value'], [
            ['Forward Impact', $analysis['forward_impact']],
            ['Backward Impact', $analysis['backward_impact']],
            ['Risk Level', $analysis['risk_level']],
        ]);
    }

    private function displayCircularDependencies(array $cycles): void
    {
        $this->info("\n🔄 Circular Dependencies:");
        foreach ($cycles as $i => $cycle) {
            $this->warn("Cycle " . ($i + 1) . ": " . implode(' → ', $cycle));
        }
    }

    private function handleVisualExport(array $flow, string $exportFormat, ?string $outputPath): void
    {
        try {
            if ($exportFormat === 'mermaid') {
                $this->exportMermaid($flow, $outputPath);
            } elseif (in_array($exportFormat, ['png', 'svg', 'dot'])) {
                $this->exportGraphviz($flow, $exportFormat, $outputPath);
            } else {
                $this->error("Unsupported export format: {$exportFormat}. Supported formats: png, svg, dot, mermaid");
                return;
            }
        } catch (\Exception $e) {
            $this->error("Export failed: " . $e->getMessage());
        }
    }

    private function exportMermaid(array $flow, ?string $outputPath): void
    {
        $mermaidDiagram = $this->visualizer->generateMermaidDiagram($flow);
        
        $outputFile = $outputPath ?: storage_path('app/flow-diagrams/flow-' . time() . '.mmd');
        $this->ensureDirectoryExists(dirname($outputFile));
        
        file_put_contents($outputFile, $mermaidDiagram);
        
        $this->info("🎨 Mermaid diagram exported to: {$outputFile}");
        $this->info("💡 You can paste this content into mermaid.live or use mermaid-cli to generate images.");
        
        $this->newLine();
        $this->line("Mermaid Diagram:");
        $this->line("================");
        $this->line($mermaidDiagram);
    }

    private function exportGraphviz(array $flow, string $format, ?string $outputPath): void
    {
        $timestamp = time();
        $defaultName = "flow-{$timestamp}";
        $outputFile = $outputPath ?: storage_path("app/flow-diagrams/{$defaultName}.{$format}");
        
        $this->ensureDirectoryExists(dirname($outputFile));
        
        $exportedFile = $this->visualizer->exportFlow($flow, $outputFile, $format);
        
        $this->info("🎨 Flow diagram exported to: {$exportedFile}");
        
        if ($format === 'png' || $format === 'svg') {
            $this->info("💡 You can open this file in any image viewer or web browser.");
        } elseif ($format === 'dot') {
            $this->info("💡 This is a Graphviz DOT file. Use 'dot -Tpng {$exportedFile} -o output.png' to generate an image.");
        }
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    private function handlePngExport(array $flow, ?string $outputPath): void
    {
        try {
            $timestamp = time();
            $defaultName = "flow-{$timestamp}";
            $outputFile = $outputPath ?: storage_path("app/flow-diagrams/{$defaultName}.png");
            
            $this->ensureDirectoryExists(dirname($outputFile));
            
            $exportedFile = $this->visualizer->exportFlowPng($flow, $outputFile);
            
            $this->info("🖼️ PNG flow diagram exported to: {$exportedFile}");
            $this->info("💡 You can open this file in any image viewer.");
            
            // Show basic flow info as well
            $this->newLine();
            $this->displayFlowSummary($flow);
            
        } catch (\Exception $e) {
            $this->error("PNG export failed: " . $e->getMessage());
        }
    }

    private function displayFlowSummary(array $flow): void
    {
        $this->info("📋 Flow Summary:");
        
        if (isset($flow['route'])) {
            $this->line("🔗 Route: " . ($flow['route']['name'] ?? 'unnamed') . " -> " . ($flow['route']['uri'] ?? ''));
        }
        
        if (!empty($flow['middleware'])) {
            $middlewareNames = array_column($flow['middleware'], 'name');
            $this->line("🛡️ Middleware: " . implode(' → ', $middlewareNames));
        }
        
        if (isset($flow['controller'])) {
            $controllerName = class_basename($flow['controller']);
            $action = $flow['action'] ?? '__invoke';
            $this->line("🎯 Controller: {$controllerName}::{$action}");
        }
        
        if (!empty($flow['services'])) {
            $serviceNames = array_map(function($service) {
                return class_basename($service['class']);
            }, $flow['services']);
            $this->line("⚙️ Services: " . implode(', ', $serviceNames));
        }
        
        if (!empty($flow['models'])) {
            $modelNames = array_map(function($model) {
                return class_basename($model['class']);
            }, $flow['models']);
            $this->line("📊 Models: " . implode(', ', $modelNames));
        }
    }

    private function generateAutomaticPng(array $flow, ?string $outputPath): void
    {
        try {
            $timestamp = time();
            $flowName = $this->getFlowName($flow);
            $filename = "flow-{$flowName}-{$timestamp}";
            $outputFile = $outputPath ?: storage_path("app/flow-diagrams/{$filename}.png");
            
            $this->ensureDirectoryExists(dirname($outputFile));
            
            $exportedFile = $this->visualizer->exportFlowPng($flow, $outputFile);
            
            $this->info("🖼️ Flow diagram automatically saved: {$exportedFile}");
            
        } catch (\Exception $e) {
            $this->warn("⚠️ PNG generation failed: " . $e->getMessage());
        }
    }

    private function getFlowName(array $flow): string
    {
        if (isset($flow['route']['name'])) {
            return str_replace('.', '-', $flow['route']['name']);
        }
        
        if (isset($flow['controller'])) {
            return strtolower(class_basename($flow['controller']));
        }
        
        return 'unknown';
    }
}
