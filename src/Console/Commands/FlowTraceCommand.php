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
                            {--output= : Output file path for PNG}
                            {--stats : Show project statistics}
                            {--scan= : Scan specific directory for classes}
                            {--deep : Trace complete flow until no more connections found}
                            {--max-deep=10 : Maximum depth for deep tracing (prevents infinite loops)}
                            {--internal : Show internal method calls and detailed operations}
                            {--show-params : Show method parameters (requires --internal)}
                            {--debug-connections : Show why connections are detected (for debugging)}';

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
        $stats = $this->option('stats');
        $scan = $this->option('scan');
        $deep = $this->option('deep');
        $maxDeep = (int) $this->option('max-deep');
        $internal = $this->option('internal');
        $showParams = $this->option('show-params');
        $debugConnections = $this->option('debug-connections');

        // Handle project statistics
        if ($stats) {
            $this->showProjectStatistics($scan ?: base_path());
            return 0;
        }

        if (!$action && !$route && !$url) {
            $this->error('Please specify one of: --action, --route, --url, or --stats');
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

            // Handle internal analysis if requested
            if ($internal) {
                $this->info("🔬 Analyzing internal method calls...");
                $flow['internal_analysis'] = $this->performInternalAnalysis($flow, $showParams);
                $this->info("✅ Internal analysis completed!");
                $this->newLine();
            }

            // Handle deep tracing if requested
            if ($deep) {
                $this->info("🔍 Starting deep trace analysis...");
                $flow['deep_trace'] = $this->performDeepTrace($flow, $maxDeep);
                $this->info("✅ Deep trace completed!");
                $this->newLine();
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

        if (isset($flow['called_from']) && !empty($flow['called_from'])) {
            $this->displayActionCallers($flow['called_from']);
        }

        if (isset($flow['uses_actions']) && !empty($flow['uses_actions'])) {
            $this->displayControllerActions($flow['uses_actions']);
        }

        if (isset($flow['internal_analysis']) && !empty($flow['internal_analysis'])) {
            $this->displayInternalAnalysis($flow['internal_analysis']);
        }

        if (isset($flow['deep_trace']) && !empty($flow['deep_trace'])) {
            $this->displayDeepTrace($flow['deep_trace']);
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

    private function showProjectStatistics(string $projectPath): void
    {
        $this->info("📊 Project Statistics for: {$projectPath}");
        $this->line("═══════════════════════════════════════════════════");

        try {
            $stats = $this->codeAnalyzer->getProjectStatistics($projectPath);
            
            $this->info("\n📈 Class Distribution:");
            $this->table(['Type', 'Count'], [
                ['Total Classes', $stats['total_classes']],
                ['Controllers', $stats['controllers']],
                ['Services', $stats['services']],
                ['Models', $stats['models']],
                ['Actions', $stats['actions']],
            ]);

            if (!empty($stats['largest_files'])) {
                $this->info("\n📏 Largest Files (>10KB):");
                $tableData = [];
                foreach (array_slice($stats['largest_files'], 0, 5) as $file) {
                    $sizeKB = round($file['size'] / 1024, 1);
                    $tableData[] = [
                        class_basename($file['class']),
                        "{$sizeKB}KB",
                        str_replace(base_path(), '', $file['file'])
                    ];
                }
                $this->table(['Class', 'Size', 'File'], $tableData);
            }

            if (!empty($stats['deepest_namespaces'])) {
                $this->info("\n🏗️ Deepest Namespaces:");
                $tableData = [];
                foreach (array_slice($stats['deepest_namespaces'], 0, 5) as $ns) {
                    $tableData[] = [
                        $ns['class'],
                        $ns['depth'],
                        $ns['namespace']
                    ];
                }
                $this->table(['Class', 'Depth', 'Namespace'], $tableData);
            }

            $this->info("\n💡 Tips for better tracing:");
            if ($stats['controllers'] > 50) {
                $this->warn("  • Large number of controllers detected - consider using --scan for specific directories");
            }
            if (!empty($stats['deepest_namespaces'])) {
                $this->warn("  • Deep namespaces detected - the tracer has been optimized for complex structures");
            }
            if (!empty($stats['largest_files'])) {
                $this->warn("  • Large files detected - these may take longer to analyze");
            }

        } catch (\Exception $e) {
            $this->error("Failed to analyze project: " . $e->getMessage());
        }
    }

    private function displayActionCallers(array $callers): void
    {
        $this->info("\n📞 Called From (Controllers & Routes using this Action):");
        $tableData = [];
        
        foreach ($callers as $caller) {
            if ($caller['type'] === 'route') {
                $tableData[] = [
                    'Route',
                    $caller['name'] ?? 'unnamed',
                    $caller['uri'] ?? 'N/A',
                    implode(', ', $caller['methods'] ?? [])
                ];
            } elseif ($caller['type'] === 'controller') {
                $controllerName = class_basename($caller['class']);
                $tableData[] = [
                    'Controller',
                    $controllerName,
                    str_replace(base_path(), '', $caller['file']),
                    'Direct Usage'
                ];
            }
        }
        
        if (!empty($tableData)) {
            $this->table(['Type', 'Name/Class', 'URI/File', 'Methods/Usage'], $tableData);
        } else {
            $this->line("  No callers found.");
        }
    }

    private function displayControllerActions(array $actions): void
    {
        $this->info("\n🎬 Used Actions (Actions called by this Controller):");
        $tableData = [];
        
        foreach ($actions as $action) {
            $actionName = class_basename($action['action']);
            $usageType = $this->formatUsageType($action['usage_type']);
            $method = $action['method'] ?? 'N/A';
            
            $tableData[] = [
                $actionName,
                $action['action'],
                $usageType,
                $method
            ];
        }
        
        if (!empty($tableData)) {
            $this->table(['Action', 'Full Class', 'Usage Type', 'Method'], $tableData);
        } else {
            $this->line("  No actions found.");
        }
    }

    private function formatUsageType(string $type): string
    {
        return match($type) {
            'instantiation' => 'new ActionClass()',
            'static_call' => 'ActionClass::method()',
            'instance_call' => '$action->method()',
            'import' => 'use statement',
            'dependency_injection' => 'Dependency Injection',
            default => $type
        };
    }

    private function performDeepTrace(array $initialFlow, int $maxDepth): array
    {
        $deepTrace = [
            'levels' => [],
            'visited_classes' => [],
            'total_depth' => 0,
            'cycles_detected' => [],
            'endpoints' => []
        ];

        // Ensure initial flow has internal analysis if requested
        if ($this->option('internal') && !isset($initialFlow['internal_analysis'])) {
            $initialFlow['internal_analysis'] = $this->performInternalAnalysis($initialFlow, $this->option('show-params'));
        }

        $this->buildDeepTrace($initialFlow, $deepTrace, 0, $maxDepth, []);
        
        return $deepTrace;
    }

    private function buildDeepTrace(array $flow, array &$deepTrace, int $currentDepth, int $maxDepth, array $visited): void
    {
        if ($currentDepth >= $maxDepth) {
            $deepTrace['endpoints'][] = [
                'reason' => 'max_depth_reached',
                'depth' => $currentDepth,
                'class' => $flow['controller'] ?? 'unknown'
            ];
            return;
        }

        $currentClass = $flow['controller'] ?? null;
        if (!$currentClass) {
            return;
        }

        // Cycle detection
        if (in_array($currentClass, $visited)) {
            $deepTrace['cycles_detected'][] = [
                'cycle_path' => array_merge($visited, [$currentClass]),
                'depth' => $currentDepth
            ];
            return;
        }

        $visited[] = $currentClass;
        
        // Prevent duplicate entries in visited_classes
        if (!in_array($currentClass, $deepTrace['visited_classes'])) {
            $deepTrace['visited_classes'][] = $currentClass;
        }

        // Initialize level if not exists
        if (!isset($deepTrace['levels'][$currentDepth])) {
            $deepTrace['levels'][$currentDepth] = [];
        }

        $levelData = [
            'class' => $currentClass,
            'action' => $flow['action'] ?? null,
            'type' => $this->getClassType($currentClass),
            'connections' => []
        ];

        // Find all connections from this class
        $connections = [];

        // Only include actual code dependencies, not false positives
        $connections = $this->findRealDependencies($flow);
        
        // Debug output
        if ($this->option('debug-connections')) {
            $className = class_basename($currentClass);
            $this->line("🔍 DEBUG: Analyzing {$className} at depth {$currentDepth}");
            $this->line("   Found " . count($connections) . " connections via internal analysis");
            if (isset($flow['services'])) {
                $this->line("   Legacy services available: " . count($flow['services']));
                foreach ($flow['services'] as $service) {
                    $serviceName = class_basename($service['class'] ?? $service['name'] ?? 'unknown');
                    $this->line("     - {$serviceName} ({$service['type']})");
                }
            }
            if (isset($flow['uses_actions'])) {
                $this->line("   Legacy actions available: " . count($flow['uses_actions']));
                foreach ($flow['uses_actions'] as $action) {
                    $actionName = class_basename($action['action']);
                    $this->line("     - {$actionName} ({$action['usage_type']})");
                }
            }
            if (isset($flow['internal_analysis']['method_calls'])) {
                $this->line("   Internal method calls: " . count($flow['internal_analysis']['method_calls']));
                foreach (array_slice($flow['internal_analysis']['method_calls'], 0, 3) as $call) {
                    $this->line("     - {$call['type']}: " . ($call['call'] ?? 'unknown'));
                }
            }
        }
        
        // Legacy fallback - but be more restrictive
        if (empty($connections)) {
            // 1. Actions used by this controller - only if we have solid evidence
            if (isset($flow['uses_actions']) && !empty($flow['uses_actions'])) {
                foreach ($flow['uses_actions'] as $actionData) {
                    if ($this->isValidDependency($actionData['action'])) {
                        // Include use statements and import statements
                        if (in_array($actionData['usage_type'], ['instantiation', 'dependency_injection', 'use statement', 'import'])) {
                            $connections[] = [
                                'target' => $actionData['action'],
                                'type' => 'uses_action',
                                'usage_type' => $actionData['usage_type'],
                                'evidence' => 'Legacy: ' . ($actionData['usage_type'] ?? 'unknown')
                            ];
                        }
                    }
                }
            }

            // Skip legacy service and model connections as they're often false positives
            // Only rely on internal analysis for accurate results
        }

        $levelData['connections'] = $connections;
        
        // Check if this exact class+depth combination already exists
        $isDuplicate = false;
        foreach ($deepTrace['levels'][$currentDepth] as $existingLevel) {
            if ($existingLevel['class'] === $currentClass) {
                $isDuplicate = true;
                break;
            }
        }
        
        if (!$isDuplicate) {
            $deepTrace['levels'][$currentDepth][] = $levelData;
        }

        // Recursively trace each connection
        foreach ($connections as $connection) {
            $targetClass = $connection['target'];
            
            try {
                // Only trace if it's a traceable class and not already visited at this depth
                if ($this->isTraceableClass($targetClass) && !in_array($targetClass, $visited)) {
                    $subFlow = $this->flowParser->parseFullFlow($targetClass, 'controller');
                    
                    // Add internal analysis for better dependency detection if --internal is enabled
                    if ($this->option('internal')) {
                        $subFlow['internal_analysis'] = $this->performInternalAnalysis($subFlow, $this->option('show-params'));
                    }
                    
                    $this->buildDeepTrace($subFlow, $deepTrace, $currentDepth + 1, $maxDepth, $visited);
                }
            } catch (\Exception $e) {
                // Log but continue tracing
                error_log("Deep trace error for {$targetClass}: " . $e->getMessage());
            }
        }

        // If no connections found, this is an endpoint
        if (empty($connections)) {
            // Check if this endpoint already exists
            $endpointExists = false;
            foreach ($deepTrace['endpoints'] as $endpoint) {
                if ($endpoint['class'] === $currentClass && $endpoint['reason'] === 'no_connections') {
                    $endpointExists = true;
                    break;
                }
            }
            
            if (!$endpointExists) {
                $deepTrace['endpoints'][] = [
                    'reason' => 'no_connections',
                    'depth' => $currentDepth,
                    'class' => $currentClass
                ];
            }
        }

        // Update total depth reached
        $deepTrace['total_depth'] = max($deepTrace['total_depth'], $currentDepth);
    }

    private function isTraceableClass(string $className): bool
    {
        // Skip built-in Laravel/PHP classes
        $skipPrefixes = [
            'Illuminate\\',
            'Symfony\\',
            'Carbon\\',
            'Monolog\\',
            'Psr\\',
            'Laravel\\',
            'Facade\\',
            'Mockery\\',
            'PHPUnit\\',
            'Exception',
            'DateTime',
            'stdClass'
        ];

        foreach ($skipPrefixes as $prefix) {
            if (str_starts_with($className, $prefix)) {
                return false;
            }
        }

        return true;
    }

    private function getClassType(string $className): string
    {
        if (str_contains($className, 'Controller')) return 'Controller';
        if (str_contains($className, 'Action')) return 'Action';
        if (str_contains($className, 'Service')) return 'Service';
        if (str_contains($className, 'Repository')) return 'Repository';
        if (str_contains($className, 'Model')) return 'Model';
        if (str_contains($className, 'Query')) return 'Query';
        if (str_contains($className, 'Handler')) return 'Handler';
        if (str_contains($className, 'Middleware')) return 'Middleware';
        
        return 'Class';
    }

    private function displayDeepTrace(array $deepTrace): void
    {
        $this->info("\n🌊 Deep Flow Trace (Complete Flow Analysis):");
        $this->line("═══════════════════════════════════════════════════════════");

        $this->info("📊 Trace Summary:");
        $this->table(['Metric', 'Value'], [
            ['Total Depth Reached', $deepTrace['total_depth']],
            ['Unique Classes Visited', count(array_unique($deepTrace['visited_classes']))],
            ['Endpoints Found', count($deepTrace['endpoints'])],
            ['Cycles Detected', count($deepTrace['cycles_detected'])]
        ]);

        // Display each level
        foreach ($deepTrace['levels'] as $level => $classes) {
            $this->info("\n📍 Level {$level}:");
            
            foreach ($classes as $classData) {
                $className = class_basename($classData['class']);
                $action = $classData['action'] ? "::{$classData['action']}" : '';
                $type = $classData['type'];
                
                $this->line("  🎯 {$className}{$action} ({$type})");
                
                if (!empty($classData['connections'])) {
                    $this->line("    └─ Connections:");
                    foreach ($classData['connections'] as $connection) {
                        $targetName = class_basename($connection['target']);
                        $connectionType = $this->formatConnectionType($connection['type']);
                        $this->line("       ├─ {$targetName} ({$connectionType})");
                        
                        // Show debug info if enabled
                        if ($this->option('debug-connections') && isset($connection['evidence'])) {
                            $this->line("          Debug: {$connection['evidence']}");
                        }
                    }
                }
            }
        }

        // Display endpoints
        if (!empty($deepTrace['endpoints'])) {
            $this->info("\n🏁 Flow Endpoints:");
            $tableData = [];
            foreach ($deepTrace['endpoints'] as $endpoint) {
                $reason = $this->formatEndpointReason($endpoint['reason']);
                $className = class_basename($endpoint['class']);
                $tableData[] = [$className, "Level {$endpoint['depth']}", $reason];
            }
            $this->table(['Class', 'Depth', 'Reason'], $tableData);
        }

        // Display cycles if found
        if (!empty($deepTrace['cycles_detected'])) {
            $this->warn("\n🔄 Circular Dependencies Detected:");
            foreach ($deepTrace['cycles_detected'] as $i => $cycle) {
                $cyclePath = array_map('class_basename', $cycle['cycle_path']);
                $this->warn("  Cycle " . ($i + 1) . " (Depth {$cycle['depth']}): " . implode(' → ', $cyclePath));
            }
        }
    }

    private function formatConnectionType(string $type): string
    {
        return match($type) {
            'uses_action' => 'Action',
            'uses_service' => 'Service',
            'uses_model' => 'Model',
            'uses_repository' => 'Repository',
            default => ucfirst(str_replace('_', ' ', $type))
        };
    }

    private function formatEndpointReason(string $reason): string
    {
        return match($reason) {
            'no_connections' => 'No further connections',
            'max_depth_reached' => 'Maximum depth reached',
            'cycle_detected' => 'Circular dependency',
            default => $reason
        };
    }

    private function performInternalAnalysis(array $flow, bool $showParams): array
    {
        $analysis = [
            'target_class' => $flow['controller'] ?? null,
            'target_method' => $flow['action'] ?? null,
            'methods' => [],
            'method_calls' => [],
            'internal_dependencies' => [],
            'detailed_operations' => []
        ];

        if (!$analysis['target_class']) {
            return $analysis;
        }

        try {
            $classAnalysis = $this->codeAnalyzer->analyzeClass($analysis['target_class']);
            
            // Analyze the specific method or all methods
            foreach ($classAnalysis['methods'] as $method) {
                $methodName = $method['name'];
                
                // Focus on the target method or analyze all if no specific method
                if ($analysis['target_method'] && $methodName !== $analysis['target_method']) {
                    continue;
                }

                $internalMethod = $this->analyzeMethodInternals($method, $analysis['target_class'], $showParams);
                $analysis['methods'][$methodName] = $internalMethod;
                
                // Collect method calls
                $analysis['method_calls'] = array_merge(
                    $analysis['method_calls'], 
                    $internalMethod['method_calls']
                );
                
                // Collect internal dependencies
                $analysis['internal_dependencies'] = array_merge(
                    $analysis['internal_dependencies'],
                    $internalMethod['internal_calls']
                );
                
                // Collect detailed operations
                $analysis['detailed_operations'] = array_merge(
                    $analysis['detailed_operations'],
                    $internalMethod['operations']
                );
            }
            
            // Remove duplicates
            $analysis['method_calls'] = array_unique($analysis['method_calls'], SORT_REGULAR);
            $analysis['internal_dependencies'] = array_unique($analysis['internal_dependencies'], SORT_REGULAR);
            $analysis['detailed_operations'] = array_unique($analysis['detailed_operations'], SORT_REGULAR);
            
        } catch (\Exception $e) {
            $analysis['error'] = $e->getMessage();
        }

        return $analysis;
    }

    private function analyzeMethodInternals(array $method, string $className, bool $showParams): array
    {
        $internal = [
            'method_name' => $method['name'],
            'visibility' => $method['visibility'],
            'parameters' => $showParams ? $method['parameters'] : [],
            'return_type' => $method['return_type'],
            'method_calls' => [],
            'internal_calls' => [],
            'operations' => [],
            'line_count' => ($method['line_end'] ?? 0) - ($method['line_start'] ?? 0),
            'complexity_indicators' => []
        ];

        if (!isset($method['source_analysis'])) {
            return $internal;
        }

        $sourceAnalysis = $method['source_analysis'];

        // Extract method source for detailed analysis
        try {
            $reflection = new \ReflectionClass($className);
            $methodReflection = $reflection->getMethod($method['name']);
            $methodSource = $this->getMethodSourceCode($methodReflection);
            
            $internal = array_merge($internal, $this->analyzeMethodSource($methodSource, $method['name']));
            
        } catch (\Exception $e) {
            $internal['source_error'] = $e->getMessage();
        }

        // Add existing source analysis data
        foreach (['database_operations', 'service_calls', 'model_operations', 'event_dispatches', 'job_dispatches'] as $key) {
            if (!empty($sourceAnalysis[$key])) {
                $internal['operations'] = array_merge($internal['operations'], array_map(function($op) use ($key) {
                    return array_merge($op, ['category' => $key]);
                }, $sourceAnalysis[$key]));
            }
        }

        return $internal;
    }

    private function getMethodSourceCode(\ReflectionMethod $method): string
    {
        $filePath = $method->getFileName();
        
        if (!$filePath || !is_file($filePath)) {
            return '';
        }
        
        try {
            $lines = file($filePath);
            $startLine = $method->getStartLine() - 1;
            $endLine = $method->getEndLine() - 1;
            
            if ($startLine < 0 || $endLine >= count($lines)) {
                return '';
            }
            
            return implode('', array_slice($lines, $startLine, $endLine - $startLine + 1));
        } catch (\Exception $e) {
            return '';
        }
    }

    private function analyzeMethodSource(string $source, string $methodName): array
    {
        $analysis = [
            'method_calls' => [],
            'internal_calls' => [],
            'variable_assignments' => [],
            'conditional_statements' => [],
            'loops' => [],
            'try_catch_blocks' => [],
            'complexity_indicators' => []
        ];

        if (empty($source)) {
            return $analysis;
        }

        // Method calls analysis
        $analysis['method_calls'] = $this->findMethodCalls($source);
        $analysis['internal_calls'] = $this->findInternalCalls($source);
        
        // Control flow analysis
        $analysis['conditional_statements'] = $this->findConditionalStatements($source);
        $analysis['loops'] = $this->findLoops($source);
        $analysis['try_catch_blocks'] = $this->findTryCatchBlocks($source);
        
        // Variable assignments
        $analysis['variable_assignments'] = $this->findVariableAssignments($source);
        
        // Complexity indicators
        $analysis['complexity_indicators'] = $this->calculateComplexityIndicators($source);

        return $analysis;
    }

    private function findMethodCalls(string $source): array
    {
        $calls = [];
        
        // Find method calls like $this->methodName(), $object->methodName(), Class::methodName()
        $patterns = [
            '/\$this\s*->\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/' => 'internal_method',
            '/\$([a-zA-Z_][a-zA-Z0-9_]*)\s*->\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/' => 'instance_method',
            '/([A-Z][a-zA-Z0-9_\\\\]*)::\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/' => 'static_method',
            '/([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/' => 'function_call'
        ];
        
        foreach ($patterns as $pattern => $type) {
            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    switch ($type) {
                        case 'internal_method':
                            $calls[] = [
                                'type' => 'Internal Method',
                                'method' => $match[1],
                                'target' => '$this',
                                'call' => $match[0]
                            ];
                            break;
                        case 'instance_method':
                            $calls[] = [
                                'type' => 'Instance Method',
                                'variable' => $match[1],
                                'method' => $match[2],
                                'call' => $match[0]
                            ];
                            break;
                        case 'static_method':
                            $calls[] = [
                                'type' => 'Static Method',
                                'class' => $match[1],
                                'method' => $match[2],
                                'call' => $match[0]
                            ];
                            break;
                        case 'function_call':
                            // Filter out common PHP functions and keywords
                            if (!in_array($match[1], ['if', 'for', 'while', 'foreach', 'switch', 'return', 'echo', 'print', 'isset', 'empty', 'array', 'count'])) {
                                $calls[] = [
                                    'type' => 'Function Call',
                                    'function' => $match[1],
                                    'call' => $match[0]
                                ];
                            }
                            break;
                    }
                }
            }
        }
        
        return $calls;
    }

    private function findInternalCalls(string $source): array
    {
        $calls = [];
        
        // Find calls to other classes/services
        $patterns = [
            '/new\s+([A-Z][a-zA-Z0-9_\\\\]+)\s*\(/' => 'instantiation',
            '/([A-Z][a-zA-Z0-9_\\\\]+)::\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/' => 'static_call'
        ];
        
        foreach ($patterns as $pattern => $type) {
            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $calls[] = [
                        'type' => $type,
                        'class' => $match[1],
                        'method' => $match[2] ?? '__construct',
                        'call' => $match[0]
                    ];
                }
            }
        }
        
        return $calls;
    }

    private function findConditionalStatements(string $source): array
    {
        $conditionals = [];
        
        $patterns = [
            '/\bif\s*\(/' => 'if',
            '/\belseif\s*\(/' => 'elseif', 
            '/\belse\b/' => 'else',
            '/\bswitch\s*\(/' => 'switch',
            '/\bcase\s+/' => 'case',
            '/\?.*:/' => 'ternary'
        ];
        
        foreach ($patterns as $pattern => $type) {
            $count = preg_match_all($pattern, $source);
            if ($count > 0) {
                $conditionals[] = [
                    'type' => $type,
                    'count' => $count
                ];
            }
        }
        
        return $conditionals;
    }

    private function findLoops(string $source): array
    {
        $loops = [];
        
        $patterns = [
            '/\bfor\s*\(/' => 'for',
            '/\bforeach\s*\(/' => 'foreach',
            '/\bwhile\s*\(/' => 'while',
            '/\bdo\s*\{/' => 'do-while'
        ];
        
        foreach ($patterns as $pattern => $type) {
            $count = preg_match_all($pattern, $source);
            if ($count > 0) {
                $loops[] = [
                    'type' => $type,
                    'count' => $count
                ];
            }
        }
        
        return $loops;
    }

    private function findTryCatchBlocks(string $source): array
    {
        $blocks = [];
        
        $tryCount = preg_match_all('/\btry\s*\{/', $source);
        $catchCount = preg_match_all('/\bcatch\s*\(/', $source);
        $finallyCount = preg_match_all('/\bfinally\s*\{/', $source);
        
        if ($tryCount > 0) {
            $blocks[] = ['type' => 'try', 'count' => $tryCount];
        }
        if ($catchCount > 0) {
            $blocks[] = ['type' => 'catch', 'count' => $catchCount];
        }
        if ($finallyCount > 0) {
            $blocks[] = ['type' => 'finally', 'count' => $finallyCount];
        }
        
        return $blocks;
    }

    private function findVariableAssignments(string $source): array
    {
        $assignments = [];
        
        // Simple variable assignments
        if (preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)\s*=/', $source, $matches)) {
            $variables = array_count_values($matches[1]);
            foreach ($variables as $var => $count) {
                $assignments[] = [
                    'variable' => '$' . $var,
                    'assignments' => $count
                ];
            }
        }
        
        return $assignments;
    }

    private function calculateComplexityIndicators(string $source): array
    {
        $indicators = [];
        
        // Count various complexity indicators
        $indicators['total_lines'] = substr_count($source, "\n") + 1;
        $indicators['cyclomatic_complexity'] = $this->calculateCyclomaticComplexity($source);
        $indicators['nesting_depth'] = $this->calculateNestingDepth($source);
        $indicators['method_calls_count'] = preg_match_all('/\w+\s*\(/', $source);
        
        return $indicators;
    }

    private function calculateCyclomaticComplexity(string $source): int
    {
        // Basic cyclomatic complexity calculation
        $complexity = 1; // Base complexity
        
        $patterns = ['/\bif\b/', '/\belse\b/', '/\bfor\b/', '/\bforeach\b/', '/\bwhile\b/', '/\bcase\b/', '/\bcatch\b/'];
        
        foreach ($patterns as $pattern) {
            $complexity += preg_match_all($pattern, $source);
        }
        
        return $complexity;
    }

    private function calculateNestingDepth(string $source): int
    {
        $depth = 0;
        $maxDepth = 0;
        
        for ($i = 0; $i < strlen($source); $i++) {
            if ($source[$i] === '{') {
                $depth++;
                $maxDepth = max($maxDepth, $depth);
            } elseif ($source[$i] === '}') {
                $depth--;
            }
        }
        
        return $maxDepth;
    }

    private function displayInternalAnalysis(array $analysis): void
    {
        $this->info("\n🔬 Internal Method Analysis:");
        $this->line("═══════════════════════════════════════════════════════════");

        $targetClass = class_basename($analysis['target_class']);
        $targetMethod = $analysis['target_method'] ?: 'All Methods';
        
        $this->info("🎯 Target: {$targetClass}::{$targetMethod}");
        $this->newLine();

        if (isset($analysis['error'])) {
            $this->error("Analysis Error: " . $analysis['error']);
            return;
        }

        // Display each method's internal analysis
        foreach ($analysis['methods'] as $methodName => $methodData) {
            $this->displayMethodInternals($methodName, $methodData);
        }

        // Summary of all method calls
        if (!empty($analysis['method_calls'])) {
            $this->info("\n📞 All Method Calls Summary:");
            $callTypes = [];
            foreach ($analysis['method_calls'] as $call) {
                $callTypes[$call['type']] = ($callTypes[$call['type']] ?? 0) + 1;
            }
            
            $tableData = [];
            foreach ($callTypes as $type => $count) {
                $tableData[] = [$type, $count];
            }
            $this->table(['Call Type', 'Count'], $tableData);
        }

        // Summary of detailed operations
        if (!empty($analysis['detailed_operations'])) {
            $this->info("\n⚙️ Detailed Operations Summary:");
            $opCategories = [];
            foreach ($analysis['detailed_operations'] as $op) {
                $category = $op['category'] ?? 'unknown';
                $opCategories[$category] = ($opCategories[$category] ?? 0) + 1;
            }
            
            $tableData = [];
            foreach ($opCategories as $category => $count) {
                $tableData[] = [ucfirst(str_replace('_', ' ', $category)), $count];
            }
            $this->table(['Operation Category', 'Count'], $tableData);
        }
    }

    private function displayMethodInternals(string $methodName, array $methodData): void
    {
        $this->info("🔍 Method: {$methodName}");
        
        // Basic method info
        $this->table(['Property', 'Value'], [
            ['Visibility', $methodData['visibility']],
            ['Return Type', $methodData['return_type']],
            ['Line Count', $methodData['line_count']],
            ['Parameters', count($methodData['parameters'])]
        ]);

        // Complexity indicators
        if (!empty($methodData['complexity_indicators'])) {
            $this->info("  📊 Complexity Metrics:");
            $complexity = $methodData['complexity_indicators'];
            $this->table(['Metric', 'Value'], [
                ['Cyclomatic Complexity', $complexity['cyclomatic_complexity'] ?? 'N/A'],
                ['Nesting Depth', $complexity['nesting_depth'] ?? 'N/A'],
                ['Method Calls Count', $complexity['method_calls_count'] ?? 'N/A']
            ]);
        }

        // Method calls
        if (!empty($methodData['method_calls'])) {
            $this->info("  📞 Method Calls:");
            $tableData = [];
            foreach (array_slice($methodData['method_calls'], 0, 10) as $call) { // Limit to first 10
                $target = $call['target'] ?? $call['class'] ?? $call['variable'] ?? $call['function'] ?? 'N/A';
                $method = $call['method'] ?? $call['function'] ?? 'N/A';
                $tableData[] = [$call['type'], $target, $method];
            }
            $this->table(['Type', 'Target', 'Method/Function'], $tableData);
            
            if (count($methodData['method_calls']) > 10) {
                $this->line("  ... and " . (count($methodData['method_calls']) - 10) . " more calls");
            }
        }

        // Control flow
        $controlFlow = array_merge(
            $methodData['conditional_statements'] ?? [],
            $methodData['loops'] ?? [],
            $methodData['try_catch_blocks'] ?? []
        );
        
        if (!empty($controlFlow)) {
            $this->info("  🔀 Control Flow:");
            $tableData = [];
            foreach ($controlFlow as $flow) {
                $tableData[] = [ucfirst($flow['type']), $flow['count']];
            }
            $this->table(['Type', 'Count'], $tableData);
        }

        // Operations
        if (!empty($methodData['operations'])) {
            $this->info("  ⚙️ Operations:");
            foreach (array_slice($methodData['operations'], 0, 5) as $op) { // Limit to first 5
                $type = $op['type'] ?? $op['category'] ?? 'Operation';
                $pattern = $op['pattern'] ?? $op['model'] ?? $op['class'] ?? 'N/A';
                $this->line("    - {$type}: {$pattern}");
            }
            
            if (count($methodData['operations']) > 5) {
                $this->line("    ... and " . (count($methodData['operations']) - 5) . " more operations");
            }
        }

        $this->newLine();
    }

    private function findRealDependencies(array $flow): array
    {
        $connections = [];
        
        // Use internal analysis if available for more accurate dependency detection
        if (isset($flow['internal_analysis']) && !empty($flow['internal_analysis']['method_calls'])) {
            foreach ($flow['internal_analysis']['method_calls'] as $call) {
                // Only include calls that represent actual dependencies
                if ($this->isActualDependency($call)) {
                    $target = $call['class'] ?? $call['target'] ?? null;
                    if ($target && $this->isValidDependency($target)) {
                        $connections[] = [
                            'target' => $target,
                            'type' => 'method_call',
                            'call_type' => $call['type'],
                            'method' => $call['method'] ?? $call['function'] ?? 'unknown',
                            'evidence' => $call['call'] ?? 'unknown'  // Add evidence for debugging
                        ];
                    }
                }
            }
        }
        
        return array_unique($connections, SORT_REGULAR);
    }
    
    private function isActualDependency(array $call): bool
    {
        // Only consider calls that represent real dependencies
        $dependencyTypes = [
            'Static Method',     // Class::method()
            'Instance Method'    // $service->method() 
        ];
        
        // Exclude internal method calls within the same class
        if ($call['type'] === 'Internal Method') {
            return false;
        }
        
        // Exclude common PHP functions
        if ($call['type'] === 'Function Call') {
            $commonFunctions = [
                'array', 'count', 'isset', 'empty', 'json_encode', 'json_decode',
                'serialize', 'unserialize', 'md5', 'sha1', 'hash', 'time', 'date',
                'strpos', 'substr', 'strlen', 'trim', 'explode', 'implode',
                'response', 'redirect', 'abort', 'url', 'route', 'config',
                'env', 'collect', 'request', 'auth', 'session'
            ];
            
            $function = $call['function'] ?? $call['method'] ?? '';
            if (in_array(strtolower($function), $commonFunctions)) {
                return false;
            }
        }
        
        return in_array($call['type'], $dependencyTypes);
    }
    
    private function isValidDependency(string $className): bool
    {
        // Skip built-in PHP and Laravel classes
        $skipPrefixes = [
            'Illuminate\\',
            'Symfony\\',
            'Carbon\\',
            'Monolog\\',
            'Psr\\',
            'Laravel\\',
            'Facade\\',
            'Exception',
            'DateTime',
            'stdClass',
            'ArrayAccess',
            'Iterator',
            'Countable'
        ];
        
        foreach ($skipPrefixes as $prefix) {
            if (str_starts_with($className, $prefix)) {
                return false;
            }
        }
        
        // Must contain typical patterns for custom classes
        $validPatterns = [
            'Service', 'Action', 'Task', 'Handler', 'Repository', 
            'Query', 'Controller', 'Model', 'Job', 'Event'
        ];
        
        foreach ($validPatterns as $pattern) {
            if (str_contains($className, $pattern)) {
                // Additional validation: class should exist or be in our project
                if (class_exists($className) || $this->isProjectClass($className)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    private function isProjectClass(string $className): bool
    {
        // Check if this looks like a class from our project
        $projectNamespaces = [
            'App\\',
            'Domain\\', 
            'Application\\',
            'Smake\\',  // For your specific project
        ];
        
        foreach ($projectNamespaces as $namespace) {
            if (str_starts_with($className, $namespace)) {
                return true;
            }
        }
        
        return false;
    }
}
