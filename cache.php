<?php

namespace App\Services\Response;

class Cache {
    private $cacheDir;
    private $cacheFile;
    private $baseCacheFile;
    public $cacheDuration;

    public function __construct($controllerFilePath = __FILE__, $cacheDuration = 300, $cacheDir = null, $additionalIdentifier = '') {
        $this->cacheDir = rtrim(string: $cacheDir ?? dirname(path: __DIR__, levels: 3) . '/cache', characters: '/') . '/';
        $controllerName = basename(path: $controllerFilePath, suffix: '.php');

        // Create base cache file name without GET/POST parameters
        $this->baseCacheFile = $this->cacheDir . md5(string: $controllerName . $additionalIdentifier) . '.json';

        // Get parameters excluding 'nocache'
        $params = array_merge($_GET, $_POST);
        unset($params['nocache']);
        $requestParameters = '';
        if (!empty($params)) {
            array_walk_recursive(array: $params, callback: function (&$value): void {
                $value = htmlspecialchars(string: $value, flags: ENT_QUOTES, encoding: 'UTF-8');
            });
            ksort(array: $params);
            $requestParameters = json_encode(value: $params);
        }

        $this->cacheFile = $this->cacheDir . md5(string: $controllerName . $requestParameters . $additionalIdentifier) . '.json';
        $this->cacheDuration = $cacheDuration;

        if (!is_dir(filename: $this->cacheDir)) {
            if (!mkdir(directory: $this->cacheDir, permissions: 0755, recursive: true)) {
                throw new \Exception(message: "Failed to create cache directory: {$this->cacheDir}");
            }
        }
    }

    public function isValid(): bool {
        if (isset($_GET['nocache'])) {
            // Clear both the current cache file and the base cache file
            $this->clear();
            if ($this->cacheFile !== $this->baseCacheFile) {
                $this->clearBaseCache();
            }
            return false;
        }

        if ($this->cacheDuration === 0) {
            return false; // Cache disabled
        }

        if (file_exists(filename: $this->cacheFile)) {
            if ((time() - filemtime(filename: $this->cacheFile)) < $this->cacheDuration) {
                return true; // Cache is valid
            } else {
                // Cache has expired; delete the file
                if (!unlink(filename: $this->cacheFile)) {
                    error_log(message: "Failed to delete expired cache file: {$this->cacheFile}");
                }
                return false;
            }
        }
        return false;
    }

    private function clearBaseCache(): bool {
        if (file_exists(filename: $this->baseCacheFile)) {
            if (!unlink(filename: $this->baseCacheFile)) {
                error_log(message: "Failed to delete base cache file: {$this->baseCacheFile}");
                return false;
            }
            return true;
        }
        return false;
    }

    public function get(): bool|string {
        if ($this->isValid()) {
            $fp = fopen(filename: $this->cacheFile, mode: 'r');
            if (flock(stream: $fp, operation: LOCK_SH)) {
                $contents = stream_get_contents(stream: $fp);
                flock(stream: $fp, operation: LOCK_UN);
                fclose(stream: $fp);
                return $contents;
            }
            fclose(stream: $fp);
        }
        return false;
    }

    public function set($data): bool {
        $fp = fopen(filename: $this->cacheFile, mode: 'w');
        if (flock(stream: $fp, operation: LOCK_EX)) {
            $result = fwrite(stream: $fp, data: $data);
            fflush(stream: $fp);
            flock(stream: $fp, operation: LOCK_UN);
            fclose(stream: $fp);
            if ($result === false) {
                throw new \Exception(message: "Failed to write cache file: {$this->cacheFile}");
            }
            return true;
        } else {
            fclose(stream: $fp);
            throw new \Exception(message: "Failed to acquire lock for cache file: {$this->cacheFile}");
        }
    }

    public function clear(): bool {
        if (file_exists(filename: $this->cacheFile)) {
            if (!unlink(filename: $this->cacheFile)) {
                error_log(message: "Failed to delete cache file: {$this->cacheFile}");
                return false;
            }
            return true;
        }
        return false;
    }
}