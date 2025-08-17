# Installation Guide

## Quick Installation

### 1. Install Package

```bash
composer require yourname/laravel-flow-tracer
```

### 2. Install Graphviz

**Windows:**
```bash
# Via winget (recommended)
winget install Graphviz.Graphviz

# Via Chocolatey
choco install graphviz

# Via Scoop
scoop install graphviz
```

**macOS:**
```bash
# Via Homebrew (recommended)
brew install graphviz

# Via MacPorts
sudo port install graphviz
```

**Linux (Ubuntu/Debian):**
```bash
sudo apt-get update
sudo apt-get install graphviz
```

**Linux (CentOS/RHEL/Fedora):**
```bash
# CentOS/RHEL
sudo yum install graphviz

# Fedora
sudo dnf install graphviz
```

### 3. Verify Installation

```bash
# Test Graphviz
dot -V

# Test Laravel Flow Tracer
php artisan flow:trace --help
```

### 4. Publish Configuration (Optional)

```bash
php artisan vendor:publish --provider="LaravelFlowTracer\FlowTracerServiceProvider" --tag="config"
```

## Configuration

### Basic Configuration

The package works out-of-the-box with sensible defaults. For custom setups, publish and edit `config/flow-tracer.php`:

```php
return [
    'output_directory' => storage_path('app/flow-diagrams'),
    
    'graphviz' => [
        'dot_path' => null, // Auto-detect or set manually
        'dpi' => 300,
        'size' => null,
    ],
    
    'search_paths' => [
        base_path('app'),
        base_path('src'),
        // Add your custom paths
    ],
];
```

### Custom Search Paths

For non-standard Laravel applications:

```php
'search_paths' => [
    base_path('app'),
    base_path('src'),
    base_path('modules'),
    base_path('packages'),
    base_path('custom-directory'),
],
```

### Manual Graphviz Path

If auto-detection fails:

```php
'graphviz' => [
    'dot_path' => '/usr/local/bin/dot',              // Linux/macOS
    // or
    'dot_path' => 'C:\Program Files\Graphviz\bin\dot.exe', // Windows
],
```

## Troubleshooting

### Graphviz Issues

**"Command 'dot' not found":**
1. Ensure Graphviz is installed: `dot -V`
2. Check if `dot` is in your PATH
3. Set manual path in config file
4. Restart your terminal/server

**"Permission denied":**
1. Check output directory permissions:
   ```bash
   chmod 755 storage/app/flow-diagrams
   ```
2. Ensure Laravel can write to storage directory

### Memory Issues

For large applications, increase PHP memory:

```bash
php -d memory_limit=512M artisan flow:trace --route="complex.route"
```

Or in `.env`:
```
MEMORY_LIMIT=512M
```

### Path Issues

**Class not found errors:**
1. Ensure your application uses PSR-4 autoloading
2. Run `composer dump-autoload`
3. Check namespace declarations in your files
4. Verify search paths in config

## Quick Test

After installation, test with a simple route:

```bash
# If you have a 'home' route
php artisan flow:trace --route="home"

# Or test with any URL
php artisan flow:trace --url="/"
```

## Integration with CI/CD

### GitHub Actions

```yaml
name: Generate Flow Diagrams

on: [push, pull_request]

jobs:
  flow-diagrams:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.2
          extensions: dom, curl, libxml, mbstring, zip
          
      - name: Install Graphviz
        run: sudo apt-get install graphviz
        
      - name: Install dependencies
        run: composer install
        
      - name: Generate flow diagrams
        run: |
          php artisan flow:trace --route="api.users.index" --no-png
          php artisan flow:trace --route="web.dashboard" --no-png
```

### Docker

```dockerfile
FROM php:8.2-fpm

# Install Graphviz
RUN apt-get update && apt-get install -y \
    graphviz \
    && rm -rf /var/lib/apt/lists/*

# Your Laravel application setup...
```

## Next Steps

1. **Explore your application**: Start tracing important routes
2. **Document flows**: Use generated diagrams in documentation
3. **Monitor complexity**: Identify complex flow patterns
4. **Optimize architecture**: Use insights to improve code structure

## Need Help?

- Check the [README](README.md) for usage examples
- Review [troubleshooting](#troubleshooting) section
- Open an issue on GitHub
- Consult Graphviz documentation for advanced diagram customization