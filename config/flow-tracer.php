<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Laravel Flow Tracer Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration options for the Laravel Flow Tracer package.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Output Directory
    |--------------------------------------------------------------------------
    |
    | The directory where flow diagrams will be saved. By default, diagrams
    | are saved to storage/app/flow-diagrams.
    |
    */
    'output_directory' => storage_path('app/flow-diagrams'),

    /*
    |--------------------------------------------------------------------------
    | Graphviz Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for Graphviz diagram generation.
    |
    */
    'graphviz' => [
        /*
        | Path to the dot executable. Set to null for auto-detection.
        | On Windows: 'C:\Program Files\Graphviz\bin\dot.exe'
        | On Linux/Mac: 'dot' (if in PATH)
        */
        'dot_path' => null,

        /*
        | Default DPI for image generation (higher = better quality, larger file)
        */
        'dpi' => 300,

        /*
        | Default image size (leave null for auto-sizing)
        */
        'size' => null, // e.g., '12,16!' for fixed size
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Paths
    |--------------------------------------------------------------------------
    |
    | Directories to search for controllers, actions, and other classes.
    | The package will automatically search these paths.
    |
    */
    'search_paths' => [
        base_path('app'),
        base_path('src'),
        // Add more paths as needed
    ],

    /*
    |--------------------------------------------------------------------------
    | Node Styling
    |--------------------------------------------------------------------------
    |
    | Visual styling options for diagram nodes.
    |
    */
    'styling' => [
        'node' => [
            'fontsize' => 14,
            'width' => 3.0,
            'height' => 1.5,
        ],
        'edge' => [
            'fontsize' => 12,
            'penwidth' => 2,
        ],
        'graph' => [
            'fontsize' => 18,
            'pad' => 1.0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Analysis Depth
    |--------------------------------------------------------------------------
    |
    | Maximum depth for dependency analysis to prevent infinite loops.
    |
    */
    'max_depth' => 3,

    /*
    |--------------------------------------------------------------------------
    | Enable Features
    |--------------------------------------------------------------------------
    |
    | Toggle specific features of the flow tracer.
    |
    */
    'features' => [
        'auto_png_generation' => true,
        'caller_detection' => true,
        'dependency_analysis' => true,
        'model_relationships' => true,
    ],
];