<?php

namespace Flowpack\ElasticSearch\ContentRepositoryAdaptor\Aspect;

/*
 * This file is part of the Flowpack.ElasticSearch.ContentRepositoryAdaptor package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Service\IndexingContext;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;

/**
 * The aspect for initializing the indexing context whenever necessary
 *
 * @Flow\Scope("singleton")
 * @Flow\Aspect
 */
class IndexerZookeeper
{
    /**
     * @Flow\Inject
     * @var IndexingContext
     */
    protected $indexingContext;


    /**
     * After returning advice
     *
     * @Flow\Before("method(Neos\ContentRepository\Domain\Service\PublishingService->publishNodes())")
     * @param JoinPointInterface $joinPoint
     */
    public function setBulkIndexing(JoinPointInterface $joinPoint)
    {
        $this->indexingContext->setBulkIndexingInProgress(true);
    }
}