# Universal Video Dimensions plugin for Craft CMS 5.x

This plugin automatically extracts and saves video dimensions after uploading video files in Craft CMS. It supports both local files and files hosted on S3 or other remote filesystems.

## Requirements

- Craft CMS 5.0.0 or later
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

## Support

If you encounter any issues or have questions, please create an issue on GitHub:  
[https://github.com/ynmstudio/craft-video-dimensions-universal/issues](https://github.com/ynmstudio/craft-video-dimensions-universal/issues)

## License

Copyright © Yil & Mann GbR
