<?php

/*
 * This file is part of the Yosymfony\Spress.
 *
 * (c) YoSymfony <http://github.com/yosymfony>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Yosymfony\Spress\Core\DataSource\Filesystem;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Yosymfony\Spress\Core\DataSource\AbstractDataSource;
use Yosymfony\Spress\Core\DataSource\Item;

/**
 * Data source for the filesystem.
 *
 * Params:
 *  - source_root       : the root directory for the main content.
 *  - layouts_root      : the root directory for the layouts.
 *  - includes_root     : the root directory for the includes.
 *  - posts_root        : the root directory for the posts.
 *  - include 		    : force to include files or directories.
 *  - exclude		    : force to exclude files or directories.
 *  - attribute_syntax  : syntax for describing attributes: "yaml" or "json". "yaml" by default.
 *
 * @author Victor Puertas <vpgugr@gmail.com>
 */
class FilesystemDataSource extends AbstractDataSource
{
    private $items;
    private $layouts;
    private $includes;
    private $include;
    private $exclude;
    private $orgDir;
    private $attributeParser;
    private $attributesFileSufix;

    /**
     * @inheritDoc
     */
    public function getItems()
    {
        return $this->items;
    }

    /**
     * @inheritDoc
     */
    public function getLayouts()
    {
        return $this->layouts;
    }

    /**
     * @inheritDoc
     */
    public function getIncludes()
    {
        return $this->includes;
    }

    /**
     * @inheritDoc
     */
    public function configure()
    {
        $this->items = [];
        $this->layouts = [];
        $this->includes = [];
        $this->include = [];
        $this->exclude = [];

        if (false === isset($this->params['source_root'])) {
            throw new \RuntimeException('The data source expected param: "source_root".');
        }

        if (false === is_string($this->params['source_root'])) {
            throw new \RuntimeException('The data source expected a string at param: "source_root".');
        }

        if (true === isset($this->params['posts_root']) && false === is_string($this->params['posts_root'])) {
            throw new \RuntimeException('The data source expected a string at param: "posts_root".');
        }

        if (true === isset($this->params['layouts_root']) && false === is_string($this->params['layouts_root'])) {
            throw new \RuntimeException('The data source expected a string at param: "layouts_root".');
        }

        if (true === isset($this->params['includes_root']) && false === is_string($this->params['includes_root'])) {
            throw new \RuntimeException('The data source expected a string at param: "includes_root".');
        }

        if (true === isset($this->params['include'])) {
            if (true === is_array($this->params['include'])) {
                $this->include = $this->params['include'];
            } else {
                throw new \RuntimeException('The data source expected an array at param: "include".');
            }
        }

        if (true === isset($this->params['exclude'])) {
            if (true === is_array($this->params['exclude'])) {
                $this->exclude = $this->params['exclude'];
            } else {
                throw new \RuntimeException('The data source expected an array at param: "exclude".');
            }
        }

        if (false === isset($this->params['attribute_syntax'])) {
            $this->params['attribute_syntax'] = 'yaml';
        }

        switch ($this->params['attribute_syntax']) {
            case 'yaml':
                $this->attributesFileSufix = 'meta.yml';
                $this->attributeParser = new AttributeParser(AttributeParser::PARSER_YAML);
                break;
            case 'json':
                $this->attributesFileSufix = 'meta.json';
                $this->attributeParser = new AttributeParser(AttributeParser::PARSER_JSON);
                break;
            default:
                throw new \RuntimeException(sprintf('Invalid value for attributte "attribute_syntax": "%s".', $this->params['attribute_syntax']));
        }
    }

    /**
     * @inheritDoc
     */
    public function process()
    {
        $this->processContentFiles();
        $this->processLayoutFiles();
        $this->processIncludeFiles();
    }

    public function setUp()
    {
        $this->orgDir = getcwd();
        $this->setCurrentDir($this->params['source_root']);
    }

    public function tearDown()
    {
        $this->setCurrentDir($this->orgDir);
    }

    private function processContentFiles()
    {
        $includedFiles = [];

        $finder = new Finder();
        $finder->in($this->params['source_root'])
            ->notPath('/^_/')
            ->notPath('config.yml')
            ->notPath('/config_.+\.yml/')
            ->notName('*.'.$this->attributesFileSufix)
            ->files();

        if (isset($this->params['posts_root'])) {
            $finder->in($this->params['posts_root']);
        }

        foreach ($this->include as $item) {
            if (is_dir($item)) {
                $finder->in($item);
            } elseif (is_file($item)) {
                $includedFiles[] = new SplFileInfo($item, '', pathinfo($item, PATHINFO_BASENAME));
            }
        }

        $finder->append($includedFiles);

        foreach ($this->exclude as $item) {
            $finder->notPath($item);
        }

        $this->processItems($finder, Item::TYPE_ITEM);
    }

    private function processLayoutFiles()
    {
        if (false === isset($this->params['layouts_root'])) {
            return;
        }

        $finder = new Finder();
        $finder->in($this->params['layouts_root'])
            ->files();

        $this->processItems($finder, Item::TYPE_LAYOUT);
    }

    private function processIncludeFiles()
    {
        if (false === isset($this->params['includes_root'])) {
            return;
        }

        $finder = new Finder();
        $finder->in($this->params['includes_root'])
            ->files();

        $this->processItems($finder, Item::TYPE_INCLUDE);
    }

    private function processItems(Finder $finder, $type)
    {
        foreach ($finder as $file) {
            $id = $file->getRelativePathname();
            $isBinary = $this->isBinary($file->getPathname());
            $contentRaw = $isBinary ? '' : $file->getContents();

            $item = new Item($contentRaw, $id, [], $isBinary, $type);
            $item->setPath($file->getRelativePathname());

            switch ($type) {
                case Item::TYPE_LAYOUT:
                    $this->processAttributes($item);
                    $this->layouts[$id] = $item;
                    break;
                case Item::TYPE_INCLUDE:
                    $this->includes[$id] = $item;
                    break;
                default:
                    $this->processAttributes($item);
                    $this->items[$id] = $item;
                    break;
            }
        }
    }

    private function processAttributes(Item $item)
    {
        if (true === $item->isBinary()) {
            return;
        }

        $attributes = $this->attributeParser->getAttributesFromFrontmatter($item->getContent());
        $content = $this->attributeParser->getContentFromFrontmatter($item->getContent());

        if (0 === count($attributes)) {
            $attributesFile = $this->getAttributesFilename($item);

            if (file_exists($attributesFile)) {
                $contentFile = file_get_contents($attributesFile);
                $this->attributes = $this->attributeParser->getAttributesFromString($contentFile);
            }
        }

        $item->setContent($content, Item::SNAPSHOT_RAW);
        $item->setAttributes($attributes);
    }

    private function isBinary($filename)
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filename);

        return 'text' !== substr($mimeType, 0, 4);
    }

    private function getAttributesFilename(Item $item)
    {
        $fileInfo = new \splfileinfo($item->getPath());
        $path = $fileInfo->getPath();
        $basename = $fileInfo->getBasename('.'.$fileInfo->getExtension());
        $filename = $path ? sprintf('%s/%s', $path, $basename) : $basename;

        return $filename.'.'.$this->attributesFileSufix;
    }

    private function setCurrentDir($path)
    {
        if (false === chdir($path)) {
            throw new \InvalidArgumentException(sprintf('Error changing the current dir to "%s"', $path));
        }
    }
}