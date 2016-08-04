# Change Log


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
