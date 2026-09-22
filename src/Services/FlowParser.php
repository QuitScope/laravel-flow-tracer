<?php

namespace LaravelFlowTracer\Services;

use Illuminate\Support\Facades\Route;

class FlowParser
{
    private CodeAnalyzer $codeAnalyzer;

    public function __construct()
    {
        $this->codeAnalyzer = new CodeAnalyzer();
    }

    public function parseFullFlow(string $startPoint, string $type = 'route'): array
    {
        if (!in_array($type, ['route', 'url', 'controller'], true)) {
            throw new \InvalidArgumentException("Unsupported flow type: {$type}");
        }

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
                
                // Automatisch Actions und Controller-Beziehungen hinzufügen
                if (str_contains($startPoint, 'Action')) {
                    // Bei Actions: Zeige welche Controller diese Action verwenden
                    $flow['called_from'] = $this->findActionCallers($startPoint);
                } else if (str_contains($startPoint, 'Controller')) {
                    // Bei Controllern: Zeige welche Actions verwendet werden
                    $flow['uses_actions'] = $this->findControllerActions($startPoint);
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
        // Try different HTTP methods for API routes
        $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
        $lastException = null;
        
        foreach ($methods as $method) {
            try {
                $request = \Illuminate\Http\Request::create($url, $method);
                $route = Route::getRoutes()->match($request);
                
                $flow['route'] = [
                    'name' => $route->getName() ?? 'unnamed',
                    'uri' => $route->uri(),
                    'methods' => $route->methods(),
                    'parameters' => $route->parameterNames(),
                    'matched_method' => $method,
                ];

                $flow['middleware'] = $this->extractMiddleware($route);

                $action = $route->getAction();
                if (isset($action['controller'])) {
                    $controllerAction = $action['controller'];
                    if (str_contains($controllerAction, '@')) {
                        [$controller, $methodName] = explode('@', $controllerAction);
                    } else {
                        $controller = $controllerAction;
                        $methodName = '__invoke';
                    }
                    
                    $flow['controller'] = $controller;
                    $flow['action'] = $methodName;
                    
                    $flow = $this->analyzeControllerFlow($controller, $methodName, $flow);
                }

                return $flow;
                
            } catch (\Exception $e) {
                $lastException = $e;
                continue;
            }
        }
        
        throw new \Exception("No matching route found for URL $url with any HTTP method. Last error: " . $lastException->getMessage());
    }

    private function parseFromController(string $controllerAction, array $flow): array
    {
        if (str_contains($controllerAction, '@')) {
            [$controller, $method] = explode('@', $controllerAction);
        } else {
            $controller = $controllerAction;
            $method = '__invoke'; // Default method for single action controllers
        }
        
        // Resolve short class name to full qualified name
        $fullControllerClass = $this->resolveFullClassName($controller);
        if (!$fullControllerClass) {
            throw new \Exception("Controller/Action '{$controller}' not found in project");
        }
        
        $controller = $fullControllerClass;
        
        // For single action controllers/actions, try to find the actual method
        if ($method === '__invoke' && class_exists($controller)) {
            $reflection = new \ReflectionClass($controller);
            $publicMethods = array_filter($reflection->getMethods(\ReflectionMethod::IS_PUBLIC), function($methodReflection) use ($reflection) {
                return !in_array($methodReflection->getName(), ['__construct', '__destruct']) && 
                       $methodReflection->getDeclaringClass()->getName() === $reflection->getName();
            });
            
            if (!empty($publicMethods)) {
                // Prefer common action method names
                $preferredMethods = ['handle', 'execute', 'run', 'perform', '__invoke'];
                foreach ($preferredMethods as $preferred) {
                    if ($reflection->hasMethod($preferred)) {
                        $method = $preferred;
                        break;
                    }
                }
                
                // If no preferred method found, use first public method
                if ($method === '__invoke' && !$reflection->hasMethod('__invoke')) {
                    $method = $publicMethods[0]->getName();
                }
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

        // Check if any of the possible paths exist first (fastest)
        foreach ($possiblePaths as $path) {
            if (class_exists($path)) {
                return $path;
            }
        }

        // Build comprehensive search paths for complex projects
        $searchPaths = [];
        $basePath = base_path();
        
        // Add common Laravel/PHP project directories
        $commonDirs = ['src', 'app', 'Application', 'lib', 'packages'];
        foreach ($commonDirs as $dir) {
            $fullPath = $basePath . DIRECTORY_SEPARATOR . $dir;
            if (is_dir($fullPath)) {
                $searchPaths[] = $fullPath;
            }
        }

        // If no common directories found, search entire project (slower but comprehensive)
        if (empty($searchPaths)) {
            $searchPaths[] = $basePath;
        }

        // Use cached class discovery for better performance
        static $classCache = [];
        $cacheKey = md5(implode('|', $searchPaths) . '|' . $controllerName);
        
        if (isset($classCache[$cacheKey])) {
            return $classCache[$cacheKey];
        }

        foreach ($searchPaths as $searchPath) {
            $result = $this->searchControllerInPath($searchPath, $controllerName);
            if ($result) {
                $classCache[$cacheKey] = $result;
                return $result;
            }
        }

        $classCache[$cacheKey] = null;
        return null;
    }

    private function searchControllerInPath(string $path, string $controllerName): ?string
    {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    // Skip vendor directory for performance
                    if (strpos($file->getPath(), 'vendor') !== false) {
                        continue;
                    }

                    $content = file_get_contents($file->getRealPath());
                    
                    // More precise regex to match exact controller class name
                    $classPattern = "/(?:abstract\s+)?class\s+{$controllerName}(?:\s+extends|\s+implements|\s*\{)/";
                    if (preg_match($classPattern, $content)) {
                        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
                            $namespace = trim($matches[1]);
                            $fullClassName = $namespace . '\\' . $controllerName;
                            
                            // Verify the class actually exists
                            if (class_exists($fullClassName)) {
                                return $fullClassName;
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Log error but continue searching
            error_log("Error searching in path {$path}: " . $e->getMessage());
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

    private function findControllerActions(string $controllerClass): array
    {
        $actions = [];
        
        try {
            // Analysiere den Controller-Code
            $controllerAnalysis = $this->codeAnalyzer->analyzeClass($controllerClass);
            $filePath = $controllerAnalysis['file'];
            
            if (!$filePath || !file_exists($filePath)) {
                return $actions;
            }
            
            $content = file_get_contents($filePath);
            
            // Suche nach Action-Verwendungen im Controller
            // Pattern für new ActionClass(), ActionClass::handle(), etc.
            $patterns = [
                '/new\s+([A-Z][a-zA-Z0-9_\\\\]*Action)\s*\(/' => 'instantiation',
                '/([A-Z][a-zA-Z0-9_\\\\]*Action)::(handle|execute|run|perform)\s*\(/' => 'static_call',
                '/\$([a-zA-Z0-9_]+Action)\s*->(handle|execute|run|perform)\s*\(/' => 'instance_call',
                '/use\s+([A-Z][a-zA-Z0-9_\\\\]*Action);/' => 'import'
            ];
            
            foreach ($patterns as $pattern => $type) {
                if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $actionName = $match[1];
                        
                        // Bei instance_call ist $match[1] die Variable, nicht die Klasse
                        if ($type === 'instance_call') {
                            // Versuche die Klasse aus der Variable-Definition zu finden
                            $varPattern = "/\\\${$actionName}\s*=\s*new\s+([A-Z][a-zA-Z0-9_\\\\]*Action)/";
                            if (preg_match($varPattern, $content, $varMatch)) {
                                $actionName = $varMatch[1];
                            } else {
                                continue; // Skip wenn wir die Klasse nicht bestimmen können
                            }
                        }
                        
                        // Erweitere Action-Name zu vollqualifiziertem Namen wenn nötig
                        $fullActionName = $this->resolveActionClassName($actionName, $content);
                        
                        if ($fullActionName && !in_array($fullActionName, array_column($actions, 'action'))) {
                            $actions[] = [
                                'action' => $fullActionName,
                                'usage_type' => $type,
                                'method' => $match[2] ?? 'unknown'
                            ];
                        }
                    }
                }
            }
            
            // Zusätzlich: Suche in Method-Dependencies
            foreach ($controllerAnalysis['methods'] as $method) {
                if (isset($method['source_analysis']['service_calls'])) {
                    foreach ($method['source_analysis']['service_calls'] as $serviceCall) {
                        if (isset($serviceCall['class']) && str_contains($serviceCall['class'], 'Action')) {
                            $actions[] = [
                                'action' => $serviceCall['class'],
                                'usage_type' => 'dependency_injection',
                                'method' => $method['name']
                            ];
                        }
                    }
                }
            }
            
        } catch (\Exception $e) {
            error_log("Error finding actions for controller {$controllerClass}: " . $e->getMessage());
        }
        
        return array_unique($actions, SORT_REGULAR);
    }
    
    private function resolveActionClassName(string $actionName, string $content): ?string
    {
        // Wenn bereits vollqualifiziert
        if (str_contains($actionName, '\\')) {
            return $actionName;
        }
        
        // Suche nach use-Statement
        $usePattern = "/use\s+([A-Za-z0-9_\\\\]+\\\\{$actionName});/";
        if (preg_match($usePattern, $content, $matches)) {
            return $matches[1];
        }
        
        // Suche nach namespace und konstruiere vollqualifizierten Namen
        if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatches)) {
            $namespace = trim($nsMatches[1]);
            $possibleActionClass = $namespace . '\\' . $actionName;
            
            // Prüfe ob die Klasse existiert
            if (class_exists($possibleActionClass)) {
                return $possibleActionClass;
            }
        }
        
        // Versuche mit Standard-Action-Pfaden
        $commonActionPaths = [
            "App\\Actions\\{$actionName}",
            "Domain\\Actions\\{$actionName}",
            "Application\\Actions\\{$actionName}",
        ];
        
        foreach ($commonActionPaths as $path) {
            if (class_exists($path)) {
                return $path;
            }
        }
        
        return null;
    }

    private function resolveFullClassName(string $className): ?string
    {
        // If already fully qualified, return as-is
        if (str_contains($className, '\\') && class_exists($className)) {
            return $className;
        }
        
        // Use cached class discovery for better performance
        static $classCache = [];
        if (isset($classCache[$className])) {
            return $classCache[$className];
        }
        
        // Try with findControllerClass method (existing logic)
        $foundClass = $this->findControllerClass($className);
        if ($foundClass) {
            $classCache[$className] = $foundClass;
            return $foundClass;
        }
        
        // Enhanced search: Look for the class in the entire project
        $foundClass = $this->searchClassInProject($className);
        if ($foundClass) {
            $classCache[$className] = $foundClass;
            return $foundClass;
        }
        
        $classCache[$className] = null;
        return null;
    }
    
    private function searchClassInProject(string $className): ?string
    {
        // Build comprehensive search paths
        $basePath = base_path();
        $searchPaths = [];
        
        // Add common Laravel/PHP project directories
        $commonDirs = ['src', 'app', 'Application', 'lib', 'packages', 'domain', 'Domain'];
        foreach ($commonDirs as $dir) {
            $fullPath = $basePath . DIRECTORY_SEPARATOR . $dir;
            if (is_dir($fullPath)) {
                $searchPaths[] = $fullPath;
            }
        }
        
        // If no common directories found, search entire project (slower but comprehensive)
        if (empty($searchPaths)) {
            $searchPaths[] = $basePath;
        }
        
        foreach ($searchPaths as $searchPath) {
            $result = $this->searchClassInPath($searchPath, $className);
            if ($result) {
                return $result;
            }
        }
        
        return null;
    }
    
    private function searchClassInPath(string $path, string $className): ?string
    {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    // Skip vendor directory for performance
                    if (strpos($file->getPath(), 'vendor') !== false) {
                        continue;
                    }

                    $content = file_get_contents($file->getRealPath());
                    
                    // More comprehensive regex to match class definitions
                    $classPatterns = [
                        "/(?:abstract\s+)?class\s+{$className}(?:\s+extends|\s+implements|\s*\{)/",
                        "/(?:final\s+)?class\s+{$className}(?:\s+extends|\s+implements|\s*\{)/",
                        "/interface\s+{$className}(?:\s+extends|\s*\{)/",
                        "/trait\s+{$className}(?:\s*\{)/"
                    ];
                    
                    foreach ($classPatterns as $pattern) {
                        if (preg_match($pattern, $content)) {
                            if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
                                $namespace = trim($matches[1]);
                                $fullClassName = $namespace . '\\' . $className;
                                
                                // Verify the class actually exists and can be loaded
                                if (class_exists($fullClassName) || interface_exists($fullClassName) || trait_exists($fullClassName)) {
                                    return $fullClassName;
                                }
                            }
                            break; // Found class definition, no need to check other patterns
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Log error but continue searching
            error_log("Error searching for class {$className} in path {$path}: " . $e->getMessage());
        }

        return null;
    }
}
