# Changelog

All notable changes to `laravel-flow-tracer` will be documented in this file.

## [1.0.0] - 2024-08-17

### Added
- Initial release of Laravel Flow Tracer
- Complete flow analysis from routes through middleware, controllers, services, and models
- High-quality PNG diagram generation using Graphviz (300 DPI)
- Automatic caller detection for Domain Actions
- Support for Domain-Driven Design (DDD) architectures
- Configurable styling and output options
- Auto-discovery Laravel Service Provider
- Comprehensive documentation and examples

### Features
- **Flow Analysis**: Route → Middleware → Controller → Services → Models
- **Visual Diagrams**: Professional Graphviz-powered layouts with color coding
- **Caller Detection**: Shows which controllers/routes call specific actions
- **Multiple Formats**: Console tables, JSON output, PNG diagrams
- **DDD Support**: Domain Actions, Services, Queries detection
- **Cross-platform**: Windows, macOS, Linux support

### Commands
- `flow:trace --route="route.name"` - Trace named routes
- `flow:trace --action="ActionClass"` - Trace controller actions
- `flow:trace --url="/path"` - Trace URL paths
- `flow:trace --format=json` - JSON output
- `flow:trace --no-png` - Disable diagram generation

### Configuration
- Customizable Graphviz settings (DPI, sizing)
- Configurable search paths for different architectures
- Visual styling options (colors, fonts, sizes)
- Feature toggles for optional functionality