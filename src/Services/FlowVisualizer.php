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
        $title = $this->getFlowTitle($flow);
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
                    $dot .= "    {$nodeKey} [label=\"📍 Called From Route\\n{$caller['name']}\\n{$caller['uri']}\", fillcolor=\"#17a2b8\", fontcolor=\"white\"];\n";
                } else {
                    $callerName = class_basename($caller['class']);
                    $dot .= "    {$nodeKey} [label=\"📍 Called From\\n{$callerName}\", fillcolor=\"#17a2b8\", fontcolor=\"white\"];\n";
                }
                $currentLevelNodes[] = $nodeKey;
                $nodeId++;
            }
            $lastLevelNodes = $currentLevelNodes;
        }

        // Route
        if (isset($flow['route'])) {
            $routeName = $flow['route']['name'] ?? 'unnamed';
            $routeUri = $flow['route']['uri'] ?? '';
            $routeMethods = implode(', ', $flow['route']['methods'] ?? []);
            
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
                $dot .= "    {$nodeKey} [label=\"🛡️ {$middleware['name']}\\n({$middleware['type']})\", fillcolor=\"#e67e22\", fontcolor=\"white\"];\n";
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
            $controllerName = class_basename($flow['controller']);
            $action = $flow['action'] ?? '__invoke';
            
            $nodeKey = "node{$nodeId}";
            if (str_contains($flow['controller'], 'Actions\\')) {
                $dot .= "    {$nodeKey} [label=\"⚡ Action\\n{$controllerName}\\nMethod: {$action}\", fillcolor=\"#8e44ad\", fontcolor=\"white\"];\n";
            } else {
                $dot .= "    {$nodeKey} [label=\"🎯 Controller\\n{$controllerName}\\nAction: {$action}\", fillcolor=\"#27ae60\", fontcolor=\"white\"];\n";
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
                $serviceName = isset($service['class']) ? class_basename($service['class']) : ($service['name'] ?? 'Service');
                $serviceType = $service['type'] ?? 'Service';
                
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
                $modelName = isset($model['class']) ? class_basename($model['class']) : ($model['model'] ?? 'Model');
                $operations = isset($model['operations']) ? implode(', ', array_slice($model['operations'], 0, 2)) : '';
                
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

        $dot .= "}\n";
        return $dot;
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
}