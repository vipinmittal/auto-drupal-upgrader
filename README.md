# Drupal Upgrade Automation

A Composer plugin that automates the upgrade process from Drupal 9 to Drupal 10 and Drupal 11.

## Features

- Automatically updates all contributed modules, themes, and core packages via Composer
- Handles required database updates for each module using Drush
- Sequentially upgrades from Drupal 9 → 10 → 11
- Provides real-time command-line output of the upgrade process
- Verifies compatibility at each step

## Requirements

- PHP 7.4 or higher
- Composer 2.0 or higher
- Drush 10 or higher
- Drupal 9.x installation

## Installation

1. Add the plugin to your project's composer.json:

```bash
composer require drupal/upgrade-automation
```

2. The plugin will automatically run after composer update/install commands.

## Usage

The plugin will automatically run during the following Composer commands:
- `composer update`
- `composer install`

You don't need to do anything special - just run your normal Composer commands, and the plugin will handle the upgrade process automatically.

## How it Works

1. The plugin detects your current Drupal version
2. If you're on Drupal 9, it will:
   - Update composer.json to require Drupal 10
   - Run composer update
   - Run database updates
3. If you're on Drupal 10, it will:
   - Update composer.json to require Drupal 11
   - Run composer update
   - Run database updates

## Troubleshooting

If you encounter any issues:

1. Make sure Drush is properly installed and accessible
2. Check that you have sufficient permissions to run Composer and Drush commands
3. Ensure your PHP version meets the requirements
4. Check the Composer output for any specific error messages

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This project is licensed under the GPL-2.0-or-later license. 