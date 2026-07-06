<?php

namespace ynmstudio\videodimensionsuniversal\models;

use craft\base\Model;

/**
 * Video Dimensions Universal settings
 *
 * Settings can be overridden via a `config/video-dimensions-universal.php`
 * file in the project.
 *
 * @package ynmstudio\videodimensionsuniversal\models
 */
class Settings extends Model
{
    /**
     * @var string Handle of the optional custom Number field that receives the
     * video duration in seconds. If the asset's field layout has no field with
     * this handle, duration storage is skipped silently. The default is
     * plugin-scoped (`vdu` prefix) so the plugin never writes into a
     * pre-existing field by accident; point it at an unprefixed handle here if
     * you want that.
     */
    public string $durationFieldHandle = 'vduVideoDuration';

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['durationFieldHandle'], 'required'],
            [['durationFieldHandle'], 'string'],
        ];
    }
}
