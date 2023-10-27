# Build a static website with Laravel on the backend

[![Total Downloads](https://img.shields.io/packagist/dt/vormkracht10/laravel-static.svg?style=flat-square)](https://packagist.org/packages/vormkracht10/laravel-static)
[![Tests](https://github.com/vormkracht10/laravel-static/actions/workflows/run-tests.yml/badge.svg?branch=main)](https://github.com/vormkracht10/laravel-static/actions/workflows/run-tests.yml)
[![PHPStan](https://github.com/vormkracht10/laravel-static/actions/workflows/phpstan.yml/badge.svg?branch=main)](https://github.com/vormkracht10/laravel-static/actions/workflows/phpstan.yml)
![GitHub release (latest by date)](https://img.shields.io/github/v/release/vormkracht10/laravel-static)
![Packagist PHP Version Support](https://img.shields.io/packagist/php-v/vormkracht10/laravel-static)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/vormkracht10/laravel-static.svg?style=flat-square)](https://packagist.org/packages/vormkracht10/laravel-static)

This is where your description should go. Limit it to a paragraph or two. Consider adding a small example.

## Installation

You can install the package via composer:

```bash
composer require vormkracht10/laravel-static
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="laravel-static-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-static-config"
```

This is the contents of the published config file:

```php
return [
];
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="laravel-static-views"
```

## Usage

```php
$laravelStatic = new Vormkracht10\LaravelStatic();
echo $laravelStatic->echoPhrase('Hello, Vormkracht10!');
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Mark van Eijk](https://github.com/vormkracht10)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
