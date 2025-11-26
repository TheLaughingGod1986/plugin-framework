# Optti WordPress Plugin Framework

A reusable, shareable framework for building Optti WordPress plugins.

## Overview

The Optti Framework provides a solid foundation for WordPress plugins that need:

- Backend connectivity to Optti backend services
- Shared PHP base classes (API client, license management, logging, caching)
- Shared JavaScript modules (settings UI, notifications, usage widgets)
- Shared CSS design system (brand, layout, typography, components)
- Clean versioning and reuse via Git submodules

## Installation

### As a Git Submodule

```bash
git submodule add https://github.com/TheLaughingGod1986/plugin-framework.git framework
cd framework
git checkout v1.0.0
cd ..
git add framework
git commit -m "Add Optti Framework v1.0.0"
```

### In Your Plugin

```php
// Bootstrap framework
require_once __DIR__ . '/framework/loader.php';

Optti_Framework::bootstrap( __FILE__, [
    'plugin_slug'  => 'my-plugin',
    'version'      => '1.0.0',
    'api_base_url' => 'https://alttext-ai-backend.onrender.com',
    'asset_url'    => plugin_dir_url( __FILE__ ) . 'framework/dist/',
    'asset_dir'    => plugin_dir_path( __FILE__ ) . 'framework/dist/',
] );

// Create your plugin class
class My_Plugin extends \Optti\Framework\PluginBase {
    // Implement abstract methods
}
```

## Quick Start

1. **Extend PluginBase**: Create a class that extends `\Optti\Framework\PluginBase`
2. **Register Routes**: Use `REST_Controller` for WordPress REST API endpoints
3. **Use Framework APIs**: Access `ApiClient`, `LicenseManager`, etc.
4. **Build Assets**: Run `npm install && npm run build` in framework directory

## Framework Components

### PHP Classes
- `PluginBase` - Base plugin class
- `ApiClient` - Backend API communication
- `LicenseManager` - License validation and management
- `REST_Controller` - Base REST API controller
- `Logger` - Logging system
- `Cache` - Caching system

### JavaScript Modules
- `OpttiApiClient` - Frontend API client
- Admin UI components
- Usage widgets
- Notifications system

### CSS Design System
- Design tokens (colors, spacing, typography)
- Reusable components (buttons, cards, forms)
- Layout utilities
- Dashboard styles

## Versioning

This framework uses semantic versioning:
- `v1.0.0` - Initial release
- `v1.1.0` - New features (backward compatible)
- `v2.0.0` - Breaking changes

See [CHANGELOG.md](CHANGELOG.md) for version history.

## Documentation

- [Blueprint for New Plugins](docs/BLUEPRINT_NEW_PLUGIN.md)
- [Submodule Setup Guide](docs/SUBMODULE_SETUP.md)
- [Upgrade Guide](docs/UPGRADE_GUIDE.md)
- [Versioning Strategy](docs/VERSIONING.md)

## Development

```bash
# Install dependencies
npm install

# Build assets
npm run build

# Development mode with watch
npm run dev
```

## License

GPL v2 or later
