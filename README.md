# Universal Video Dimensions plugin for Craft CMS 4.x & 5.x

This plugin automatically extracts and saves video dimensions after uploading video files in Craft CMS. It supports both local files and files hosted on S3 or other remote filesystems.

## Requirements

- Craft CMS 4.0.0 or later (Craft 4 and 5 are both supported)
- PHP 8.0.2 or later

## Installation

You can install this plugin from the Plugin Store or with Composer.

### From the Plugin Store

Go to the Plugin Store in your project's Control Panel and search for "Video Dimensions Universal". Then click "Install".

### With Composer

```bash
cd /path/to/your-project
composer require ynmstudio/craft-video-dimensions-universal
./craft plugin/install video-dimensions-universal
```

## Features

- Automatically extracts video dimensions upon upload
- Optionally stores the video duration in a custom field (see [Storing the video duration](#storing-the-video-duration-optional))
- Supports both local and remote files (e.g., S3)
- Works with any filesystem extending the `craft\base\Fs` class
- Updates asset records with correct width and height
- Handles errors gracefully with proper logging
- Cleans up temporary files

## Usage

After installation, simply upload a video file through the Craft CMS control panel. The plugin will:

1. Detect that the uploaded file is a video
2. Extract its dimensions
3. Save the dimensions to the asset record

You can access the dimensions in your templates the same way as it would be a image asset:

```twig
{% set video = entry.videoField.one() %}
{% if video %}
    Width: {{ video.width }}
    Height: {{ video.height }}
{% endif %}
```

## Storing the video duration (optional)

On-the-fly video transcoders like TwicPics deliver fragmented MP4 without total duration metadata, so browsers can't reliably show a video's length before enough of it is buffered. Storing the duration in the CMS lets your frontend render the correct total time immediately.

The plugin extracts the duration in the same analysis pass as the dimensions. Since assets have no native duration attribute, it is stored in a custom field — by convention:

1. Create a **Number** field with the handle `vduVideoDuration`. Give it enough decimal places (e.g. 3) if you want sub-second precision — the value is stored in seconds. (The default handle is plugin-scoped so the plugin never writes into a field you already use for something else.)
2. Add the field to your video volume's field layout.

That's it. From the next upload on, the duration is saved automatically:

```twig
{% set video = entry.videoField.one() %}
{% if video %}
    Duration: {{ video.vduVideoDuration }} seconds
{% endif %}
```

If no field with that handle exists, the plugin silently skips this step — nothing to configure, nothing breaks.

### Using a different field handle

Create a `config/video-dimensions-universal.php` file in your project to override the handle, e.g. to reuse an existing duration field:

```php
<?php

return [
    'durationFieldHandle' => 'videoDuration',
];
```

### GraphQL

Custom fields are exposed through Craft's GraphQL API automatically, so no extra setup is needed beyond including the volume in your schema:

```graphql
{
  asset(volume: "videos", kind: ["video"]) {
    url
    width
    height
    vduVideoDuration
  }
}
```

### Populating existing videos

Assets uploaded before the field existed can be backfilled with Craft's resave command:

```bash
php craft resave/assets --volume=<volumeHandle>
```

The plugin re-analyzes every video asset the command saves (other file kinds are skipped). Note that each remote/cloud video is downloaded once for analysis, so backfilling large volumes can take a while.

## Support

If you encounter any issues or have questions, please create an issue on GitHub:  
[https://github.com/ynmstudio/craft-video-dimensions-universal/issues](https://github.com/ynmstudio/craft-video-dimensions-universal/issues)

## License

Copyright © Yil & Mann GbR
