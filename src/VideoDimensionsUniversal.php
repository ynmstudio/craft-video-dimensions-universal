<?php

namespace ynmstudio\videodimensionsuniversal;

use Craft;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\ModelEvent;
use craft\helpers\FileHelper;
use craft\records\Asset as AssetRecord;
use craft\models\Volume;
use craft\base\Fs as BaseFs;
use craft\cloud\fs\Fs as CloudFs;
use yii\base\Event;
use getID3;

/**
 * Video Dimensions Universal plugin for Craft CMS
 *
 * This plugin automatically extracts and stores the width and height of video assets
 * when they are uploaded or saved in Craft CMS. It supports local, remote, and Craft Cloud filesystems.
 *
 * - For local filesystems, it reads the file directly from disk.
 * - For remote/cloud filesystems (including Craft Cloud), it streams the file to a temp location for analysis.
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
     * Handle asset save event. If the asset is a video, extract and store its dimensions.
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

        try {
            $dimensions = $this->processVideoAsset($asset);
            if ($dimensions) {
                $this->updateAssetDimensions($asset, $dimensions);
            }
        } catch (\Throwable $e) {
            Craft::error('Error processing video dimensions: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Process a video asset and return its dimensions.
     *
     * @param Asset $asset The video asset to process
     * @return array{width: int, height: int}|null Array with 'width' and 'height' keys, or null if dimensions couldn't be determined
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
     * Process a locally stored video asset and return its dimensions.
     *
     * @param Asset $asset The video asset
     * @param Fs $filesystem The local filesystem
     * @param Volume $volume The asset volume
     * @return array|null The getID3 analysis result
     */
    protected function processLocalVideo(Asset $asset, BaseFs $filesystem, Volume $volume): ?array
    {
        $fsPath = Craft::getAlias($filesystem->path);
        $subPath = Craft::getAlias($volume->subpath);
        $assetFilePath = FileHelper::normalizePath(
            $fsPath . DIRECTORY_SEPARATOR . $subPath . DIRECTORY_SEPARATOR . $asset->getPath()
        );

        return $this->getID3Instance()->analyze($assetFilePath);
    }

    /**
     * Process a streamed video asset (remote/cloud) and return its dimensions.
     *
     * @param Asset $asset The video asset
     * @param BaseFs|CloudFs $filesystem The remote/cloud filesystem
     * @return array{width: int, height: int}|null Array with 'width' and 'height' keys, or null if dimensions couldn't be determined
     */
    protected function processStreamedVideo(Asset $asset, $filesystem): ?array
    {
        $tempPath = $this->createTempDirectory();
        $tempFile = $tempPath . DIRECTORY_SEPARATOR . $asset->filename;

        // NOTE: In this Craft Cloud setup, the subpath must be prepended to the asset path for getFileStream to work correctly.
        $expectedPath = $asset->getPath();
        $subPath = $asset->getVolume()->subpath ?? '';
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
            return $this->extractDimensions($analysis);
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
     * Extract width and height from getID3 analysis result.
     *
     * @param array $file The getID3 analysis result
     * @return array{width: int, height: int}|null The extracted dimensions, or null if not found
     */
    protected function extractDimensions(array $file): ?array
    {
        if (!isset($file['video']['resolution_x'], $file['video']['resolution_y'])) {
            return null;
        }

        return [
            'width' => $file['video']['resolution_x'],
            'height' => $file['video']['resolution_y']
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
