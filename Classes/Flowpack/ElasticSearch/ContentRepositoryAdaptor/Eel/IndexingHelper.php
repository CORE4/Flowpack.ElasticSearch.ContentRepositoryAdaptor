<?php
namespace Flowpack\ElasticSearch\ContentRepositoryAdaptor\Eel;

/*
 * This file is part of the Flowpack.ElasticSearch.ContentRepositoryAdaptor package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Media\Domain\Model\AssetInterface;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;
use TYPO3\TYPO3CR\Search\Exception\IndexingException;

/**
 * IndexingHelper
 */
class IndexingHelper extends \TYPO3\TYPO3CR\Search\Eel\IndexingHelper
{

    /**
     * @Flow\InjectConfiguration(package="TYPO3.TYPO3CR.Search", path="elasticSearch.assetSizeLimit")
     * @var int
     */
    protected $assetSizeLimit;

    /**
     * Convert an array of nodes to an array of node identifiers
     *
     * @param array <NodeInterface> $nodes
     * @return array
     */
    public function convertArrayOfNodesToArrayOfNodeIdentifiers($nodes)
    {
        if (!is_array($nodes) && !$nodes instanceof \Traversable) {
            return [];
        }
        $nodeIdentifiers = [];
        foreach ($nodes as $node) {
            $nodeIdentifiers[] = $node instanceof NodeInterface ? $node->getIdentifier() : $node;
        }

        return $nodeIdentifiers;
    }

    /**
     * Index an asset list or a single asset (by base64-encoding-it);
     * in the same manner as expected by the ElasticSearch "attachment"
     * core plugin.
     *
     * @param $value
     * @return array|null|string
     * @throws IndexingException
     */
    public function indexAsset($value)
    {
        if ($value === null) {
            return null;
        } elseif (is_array($value)) {
            $result = [];
            foreach ($value as $element) {
                $result[] = $this->indexAsset($element);
            }
            return $result;
        } elseif ($value instanceof AssetInterface) {
            if (!$value->getResource()) {
                return null;
            }
            if ($this->assetSizeLimit && $value->getResource()->getFileSize() > $this->assetSizeLimit) {
                return null;
            }
            $stream = $value->getResource()->getStream();
            if ($stream) {
                stream_filter_append($stream, 'convert.base64-encode');
                $result = stream_get_contents($stream);
            } else {
                $result = null;
            }
            return $result;
        } else {
            throw new IndexingException('Value of type ' . gettype($value) . ' - ' . get_class($value) . ' could not be converted to asset binary.', 1437555909);
        }
    }
}
