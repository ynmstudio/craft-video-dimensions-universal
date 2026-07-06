<?php

namespace ynmstudio\videodimensionsuniversal;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\ModelEvent;
use craft\fields\Number as NumberField;
use craft\helpers\FileHelper;
use craft\helpers\App;
use craft\records\Asset as AssetRecord;
use craft\models\Volume;
use craft\base\Fs as BaseFs;
use craft\cloud\fs\Fs as CloudFs;
use yii\base\Event;
use ynmstudio\videodimensionsuniversal\models\Settings;
use getID3;

/**
 * Video Dimensions Universal plugin for Craft CMS
 *
 * This plugin automatically extracts and stores the width and height of video assets
 * when they are uploaded or saved in Craft CMS. It supports local, remote, and Craft Cloud filesystems.
 *
 * - For local filesystems, it reads the file directly from disk.
 * - For remote/cloud filesystems (including Craft Cloud), it streams the file to a temp location for analysis.
 * - If the asset's field layout contains a Number field with the configured handle
 *   (`vduVideoDuration` by default), the video duration in seconds is stored there as well.
 *
 * Uses getID3 for video analysis.
 *
 * @package ynmstudio\videodimensionsuniversal
 */
class VideoDimensionsUniversal extends Plugin
{
    public static $plugin;

    /**
     * @var string The plugin schema version
     */
    public string $schemaVersion = '1.0.0';

    /**
     * @var getID3|null The getID3 instance (for video analysis)
     */
    protected ?getID3 $getID3 = null;

    /**
     * @var array<int, bool> IDs of assets currently being re-saved by this plugin,
     * used to skip the save events those re-saves trigger
     */
    private array $internalSaveAssetIds = [];

    /**
     * Initialize the plugin and register event listeners.
     *
     * @return void
     */
    public function init()
    {
        parent::init();
        self::$plugin = $this;

        $this->initializeEventListeners();

        Craft::info(
            Craft::t(
                'video-dimensions-universal',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }

    /**
     * Register event listeners for asset save events.
     *
     * @return void
     */
    protected function initializeEventListeners(): void
    {
        Event::on(
            Asset::class,
            Asset::EVENT_AFTER_SAVE,
            [$this, 'handleAssetSave']
        );
    }

    /**
     * Create the plugin settings model.
     *
     * @return Model|null
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Handle asset save event. If the asset is a video, extract and store its
     * dimensions and (if a matching custom field exists) its duration.
     *
     * @param ModelEvent $event
     * @return void
     */
    public function handleAssetSave(ModelEvent $event): void
    {
        /** @var Asset $asset */
        $asset = $event->sender;
        if ($asset->kind !== 'video') {
            return;
        }

        // Skip saves triggered by this plugin itself and multi-site propagation
        // re-saves — both would re-download and re-analyze the same file.
        if (isset($this->internalSaveAssetIds[$asset->id]) || $asset->propagating) {
            return;
        }

        try {
            $metadata = $this->processVideoAsset($asset);
            if ($metadata) {
                $this->updateAssetDimensions($asset, $metadata);
                $this->updateVideoDuration($asset, $metadata);
            }
        } catch (\Throwable $e) {
            Craft::error('Error processing video metadata: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Process a video asset and return its metadata.
     *
     * @param Asset $asset The video asset to process
     * @return array{width: int, height: int, duration: float|null}|null The extracted metadata, or null if dimensions couldn't be determined
     * @throws \Exception
     */
    protected function processVideoAsset(Asset $asset): ?array
    {
        $volume = $asset->getVolume();
        $filesystem = $volume->getFs();

        // If it's a local filesystem, use the direct path
        if ($filesystem instanceof \craft\fs\Local) {
            return $this->processLocalVideo($asset, $filesystem, $volume);
        }

        // For all other filesystems (including CloudFs), use the stream approach
        return $this->processStreamedVideo($asset, $filesystem);
    }

    /**
     * Process a locally stored video asset and return its metadata.
     *
     * @param Asset $asset The video asset
     * @param Fs $filesystem The local filesystem
     * @param Volume $volume The asset volume
     * @return array{width: int, height: int, duration: float|null}|null The extracted metadata, or null if dimensions couldn't be determined
     */
    protected function processLocalVideo(Asset $asset, BaseFs $filesystem, Volume $volume): ?array
    {
        $fsPath = App::parseEnv($filesystem->path);
        // `Volume::$subpath` only exists in Craft 5; fall back to '' on Craft 4 (handled via Yii's __isset).
        $subPath = App::parseEnv($volume->subpath ?? '');
        $assetFilePath = FileHelper::normalizePath(
            $fsPath . DIRECTORY_SEPARATOR . $subPath . DIRECTORY_SEPARATOR . $asset->getPath()
        );

        $analysis = $this->getID3Instance()->analyze($assetFilePath);
        return $this->extractVideoMetadata($analysis);
    }

    /**
     * Process a streamed video asset (remote/cloud) and return its metadata.
     *
     * @param Asset $asset The video asset
     * @param BaseFs|CloudFs $filesystem The remote/cloud filesystem
     * @return array{width: int, height: int, duration: float|null}|null The extracted metadata, or null if dimensions couldn't be determined
     */
    protected function processStreamedVideo(Asset $asset, $filesystem): ?array
    {
        $tempPath = $this->createTempDirectory();
        $tempFile = $tempPath . DIRECTORY_SEPARATOR . $asset->filename;

        // NOTE: In this Craft Cloud setup, the subpath must be prepended to the asset path for getFileStream to work correctly.
        $expectedPath = $asset->getPath();
        $subPath = App::parseEnv($asset->getVolume()->subpath ?? '');
        if ($subPath && strpos($expectedPath, $subPath) !== 0) {
            $expectedPath = $subPath . '/' . $expectedPath;
        }

        $stream = $filesystem->getFileStream($expectedPath);
        if (!$stream) {
            throw new \Exception('Could not get file stream for ' . $expectedPath);
        }
        file_put_contents($tempFile, stream_get_contents($stream));
        $analysis = $this->getID3Instance()->analyze($tempFile);
        try {
            return $this->extractVideoMetadata($analysis);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            if (file_exists($tempPath)) {
                \craft\helpers\FileHelper::removeDirectory($tempPath);
            }
        }
    }

    /**
     * Extract width, height and duration from getID3 analysis result.
     *
     * @param array $file The getID3 analysis result
     * @return array{width: int, height: int, duration: float|null}|null The extracted metadata, or null if dimensions were not found
     */
    protected function extractVideoMetadata(array $file): ?array
    {
        if (!isset($file['video']['resolution_x'], $file['video']['resolution_y'])) {
            return null;
        }

        return [
            'width' => $file['video']['resolution_x'],
            'height' => $file['video']['resolution_y'],
            'duration' => isset($file['playtime_seconds']) ? (float)$file['playtime_seconds'] : null,
        ];
    }

    /**
     * Update asset dimensions in the database.
     *
     * @param Asset $asset The asset to update
     * @param array{width: int, height: int} $dimensions The dimensions to store
     * @return void
     */
    protected function updateAssetDimensions(Asset $asset, array $dimensions): void
    {
        $assetRecord = AssetRecord::findOne($asset->id);
        if ($assetRecord) {
            $assetRecord->width = $dimensions['width'];
            $assetRecord->height = $dimensions['height'];
            $assetRecord->save(true);
        }
    }

    /**
     * Store the video duration in the asset's custom duration field, if the
     * field layout has one. Skips silently when the field doesn't exist, so the
     * feature stays zero-config and optional.
     *
     * @param Asset $asset The asset to update
     * @param array{width: int, height: int, duration: float|null} $metadata The extracted metadata
     * @return void
     */
    protected function updateVideoDuration(Asset $asset, array $metadata): void
    {
        $duration = $metadata['duration'];
        if ($duration === null) {
            return;
        }

        /** @var Settings $settings */
        $settings = $this->getSettings();
        $fieldHandle = $settings->durationFieldHandle;
        $field = $asset->getFieldLayout()?->getFieldByHandle($fieldHandle);
        if (!$field) {
            return;
        }

        // Number fields serialize at their configured precision — compare (and
        // store) at that precision, or low-precision fields would look changed
        // on every save.
        if ($field instanceof NumberField) {
            $duration = round($duration, $field->decimals);
        }

        $currentValue = $asset->getFieldValue($fieldHandle);
        if ($currentValue !== null && abs((float)$currentValue - $duration) < 0.000001) {
            return;
        }

        $asset->setFieldValue($fieldHandle, $duration);

        // Asset::afterSave() rewrites width/height from the element's own
        // attributes, so sync them with what updateAssetDimensions() just stored
        // before re-saving, or the re-save would wipe them.
        $asset->setWidth($metadata['width']);
        $asset->setHeight($metadata['height']);

        $this->internalSaveAssetIds[$asset->id] = true;
        try {
            // runValidation=false: an unrelated invalid field must not block the
            // duration write. propagate=false: when this runs inside an outer
            // save, that save propagates this same (mutated) element to the other
            // sites right after this listener returns.
            Craft::$app->getElements()->saveElement($asset, false, false, false);
        } finally {
            unset($this->internalSaveAssetIds[$asset->id]);
        }
    }

    /**
     * Create a temporary directory for video processing.
     *
     * @return string The path to the temp directory
     */
    protected function createTempDirectory(): string
    {
        $tempPath = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . 'video-dimensions-universal';
        if (!file_exists($tempPath)) {
            FileHelper::createDirectory($tempPath);
        }
        return $tempPath;
    }

    /**
     * Get or create the getID3 instance for video analysis.
     *
     * @return getID3 The getID3 instance
     */
    protected function getID3Instance(): getID3
    {
        if ($this->getID3 === null) {
            $this->getID3 = new getID3;
        }
        return $this->getID3;
    }
}
