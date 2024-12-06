# WooCommerce to Aimeos

Migrate your WooCommerce database to your Aimeos ecommerce installation.

## Requirements

- Wordpress with WooCommerce
- Aimeos 2023.10+

## Installation

In your Aimeos setup, use composer to install the ai-woocommerce package:

```
composer req aimeos/ai-woocommerce
```

## Migration

Configure your Wordpress database in your Laravel `./config/shop.php`:

```php
	'resource' => [
		'db' => [
			// existing DB connection settings
		],
		'db-woocommerce' => [
			'adapter' => 'mysql',
			'host' => '127.0.0.1',
			'port' => '3306',
			'database' => 'wordpress',
			'username' => 'wp_db_user',
			'password' => 'wp_password',
		],
	],
```

**Caution:** Make sure the Aimeos installation contains no demo data and `db-woocommerce` is at the end of the `resource` list!

Afterwards, run this command to execute all setup tasks including those from the ai-woocommerce package:

```
php artisan aimeos:setup
```

This will migrate these entities from your WooCommerce database to the Aimeos database:

- Products
- Categories
- Suppliers/Brands
- Attributes and attribute types
- Extra product options from a WooCommerce extension (partly)

If everything works correctly, remove the `db-woocommerce` database settings from your `./config/shop.php` again.
