<?php
namespace Flowpack\ElasticSearch\ContentRepositoryAdaptor\Command;

/*
 * This file is part of the Flowpack.ElasticSearch.ContentRepositoryAdaptor package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Mapping\NodeTypeMappingBuilder;
use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Service\NodeTreeService;
use Nezaniel\Arboretum\ContentRepositoryAdaptor\Application\Service\GraphService;
use Nezaniel\Arboretum\ContentRepositoryAdaptor\Application as ArboretumAdaptor;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Cli\CommandController;
use TYPO3\Flow\Persistence\Doctrine\PersistenceManager;
use TYPO3\TYPO3CR\Domain\Model\NodeData;
use TYPO3\TYPO3CR\Domain\Service\ContextFactory;
use TYPO3\TYPO3CR\Search\Indexer\NodeIndexingManager;

/**
 * Provides CLI features for index handling
 *
 * @Flow\Scope("singleton")
 */
class NodeIndexCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var \Flowpack\ElasticSearch\ContentRepositoryAdaptor\Indexer\NodeIndexer
     */
    protected $nodeIndexer;

    /**
     * @Flow\Inject
     * @var \TYPO3\TYPO3CR\Domain\Repository\WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var \TYPO3\TYPO3CR\Domain\Repository\NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @Flow\Inject
     * @var \TYPO3\TYPO3CR\Domain\Factory\NodeFactory
     */
    protected $nodeFactory;

    /**
     * @Flow\Inject
     * @var \TYPO3\Neos\Domain\Service\ContentDimensionPresetSourceInterface
     */
    protected $contentDimensionPresetSource;

    /**
     * @Flow\Inject
     * @var NodeTypeMappingBuilder
     */
    protected $nodeTypeMappingBuilder;

    /**
     * @Flow\Inject
     * @var \Flowpack\ElasticSearch\ContentRepositoryAdaptor\LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\Inject
     * @var \TYPO3\Flow\Configuration\ConfigurationManager
     */
    protected $configurationManager;

    /**
     * @Flow\Inject
     * @var NodeTreeService
     */
    protected $nodeTreeService;

    /**
     * @Flow\Inject
     * @var GraphService
     */
    protected $graphService;

    /**
     * @Flow\Inject
     * @var ContextFactory
     */
    protected $contentContextFactory;

    /**
     * @Flow\Inject
     * @var NodeIndexingManager
     */
    protected $nodeIndexingManager;

    /**
     * @Flow\Inject
     * @var PersistenceManager
     */
    protected $persistenceManager;

    /**
     * @var array
     */
    protected $settings;

    /**
     * Called by the Flow object framework after creating the object and resolving all dependencies.
     *
     * @param integer $cause Creation cause
     */
    public function initializeObject($cause)
    {
        if ($cause === \TYPO3\Flow\Object\ObjectManagerInterface::INITIALIZATIONCAUSE_CREATED) {
            $this->settings = $this->configurationManager->getConfiguration(\TYPO3\Flow\Configuration\ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'TYPO3.TYPO3CR.Search');
        }
    }

    /**
     * Show the mapping which would be sent to the ElasticSearch server
     *
     * @return void
     */
    public function showMappingCommand()
    {
        $nodeTypeMappingCollection = $this->nodeTypeMappingBuilder->buildMappingInformation($this->nodeIndexer->getIndex());
        foreach ($nodeTypeMappingCollection as $mapping) {
            /** @var \Flowpack\ElasticSearch\Domain\Model\Mapping $mapping */
            $this->output(\Symfony\Component\Yaml\Yaml::dump($mapping->asArray(), 5, 2));
            $this->outputLine();
        }
        $this->outputLine('------------');

        $mappingErrors = $this->nodeTypeMappingBuilder->getLastMappingErrors();
        if ($mappingErrors->hasErrors()) {
            $this->outputLine('<b>Mapping Errors</b>');
            foreach ($mappingErrors->getFlattenedErrors() as $errors) {
                foreach ($errors as $error) {
                    $this->outputLine($error);
                }
            }
        }

        if ($mappingErrors->hasWarnings()) {
            $this->outputLine('<b>Mapping Warnings</b>');
            foreach ($mappingErrors->getFlattenedWarnings() as $warnings) {
                foreach ($warnings as $warning) {
                    $this->outputLine($warning);
                }
            }
        }
    }

    /**
     * Index all nodes by creating a new index and when everything was completed, switch the index alias.
     *
     * This command (re-)indexes all nodes contained in the content repository and sets the schema beforehand.
     *
     * @param integer $limit Amount of nodes to index at maximum
     * @param boolean $update if TRUE, do not throw away the index at the start. Should *only be used for development*.
     * @param string $workspace name of the workspace which should be indexed
     * @param string $postfix Index postfix, index with the same postifix will be deleted if exist
     * @return void
     */
    public function buildCommand($limit = null, $update = false, $workspace = null, $postfix = null)
    {
        if ($update === true) {
            $this->logger->log('!!! Update Mode (Development) active!', LOG_INFO);
        } else {
            $this->nodeIndexer->setIndexNamePostfix($postfix ?: time());
            if ($this->nodeIndexer->getIndex()->exists() === true) {
                $this->logger->log(sprintf('Deleted index with the same postfix (%s)!', $postfix), LOG_WARNING);
                $this->nodeIndexer->getIndex()->delete();
            }
            $this->nodeIndexer->getIndex()->create();
            $this->logger->log('Created index ' . $this->nodeIndexer->getIndexName(), LOG_INFO);

            $nodeTypeMappingCollection = $this->nodeTypeMappingBuilder->buildMappingInformation($this->nodeIndexer->getIndex());
            foreach ($nodeTypeMappingCollection as $mapping) {
                /** @var \Flowpack\ElasticSearch\Domain\Model\Mapping $mapping */
                $mapping->apply();
            }
            $this->logger->log('Updated Mapping.', LOG_INFO);
        }

        $this->logger->log(sprintf('Indexing %snodes ... ', ($limit !== null ? 'the first ' . $limit . ' ' : '')), LOG_INFO);

        $this->outputLine('Initializing content graph...');
        $query = $this->nodeDataRepository->createQuery();
        $numberOfNodes = $query->matching($query->equals('workspace', 'live'))->count();
        $this->output->progressStart($numberOfNodes);
        $graph = $this->graphService->getGraph(function(NodeData $nodeData) {
            $this->output->progressAdvance();
        });
        $this->output->progressFinish();

        $this->outputLine('Indexing content graph...');
        $time = time();
        $this->output->progressStart(count($graph->getNodes()));
        $indexedEdges = 0;
        $nodesSinceLastFlush = 0;
        foreach ($graph->getNodes() as $node) {
            $this->nodeIndexer->indexGraphNode($node);
            $nodesSinceLastFlush++;
            $indexedEdges += count($node->getIncomingEdges());
            $this->output->progressAdvance();
            if ($nodesSinceLastFlush >= 1000) {
                $this->nodeIndexer->flush();
                $nodesSinceLastFlush = 0;
            }
        }
        $this->output->progressFinish();
        $this->nodeIndexer->flush();
        $timeSpent = time() - $time;
        $this->logger->log('Done. Indexed ' . count($graph->getNodes()) . ' nodes and ' . $indexedEdges . ' edges in ' . $timeSpent . ' s at ' . round(count($graph->getNodes()) / $timeSpent) . ' nodes/s (' . round($indexedEdges / $timeSpent) . ' edges/s)', LOG_INFO);
        $this->nodeIndexer->getIndex()->refresh();

        // TODO: smoke tests
        if ($update === false) {
            $this->nodeIndexer->updateIndexAlias();
        }
    }

    /**
     * Clean up old indexes (i.e. all but the current one)
     *
     * @return void
     */
    public function cleanupCommand()
    {
        try {
            $indicesToBeRemoved = $this->nodeIndexer->removeOldIndices();
            if (count($indicesToBeRemoved) > 0) {
                foreach ($indicesToBeRemoved as $indexToBeRemoved) {
                    $this->logger->log('Removing old index ' . $indexToBeRemoved);
                }
            } else {
                $this->logger->log('Nothing to remove.');
            }
        } catch (\Flowpack\ElasticSearch\Transfer\Exception\ApiException $exception) {
            $response = json_decode($exception->getResponse());
            $this->logger->log(sprintf('Nothing removed. ElasticSearch responded with status %s, saying "%s"', $response->status, $response->error));
        }
    }
}
