# Application Template

This is a template for creating new applications in AlkaidSYS.

## Directory Structure

```
_template/
├── Application.php      # Main application class
├── manifest.json        # Application metadata
├── app/                 # Backend code
│   ├── controller/      # Controllers
│   ├── model/           # Models
│   └── service/         # Services
├── config/              # Configuration files
│   └── menu.php         # Menu configuration
├── sql/                 # Database scripts
│   ├── install.sql      # Installation script
│   ├── uninstall.sql    # Uninstallation script
│   └── upgrade/         # Upgrade scripts
├── lang/                # Language files
└── docs/                # Documentation
```

## Creating a New Application

1. Copy this `_template` directory to a new directory with your application key
2. Update `manifest.json` with your application details
3. Modify `Application.php` with your application logic
4. Add your controllers, models, and services in the `app/` directory
5. Create your database tables in `sql/install.sql`
6. Define your menus in `config/menu.php`

## Lifecycle Methods

- `install()`: Called when the application is installed
- `uninstall()`: Called when the application is uninstalled
- `enable()`: Called when the application is enabled
- `disable()`: Called when the application is disabled
- `upgrade()`: Called when the application is upgraded

## Events

The following events are triggered during the application lifecycle:

- `ApplicationInstalled`: After successful installation
- `ApplicationUninstalled`: After successful uninstallation
- `ApplicationEnabled`: After the application is enabled
- `ApplicationDisabled`: After the application is disabled
- `ApplicationUpgraded`: After successful upgrade

