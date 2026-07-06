# Release Notes for Video Dimensions Universal

## 1.2.0 - 2026-07-06

### Added

- Optional video duration storage: if the asset's field layout contains a Number field with the handle `vduVideoDuration`, the duration (in seconds, extracted in the same getID3 pass as the dimensions) is written to it. Useful for fragmented MP4 delivery (e.g. TwicPics), where browsers can't determine the total duration up front.
- `durationFieldHandle` plugin setting to override the field handle via `config/video-dimensions-universal.php`. The default handle is plugin-scoped (`vdu` prefix) so the plugin never writes into a pre-existing field by accident.

### Changed

- Skip video re-analysis during multi-site propagation saves — the file was previously downloaded and analyzed once per site

### Upgrade notes

Existing installs are unaffected unless they add the duration field — without it, the plugin behaves exactly as before. To populate existing assets after adding the field, run `php craft resave/assets --volume=<volumeHandle>`. Note that this downloads each remote/cloud video once for analysis.

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
