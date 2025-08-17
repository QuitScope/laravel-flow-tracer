<?php

namespace LaravelFlowTracer\Services;

use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
class CodeAnalyzer
{
    public function __construct()
    {
        // Simple analyzer without PhpParser dependency
    }

    public function analyzeClass(string $className): array
    {
        if (!class_exists($className)) {
            throw new \Exception("Class $className not found");
        }

        $reflection = new ReflectionClass($className);
        $filePath = $reflection->getFileName();
        
        $analysis = [
            'class' => $className,
            'file' => $filePath,
            'namespace' => $reflection->getNamespaceName(),
            'methods' => [],
            'properties' => [],
            'dependencies' => [],
            'implements' => $reflection->getInterfaceNames(),
            'extends' => $reflection->getParentClass() ? $reflection->getParentClass()->getName() : null,
            'traits' => $reflection->getTraitNames(),
        ];

        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === $className) {
                $analysis['methods'][] = $this->analyzeMethod($method);
            }
        }

        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() === $className) {
                $propertyType = 'mixed';
                if ($property->getType()) {
                    $reflectionType = $property->getType();
                    if ($reflectionType instanceof \ReflectionNamedType) {
                        $propertyType = $reflectionType->getName();
                    } elseif ($reflectionType instanceof \ReflectionUnionType) {
                        $types = [];
                        foreach ($reflectionType->getTypes() as $unionType) {
                            $types[] = $unionType->getName();
                        }
                        $propertyType = implode('|', $types);
                    }
                }

                $analysis['properties'][] = [
                    'name' => $property->getName(),
                    'type' => $propertyType,
                    'visibility' => $this->getPropertyVisibility($property),
                ];
            }
        }

        $analysis['dependencies'] = $this->extractDependencies($filePath);

        return $analysis;
    }

    public function analyzeMethod(ReflectionMethod $method): array
    {
        $returnType = 'mixed';
        if ($method->getReturnType()) {
            $reflectionType = $method->getReturnType();
            if ($reflectionType instanceof \ReflectionNamedType) {
                $returnType = $reflectionType->getName();
            } elseif ($reflectionType instanceof \ReflectionUnionType) {
                $types = [];
                foreach ($reflectionType->getTypes() as $unionType) {
                    $types[] = $unionType->getName();
                }
                $returnType = implode('|', $types);
            }
        }

        $methodData = [
            'name' => $method->getName(),
            'visibility' => $this->getMethodVisibility($method),
            'parameters' => [],
            'return_type' => $returnType,
            'line_start' => $method->getStartLine(),
            'line_end' => $method->getEndLine(),
            'source_analysis' => [],
        ];

        foreach ($method->getParameters() as $parameter) {
            $methodData['parameters'][] = $this->analyzeParameter($parameter);
        }

        $source = $this->getMethodSource($method);
        $methodData['source_analysis'] = $this->analyzeSourceCode($source);

        return $methodData;
    }

    private function analyzeParameter(ReflectionParameter $parameter): array
    {
        $type = 'mixed';
        if ($parameter->getType()) {
            $reflectionType = $parameter->getType();
            if ($reflectionType instanceof \ReflectionNamedType) {
                $type = $reflectionType->getName();
            } elseif ($reflectionType instanceof \ReflectionUnionType) {
                $types = [];
                foreach ($reflectionType->getTypes() as $unionType) {
                    $types[] = $unionType->getName();
                }
                $type = implode('|', $types);
            }
        }

        return [
            'name' => $parameter->getName(),
            'type' => $type,
            'default' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
            'nullable' => $parameter->allowsNull(),
        ];
    }

    private function getMethodSource(ReflectionMethod $method): string
    {
        $filePath = $method->getFileName();
        
        // Check if file path is valid and file exists
        if (!$filePath || !is_file($filePath)) {
            return '// Source not available (internal or generated method)';
        }
        
        try {
            $lines = file($filePath);
            $startLine = $method->getStartLine() - 1;
            $endLine = $method->getEndLine() - 1;
            
            if ($startLine < 0 || $endLine >= count($lines)) {
                return '// Source lines out of range';
            }
            
            return implode('', array_slice($lines, $startLine, $endLine - $startLine + 1));
        } catch (\Exception $e) {
            return '// Error reading source: ' . $e->getMessage();
        }
    }

    private function analyzeSourceCode(string $source): array
    {
        $analysis = [
            'database_operations' => [],
            'service_calls' => [],
            'model_operations' => [],
            'event_dispatches' => [],
            'job_dispatches' => [],
            'api_calls' => [],
            'cache_operations' => [],
            'file_operations' => [],
            'validation' => [],
        ];

        $analysis['database_operations'] = $this->findDatabaseOperations($source);
        $analysis['service_calls'] = $this->findServiceCalls($source);
        $analysis['model_operations'] = $this->findModelOperations($source);
        $analysis['event_dispatches'] = $this->findEventDispatches($source);
        $analysis['job_dispatches'] = $this->findJobDispatches($source);
        $analysis['api_calls'] = $this->findApiCalls($source);
        $analysis['cache_operations'] = $this->findCacheOperations($source);
        $analysis['file_operations'] = $this->findFileOperations($source);
        $analysis['validation'] = $this->findValidation($source);

        return $analysis;
    }

    private function findDatabaseOperations(string $source): array
    {
        $operations = [];
        $patterns = [
            '/DB::/' => 'DB Facade',
            '/->where\s*\(/' => 'Where Query',
            '/->find\s*\(/' => 'Find Query',
            '/->create\s*\(/' => 'Create Operation',
            '/->update\s*\(/' => 'Update Operation',
            '/->delete\s*\(/' => 'Delete Operation',
            '/->save\s*\(/' => 'Save Operation',
            '/Query::/' => 'Query Builder',
            '/->query\s*\(/' => 'Raw Query',
        ];

        foreach ($patterns as $pattern => $type) {
            if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $operations[] = [
                        'type' => $type,
                        'pattern' => $match[0],
                        'position' => $match[1],
                    ];
                }
            }
        }

        return $operations;
    }

    private function findServiceCalls(string $source): array
    {
        $calls = [];
        
        // More precise patterns that require proper PHP context
        $patterns = [
            // new ServiceClass() or new Namespace\ServiceClass()
            '/new\s+([A-Z][a-zA-Z0-9_\\\\]*(?:Service|Action|Task|Handler|Repository|Query))\s*\(/' => 'Service Instantiation',
            // ServiceClass::method() or Namespace\ServiceClass::method()
            '/([A-Z][a-zA-Z0-9_\\\\]*(?:Service|Action|Task|Handler|Repository|Query))::\s*[a-zA-Z_][a-zA-Z0-9_]*\s*\(/' => 'Static Service Call',
            // $this->serviceProperty or $this->serviceMethod()
            '/\$this\s*->\s*([a-zA-Z_][a-zA-Z0-9_]*(?:Service|Action|Task|Handler|Repository|Query))\s*[\(\-]/' => 'Property/Method Access',
            // $variable->method() where $variable could be a service
            '/\$([a-zA-Z_][a-zA-Z0-9_]*)\s*->\s*[a-zA-Z_][a-zA-Z0-9_]*\s*\(/' => 'Instance Method Call'
        ];

        foreach ($patterns as $pattern => $type) {
            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    // Verify this is not in a comment or string
                    if (!$this->isInCommentOrString($source, $match[0])) {
                        $calls[] = [
                            'type' => $type, 
                            'class' => $match[1],
                            'context' => trim(substr($match[0], 0, 50))
                        ];
                    }
                }
            }
        }

        return array_unique($calls, SORT_REGULAR);
    }

    private function findModelOperations(string $source): array
    {
        $operations = [];
        if (preg_match_all('/([A-Z][a-zA-Z0-9_\\\\]*)::(find|create|update|delete|where|all)/', $source, $matches)) {
            for ($i = 0; $i < count($matches[0]); $i++) {
                $operations[] = [
                    'model' => $matches[1][$i],
                    'operation' => $matches[2][$i],
                ];
            }
        }

        return $operations;
    }

    private function findEventDispatches(string $source): array
    {
        $events = [];
        if (preg_match_all('/(event\s*\(|Event::dispatch)/', $source, $matches)) {
            $events[] = ['type' => 'Event Dispatch', 'count' => count($matches[0])];
        }

        return $events;
    }

    private function findJobDispatches(string $source): array
    {
        $jobs = [];
        if (preg_match_all('/(dispatch\s*\(|Job::dispatch)/', $source, $matches)) {
            $jobs[] = ['type' => 'Job Dispatch', 'count' => count($matches[0])];
        }

        return $jobs;
    }

    private function findApiCalls(string $source): array
    {
        $calls = [];
        $patterns = [
            '/Http::/' => 'HTTP Client',
            '/curl_/' => 'cURL',
            '/file_get_contents/' => 'File Get Contents',
            '/->get\s*\(/' => 'HTTP GET',
            '/->post\s*\(/' => 'HTTP POST',
        ];

        foreach ($patterns as $pattern => $type) {
            if (preg_match_all($pattern, $source, $matches)) {
                $calls[] = ['type' => $type, 'count' => count($matches[0])];
            }
        }

        return $calls;
    }

    private function findCacheOperations(string $source): array
    {
        $operations = [];
        if (preg_match_all('/(Cache::|->cache\(|->remember\()/', $source, $matches)) {
            $operations[] = ['type' => 'Cache Operations', 'count' => count($matches[0])];
        }

        return $operations;
    }

    private function findFileOperations(string $source): array
    {
        $operations = [];
        $patterns = [
            '/Storage::/' => 'Storage Facade',
            '/file_/' => 'File Functions',
            '/fopen|fread|fwrite/' => 'File Handles',
        ];

        foreach ($patterns as $pattern => $type) {
            if (preg_match_all($pattern, $source, $matches)) {
                $operations[] = ['type' => $type, 'count' => count($matches[0])];
            }
        }

        return $operations;
    }

    private function findValidation(string $source): array
    {
        $validation = [];
        if (preg_match_all('/(validate\s*\(|Validator::)/', $source, $matches)) {
            $validation[] = ['type' => 'Validation', 'count' => count($matches[0])];
        }

        return $validation;
    }

    private function extractDependencies(string $filePath): array
    {
        // Check if file path is valid and file exists
        if (!$filePath || !is_file($filePath)) {
            return [];
        }
        
        try {
            $content = file_get_contents($filePath);
            $dependencies = [];

            if (preg_match_all('/use\s+([A-Za-z0-9_\\\\]+);/', $content, $matches)) {
                $dependencies = array_unique($matches[1]);
            }

            return $dependencies;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getMethodVisibility(ReflectionMethod $method): string
    {
        if ($method->isPublic()) return 'public';
        if ($method->isProtected()) return 'protected';
        if ($method->isPrivate()) return 'private';
        return 'unknown';
    }

    private function getPropertyVisibility(\ReflectionProperty $property): string
    {
        if ($property->isPublic()) return 'public';
        if ($property->isProtected()) return 'protected';
        if ($property->isPrivate()) return 'private';
        return 'unknown';
    }

    public function findClassesInDirectory(string $directory, int $maxFiles = 1000): array
    {
        $classes = [];
        $fileCount = 0;
        
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                // Limit file processing for large codebases
                if ($fileCount >= $maxFiles) {
                    break;
                }

                if ($file->isFile() && $file->getExtension() === 'php') {
                    // Skip vendor directory and other common non-source directories
                    $relativePath = str_replace($directory, '', $file->getPath());
                    if (preg_match('/\/(vendor|node_modules|storage|bootstrap\/cache)/', $relativePath)) {
                        continue;
                    }

                    $fileCount++;
                    $content = file_get_contents($file->getRealPath());
                    
                    // Enhanced regex to handle multiple classes in one file and abstract classes
                    if (preg_match_all('/namespace\s+([^;]+);.*?(?:abstract\s+)?(?:class|interface|trait)\s+([A-Za-z0-9_]+)/s', $content, $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $match) {
                            $namespace = trim($match[1]);
                            $className = trim($match[2]);
                            $fullClassName = $namespace . '\\' . $className;
                            
                            // Check if it's a controller, service, or action
                            $type = 'Unknown';
                            if (str_contains($className, 'Controller')) $type = 'Controller';
                            elseif (str_contains($className, 'Service')) $type = 'Service';
                            elseif (str_contains($className, 'Action')) $type = 'Action';
                            elseif (str_contains($className, 'Repository')) $type = 'Repository';
                            elseif (str_contains($className, 'Model')) $type = 'Model';
                            elseif (str_contains($className, 'Middleware')) $type = 'Middleware';
                            
                            $classes[] = [
                                'class' => $fullClassName,
                                'file' => $file->getRealPath(),
                                'namespace' => $namespace,
                                'name' => $className,
                                'type' => $type,
                                'size' => $file->getSize(),
                            ];
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("Error scanning directory {$directory}: " . $e->getMessage());
        }

        // Sort by type and name for better organization
        usort($classes, function($a, $b) {
            if ($a['type'] === $b['type']) {
                return strcmp($a['name'], $b['name']);
            }
            return strcmp($a['type'], $b['type']);
        });

        return $classes;
    }

    public function getProjectStatistics(string $projectPath): array
    {
        $stats = [
            'total_classes' => 0,
            'controllers' => 0,
            'services' => 0,
            'models' => 0,
            'actions' => 0,
            'largest_files' => [],
            'deepest_namespaces' => [],
        ];

        $classes = $this->findClassesInDirectory($projectPath, 2000);
        $stats['total_classes'] = count($classes);

        foreach ($classes as $class) {
            switch ($class['type']) {
                case 'Controller':
                    $stats['controllers']++;
                    break;
                case 'Service':
                    $stats['services']++;
                    break;
                case 'Model':
                    $stats['models']++;
                    break;
                case 'Action':
                    $stats['actions']++;
                    break;
            }

            // Track large files
            if ($class['size'] > 10000) { // > 10KB
                $stats['largest_files'][] = [
                    'class' => $class['class'],
                    'size' => $class['size'],
                    'file' => $class['file'],
                ];
            }

            // Track deep namespaces
            $namespaceDepth = substr_count($class['namespace'], '\\');
            if ($namespaceDepth > 4) {
                $stats['deepest_namespaces'][] = [
                    'namespace' => $class['namespace'],
                    'depth' => $namespaceDepth,
                    'class' => $class['name'],
                ];
            }
        }

        // Sort and limit results
        usort($stats['largest_files'], fn($a, $b) => $b['size'] <=> $a['size']);
        $stats['largest_files'] = array_slice($stats['largest_files'], 0, 10);

        usort($stats['deepest_namespaces'], fn($a, $b) => $b['depth'] <=> $a['depth']);
        $stats['deepest_namespaces'] = array_slice($stats['deepest_namespaces'], 0, 10);

        return $stats;
    }

    private function isInCommentOrString(string $source, string $needle): bool
    {
        $position = strpos($source, $needle);
        if ($position === false) {
            return false;
        }

        // Check if we're inside a string literal
        $beforeText = substr($source, 0, $position);
        
        // Count unescaped quotes before this position
        $singleQuotes = substr_count($beforeText, "'") - substr_count($beforeText, "\\'");
        $doubleQuotes = substr_count($beforeText, '"') - substr_count($beforeText, '\\"');
        
        // If odd number of quotes, we're inside a string
        if (($singleQuotes % 2) === 1 || ($doubleQuotes % 2) === 1) {
            return true;
        }

        // Check if we're inside a comment
        $lines = explode("\n", $beforeText);
        $currentLine = end($lines);
        
        // Single line comment
        if (strpos($currentLine, '//') !== false) {
            $commentPos = strpos($currentLine, '//');
            $needlePos = strlen($beforeText) - strlen($currentLine) + strpos($source . "\n", $needle) - strlen($beforeText);
            if ($needlePos > $commentPos) {
                return true;
            }
        }
        
        // Multi-line comment (basic check)
        $openComment = strrpos($beforeText, '/*');
        $closeComment = strrpos($beforeText, '*/');
        
        if ($openComment !== false && ($closeComment === false || $openComment > $closeComment)) {
            return true;
        }

        return false;
    }
}