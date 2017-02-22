# Change Log

## 1.3.0 - unreleased
### Added

- New `methods` setting which allows to configure the request methods which can be cached.
- New `respect_response_cache_directives` config setting to define specific cache directives to respect when handling responses.
- Introduced `CachePlugin::clientCache` and `CachePlugin::serverCache` factory methods to easily setup the plugin with 
the correct config settigns for each usecase.

### Changed

- The `no-cache` directive is now respected by the plugin and will not cache the response

### Deprecated

- The `respect_cache_headers` option is deprecated and will be removed in 2.0. This option is replaced by the new `respect_response_cache_directives` option.

## 1.2.0 - 2016-08-16

### Changed

- The default value for `default_ttl` is changed from `null` to `0`.

### Fixed

- Issue when you use `respect_cache_headers=>false` in combination with `default_ttl=>null`.
- We allow `cache_lifetime` to be set to `null`.


## 1.1.0 - 2016-08-04

### Added

- Support for cache validation with ETag and Last-Modified headers. (Enabled automatically when the server sends the relevant headers.)
- `hash_algo` config option used for cache key generation (defaults to **sha1**).

### Changed

- Default hash algo used for cache generation (from **md5** to **sha1**).

### Fixed

- Cast max age header to integer in order to get valid expiration value.


## 1.0.0 - 2016-05-05

- Initial release
