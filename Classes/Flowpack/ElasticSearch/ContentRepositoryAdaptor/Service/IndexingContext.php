<?php
namespace Flowpack\ElasticSearch\ContentRepositoryAdaptor\Service;

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

/**
 * The indexing context
 * @Flow\Scope("singleton")
 */
class IndexingContext
{
    /**
     * @var bool
     */
    protected $bulkIndexingInProgress = false;


    /**
     * @return boolean
     */
    public function isBulkIndexingInProgress()
    {
        return $this->bulkIndexingInProgress;
    }

    /**
     * @param boolean $bulkIndexingInProgress
     */
    public function setBulkIndexingInProgress($bulkIndexingInProgress)
    {
        $this->bulkIndexingInProgress = $bulkIndexingInProgress;
    }


    /**
     * @return void
     */
    public function reset()
    {
        $this->setBulkIndexingInProgress(false);
    }
}
