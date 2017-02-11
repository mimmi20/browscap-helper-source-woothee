<?php

namespace BrowscapHelper\Source;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
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
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    private $output = null;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger = null;

    /**
     * @param \Psr\Log\LoggerInterface                          $logger
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    public function __construct(LoggerInterface $logger, OutputInterface $output)
    {
        $this->logger = $logger;
        $this->output = $output;
    }

    /**
     * @param int $limit
     *
     * @return string[]
     */
    public function getUserAgents($limit = 0)
    {
        $counter   = 0;
        $allAgents = [];

        foreach ($this->loadFromPath() as $data) {
            if ($limit && $counter >= $limit) {
                return;
            }

            foreach ($data as $row) {
                if ($limit && $counter >= $limit) {
                    return;
                }

                if (empty($row['target'])) {
                    continue;
                }

                if (array_key_exists($row['target'], $allAgents)) {
                    continue;
                }

                yield $row['target'];
                $allAgents[$row['target']] = 1;
                ++$counter;
            }
        }
    }

    /**
     * @return \UaResult\Result\Result[]
     */
    public function getTests()
    {
        $allTests = [];

        foreach ($this->loadFromPath() as $data) {
            foreach ($data as $row) {
                if (empty($row['target'])) {
                    continue;
                }

                if (array_key_exists($row['target'], $allTests)) {
                    continue;
                }

                $request  = (new GenericRequestFactory())->createRequestForUserAgent($row['target']);
                $browser  = new Browser(null);
                $device   = new Device(null, null);
                $platform = new Os(null, null);
                $engine   = new Engine(null);

                yield $row['target'] => new Result($request, $device, $platform, $browser, $engine);
                $allTests[$row['target']] = 1;
            }
        }
    }

    /**
     * @return \Generator
     */
    private function loadFromPath()
    {
        $path = 'vendor/woothee/woothee-testset/testsets';

        if (!file_exists($path)) {
            return;
        }

        $this->output->writeln('    reading path ' . $path);

        $iterator = new \RecursiveDirectoryIterator($path);

        foreach (new \RecursiveIteratorIterator($iterator) as $file) {
            /** @var $file \SplFileInfo */
            if (!$file->isFile()) {
                continue;
            }

            $filepath = $file->getPathname();

            $this->output->writeln('    reading file ' . str_pad($filepath, 100, ' ', STR_PAD_RIGHT));
            switch ($file->getExtension()) {
                case 'yaml':
                    yield Yaml::parse(file_get_contents($filepath));
                    break;
                default:
                    // do nothing here
                    break;
            }
        }
    }
}
