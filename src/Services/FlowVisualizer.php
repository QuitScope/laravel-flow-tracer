<?php

namespace LaravelFlowTracer\Services;

use Symfony\Component\Process\Process;

class FlowVisualizer
{
    public function exportFlowPng(array $flow, string $outputPath): string
    {
        $dotContent = $this->generateDotContent($flow);
        
        $outputFile = $this->ensureOutputPath($outputPath, 'png');
        
        $this->generateImageWithGraphviz($dotContent, $outputFile);
        
        return $outputFile;
    }

    public function exportFlow(array $flow, string $outputPath, string $format = 'png'): string
    {
        $dotContent = $this->generateDotContent($flow);
        
        $outputFile = $this->ensureOutputPath($outputPath, $format);
        
        $this->generateImage($dotContent, $outputFile, $format);
        
        return $outputFile;
    }

    private function generateDotContent(array $flow): string
    {
        $dot = "digraph FlowTrace {\n";
        $dot .= "    rankdir=TB;\n";
        $dot .= "    bgcolor=\"white\";\n";
        $dot .= "    node [shape=box, style=\"filled,rounded\", fontname=\"Arial\", fontsize=14, width=3.0, height=1.5];\n";
        $dot .= "    edge [color=\"#6c757d\", fontname=\"Arial\", fontsize=12, penwidth=2];\n";
        $dot .= "    graph [fontname=\"Arial\", fontsize=18, pad=1.0];\n";
        $dot .= "    overlap=false;\n";
        $dot .= "    splines=true;\n\n";

        $allNodes = [];
        $allConnections = [];
        
        // Generate title
        $title = $this->escapeDotLabel($this->getFlowTitle($flow));
        $dot .= "    label=\"{$title}\";\n";
        $dot .= "    labelloc=\"t\";\n\n";

        // Collect all nodes
        $nodeId = 0;
        $lastLevelNodes = [];
        
        // Called From (zeige welche Controller/Routes diese Action aufrufen)
        if (isset($flow['called_from']) && !empty($flow['called_from'])) {
            $currentLevelNodes = [];
            foreach ($flow['called_from'] as $caller) {
                $nodeKey = "node{$nodeId}";
                if ($caller['type'] === 'route') {
                    $name = $this->escapeDotLabel((string) ($caller['name'] ?? 'unnamed'));
                    $uri = $this->escapeDotLabel((string) ($caller['uri'] ?? ''));
                    $dot .= "    {$nodeKey} [label=\"📍 Called From Route\\n{$name}\\n{$uri}\", fillcolor=\"#17a2b8\", fontcolor=\"white\"];\n";
                } else {
                    $callerName = class_basename($caller['class']);
                    $callerName = $this->escapeDotLabel($callerName);
                    $dot .= "    {$nodeKey} [label=\"📍 Called From\\n{$callerName}\", fillcolor=\"#17a2b8\", fontcolor=\"white\"];\n";
                }
                $currentLevelNodes[] = $nodeKey;
                $nodeId++;
            }
            $lastLevelNodes = $currentLevelNodes;
        }

        // Route
        if (isset($flow['route'])) {
            $routeName = $this->escapeDotLabel((string) ($flow['route']['name'] ?? 'unnamed'));
            $routeUri = $this->escapeDotLabel((string) ($flow['route']['uri'] ?? ''));
            $routeMethods = $this->escapeDotLabel(implode(', ', $flow['route']['methods'] ?? []));
            
            $nodeKey = "node{$nodeId}";
            $dot .= "    {$nodeKey} [label=\"🔗 Route\\n{$routeName}\\n{$routeUri}\\n[{$routeMethods}]\", fillcolor=\"#3498db\", fontcolor=\"white\"];\n";
            $lastLevelNodes = [$nodeKey];
            $nodeId++;
        }

        // Middleware
        if (!empty($flow['middleware'])) {
            $currentLevelNodes = [];
            foreach ($flow['middleware'] as $middleware) {
                $nodeKey = "node{$nodeId}";
                $name = $this->escapeDotLabel((string) ($middleware['name'] ?? 'Middleware'));
                $type = $this->escapeDotLabel((string) ($middleware['type'] ?? 'Custom'));
                $dot .= "    {$nodeKey} [label=\"🛡️ {$name}\\n({$type})\", fillcolor=\"#e67e22\", fontcolor=\"white\"];\n";
                $currentLevelNodes[] = $nodeKey;
                $nodeId++;
            }
            
            // Connect previous level to current level
            foreach ($lastLevelNodes as $prevNode) {
                foreach ($currentLevelNodes as $currNode) {
                    $dot .= "    {$prevNode} -> {$currNode};\n";
                }
            }
            $lastLevelNodes = $currentLevelNodes;
        }

        // Controller/Action
        if (isset($flow['controller'])) {
            $controllerName = $this->escapeDotLabel(class_basename($flow['controller']));
            $action = $this->escapeDotLabel((string) ($flow['action'] ?? '__invoke'));
            
            $nodeKey = "node{$nodeId}";
            $label = "";
            
            // Add internal analysis to label if available
            if (isset($flow['internal_analysis']) && !empty($flow['internal_analysis']['methods'])) {
                $internalInfo = $this->generateInternalInfo($flow['internal_analysis']);
                if (str_contains($flow['controller'], 'Actions\\') || str_contains($flow['controller'], 'Action')) {
                    $label = "⚡ Action\\n{$controllerName}\\nMethod: {$action}\\n{$internalInfo}";
                } else {
                    $label = "🎯 Controller\\n{$controllerName}\\nAction: {$action}\\n{$internalInfo}";
                }
            } else {
                if (str_contains($flow['controller'], 'Actions\\') || str_contains($flow['controller'], 'Action')) {
                    $label = "⚡ Action\\n{$controllerName}\\nMethod: {$action}";
                } else {
                    $label = "🎯 Controller\\n{$controllerName}\\nAction: {$action}";
                }
            }
            
            if (str_contains($flow['controller'], 'Actions\\') || str_contains($flow['controller'], 'Action')) {
                $dot .= "    {$nodeKey} [label=\"{$label}\", fillcolor=\"#8e44ad\", fontcolor=\"white\", height=2.5];\n";
            } else {
                $dot .= "    {$nodeKey} [label=\"{$label}\", fillcolor=\"#27ae60\", fontcolor=\"white\", height=2.5];\n";
            }
            
            // Connect from previous level
            foreach ($lastLevelNodes as $prevNode) {
                $dot .= "    {$prevNode} -> {$nodeKey};\n";
            }
            $lastLevelNodes = [$nodeKey];
            $nodeId++;
        }

        // Services
        if (!empty($flow['services'])) {
            $currentLevelNodes = [];
            foreach ($flow['services'] as $service) {
                $serviceName = $this->escapeDotLabel(isset($service['class']) ? class_basename($service['class']) : ($service['name'] ?? 'Service'));
                $serviceType = $this->escapeDotLabel($service['type'] ?? 'Service');
                
                $nodeKey = "node{$nodeId}";
                $dot .= "    {$nodeKey} [label=\"⚙️ {$serviceType}\\n{$serviceName}\", fillcolor=\"#f39c12\", fontcolor=\"white\"];\n";
                $currentLevelNodes[] = $nodeKey;
                $nodeId++;
            }
            
            // Connect from previous level
            foreach ($lastLevelNodes as $prevNode) {
                foreach ($currentLevelNodes as $currNode) {
                    $dot .= "    {$prevNode} -> {$currNode};\n";
                }
            }
            $lastLevelNodes = $currentLevelNodes;
        }

        // Models
        if (!empty($flow['models'])) {
            $currentLevelNodes = [];
            foreach ($flow['models'] as $model) {
                $modelName = $this->escapeDotLabel(isset($model['class']) ? class_basename($model['class']) : ($model['model'] ?? 'Model'));
                $operations = isset($model['operations']) ? $this->escapeDotLabel(implode(', ', array_slice($model['operations'], 0, 2))) : '';
                
                $nodeKey = "node{$nodeId}";
                $label = "📊 Model\\n{$modelName}";
                if ($operations) {
                    $label .= "\\n{$operations}";
                }
                $dot .= "    {$nodeKey} [label=\"{$label}\", fillcolor=\"#e91e63\", fontcolor=\"white\"];\n";
                $currentLevelNodes[] = $nodeKey;
                $nodeId++;
            }
            
            // Connect from previous level
            foreach ($lastLevelNodes as $prevNode) {
                foreach ($currentLevelNodes as $currNode) {
                    $dot .= "    {$prevNode} -> {$currNode};\n";
                }
            }
            $lastLevelNodes = $currentLevelNodes;
        }

        // Add internal method calls if available
        if (isset($flow['internal_analysis']) && !empty($flow['internal_analysis']['method_calls'])) {
            $dot .= $this->addInternalMethodNodes($flow['internal_analysis'], $lastLevelNodes, $nodeId);
        }

        // Add deep trace levels if available
        if (isset($flow['deep_trace']) && !empty($flow['deep_trace']['levels'])) {
            $dot .= $this->addDeepTraceNodes($flow['deep_trace'], $lastLevelNodes, $nodeId);
        }

        $dot .= "}\n";
        return $dot;
    }

    private function escapeDotLabel(string $value): string
    {
        return str_replace(["\\", '"', "\r", "\n"], ["\\\\", '\\"', '', '\\n'], $value);
    }

    private function generateDependencyNodes(array $dependencies, int &$nodeCounter): array
    {
        $nodes = [];
        $connections = [];

        $this->processDependencyNode($dependencies, $nodeCounter, $nodes, $connections, null);

        return [
            'nodes' => $nodes,
            'connections' => $connections,
            'counter' => $nodeCounter
        ];
    }

    private function processDependencyNode($node, int &$nodeCounter, array &$nodes, array &$connections, ?string $parentId): ?string
    {
        if (empty($node) || !is_array($node)) {
            return null;
        }

        if (isset($node['class'])) {
            $nodeId = 'dep_' . $nodeCounter++;
            $className = class_basename($node['class']);
            $type = $node['type'] ?? 'Unknown';
            
            $nodes[$nodeId] = [
                'label' => "{$type}: {$className}",
                'type' => strtolower($type)
            ];

            if ($parentId) {
                $connections[] = ['from' => $parentId, 'to' => $nodeId];
            }

            // Process child dependencies
            if (isset($node['dependencies'])) {
                foreach ($node['dependencies'] as $childNode) {
                    $this->processDependencyNode($childNode, $nodeCounter, $nodes, $connections, $nodeId);
                }
            }

            return $nodeId;
        } else {
            // Process array of nodes
            $lastNodeId = $parentId;
            foreach ($node as $childNode) {
                $childNodeId = $this->processDependencyNode($childNode, $nodeCounter, $nodes, $connections, $lastNodeId);
                if ($childNodeId) {
                    $lastNodeId = $childNodeId;
                }
            }
            return $lastNodeId;
        }
    }

    private function getFlowTitle(array $flow): string
    {
        if (isset($flow['route']['name'])) {
            return "Flow Trace: Route " . $flow['route']['name'];
        }
        
        if (isset($flow['controller'])) {
            $controllerName = class_basename($flow['controller']);
            return "Flow Trace: {$controllerName}";
        }
        
        return "Laravel Flow Trace";
    }

    private function ensureOutputPath(string $outputPath, string $format): string
    {
        if (!pathinfo($outputPath, PATHINFO_EXTENSION)) {
            $outputPath .= '.' . $format;
        }

        $directory = dirname($outputPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $outputPath;
    }

    private function generateImageWithGraphviz(string $dotContent, string $outputFile): void
    {
        $this->generateImage($dotContent, $outputFile, 'png');
    }

    private function generateImage(string $dotContent, string $outputFile, string $format): void
    {
        // Get dot command from config or auto-detect
        $dotCommand = config('flow-tracer.graphviz.dot_path');
        if (!$dotCommand) {
            // Auto-detect based on OS
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $dotCommand = 'C:\Program Files\Graphviz\bin\dot.exe';
            } else {
                $dotCommand = 'dot';
            }
        }
        
        // Get DPI and size from config
        $dpi = config('flow-tracer.graphviz.dpi', 300);
        $size = config('flow-tracer.graphviz.size');
        
        $args = [$dotCommand, "-T{$format}", "-Gdpi={$dpi}"];
        if ($size) {
            $args[] = "-Gsize={$size}";
        }
        $args[] = '-o';
        $args[] = $outputFile;
        
        $process = new Process($args);
        $process->setInput($dotContent);
        $process->mustRun();
    }

    public function generateMermaidDiagram(array $flow): string
    {
        $mermaid = "graph TD\n";
        
        $nodeCounter = 0;
        $nodes = [];

        // Route node
        if (isset($flow['route'])) {
            $routeId = 'R' . $nodeCounter++;
            $routeName = $flow['route']['name'] ?? 'unnamed';
            $mermaid .= "    {$routeId}[\"🔗 Route: {$routeName}\"]\n";
            $nodes['route'] = $routeId;
        }

        // Middleware nodes
        if (!empty($flow['middleware'])) {
            foreach ($flow['middleware'] as $i => $middleware) {
                $middlewareId = 'M' . $nodeCounter++;
                $mermaid .= "    {$middlewareId}[\"🛡️ {$middleware['name']}\"]\n";
                $nodes['middleware'][] = $middlewareId;
            }
        }

        // Controller node
        if (isset($flow['controller'])) {
            $controllerId = 'C' . $nodeCounter++;
            $controllerName = class_basename($flow['controller']);
            $action = $flow['action'] ?? '__invoke';
            $mermaid .= "    {$controllerId}[\"🎯 {$controllerName}::{$action}\"]\n";
            $nodes['controller'] = $controllerId;
        }

        // Service nodes
        if (!empty($flow['services'])) {
            foreach ($flow['services'] as $service) {
                $serviceId = 'S' . $nodeCounter++;
                $serviceName = class_basename($service['class']);
                $serviceType = $service['type'] ?? 'Service';
                $mermaid .= "    {$serviceId}[\"⚙️ {$serviceType}: {$serviceName}\"]\n";
                $nodes['services'][] = $serviceId;
            }
        }

        // Model nodes
        if (!empty($flow['models'])) {
            foreach ($flow['models'] as $model) {
                $modelId = 'D' . $nodeCounter++;
                $modelName = class_basename($model['class']);
                $mermaid .= "    {$modelId}[\"📊 {$modelName}\"]\n";
                $nodes['models'][] = $modelId;
            }
        }

        // Add connections
        $mermaid .= "\n    %% Connections\n";
        
        $lastNode = $nodes['route'] ?? null;
        
        if (!empty($nodes['middleware']) && $lastNode) {
            foreach ($nodes['middleware'] as $middlewareId) {
                $mermaid .= "    {$lastNode} --> {$middlewareId}\n";
                $lastNode = $middlewareId;
            }
        }

        if (isset($nodes['controller']) && $lastNode) {
            $mermaid .= "    {$lastNode} --> {$nodes['controller']}\n";
        }

        if (!empty($nodes['services']) && isset($nodes['controller'])) {
            foreach ($nodes['services'] as $serviceId) {
                $mermaid .= "    {$nodes['controller']} --> {$serviceId}\n";
            }
        }

        if (!empty($nodes['models']) && isset($nodes['controller'])) {
            foreach ($nodes['models'] as $modelId) {
                $mermaid .= "    {$nodes['controller']} --> {$modelId}\n";
            }
        }

        return $mermaid;
    }

    private function generateInternalInfo(array $internalAnalysis): string
    {
        $info = [];
        $targetMethod = $internalAnalysis['target_method'] ?? 'main';
        
        if (isset($internalAnalysis['methods'][$targetMethod])) {
            $method = $internalAnalysis['methods'][$targetMethod];
            
            // Complexity info
            if (isset($method['complexity_indicators'])) {
                $complexity = $method['complexity_indicators'];
                if (isset($complexity['cyclomatic_complexity'])) {
                    $info[] = "Complexity: {$complexity['cyclomatic_complexity']}";
                }
                if (isset($complexity['method_calls_count'])) {
                    $info[] = "Calls: {$complexity['method_calls_count']}";
                }
            }
            
            // Method calls summary
            if (!empty($method['method_calls'])) {
                $callTypes = [];
                foreach ($method['method_calls'] as $call) {
                    $callTypes[$call['type']] = ($callTypes[$call['type']] ?? 0) + 1;
                }
                
                $callSummary = [];
                foreach ($callTypes as $type => $count) {
                    $shortType = $this->shortenCallType($type);
                    $callSummary[] = "{$shortType}: {$count}";
                }
                
                if (!empty($callSummary)) {
                    $info[] = implode(', ', array_slice($callSummary, 0, 2));
                }
            }
        }
        
        return implode('\\n', array_slice($info, 0, 3));
    }

    private function shortenCallType(string $type): string
    {
        return match($type) {
            'Internal Method' => 'Internal',
            'Instance Method' => 'Instance',
            'Static Method' => 'Static',
            'Function Call' => 'Function',
            default => $type
        };
    }

    private function addInternalMethodNodes(array $internalAnalysis, array $lastLevelNodes, int &$nodeId): string
    {
        $dot = "\n    // Internal Method Calls\n";
        
        if (empty($internalAnalysis['method_calls'])) {
            return '';
        }
        
        // Group method calls by type
        $groupedCalls = [];
        foreach ($internalAnalysis['method_calls'] as $call) {
            $groupedCalls[$call['type']][] = $call;
        }
        
        $currentLevelNodes = [];
        
        foreach ($groupedCalls as $type => $calls) {
            // Limit to most important calls
            $limitedCalls = array_slice($calls, 0, 3);
            
            foreach ($limitedCalls as $call) {
                $nodeKey = "node{$nodeId}";
                
                $target = $call['target'] ?? $call['class'] ?? $call['variable'] ?? $call['function'] ?? 'N/A';
                $method = $call['method'] ?? $call['function'] ?? 'N/A';
                
                $label = "🔧 {$type}\\n{$target}\\n{$method}";
                $color = $this->getCallTypeColor($type);
                
                $dot .= "    {$nodeKey} [label=\"{$label}\", fillcolor=\"{$color}\", fontcolor=\"white\", shape=\"ellipse\", height=1.2];\n";
                $currentLevelNodes[] = $nodeKey;
                $nodeId++;
            }
        }
        
        // Connect from controller/action to internal calls
        foreach ($lastLevelNodes as $prevNode) {
            foreach ($currentLevelNodes as $currNode) {
                $dot .= "    {$prevNode} -> {$currNode} [style=\"dashed\", color=\"#95a5a6\"];\n";
            }
        }
        
        return $dot;
    }

    private function getCallTypeColor(string $type): string
    {
        return match($type) {
            'Internal Method' => '#9b59b6',
            'Instance Method' => '#3498db',
            'Static Method' => '#e74c3c',
            'Function Call' => '#f39c12',
            default => '#95a5a6'
        };
    }

    private function addDeepTraceNodes(array $deepTrace, array $lastLevelNodes, int &$nodeId): string
    {
        if (empty($deepTrace['levels']) || count($deepTrace['levels']) <= 1) {
            return '';
        }
        
        $dot = "\n    // Deep Trace Levels\n";
        $currentLevelNodes = $lastLevelNodes;
        
        // Skip level 0 (already shown in main flow)
        for ($level = 1; $level < count($deepTrace['levels']) && $level <= 3; $level++) {
            $levelClasses = $deepTrace['levels'][$level];
            $nextLevelNodes = [];
            
            foreach ($levelClasses as $classData) {
                $nodeKey = "node{$nodeId}";
                $className = class_basename($classData['class']);
                $action = $classData['action'] ? "::{$classData['action']}" : '';
                $type = $classData['type'];
                
                $label = "🌊 Level {$level}\\n{$type}\\n{$className}{$action}";
                $color = $this->getDeepTraceColor($type);
                
                $dot .= "    {$nodeKey} [label=\"{$label}\", fillcolor=\"{$color}\", fontcolor=\"white\", shape=\"box\", style=\"filled,rounded,dashed\"];\n";
                $nextLevelNodes[] = $nodeKey;
                $nodeId++;
                
                // Show connections count
                if (!empty($classData['connections'])) {
                    $connectionCount = count($classData['connections']);
                    if ($connectionCount > 0) {
                        $label .= "\\n({$connectionCount} connections)";
                    }
                }
            }
            
            // Connect previous level to current level
            foreach ($currentLevelNodes as $prevNode) {
                foreach ($nextLevelNodes as $currNode) {
                    $dot .= "    {$prevNode} -> {$currNode} [style=\"dotted\", color=\"#34495e\"];\n";
                }
            }
            
            $currentLevelNodes = $nextLevelNodes;
        }
        
        return $dot;
    }

    private function getDeepTraceColor(string $type): string
    {
        return match($type) {
            'Controller' => '#27ae60',
            'Action' => '#8e44ad',
            'Service' => '#f39c12',
            'Repository' => '#e67e22',
            'Model' => '#e91e63',
            'Query' => '#16a085',
            default => '#7f8c8d'
        };
    }

    public function generateMermaidDiagramWithInternal(array $flow): string
    {
        $mermaid = "graph TD\n";
        
        $nodeCounter = 0;
        $nodes = [];

        // Add title
        $title = $this->getFlowTitle($flow);
        $mermaid .= "    %% {$title}\n\n";

        // Route node
        if (isset($flow['route'])) {
            $routeId = 'R' . $nodeCounter++;
            $routeName = $flow['route']['name'] ?? 'unnamed';
            $mermaid .= "    {$routeId}[\"🔗 Route: {$routeName}\"]\n";
            $nodes['route'] = $routeId;
        }

        // Middleware nodes
        if (!empty($flow['middleware'])) {
            foreach ($flow['middleware'] as $i => $middleware) {
                $middlewareId = 'M' . $nodeCounter++;
                $mermaid .= "    {$middlewareId}[\"🛡️ {$middleware['name']}\"]\n";
                $nodes['middleware'][] = $middlewareId;
            }
        }

        // Controller node with internal info
        if (isset($flow['controller'])) {
            $controllerId = 'C' . $nodeCounter++;
            $controllerName = class_basename($flow['controller']);
            $action = $flow['action'] ?? '__invoke';
            
            $label = "";
            if (str_contains($flow['controller'], 'Action')) {
                $label = "⚡ {$controllerName}::{$action}";
            } else {
                $label = "🎯 {$controllerName}::{$action}";
            }
            
            // Add internal analysis to label
            if (isset($flow['internal_analysis']) && !empty($flow['internal_analysis']['methods'])) {
                $internalInfo = $this->generateInternalInfo($flow['internal_analysis']);
                if ($internalInfo) {
                    $label .= "<br/>{$internalInfo}";
                }
            }
            
            $mermaid .= "    {$controllerId}[\"{$label}\"]\n";
            $nodes['controller'] = $controllerId;
        }

        // Internal method calls
        if (isset($flow['internal_analysis']) && !empty($flow['internal_analysis']['method_calls'])) {
            $methodCallGroups = [];
            foreach ($flow['internal_analysis']['method_calls'] as $call) {
                $methodCallGroups[$call['type']][] = $call;
            }
            
            foreach ($methodCallGroups as $type => $calls) {
                // Limit to 2 most important calls per type
                foreach (array_slice($calls, 0, 2) as $call) {
                    $callId = 'MC' . $nodeCounter++;
                    $target = $call['target'] ?? $call['class'] ?? $call['variable'] ?? $call['function'] ?? 'N/A';
                    $method = $call['method'] ?? $call['function'] ?? 'N/A';
                    
                    $mermaid .= "    {$callId}[\"🔧 {$type}<br/>{$target}<br/>{$method}\"]\n";
                    $nodes['method_calls'][] = $callId;
                }
            }
        }

        // Service nodes
        if (!empty($flow['services'])) {
            foreach ($flow['services'] as $service) {
                $serviceId = 'S' . $nodeCounter++;
                $serviceName = class_basename($service['class']);
                $serviceType = $service['type'] ?? 'Service';
                $mermaid .= "    {$serviceId}[\"⚙️ {$serviceType}: {$serviceName}\"]\n";
                $nodes['services'][] = $serviceId;
            }
        }

        // Model nodes
        if (!empty($flow['models'])) {
            foreach ($flow['models'] as $model) {
                $modelId = 'D' . $nodeCounter++;
                $modelName = class_basename($model['class']);
                $mermaid .= "    {$modelId}[\"📊 {$modelName}\"]\n";
                $nodes['models'][] = $modelId;
            }
        }

        // Add connections
        $mermaid .= "\n    %% Connections\n";
        
        $lastNode = $nodes['route'] ?? null;
        
        if (!empty($nodes['middleware']) && $lastNode) {
            foreach ($nodes['middleware'] as $middlewareId) {
                $mermaid .= "    {$lastNode} --> {$middlewareId}\n";
                $lastNode = $middlewareId;
            }
        }

        if (isset($nodes['controller']) && $lastNode) {
            $mermaid .= "    {$lastNode} --> {$nodes['controller']}\n";
        }

        // Connect to internal method calls
        if (!empty($nodes['method_calls']) && isset($nodes['controller'])) {
            foreach ($nodes['method_calls'] as $callId) {
                $mermaid .= "    {$nodes['controller']} -.-> {$callId}\n";
            }
        }

        if (!empty($nodes['services']) && isset($nodes['controller'])) {
            foreach ($nodes['services'] as $serviceId) {
                $mermaid .= "    {$nodes['controller']} --> {$serviceId}\n";
            }
        }

        if (!empty($nodes['models']) && isset($nodes['controller'])) {
            foreach ($nodes['models'] as $modelId) {
                $mermaid .= "    {$nodes['controller']} --> {$modelId}\n";
            }
        }

        // Add styling
        $mermaid .= "\n    %% Styling\n";
        if (isset($nodes['controller'])) {
            $mermaid .= "    classDef controller fill:#27ae60,stroke:#27ae60,stroke-width:2px,color:#fff\n";
            $mermaid .= "    class {$nodes['controller']} controller\n";
        }

        return $mermaid;
    }
}
