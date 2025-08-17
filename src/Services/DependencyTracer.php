<?php

namespace LaravelFlowTracer\Services;

use ReflectionClass;

class DependencyTracer
{
    private CodeAnalyzer $codeAnalyzer;
    private array $visited = [];

    public function __construct()
    {
        $this->codeAnalyzer = new CodeAnalyzer();
    }

    public function forwardTrace(string $className, int $maxDepth = 3): array
    {
        $this->visited = [];
        return $this->buildForwardTree($className, 0, $maxDepth);
    }

    public function backwardTrace(string $className, int $maxDepth = 3): array
    {
        $this->visited = [];
        return $this->buildBackwardTree($className, 0, $maxDepth);
    }

    private function buildForwardTree(string $className, int $currentDepth, int $maxDepth): array
    {
        if ($currentDepth >= $maxDepth || in_array($className, $this->visited)) {
            return [];
        }

        $this->visited[] = $className;

        try {
            $analysis = $this->codeAnalyzer->analyzeClass($className);
            
            $node = [
                'class' => $className,
                'depth' => $currentDepth,
                'file' => $analysis['file'],
                'type' => $this->classifyClass($className),
                'dependencies' => [],
                'methods' => [],
                'database_operations' => [],
                'external_calls' => [],
            ];

            foreach ($analysis['methods'] as $method) {
                $methodNode = [
                    'name' => $method['name'],
                    'visibility' => $method['visibility'],
                    'dependencies' => [],
                    'operations' => $method['source_analysis'] ?? [],
                ];

                $node['methods'][] = $methodNode;

                if (isset($method['source_analysis']['database_operations'])) {
                    $node['database_operations'] = array_merge(
                        $node['database_operations'],
                        $method['source_analysis']['database_operations']
                    );
                }

                if (isset($method['source_analysis']['service_calls'])) {
                    foreach ($method['source_analysis']['service_calls'] as $serviceCall) {
                        if (isset($serviceCall['class']) && 
                            class_exists($serviceCall['class']) && 
                            !in_array($serviceCall['class'], $this->visited)) {
                            
                            $subTree = $this->buildForwardTree($serviceCall['class'], $currentDepth + 1, $maxDepth);
                            if (!empty($subTree)) {
                                $node['dependencies'][] = $subTree;
                            }
                        }
                    }
                }
            }

            foreach ($analysis['dependencies'] as $dependency) {
                if (class_exists($dependency) && 
                    !in_array($dependency, $this->visited) &&
                    $this->isRelevantDependency($dependency)) {
                    
                    $subTree = $this->buildForwardTree($dependency, $currentDepth + 1, $maxDepth);
                    if (!empty($subTree)) {
                        $node['dependencies'][] = $subTree;
                    }
                }
            }

            return $node;

        } catch (\Exception $e) {
            return [
                'class' => $className,
                'depth' => $currentDepth,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function buildBackwardTree(string $targetClass, int $currentDepth, int $maxDepth): array
    {
        if ($currentDepth >= $maxDepth) {
            return [];
        }

        $dependents = $this->findClassDependents($targetClass);
        $tree = [];

        foreach ($dependents as $dependent) {
            if (in_array($dependent['class'], $this->visited)) {
                continue;
            }

            $this->visited[] = $dependent['class'];

            $node = [
                'class' => $dependent['class'],
                'depth' => $currentDepth,
                'file' => $dependent['file'],
                'type' => $this->classifyClass($dependent['class']),
                'usage_type' => $dependent['usage_type'],
                'dependents' => [],
            ];

            if ($currentDepth < $maxDepth - 1) {
                $subTree = $this->buildBackwardTree($dependent['class'], $currentDepth + 1, $maxDepth);
                if (!empty($subTree)) {
                    $node['dependents'] = $subTree;
                }
            }

            $tree[] = $node;
        }

        return $tree;
    }

    private function findClassDependents(string $targetClass): array
    {
        $dependents = [];
        $srcPath = base_path('src');
        $targetClassName = class_basename($targetClass);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcPath)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getRealPath());
                $usageType = $this->findUsageType($content, $targetClass, $targetClassName);
                
                if ($usageType) {
                    $className = $this->extractClassNameFromFile($file->getRealPath());
                    if ($className && $className !== $targetClass) {
                        $dependents[] = [
                            'class' => $className,
                            'file' => $file->getRealPath(),
                            'usage_type' => $usageType,
                        ];
                    }
                }
            }
        }

        return $dependents;
    }

    private function findUsageType(string $content, string $fullClassName, string $className): ?string
    {
        $escapedFullClassName = preg_quote($fullClassName, '/');
        $escapedClassName = preg_quote($className, '/');

        if (preg_match("/use\s+{$escapedFullClassName};/", $content)) {
            return 'Direct Import';
        }

        if (preg_match("/new\s+{$escapedClassName}\s*\(/", $content)) {
            return 'Instantiation';
        }

        if (preg_match("/{$escapedClassName}::/", $content)) {
            return 'Static Call';
        }

        if (preg_match("/extends\s+{$escapedClassName}/", $content)) {
            return 'Inheritance';
        }

        if (preg_match("/implements\s+.*{$escapedClassName}/", $content)) {
            return 'Implementation';
        }

        if (preg_match("/:\s*{$escapedClassName}/", $content)) {
            return 'Type Hint';
        }

        return null;
    }

    private function extractClassNameFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        
        if (preg_match('/namespace\s+([^;]+);.*class\s+([A-Za-z0-9_]+)/s', $content, $matches)) {
            $namespace = trim($matches[1]);
            $className = trim($matches[2]);
            return $namespace . '\\' . $className;
        }

        return null;
    }

    private function isRelevantDependency(string $dependency): bool
    {
        $irrelevantPrefixes = [
            'Illuminate\\',
            'App\\Http\\Requests\\',
            'App\\Http\\Resources\\',
            'Carbon\\',
            'Exception',
        ];

        foreach ($irrelevantPrefixes as $prefix) {
            if (str_starts_with($dependency, $prefix)) {
                return false;
            }
        }

        return true;
    }

    private function classifyClass(string $className): string
    {
        $parts = explode('\\', $className);
        $lastPart = end($parts);

        if (str_contains($lastPart, 'Controller')) return 'Controller';
        if (str_contains($lastPart, 'Service')) return 'Service';
        if (str_contains($lastPart, 'Action')) return 'Action';
        if (str_contains($lastPart, 'Query')) return 'Query';
        if (str_contains($lastPart, 'Model')) return 'Model';
        if (str_contains($lastPart, 'Repository')) return 'Repository';
        if (str_contains($lastPart, 'Handler')) return 'Handler';
        if (str_contains($lastPart, 'Event')) return 'Event';
        if (str_contains($lastPart, 'Job')) return 'Job';
        if (str_contains($lastPart, 'Middleware')) return 'Middleware';
        if (str_contains($lastPart, 'Request')) return 'Request';
        if (str_contains($lastPart, 'Resource')) return 'Resource';

        if (str_contains($className, 'Domain\\')) return 'Domain';
        if (str_contains($className, 'Application\\')) return 'Application';
        if (str_contains($className, 'Infrastructure\\')) return 'Infrastructure';

        return 'Unknown';
    }

    public function getImpactAnalysis(string $className): array
    {
        $forwardDeps = $this->forwardTrace($className, 2);
        $backwardDeps = $this->backwardTrace($className, 2);

        return [
            'class' => $className,
            'forward_impact' => $this->countNodesInTree($forwardDeps),
            'backward_impact' => $this->countNodesInTree($backwardDeps),
            'risk_level' => $this->calculateRiskLevel($forwardDeps, $backwardDeps),
            'forward_tree' => $forwardDeps,
            'backward_tree' => $backwardDeps,
        ];
    }

    private function countNodesInTree($tree): int
    {
        if (empty($tree)) {
            return 0;
        }

        $count = 1;

        if (isset($tree['dependencies'])) {
            foreach ($tree['dependencies'] as $dep) {
                $count += $this->countNodesInTree($dep);
            }
        }

        if (isset($tree['dependents'])) {
            foreach ($tree['dependents'] as $dep) {
                $count += $this->countNodesInTree($dep);
            }
        }

        return $count;
    }

    private function calculateRiskLevel($forwardTree, $backwardTree): string
    {
        $forwardCount = $this->countNodesInTree($forwardTree);
        $backwardCount = $this->countNodesInTree($backwardTree);
        $totalImpact = $forwardCount + $backwardCount;

        if ($totalImpact <= 3) return 'Low';
        if ($totalImpact <= 8) return 'Medium';
        if ($totalImpact <= 15) return 'High';
        return 'Critical';
    }

    public function findCircularDependencies(string $rootClass): array
    {
        $visited = [];
        $recursionStack = [];
        $cycles = [];

        $this->findCycles($rootClass, $visited, $recursionStack, $cycles, []);

        return $cycles;
    }

    private function findCycles(string $className, array &$visited, array &$recursionStack, array &$cycles, array $currentPath): void
    {
        if (in_array($className, $recursionStack)) {
            $cycleStart = array_search($className, $currentPath);
            $cycle = array_slice($currentPath, $cycleStart);
            $cycle[] = $className;
            $cycles[] = $cycle;
            return;
        }

        if (in_array($className, $visited)) {
            return;
        }

        $visited[] = $className;
        $recursionStack[] = $className;
        $currentPath[] = $className;

        try {
            $analysis = $this->codeAnalyzer->analyzeClass($className);
            
            foreach ($analysis['dependencies'] as $dependency) {
                if (class_exists($dependency) && $this->isRelevantDependency($dependency)) {
                    $this->findCycles($dependency, $visited, $recursionStack, $cycles, $currentPath);
                }
            }

        } catch (\Exception $e) {
            // Skip classes that can't be analyzed
        }

        array_pop($recursionStack);
        array_pop($currentPath);
    }
}