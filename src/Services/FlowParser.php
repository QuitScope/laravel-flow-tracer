<?php

namespace LaravelFlowTracer\Services;

use Illuminate\Support\Facades\Route;
use Illuminate\Routing\Router;

class FlowParser
{
    private CodeAnalyzer $codeAnalyzer;

    public function __construct()
    {
        $this->codeAnalyzer = new CodeAnalyzer();
    }

    public function parseFullFlow(string $startPoint, string $type = 'route'): array
    {
        $flow = [
            'start_point' => $startPoint,
            'type' => $type,
            'route' => null,
            'middleware' => [],
            'controller' => null,
            'action' => null,
            'services' => [],
            'models' => [],
            'database_queries' => [],
            'events' => [],
            'jobs' => [],
            'dependencies' => [],
        ];

        switch ($type) {
            case 'route':
                $flow = $this->parseFromRoute($startPoint, $flow);
                break;
            case 'url':
                $flow = $this->parseFromUrl($startPoint, $flow);
                break;
            case 'controller':
                $flow = $this->parseFromController($startPoint, $flow);
                // Füge Information hinzu welche Controller/Routes diese Action verwenden
                if (str_contains($startPoint, 'Action')) {
                    $flow['called_from'] = $this->findActionCallers($startPoint);
                }
                break;
        }

        return $flow;
    }

    private function parseFromRoute(string $routeName, array $flow): array
    {
        $route = Route::getRoutes()->getByName($routeName);
        if (!$route) {
            throw new \Exception("Route $routeName not found");
        }

        $flow['route'] = [
            'name' => $routeName,
            'uri' => $route->uri(),
            'methods' => $route->methods(),
            'parameters' => $route->parameterNames(),
        ];

        $flow['middleware'] = $this->extractMiddleware($route);

        $action = $route->getAction();
        if (isset($action['controller'])) {
            $controllerAction = $action['controller'];
            if (str_contains($controllerAction, '@')) {
                [$controller, $method] = explode('@', $controllerAction);
            } else {
                $controller = $controllerAction;
                $method = '__invoke';
            }
            
            $flow['controller'] = $controller;
            $flow['action'] = $method;
            
            $flow = $this->analyzeControllerFlow($controller, $method, $flow);
        }

        return $flow;
    }

    private function parseFromUrl(string $url, array $flow): array
    {
        $request = \Illuminate\Http\Request::create($url, 'GET');
        
        try {
            $route = Route::getRoutes()->match($request);
            $routeName = $route->getName() ?? 'unnamed';
            return $this->parseFromRoute($routeName, $flow);
        } catch (\Exception $e) {
            throw new \Exception("No matching route found for URL $url");
        }
    }

    private function parseFromController(string $controllerAction, array $flow): array
    {
        if (str_contains($controllerAction, '@')) {
            [$controller, $method] = explode('@', $controllerAction);
        } else {
            $controller = $controllerAction;
            $method = '__invoke'; // Default method for single action controllers
            
            // Try to find the controller class and get a public method
            $controllerClass = $this->findControllerClass($controller);
            if ($controllerClass && class_exists($controllerClass)) {
                $reflection = new \ReflectionClass($controllerClass);
                $publicMethods = array_filter($reflection->getMethods(\ReflectionMethod::IS_PUBLIC), function($method) use ($reflection) {
                    return !in_array($method->getName(), ['__construct', '__destruct']) && 
                           $method->getDeclaringClass()->getName() === $reflection->getName();
                });
                
                if (!empty($publicMethods)) {
                    $method = $publicMethods[0]->getName();
                }
                $controller = $controllerClass;
            }
        }
        
        $flow['controller'] = $controller;
        $flow['action'] = $method;
        
        return $this->analyzeControllerFlow($controller, $method, $flow);
    }

    private function extractMiddleware($route): array
    {
        $middleware = [];
        $middlewareList = $route->middleware();

        foreach ($middlewareList as $middlewareName) {
            $middleware[] = [
                'name' => $middlewareName,
                'type' => $this->classifyMiddleware($middlewareName),
            ];
        }

        return $middleware;
    }

    private function classifyMiddleware(string $middleware): string
    {
        $types = [
            'auth' => 'Authentication',
            'guest' => 'Guest Only',
            'verified' => 'Email Verification',
            'throttle' => 'Rate Limiting',
            'cors' => 'CORS',
            'web' => 'Web Middleware Group',
            'api' => 'API Middleware Group',
        ];

        foreach ($types as $key => $type) {
            if (str_contains($middleware, $key)) {
                return $type;
            }
        }

        return 'Custom';
    }

    private function analyzeControllerFlow(string $controller, string $method, array $flow): array
    {
        try {
            $controllerAnalysis = $this->codeAnalyzer->analyzeClass($controller);
            
            $methodAnalysis = null;
            foreach ($controllerAnalysis['methods'] as $methodData) {
                if ($methodData['name'] === $method) {
                    $methodAnalysis = $methodData;
                    break;
                }
            }

            if ($methodAnalysis) {
                $flow = $this->extractFlowFromMethod($methodAnalysis, $flow, $controller);
                $flow = $this->findRelatedServices($controllerAnalysis, $flow);
                $flow = $this->findRelatedModels($methodAnalysis, $flow);
            }

        } catch (\Exception $e) {
            $flow['analysis_error'] = $e->getMessage();
        }

        return $flow;
    }

    private function extractFlowFromMethod(array $methodAnalysis, array $flow, string $controller): array
    {
        $sourceAnalysis = $methodAnalysis['source_analysis'];

        $flow['database_queries'] = $sourceAnalysis['database_operations'] ?? [];
        $flow['events'] = $sourceAnalysis['event_dispatches'] ?? [];
        $flow['jobs'] = $sourceAnalysis['job_dispatches'] ?? [];

        $flow['services'] = array_merge(
            $flow['services'],
            $sourceAnalysis['service_calls'] ?? []
        );

        $flow['models'] = array_merge(
            $flow['models'],
            $sourceAnalysis['model_operations'] ?? []
        );

        return $flow;
    }

    private function findRelatedServices(array $controllerAnalysis, array $flow): array
    {
        foreach ($controllerAnalysis['dependencies'] as $dependency) {
            if (str_contains($dependency, 'Service') || 
                str_contains($dependency, 'Action') ||
                str_contains($dependency, 'Query')) {
                
                try {
                    $serviceAnalysis = $this->codeAnalyzer->analyzeClass($dependency);
                    $flow['services'][] = [
                        'class' => $dependency,
                        'type' => $this->classifyService($dependency),
                        'methods' => array_column($serviceAnalysis['methods'], 'name'),
                        'file' => $serviceAnalysis['file'],
                    ];
                } catch (\Exception $e) {
                    $flow['services'][] = [
                        'class' => $dependency,
                        'type' => 'Unresolved',
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return $flow;
    }

    private function findRelatedModels(array $methodAnalysis, array $flow): array
    {
        $sourceAnalysis = $methodAnalysis['source_analysis'];
        
        foreach ($sourceAnalysis['model_operations'] ?? [] as $modelOp) {
            $modelClass = $modelOp['model'];
            if (!in_array($modelClass, array_column($flow['models'], 'class'))) {
                try {
                    $modelAnalysis = $this->codeAnalyzer->analyzeClass($modelClass);
                    $flow['models'][] = [
                        'class' => $modelClass,
                        'operations' => [$modelOp['operation']],
                        'file' => $modelAnalysis['file'],
                        'relationships' => $this->findModelRelationships($modelAnalysis),
                    ];
                } catch (\Exception $e) {
                    $flow['models'][] = [
                        'class' => $modelClass,
                        'operations' => [$modelOp['operation']],
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return $flow;
    }

    private function findModelRelationships(array $modelAnalysis): array
    {
        $relationships = [];
        
        foreach ($modelAnalysis['methods'] as $method) {
            $methodName = $method['name'];
            if (in_array($methodName, ['hasOne', 'hasMany', 'belongsTo', 'belongsToMany', 'morphTo', 'morphMany'])) {
                $relationships[] = [
                    'type' => $methodName,
                    'method' => $method['name'],
                ];
            }
        }

        return $relationships;
    }

    private function classifyService(string $service): string
    {
        if (str_contains($service, 'Action')) return 'Domain Action';
        if (str_contains($service, 'Service')) return 'Service';
        if (str_contains($service, 'Query')) return 'Query';
        if (str_contains($service, 'Repository')) return 'Repository';
        if (str_contains($service, 'Handler')) return 'Handler';
        
        return 'Unknown Service';
    }

    public function findDependencyChain(string $startClass, int $maxDepth = 3): array
    {
        $chain = [];
        $visited = [];
        
        $this->buildDependencyChain($startClass, $chain, $visited, 0, $maxDepth);
        
        return $chain;
    }

    private function buildDependencyChain(string $class, array &$chain, array &$visited, int $currentDepth, int $maxDepth): void
    {
        if ($currentDepth >= $maxDepth || in_array($class, $visited)) {
            return;
        }

        $visited[] = $class;

        try {
            $analysis = $this->codeAnalyzer->analyzeClass($class);
            
            $classNode = [
                'class' => $class,
                'depth' => $currentDepth,
                'dependencies' => [],
                'file' => $analysis['file'],
            ];

            foreach ($analysis['dependencies'] as $dependency) {
                if (class_exists($dependency) && !in_array($dependency, $visited)) {
                    $this->buildDependencyChain($dependency, $classNode['dependencies'], $visited, $currentDepth + 1, $maxDepth);
                }
            }

            $chain[] = $classNode;

        } catch (\Exception $e) {
            $chain[] = [
                'class' => $class,
                'depth' => $currentDepth,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function findRouteGroups(): array
    {
        $routes = Route::getRoutes();
        $groups = [];

        foreach ($routes as $route) {
            $prefix = $route->getPrefix() ?? '/';
            $middleware = $route->middleware();
            $groupKey = $prefix . '_' . implode('_', $middleware);

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'prefix' => $prefix,
                    'middleware' => $middleware,
                    'routes' => [],
                ];
            }

            $groups[$groupKey]['routes'][] = [
                'name' => $route->getName(),
                'uri' => $route->uri(),
                'methods' => $route->methods(),
                'action' => $route->getActionName(),
            ];
        }

        return array_values($groups);
    }

    private function findControllerClass(string $controllerName): ?string
    {
        // First try standard Laravel controller paths
        $possiblePaths = [
            "App\\Http\\Controllers\\{$controllerName}",
        ];

        // Then search in src directory structure
        $srcPath = base_path('src');
        if (is_dir($srcPath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($srcPath)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $content = file_get_contents($file->getRealPath());
                    if (preg_match("/class\s+{$controllerName}\s+/", $content)) {
                        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
                            $namespace = trim($matches[1]);
                            return $namespace . '\\' . $controllerName;
                        }
                    }
                }
            }
        }

        // Check if any of the possible paths exist
        foreach ($possiblePaths as $path) {
            if (class_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function findActionCallers(string $actionClass): array
    {
        $callers = [];
        $actionBaseName = class_basename($actionClass);
        
        // Suche in allen Routes
        $routes = Route::getRoutes();
        foreach ($routes as $route) {
            $action = $route->getActionName();
            if (str_contains($action, $actionBaseName)) {
                $callers[] = [
                    'type' => 'route',
                    'name' => $route->getName() ?? 'unnamed',
                    'uri' => $route->uri(),
                    'methods' => $route->methods(),
                    'controller' => $action
                ];
            }
        }
        
        // Suche in Controller-Dateien nach Verwendung der Action
        $finder = new \Symfony\Component\Finder\Finder();
        $searchPaths = [];
        if (is_dir(base_path('src'))) $searchPaths[] = base_path('src');
        if (is_dir(base_path('app'))) $searchPaths[] = base_path('app');
        if (is_dir(base_path('Application'))) $searchPaths[] = base_path('Application');
        
        if (empty($searchPaths)) {
            return $callers;
        }
        
        $finder->files()->in($searchPaths)->name('*.php')->contains($actionBaseName);
        
        foreach ($finder as $file) {
            try {
                $content = file_get_contents($file->getRealPath());
                
                // Prüfe ob es ein Controller ist
                if (preg_match('/class\s+(\w*Controller)\s+/', $content, $matches)) {
                    $controllerName = $matches[1];
                    
                    // Hole Namespace
                    if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatches)) {
                        $namespace = trim($nsMatches[1]);
                        $fullControllerName = $namespace . '\\' . $controllerName;
                        
                        // Prüfe ob die Action verwendet wird
                        if (preg_match("/{$actionBaseName}/", $content)) {
                            $callers[] = [
                                'type' => 'controller',
                                'class' => $fullControllerName,
                                'file' => $file->getRealPath()
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                // Ignoriere Fehler beim Dateien lesen
            }
        }
        
        return $callers;
    }
}