<?php
/**
 * This file is part of the browscap-helper-source-woothee package.
 *
 * Copyright (c) 2016-2017, Thomas Mueller <mimmi20@live.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);
namespace BrowscapHelper\Source;

use BrowserDetector\Loader\NotFoundException;
use BrowserDetector\Version\Version;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use UaDataMapper\BrowserNameMapper;
use UaDataMapper\BrowserTypeMapper;
use UaDataMapper\BrowserVersionMapper;
use UaDataMapper\PlatformNameMapper;
use UaDataMapper\PlatformVersionMapper;
use UaResult\Browser\Browser;
use UaResult\Device\Device;
use UaResult\Engine\Engine;
use UaResult\Os\Os;
use UaResult\Result\Result;
use Wurfl\Request\GenericRequestFactory;

/**
 * Class DirectorySource
 *
 * @author  Thomas Mueller <mimmi20@live.de>
 */
class WootheeSource implements SourceInterface
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger = null;

    /**
     * @var \Psr\Cache\CacheItemPoolInterface|null
     */
    private $cache = null;

    /**
     * @param \Psr\Log\LoggerInterface          $logger
     * @param \Psr\Cache\CacheItemPoolInterface $cache
     */
    public function __construct(LoggerInterface $logger, CacheItemPoolInterface $cache)
    {
        $this->logger = $logger;
        $this->cache  = $cache;
    }

    /**
     * @param int $limit
     *
     * @return string[]
     */
    public function getUserAgents($limit = 0)
    {
        $counter = 0;

        foreach ($this->loadFromPath() as $row) {
            if ($limit && $counter >= $limit) {
                return;
            }

            $row = json_decode($row, false);

            yield $row->target;
            ++$counter;
        }
    }

    /**
     * @return \UaResult\Result\Result[]
     */
    public function getTests()
    {
        foreach ($this->loadFromPath() as $row) {
            $row     = json_decode($row, false);
            $request = (new GenericRequestFactory())->createRequestForUserAgent($row->target);

            $browserName = (new BrowserNameMapper())->mapBrowserName($row->name);

            try {
                $browserType = (new BrowserTypeMapper())->mapBrowserType($this->cache, $row->category);
            } catch (NotFoundException $e) {
                $this->logger->critical($e);
                $browserType = null;
            }

            $browser = new Browser(
                $browserName,
                null,
                (new BrowserVersionMapper())->mapBrowserVersion($row->version, $browserName),
                $browserType
            );

            if (!empty($row->os) && !in_array($row->os, ['iPad', 'iPhone'])) {
                $osName    = (new PlatformNameMapper())->mapOsName($row->os);
                $osVersion = (new PlatformVersionMapper())->mapOsVersion($row->os_version, $osName);

                if (!($osVersion instanceof Version)) {
                    $osVersion = null;
                }

                $os = new Os($osName, null, null, $osVersion);
            } else {
                $os = new Os(null, null);
            }

            $device = new Device(null, null);
            $engine = new Engine(null);

            yield $row['target'] => new Result($request, $device, $os, $browser, $engine);
        }
    }

    /**
     * @return string[]
     */
    private function loadFromPath()
    {
        $path = 'vendor/woothee/woothee-testset/testsets';

        if (!file_exists($path)) {
            return;
        }

        $this->logger->info('    reading path ' . $path);

        $allTests = [];
        $finder   = new Finder();
        $finder->files();
        $finder->name('*.yaml');
        $finder->ignoreDotFiles(true);
        $finder->ignoreVCS(true);
        $finder->sortByName();
        $finder->ignoreUnreadableDirs();
        $finder->in($path);

        foreach ($finder as $file) {
            /** @var \Symfony\Component\Finder\SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }

            if ('yaml' !== $file->getExtension()) {
                continue;
            }

            $filepath = $file->getPathname();

            $this->logger->info('    reading file ' . str_pad($filepath, 100, ' ', STR_PAD_RIGHT));
            $data = Yaml::parse(file_get_contents($filepath));

            if (!is_array($data)) {
                continue;
            }

            foreach ($data as $row) {
                if (empty($row['target'])) {
                    continue;
                }

                if (array_key_exists($row['target'], $allTests)) {
                    continue;
                }

                yield json_encode($row, JSON_FORCE_OBJECT);
                $allTests[$row['target']] = 1;
            }
        }
    }
}
