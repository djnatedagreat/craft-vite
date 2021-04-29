<?php
/**
 * Vite plugin for Craft CMS 3.x
 *
 * Allows the use of the Vite.js next generation frontend tooling with Craft CMS
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2021 nystudio107
 */

namespace nystudio107\vite\services;

use nystudio107\vite\Vite;

use Craft;
use craft\base\Component;
use craft\helpers\Html as HtmlHelper;
use craft\helpers\Json as JsonHelper;
use craft\helpers\UrlHelper;

use yii\caching\ChainedDependency;
use yii\caching\FileDependency;
use yii\caching\TagDependency;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

/**
 * @author    nystudio107
 * @package   Vite
 * @since     1.0.0
 */
class Connector extends Component
{
    // Constants
    // =========================================================================

    const VITE_CLIENT = '@vite/client.js';

    const CACHE_KEY = 'vite';
    const CACHE_TAG = 'vite';

    const DEVMODE_CACHE_DURATION = 1;

    const USER_AGENT_STRING = 'User-Agent:Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13';

    // Public Properties
    // =========================================================================

    /**
     * @var bool
     */
    public $useDevServer = true;

    /**
     * @var string
     */
    public $manifestPath = '@webroot/dist/manifest.json';

    /**
     * @var string
     */
    public $devServerPublic = 'http://localhost:3000/';

    /**
     * @var string
     */
    public $devServerInternal = 'http://craft-vite-buildchain:3000/';

    /**
     * @var string
     */
    public $serverPublic = 'http://localhost:8000/dist/';

    // Public Methods
    // =========================================================================

    /**
     * Return the appropriate tags to load the Vite script, either via the dev server or
     * extracting it from the manifest.json file
     *
     * @param string $path
     * @param bool $asyncCss
     * @param array $scriptTagAttrs
     * @param array $cssTagAttrs
     *
     * @return string
     */
    public function viteScript(string $path, $asyncCss = true, array $scriptTagAttrs = [], array $cssTagAttrs = []): string
    {
        if ($this->devServerRunning()) {
            return $this->viteDevServerScript($path, $scriptTagAttrs);
        }

        return $this->viteManifestScript($path, $asyncCss, $scriptTagAttrs, $cssTagAttrs);
    }

    /**
     * Return the script tag to load the script from the Vite dev server
     *
     * @param string $path
     * @param array $scriptTagAttrs
     *
     * @return string
     */
    public function viteDevServerScript(string $path, array $scriptTagAttrs = []): string
    {
        $lines = [];
        // Include the entry script
        $url = $this->createUrl($this->devServerPublic, $path);
        $lines[] = HtmlHelper::jsFile($url, array_merge([
            'type' => 'module',
        ], $scriptTagAttrs));

        return implode("\r\n", $lines);
    }

    /**
     * Return the script, module link, and CSS link tags for the script from the manifest.json file
     *
     * @param string $path
     * @param bool $asyncCss
     * @param array $scriptTagAttrs
     * @param array $cssTagAttrs
     *
     * @return string
     */
    public function viteManifestScript(string $path, bool $asyncCss = true, array $scriptTagAttrs = [], array $cssTagAttrs = []): string
    {
        $lines = [];
        // Grab the manifest
        $pathOrUrl = (string)Craft::parseEnv($this->manifestPath);
        $manifest = $this->fetchFile($pathOrUrl, [JsonHelper::class, 'decodeIfJson']);
        // If no manifest file is found, bail
        if ($manifest === null) {
            Craft::error('Manifest not found at ' . $this->manifestPath, __METHOD__);

            return '';
        }
        // Set the async CSS args
        $asyncArgs = [];
        if ($asyncCss) {
            $asyncArgs = [
                'media' => 'print',
                'onload' => "this.media='all'",
            ];
        }
        // Iterate through the manifest
        foreach ($manifest as $manifestFile => $entry) {
            if (isset($entry['isEntry']) && $entry['isEntry']) {
                // Include the entry script
                if (isset($entry['file']) && strpos($path, $manifestFile) !== false) {
                    $url = $this->createUrl($this->serverPublic, $entry['file']);
                    $lines[] = HtmlHelper::jsFile($url, array_merge([
                        'type' => 'module',
                        'crossorigin' => true,
                    ], $scriptTagAttrs));
                    // If there are any imports, include them
                    if (isset($entry['imports'])) {
                        foreach ($entry['imports'] as $import) {
                            if (isset($manifest[$import]['file'])) {
                                $url = $this->createUrl($this->serverPublic, $manifest[$import]['file']);
                                $lines[] = HtmlHelper::cssFile($url, array_merge([
                                    'rel' => 'modulepreload',
                                ], $cssTagAttrs));
                            }
                        }
                    }
                    // If there are any CSS files, include them
                    if (isset($entry['css'])) {
                        foreach ($entry['css'] as $css) {
                            $url = $this->createUrl($this->serverPublic, $css);
                            $lines[] = HtmlHelper::cssFile($url, array_merge([
                                'rel' => 'stylesheet',
                            ], $asyncArgs, $cssTagAttrs));
                        }
                    }
                }
            }
        }

        return implode("\r\n", $lines);
    }

    /**
     * Determine whether the Vite dev server is running
     *
     * @return bool
     */
    public function devServerRunning(): bool
    {
        if (!$this->useDevServer) {
            return false;
        }

        $url = $this->createUrl($this->devServerInternal, self::VITE_CLIENT);
        if ($this->fetchFile($url) === null) {
            return false;
        }

        return true;
    }

    /**
     * Combine a path with a URL to create a URL
     *
     * @param string $url
     * @param string $path
     *
     * @return string
     */
    public function createUrl(string $url, string $path): string
    {
        $url = (string)Craft::parseEnv($url);
        return rtrim($url, '/') . '/' . trim($path, '/');
    }

    /**
     * Return the contents of a local or remote file, or null
     *
     * @param string $pathOrUrl
     * @param callable|null $callback
     * @return mixed
     */
    public function fetchFile(string $pathOrUrl, callable $callback = null)
    {
        // Create the dependency tags
        $dependency = new TagDependency([
            'tags' => [
                self::CACHE_TAG,
                self::CACHE_TAG . $pathOrUrl,
            ],
        ]);
        // If this is a file path such as for the `manifest.json`, add a FileDependency so it's cache bust if the file changes
        if (!UrlHelper::isAbsoluteUrl($pathOrUrl)) {
            $dependency = new ChainedDependency([
                'dependencies' => [
                    new FileDependency([
                        'fileName' => $pathOrUrl
                    ]),
                    $dependency
                ]
            ]);
        }
        // Set the cache duration based on devMode
        $cacheDuration = Craft::$app->getConfig()->getGeneral()->devMode
            ? self::DEVMODE_CACHE_DURATION
            : null;
        // Get the result from the cache, or parse the file
        $cache = Craft::$app->getCache();
        $settings = Vite::$plugin->getSettings();
        $cacheKeySuffix = $settings->cacheKeySuffix ?? '';
        $file = $cache->getOrSet(
            self::CACHE_KEY . $cacheKeySuffix . $pathOrUrl,
            function () use ($pathOrUrl, $callback) {
                $contents = null;
                $result = null;
                if (UrlHelper::isAbsoluteUrl($pathOrUrl)) {
                    // See if we can connect to the server
                    $clientOptions = [
                        RequestOptions::HTTP_ERRORS => false,
                        RequestOptions::CONNECT_TIMEOUT => 3,
                        RequestOptions::VERIFY => false,
                        RequestOptions::TIMEOUT => 5,
                    ];
                    $client = new Client($clientOptions);
                    try {
                        $response = $client->request('GET', $pathOrUrl, [
                            RequestOptions::HEADERS => [
                                'User-Agent' => self::USER_AGENT_STRING,
                                'Accept' => '*/*',
                            ],
                        ]);
                        if ($response->getStatusCode() === 200) {
                            $contents = $response->getBody()->getContents();
                        }
                    } catch (\Throwable $e) {
                        Craft::error($e, __METHOD__);
                    }
                } else {
                    $contents = @file_get_contents($pathOrUrl);
                }
                if ($contents) {
                    $result = $contents;
                    if ($callback) {
                        $result = $callback($result);
                    }
                }

                return $result;
            },
            $cacheDuration,
            $dependency
        );

        return $file;
    }

    // Protected Methods
    // =========================================================================
}
