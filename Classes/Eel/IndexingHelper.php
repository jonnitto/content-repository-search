<?php
declare(strict_types=1);

namespace Neos\ContentRepository\Search\Eel;

/*
 * This file is part of the Neos.ContentRepository.Search package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Search\AssetExtraction\AssetExtractorInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Search\Exception\IndexingException;
use Psr\Log\LoggerInterface;
use Litefyr\Presentation\EelHelper\PresentationHelper;

/**
 * IndexingHelper
 */
class IndexingHelper implements ProtectedContextAwareInterface
{
    /**
     * @Flow\InjectConfiguration(package="Litefyr.Meilisearch", path="fulltextPlain")
     * @var string
     */
    protected $fulltextPlain;

    /**
     * @Flow\Inject
     * @var PresentationHelper
     */
    protected $presentationHelper;


    /**
     * @Flow\Inject
     * @var AssetExtractorInterface
     */
    protected $assetExtractor;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Build all path prefixes. From an input such as:
     *
     *   /foo/bar/baz
     *
     * it emits an array with:
     *
     *   /
     *   /foo
     *   /foo/bar
     *   /foo/bar/baz
     *
     * This method works both with absolute and relative paths. If a relative path is given,
     * the returned array will lack the first element and the leading slashes, obviously.
     *
     * @param string $path
     * @return array<string>
     */
    public function buildAllPathPrefixes(string $path): array
    {
        if ($path === '') {
            return [];
        }

        if ($path === '/') {
            return ['/'];
        }

        $currentPath = '';
        $pathPrefixes = [];
        if (strpos($path, '/') === 0) {
            $currentPath = '/';
            $pathPrefixes[] = $currentPath;
        }
        $path = ltrim($path, '/');

        foreach (explode('/', $path) as $pathPart) {
            $currentPath .= $pathPart . '/';
            $pathPrefixes[] = rtrim($currentPath, '/');
        }

        return $pathPrefixes;
    }

    /**
     * Returns an array of node type names including the passed $nodeType and all its supertypes, recursively
     *
     * @param NodeType $nodeType
     * @return array<String>
     */
    public function extractNodeTypeNamesAndSupertypes(NodeType $nodeType): array
    {
        $nodeTypeNames = [];
        $this->extractNodeTypeNamesAndSupertypesInternal($nodeType, $nodeTypeNames);
        return array_values($nodeTypeNames);
    }

    /**
     * Recursive function for fetching all node type names
     *
     * @param NodeType $nodeType
     * @param array $nodeTypeNames
     * @return void
     */
    protected function extractNodeTypeNamesAndSupertypesInternal(NodeType $nodeType, array &$nodeTypeNames): void
    {
        $nodeTypeNames[$nodeType->getName()] = $nodeType->getName();
        foreach ($nodeType->getDeclaredSuperTypes() as $superType) {
            $this->extractNodeTypeNamesAndSupertypesInternal($superType, $nodeTypeNames);
        }
    }

    /**
     * Convert an array of nodes to an array of node identifiers
     *
     * @param array<NodeInterface> $nodes
     * @return array
     */
    public function convertArrayOfNodesToArrayOfNodeIdentifiers($nodes): array
    {
        if (!is_array($nodes) && !$nodes instanceof \Traversable) {
            return [];
        }
        $nodeIdentifiers = [];
        foreach ($nodes as $node) {
            $nodeIdentifiers[] = $node->getIdentifier();
        }

        return $nodeIdentifiers;
    }

    /**
     * Convert an array of nodes to an array of node property
     *
     * @param array<NodeInterface> $nodes
     * @param string $propertyName
     * @return array
     */
    public function convertArrayOfNodesToArrayOfNodeProperty($nodes, string $propertyName): array
    {
        if (!is_array($nodes) && !$nodes instanceof \Traversable) {
            return [];
        }
        $nodeProperties = [];
        foreach ($nodes as $node) {
            $nodeProperties[] = $node->getProperty($propertyName);
        }

        return $nodeProperties;
    }

    private function litefyrCleanup($string) {
        // Remove ⚑, as it get replaced with the logo
        $string = str_replace('⚑', '', (string) $string);

        // Remove typewriter
        return $this->presentationHelper->removeTypewriter($string);
    }

    /**
     *
     * @param $string
     * @return array
     */
    public function extractHtmlTags($string): array
    {
        if (!$string || trim($string) === "") {
            return [];
        }

        // prevents concatenated words when stripping tags afterwards
        $string = str_replace(['<', '>'], [' <', '> '], $string);

        $string = $this->litefyrCleanup($string);

        $parts = [
            'text' => '',
        ];

        if ($this->fulltextPlain) {
            $plainText = strip_tags($string);
            $plainText = preg_replace('/\s+/u', ' ', $plainText);
            $parts['text'] = $plainText;
        }

        // strip all tags except h1-6
        $string = strip_tags($string, '<h1><h2><h3><h4><h5><h6>');

        while ($string !== '') {
            $matches = [];
            if (preg_match('/<(h1|h2|h3|h4|h5|h6)[^>]*>.*?<\/\1>/ui', $string, $matches, PREG_OFFSET_CAPTURE)) {
                $fullMatch = $matches[0][0];
                $startOfMatch = $matches[0][1];
                $tagName = $matches[1][0];

                if ($startOfMatch > 0) {
                    if (!$this->fulltextPlain) {
                        $parts['text'] .= substr($string, 0, $startOfMatch);
                    }
                    $string = substr($string, $startOfMatch);
                }
                if (!isset($parts[$tagName])) {
                    $parts[$tagName] = '';
                }

                $parts[$tagName] .= ' ' . $fullMatch;
                $string = substr($string, strlen($fullMatch));
            } else {
                // no h* found anymore in the remaining string
                if (!$this->fulltextPlain) {
                    $parts['text'] .= $string;
                }
                break;
            }
        }

        foreach ($parts as &$part) {
            $part = preg_replace('/\s+/u', ' ', strip_tags($part));
        }

        return $parts;
    }

    /**
     * @param string $bucketName
     * @param string $string
     * @return array
     */
    public function extractInto(string $bucketName, $string): array
    {
        $string = $this->litefyrCleanup($string);

        if ($this->fulltextPlain) {
            return [
                'text' => (string)$string,
                $bucketName => (string)$string
            ];
        }

        return [
            $bucketName => (string)$string
        ];
    }

    /**
     * Extract the asset content and meta data
     *
     * @param AssetInterface|AssetInterface[]|null $value
     * @param string $field
     * @return array|null|string
     * @throws IndexingException
     */
    public function extractAssetContent($value, string $field = 'content')
    {
        if (empty($value)) {
            return null;
        } elseif (is_array($value)) {
            $result = [];
            foreach ($value as $element) {
                $result[] = $this->extractAssetContent($element, $field);
            }
            return $result;
        } elseif ($value instanceof AssetInterface) {
            try {
                $assetContent = $this->assetExtractor->extract($value);
                $getter = 'get' . lcfirst($field);
                $content = $assetContent->$getter();
            } catch (\Throwable $t) {
                $this->logger->error('Value of type ' . gettype($value) . ' - ' . get_class($value) . ' could not be extracted: ' . $t->getMessage(), LogEnvironment::fromMethodName(__METHOD__));
                return null;
            }

            return $content;
        } else {
            $this->logger->error('Value of type ' . gettype($value) . ' - ' . get_class($value) . ' could not be extracted.', LogEnvironment::fromMethodName(__METHOD__));
            return null;
        }
    }

    /**
     * All methods are considered safe
     *
     * @param string $methodName
     * @return boolean
     */
    public function allowsCallOfMethod($methodName)
    {
        return true;
    }
}
