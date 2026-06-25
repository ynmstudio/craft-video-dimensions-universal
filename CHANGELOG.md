# Release Notes for Video Dimensions Universal

## 1.1.0 - 2026-06-25

### Added

- Craft CMS 4 support — the plugin now runs on both Craft 4 and Craft 5
- Allow Craft Cloud 3 (`craftcms/cloud` 3.x) ([#4])

### Fixed

- Guard access to the Craft 5-only `Volume::$subpath` property so local video processing no longer errors on Craft 4

[#4]: https://github.com/ynmstudio/craft-video-dimensions-universal/pull/4

## 1.0.2 - 2025-07-14

### Fixed

- Parse environment variables for local filesystem paths ([#1])
- Parse environment variables for streamed video paths ([#1])

[#1]: https://github.com/ynmstudio/craft-video-dimensions-universal/issues/1

## 1.0.1 - 2025-05-05

### Fixed

- Add missing call to extract video dimensions for local video files

## 1.0.0 - 2025-05-01

### Added

- Initial release
- Automatic video dimension extraction
- Support for local and S3-hosted video files
- Proper error handling and logging
- Temporary file cleanup
