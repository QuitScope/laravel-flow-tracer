# Laravel Flow Tracer

🔍 **Powerful Laravel package to trace and visualize application flows with automatic high-quality PNG diagrams**

Laravel Flow Tracer analyzes your Laravel application's request flow from routes through middleware, controllers, services, actions, and models, automatically generating beautiful flow diagrams using Graphviz.

![Flow Diagram Example](https://via.placeholder.com/800x600/2563eb/ffffff?text=Flow+Diagram+Example)

## Features

✨ **Comprehensive Flow Analysis**
- Route → Middleware → Controller → Services → Models
- Domain-Driven Design (DDD) support
- Action and Service detection
- Model operations tracking

🎨 **Professional Visualizations**
- High-resolution PNG diagrams (300 DPI)
- Automatic caller detection ("Called From" information)
- Color-coded node types with emojis
- Graphviz-powered professional layouts

🚀 **Easy to Use**
- Single Artisan command
- Automatic diagram generation
- Support for routes, actions, and URLs
- JSON output for programmatic use

## Installation

### 1. Install via Composer

```bash
composer require yourname/laravel-flow-tracer
```

### 2. Install Graphviz

**Windows (via winget):**
```bash
winget install Graphviz.Graphviz
```

**macOS (via Homebrew):**
```bash
brew install graphviz
```

**Ubuntu/Debian:**
```bash
sudo apt-get install graphviz
```

### 3. Publish Configuration (Optional)

```bash
php artisan vendor:publish --provider="LaravelFlowTracer\FlowTracerServiceProvider" --tag="config"
```

## Usage

### Basic Commands

**Trace a named route:**
```bash
php artisan flow:trace --route="posts.index"
```

**Trace a controller action:**
```bash
php artisan flow:trace --action="CreateTaskAction"
```

**Trace a URL path:**
```bash
php artisan flow:trace --url="/api/v1/posts"
```

### Advanced Options

**Get JSON output:**
```bash
php artisan flow:trace --route="posts.index" --format=json
```

**Custom output path:**
```bash
php artisan flow:trace --route="posts.index" --output="/path/to/diagram.png"
```

**Disable automatic PNG generation:**
```bash
php artisan flow:trace --route="posts.index" --no-png
```

## Example Output

### Console Output
```
Laravel Flow Tracer Results
============================

🔗 Route Information:
+------------+--------------+
| Property   | Value        |
+------------+--------------+
| Name       | posts.index  |
| URI        | api/v1/posts |
| Methods    | GET, HEAD    |
+------------+--------------+

🛡️ Middleware Stack:
+--------------+----------------------+
| Middleware   | Type                 |
+--------------+----------------------+
| api          | API Middleware Group |
| auth:sanctum | Authentication       |
+--------------+----------------------+

🎯 Controller Action:
+------------+---------------------------------+
| Property   | Value                           |
+------------+---------------------------------+
| Controller | App\Http\Controllers\PostController |
| Action     | index                           |
+------------+---------------------------------+

🖼️ Flow diagram automatically saved: storage/app/flow-diagrams/flow-posts-index-1234567890.png
```

### Visual Diagram Features

- **Route Node**: Blue, shows route name, URI, and HTTP methods
- **Middleware Nodes**: Orange, shows middleware name and type
- **Controller Node**: Green, shows controller and action
- **Action Node**: Purple, for Domain Actions with caller information
- **Service Nodes**: Yellow, shows service type and methods
- **Model Nodes**: Pink, shows model name and operations
- **Called From**: Teal, shows which controllers/routes call an action

## Configuration

The package comes with sensible defaults, but you can customize it via `config/flow-tracer.php`:

```php
return [
    // Output directory for diagrams
    'output_directory' => storage_path('app/flow-diagrams'),
    
    // Graphviz settings
    'graphviz' => [
        'dot_path' => null, // Auto-detect or specify path
        'dpi' => 300,       // High resolution
        'size' => null,     // Auto-sizing
    ],
    
    // Search paths for your application
    'search_paths' => [
        base_path('app'),
        base_path('src'),
    ],
    
    // Visual styling
    'styling' => [
        'node' => ['fontsize' => 14, 'width' => 3.0, 'height' => 1.5],
        'edge' => ['fontsize' => 12, 'penwidth' => 2],
        'graph' => ['fontsize' => 18, 'pad' => 1.0],
    ],
    
    // Feature toggles
    'features' => [
        'auto_png_generation' => true,
        'caller_detection' => true,
        'dependency_analysis' => true,
        'model_relationships' => true,
    ],
];
```

## Architecture Support

Laravel Flow Tracer works with various Laravel application architectures:

- **Standard Laravel**: Controllers in `app/Http/Controllers`
- **Domain-Driven Design (DDD)**: Actions, Services, Queries in `src/` or custom directories
- **Modular Applications**: Multiple namespace structures
- **API Applications**: Full middleware and controller analysis

## Requirements

- PHP 8.1+
- Laravel 10.0+ | 11.0+ | 12.0+
- Graphviz (for diagram generation)

## Troubleshooting

### Graphviz not found
If you get "dot command not found" errors:

1. **Verify installation**: `dot -V`
2. **Check PATH**: Ensure Graphviz bin directory is in your system PATH
3. **Set manual path**: Update `config/flow-tracer.php` with explicit `dot_path`

### Permission issues
Ensure the output directory is writable:
```bash
chmod 755 storage/app/flow-diagrams
```

### Complex flows
For very large applications, you may need to:
- Increase PHP memory limit
- Adjust `max_depth` in configuration
- Use `--no-png` for console-only output

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Credits

- **[Your Name](https://github.com/yourusername)**
- Powered by [Graphviz](https://graphviz.org/) for professional diagram generation
- Built for Laravel developers who love clean, visual code analysis

---

**Made with ❤️ for the Laravel community**