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
        if (preg_match_all('/new\s+([A-Z][a-zA-Z0-9_\\\\]*Service)/', $source, $matches)) {
            foreach ($matches[1] as $service) {
                $calls[] = ['type' => 'Service Instantiation', 'class' => $service];
            }
        }

        if (preg_match_all('/([A-Z][a-zA-Z0-9_\\\\]*Service)::/', $source, $matches)) {
            foreach ($matches[1] as $service) {
                $calls[] = ['type' => 'Static Service Call', 'class' => $service];
            }
        }

        return $calls;
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

    public function findClassesInDirectory(string $directory): array
    {
        $classes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getRealPath());
                if (preg_match('/namespace\s+([^;]+);.*class\s+([A-Za-z0-9_]+)/s', $content, $matches)) {
                    $namespace = trim($matches[1]);
                    $className = trim($matches[2]);
                    $fullClassName = $namespace . '\\' . $className;
                    
                    $classes[] = [
                        'class' => $fullClassName,
                        'file' => $file->getRealPath(),
                        'namespace' => $namespace,
                        'name' => $className,
                    ];
                }
            }
        }

        return $classes;
    }
}