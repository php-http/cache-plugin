# Cache Plugin

[![Latest Version](https://img.shields.io/github/release/php-http/cache-plugin.svg?style=flat-square)](https://github.com/php-http/cache-plugin/releases)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Build Status](https://github.com/php-http/cache-plugin/actions/workflows/tests.yml/badge.svg)](https://github.com/php-http/cache-plugin/actions/workflows/tests.yml)
[![Code Coverage](https://img.shields.io/scrutinizer/coverage/g/php-http/cache-plugin.svg?style=flat-square)](https://scrutinizer-ci.com/g/php-http/cache-plugin)
[![Quality Score](https://img.shields.io/scrutinizer/g/php-http/cache-plugin.svg?style=flat-square)](https://scrutinizer-ci.com/g/php-http/cache-plugin)
[![Total Downloads](https://img.shields.io/packagist/dt/php-http/cache-plugin.svg?style=flat-square)](https://packagist.org/packages/php-http/cache-plugin)

**PSR-6 Cache plugin for HTTPlug.**


## Install

Via Composer

``` bash
composer require php-http/cache-plugin
```


## Documentation

Please see the [official documentation](http://docs.php-http.org/en/latest/plugins/cache.html).

To only cache ETag-backed responses and always revalidate cached bodies with `If-None-Match`, enable `etag_only`:

``` php
$plugin = CachePlugin::clientCache($pool, $streamFactory, [
    'etag_only' => true,
]);
```

When this option is enabled, responses without an `ETag` header are not cached. Cached responses are only returned after the origin replies with `304 Not Modified`.


## Testing

``` bash
composer test
```


## Contributing

Please see our [contributing guide](http://docs.php-http.org/en/latest/development/contributing.html).


## Security

If you discover any security related issues, please contact us at [security@php-http.org](mailto:security@php-http.org).


## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
