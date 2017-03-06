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

use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Exception\QueryBuildingException;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Utility\Arrays;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\ContentRepository\Search\Search\QueryBuilderInterface;

/**
 * Query Builder for ElasticSearch Queries
 */
class ElasticSearchQueryBuilder implements QueryBuilderInterface, ProtectedContextAwareInterface
{
    const AGGREGATION_TYPE_TERMS = 'terms';
    const AGGREGATION_TYPE_MIN = 'min';
    const AGGREGATION_TYPE_MAX = 'max';
    const AGGREGATION_TYPE_FILTER = 'filter';
    const AGGREGATION_TYPE_GLOBAL = 'global';


    /**
     * @Flow\Inject
     * @var \Flowpack\ElasticSearch\ContentRepositoryAdaptor\ElasticSearchClient
     */
    protected $elasticSearchClient;

    /**
     * The node inside which searching should happen
     *
     * @var NodeInterface
     */
    protected $contextNode;

    /**
     * @Flow\Inject
     * @var \Flowpack\ElasticSearch\ContentRepositoryAdaptor\LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\Inject
     * @var ContentDimensionPresetSourceInterface
     */
    protected $dimensionPresetSource;

    /**
     * @var boolean
     */
    protected $logThisQuery = false;

    /**
     * @var string
     */
    protected $logMessage;

    /**
     * @var integer
     */
    protected $limit;

    /**
     * @var integer
     */
    protected $from;

    /**
     * These fields are not accepted in a count request and must therefore be removed before doing so
     *
     * @var array
     */
    protected $unsupportedFieldsInCountRequest = ['fields', 'sort', 'from', 'size', 'highlight', 'aggs', 'aggregations'];

    /**
     * Amount of total items in response without limit
     *
     * @var integer
     */
    protected $totalItems;

    /**
     * The last request's response's aggregations
     *
     * @var array
     */
    protected $aggregations = [];

    /**
     * @var array
     */
    protected $facetFilters = [];

    /**
     * The last request's response's facets
     *
     * @var array
     */
    protected $facets = [];

    /**
     * The directory for which facets are protected from what filters
     *
     * @var array
     */
    protected $protectedFacets = [];

    /**
     * The last request's response's distances
     *
     * @var array
     */
    protected $distances = [];

    /**
     * This (internal) array stores, for the last search request, a mapping from Node Identifiers
     * to the full ElasticSearch Hit which was returned.
     *
     * This is needed to e.g. use result highlighting.
     *
     * @var array
     */
    protected $elasticSearchHitsIndexedByNodeFromLastRequest;

    /**
     * The ElasticSearch request, as it is being built up.
     *
     * @var array
     */
    protected $request = [
        // http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/search-request-query.html
        'query' => [
            // The top-level query we're working on is a *filtered* query, as this allows us to efficiently
            // apply *global constraints* in the form of *filters* which apply on the whole query.
            //
            // NOTE: we do NOT add a search request FILTER to the query currently, because that would mean
            // that the filters ONLY apply for query results, but NOT for facet calculation (as explained on
            // http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/search-request-filter.html)
            //
            // Reference: http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/query-dsl-filtered-query.html
            'filtered' => [
                'query' => [
                    'bool' => [
                        'must' => [
                            [
                                'match_all' => []
                            ]
                        ],
                        'should' => [],
                        'must_not' => []
                    ]

                ],
                'filter' => [
                    // http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/query-dsl-bool-filter.html
                    'bool' => [
                        'must' => [
                            // @todo adjust to current roles
                            [
                                'missing' => [
                                    'field' => '_accessroles'
                                ]
                            ]
                        ],
                        'should' => [],
                        'must_not' => [
                            // Filter out all hidden elements
                            [
                                'term' => ['_hidden' => true]
                            ],
                            // if now < hiddenBeforeDateTime: HIDE
                            // -> hiddenBeforeDateTime > now
                            [
                                'range' => [
                                    '_hiddenBeforeDateTime' => [
                                        'gt' => 'now'
                                    ]
                                ]
                            ],
                            [
                                'range' => [
                                    '_hiddenAfterDateTime' => [
                                        'lt' => 'now'
                                    ]
                                ]
                            ],
                        ],
                    ]
                ]
            ]
        ],
        'fields' => ['__path']
    ];

    /**
     * @return $this
     */
    public function resetAccessibilityFilters()
    {
        $this->request['query']['filtered']['filter']['bool']['must_not'] = [];
        $musts = [];
        foreach ($this->request['query']['filtered']['filter']['bool']['must'] as $index => $must) {
            if (!(isset($must['missing']) && $must['missing']['field'] === '_accessroles') &&
                !(isset($must['term']) && isset($must['term']['__dimensionCombinationHash']))
            ) {
                $musts[] = $must;
            }
        }
        $this->request['query']['filtered']['filter']['bool']['must'] = $musts;

        return $this;
    }


    /**
     * @var array
     */
    protected $result = [];

    /**
     * Filter by node type, taking inheritance into account.
     *
     * @param string $nodeType the node type to filter for
     * @return ElasticSearchQueryBuilder
     * @api
     */
    public function nodeType($nodeType)
    {
        // on indexing, __typeAndSupertypes contains the typename itself and all supertypes, so that's why we can
        // use a simple term filter here.

        // http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/query-dsl-term-filter.html
        return $this->queryFilter('term', ['__typeAndSupertypes' => $nodeType]);
    }

    /**
     * @param string $dimensionName
     * @param array $dimensionValues
     * @return $this
     * @throws QueryBuildingException
     * @api
     */
    public function dimension($dimensionName, array $dimensionValues = null)
    {
        if (is_null($dimensionValues)) {
            $dimensionValues = $this->contextNode->getContext()->getDimensions()[$dimensionName];
        }
        $excludedDimensionValues = array_values(array_diff(array_keys($this->dimensionPresetSource->getAllPresets()[$dimensionName]['presets']),
            $dimensionValues));

        return $this->queryFilter('terms', [
            '__dimensionCombinations.' . $dimensionName => $dimensionValues,
            'execution' => 'and'
        ])
            ->queryFilter('terms', [
                '__dimensionCombinations.' . $dimensionName => $excludedDimensionValues,
                'execution' => 'or'
            ], 'must_not');
    }

    /**
     * Sort descending by $propertyName
     *
     * @param string $propertyName the property name to sort by
     * @return ElasticSearchQueryBuilder
     * @api
     */
    public function sortDesc($propertyName)
    {
        $configuration = [
            $propertyName => [
                'order' => 'desc',
                'missing' => PHP_INT_MAX - 1
            ]
        ];

        $this->sort($configuration);

        return $this;
    }

    /**
     * Sort ascending by $propertyName
     *
     * @param string $propertyName the property name to sort by
     * @return ElasticSearchQueryBuilder
     * @api
     */
    public function sortAsc($propertyName)
    {

        $configuration = [
            $propertyName => [
                'order' => 'asc',
                'missing' => PHP_INT_MAX - 1
            ]
        ];

        $this->sort($configuration);

        return $this;
    }

    /**
     * @param string $property
     * @param double $referenceLatitude
     * @param double $referenceLongitude
     * @param string $unit
     * @see http://www.elasticsearch.org/blog/geo-location-and-search/
     * @return $this
     */
    public function sortGeoDistanceAsc($property, $referenceLatitude, $referenceLongitude, $unit = 'km')
    {
        $configuration = [
            '_geo_distance' => [
                $property => [
                    'lat' => (double)$referenceLatitude,
                    'lon' => (double)$referenceLongitude
                ],
                'order' => 'asc',
                'unit' => $unit
            ]
        ];

        $this->sort($configuration);

        return $this;
    }

    /**
     * @param string $property
     * @param double $referenceLatitude
     * @param double $referenceLongitude
     * @param string $unit
     * @see http://www.elasticsearch.org/blog/geo-location-and-search/
     * @return $this
     */
    public function sortGeoDistanceDesc($property, $referenceLatitude, $referenceLongitude, $unit = 'km')
    {
        $configuration = [
            '_geo_distance' => [
                $property => [
                    $referenceLongitude,
                    $referenceLatitude
                ],
                'order' => 'desc',
                'unit' => $unit
            ]
        ];

        $this->sort($configuration);

        return $this;
    }

    /**
     * Add a $configuration sort filter to the request
     *
     * @param array $configuration
     * @return ElasticSearchQueryBuilder
     * @see http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/search-request-sort.html
     * @api
     */
    public function sort($configuration)
    {
        if (!isset($this->request['sort'])) {
            $this->request['sort'] = [];
        }

        $this->request['sort'][] = $configuration;

        return $this;
    }

    /**
     * output only $limit records
     *
     * Internally, we fetch $limit*$workspaceNestingLevel records, because we fetch the *conjunction* of all workspaces;
     * and then we filter after execution when we have found the right number of results.
     *
     * This algorithm can be re-checked when https://github.com/elasticsearch/elasticsearch/issues/3300 is merged.
     *
     *
     * @param integer $limit
     * @return ElasticSearchQueryBuilder
     * @api
     */
    public function limit($limit)
    {
        if (!$limit) {
            return $this;
        }

        $currentWorkspaceNestingLevel = 1;
        $workspace = $this->contextNode->getContext()->getWorkspace();
        while ($workspace->getBaseWorkspace() !== null) {
            $currentWorkspaceNestingLevel++;
            $workspace = $workspace->getBaseWorkspace();
        }

        $this->limit = $limit;

        // http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/search-request-from-size.html
        $this->request['size'] = $limit * $currentWorkspaceNestingLevel;

        return $this;
    }

    /**
     * output records starting at $from
     *
     *
     * @param integer $from
     * @return ElasticSearchQueryBuilder
     * @api
     */
    public function from($from)
    {
        if (!$from) {
            return $this;
        }

        $this->from = $from;
        $this->request['from'] = $from;

        return $this;
    }

    /**
     * add an exact-match query for a given property
     *
     * @param string $propertyName Name of the property
     * @param mixed $value Value for comparison
     * @return ElasticSearchQueryBuilder
     * @api
     */
    public function exactMatch($propertyName, $value)
    {
        if ($value instanceof NodeInterface) {
            $value = $value->getIdentifier();
        }

        if (is_array($value)) {
            return $this->queryFilter('terms', [$propertyName => $value], 'must');
        } else {
            return $this->queryFilter('term', [$propertyName => $value], 'must');
        }
    }

    /**
     * @param string $field
     * @param mixed $value
     * @return $this
     */
    public function notExactMatch($field, $value)
    {
        $this->request['query']['filtered']['filter']['bool']['must_not'][] = [
            'term' => [
                $field => $value
            ]

        ];

        return $this;
    }

    /**
     * add a range filter (gt) for the given property
     *
     * @param string $propertyName Name of the property
     * @param mixed $value Value for comparison
     * @return ElasticSearchQueryBuilder
     * @api
     */
    public function greaterThan($propertyName, $value)
    {
        return $this->queryFilter('range', [$propertyName => ['gt' => $value]], 'must');
    }

    /**
     * add a range filter (gte) for the given property
     *
     * @param string $propertyName Name of the property
     * @param mixed $value Value for comparison
     * @return ElasticSearchQueryBuilder
     * @api
     */
    public function greaterThanOrEqual($propertyName, $value)
    {
        return $this->queryFilter('range', [$propertyName => ['gte' => $value]], 'must');
    }

    /**
     * add a range filter (lt) for the given property
     *
     * @param string $propertyName Name of the property
     * @param mixed $value Value for comparison
     * @return ElasticSearchQueryBuilder
     * @api
     */
    public function lessThan($propertyName, $value)
    {
        return $this->queryFilter('range', [$propertyName => ['lt' => $value]], 'must');
    }

    /**
     * add a range filter (lte) for the given property
     *
     * @param string $propertyName Name of the property
     * @param mixed $value Value for comparison
     * @return ElasticSearchQueryBuilder
     * @api
     */
    public function lessThanOrEqual($propertyName, $value)
    {
        return $this->queryFilter('range', [$propertyName => ['lte' => $value]], 'must');
    }

    /**
     * Adds a phrase match filter for the given property
     *
     * @param string $property
     * @param mixed $value
     * @param string $analyzer
     * @param bool $skipEmptyValue
     * @return ElasticSearchQueryBuilder
     * @throws QueryBuildingException
     */
    public function matchPhrase($property, $value, $analyzer = null, $skipEmptyValue = false)
    {
        if (empty($value) && $skipEmptyValue) {
            return $this;
        } else {
            return $this->queryMatch($property, $value, 'phrase', empty($analyzer) ? [] : ['analyzer' => $analyzer]);
        }
    }

    /**
     * @param string $property
     * @param mixed $value
     * @param boolean $skipEmptyValue
     * @return $this
     */
    public function wildcard($property, $value, $skipEmptyValue = false)
    {
        if (empty($value) && $skipEmptyValue) {
            return $this;
        } else {
            $this->request['query']['filtered']['query']['bool']['must'][] = ['wildcard' => [$property => strtolower($value)]];

            return $this;
        }
    }

    /**
     * @param string $aggregationName
     * @param string $attributeCode
     * @param array $aggregationBody
     * @param string $parentAggregationPath
     * @return $this
     */
    public function aggregateTerms(
        $aggregationName,
        $attributeCode,
        array $aggregationBody = [],
        $parentAggregationPath = ''
    ) {
        return $this->aggregate(
            $aggregationName,
            self::AGGREGATION_TYPE_TERMS,
            Arrays::arrayMergeRecursiveOverrule($aggregationBody, ['field' => $attributeCode]),
            $parentAggregationPath
        );
    }

    /**
     * @param string $aggregationName
     * @param string $aggregationType
     * @param string $propertyName
     * @param array $aggregationBody
     * @param array $protectFromFilters
     * @param string $parentAggregationPath
     * @return $this
     */
    public function facet(
        $aggregationName,
        $aggregationType,
        $propertyName,
        array $aggregationBody = [],
        array $protectFromFilters = [],
        $parentAggregationPath = ''
    ) {
        if (!isset($this->request['aggregations']['facets'])) {
            $this->request['aggregations']['facets'] = [
                'global' => new \stdClass(),
                'aggregations' => []
            ];
        }

        $this->request['aggregations']['facets']['aggregations'][$aggregationName]['aggregations'][$aggregationName] = [
            $aggregationType => Arrays::arrayMergeRecursiveOverrule($aggregationBody, ['field' => $propertyName])
        ];
        $this->request['aggregations']['facets']['aggregations'][$aggregationName]['filter']['bool']['must'] = [];

        if (!isset($this->protectedFacets[$aggregationName])) {
            $this->protectedFacets[$aggregationName] = [$aggregationName];
        }
        $this->protectedFacets[$aggregationName] = array_merge($this->protectedFacets[$aggregationName], $protectFromFilters);

        foreach ($this->facetFilters as $key => $facetFilter) {
            if (!$this->isFacetProtectedFromActiveFilter($aggregationName, $facetFilter)) {
                $aggregationFilters = &$this->request['aggregations']['facets']['aggregations'][$aggregationName]['filter']['bool'];
                foreach ($facetFilter as $aggregationType => $aggregationBody) {
                    $aggregationFilters[$aggregationType][] = $aggregationBody;
                }
            }
        }

        foreach ($this->request['query']['filtered']['query']['bool']['must'] as $queryMatch) {
            $this->request['aggregations']['facets']['aggregations'][$aggregationName]['filter']['bool']['must'][] = [
                'query' => $queryMatch
            ];
        }

        return $this;
    }

    /**
     * @param string $facetName
     * @param array $facetFilter
     * @return boolean
     */
    protected function isFacetProtectedFromActiveFilter($facetName, array $facetFilter)
    {
        if (isset($facetFilter['must'])) {
            if (isset($facetFilter['must']['term'])) {
                $filterName = key($facetFilter['must']['term']);
            } elseif (isset($facetFilter['must']['terms'])) {
                $values = array_keys($facetFilter['must']['terms']);
                $filterName = reset($values);
            }
        }
        if (isset($filterName)) {
            return $this->isFacetProtectedFromFilter($facetName, $filterName);
        } else {
            return false;
        }
    }

    /**
     * @param string $facetName
     * @param string $filterName
     * @return boolean
     */
    protected function isFacetProtectedFromFilter($facetName, $filterName)
    {
        return isset($this->protectedFacets[$facetName]) && in_array($filterName, $this->protectedFacets[$facetName]);
    }

    /**
     * LOW-LEVEL API
     */

    /**
     * @param string $aggregationName
     * @param string $aggregationType
     * @param array $aggregationBody
     * @param string $parentAggregationPath
     * @return $this
     * @throws QueryBuildingException
     */
    public function aggregate($aggregationName, $aggregationType, array $aggregationBody, $parentAggregationPath = '')
    {
        $currentElement =& $this->request['aggregations'];
        if (!empty($parentAggregationPath)) {
            foreach (explode('.', $parentAggregationPath) as $pathPart) {
                if (!isset($currentElement[$pathPart])) {
                    throw new QueryBuildingException('The element at path "' . $parentAggregationPath . '" was not an array (failed at "' . $pathPart . '").',
                        1383716367);
                }
                $currentElement =& $currentElement[$pathPart];
            }
        }

        $currentElement[$aggregationName] = [
            $aggregationType => $aggregationBody
        ];

        return $this;
    }

    /**
     * Adds a filter to query.filtered.query
     *
     * @param string $property
     * @param mixed $value
     * @param string $matchType
     * @param array $options
     * @throws QueryBuildingException
     * @return ElasticSearchQueryBuilder
     * @api
     */
    public function queryMatch($property, $value, $matchType = 'phrase', array $options = [])
    {
        if (!in_array($matchType, array('boolean', 'phrase', 'phrase_prefix'))) {
            throw new QueryBuildingException('The given match type "' . $matchType . '" is not supported. Must be one of "boolean", "phrase", "phrase_prefix".',
                1436521987);
        }

        $match = [
            'match' => [
                $property => array_merge($options, [
                    'query' => $value,
                    'type' => $matchType
                ])
            ]
        ];
        if (isset($this->request['aggregations']['facets']) && count($this->request['aggregations']['facets']['aggregations']) > 0) {
            foreach ($this->request['aggregations']['facets']['aggregations'] as $aggregationName => &$facetAggregation) {
                $facetAggregation['filter']['bool']['must'][]['query'] = $match;
            }
        }

        return $this->appendAtPath('query.filtered.query.bool.must', $match);
    }

    /**
     * Add a filter to query.filtered.filter
     *
     * @param string $filterType
     * @param mixed $filterOptions
     * @param string $clauseType one of must, should, must_not
     * @return ElasticSearchQueryBuilder
     * @throws QueryBuildingException
     * @api
     */
    public function queryFilter($filterType, $filterOptions, $clauseType = 'must')
    {
        if (!in_array($clauseType, array('must', 'should', 'must_not'))) {
            throw new QueryBuildingException('The given clause type "' . $clauseType . '" is not supported. Must be one of "mmust", "should", "must_not".',
                1383716082);
        }

        $filterArray = [
            $clauseType => [
                $filterType => $filterOptions
            ]
        ];
        $this->facetFilters[] = $filterArray;
        if (isset($this->request['aggregations']['facets']) && count($this->request['aggregations']['facets']['aggregations']) > 0) {
            foreach ($this->request['aggregations']['facets']['aggregations'] as $aggregationName => &$facetAggregation) {
                if (!$this->isFacetProtectedFromActiveFilter($aggregationName, $filterArray)) {
                    $facetAggregation['filter']['bool'][$clauseType][] = [
                        $filterType => $filterOptions
                    ];
                }
            }
        }

        return $this->appendAtPath('query.filtered.filter.bool.' . $clauseType, [$filterType => $filterOptions]);
    }

    /**
     * @param string $property
     * @param integer $radius
     * @param double $referenceLatitude
     * @param double $referenceLongitude
     * @param string $unit
     * @return $this
     */
    public function filterRadius($property, $radius, $referenceLatitude, $referenceLongitude, $unit = 'km')
    {
        $this->request['query']['filtered']['filter']['bool']['must'][]['geo_distance'] = [
            'distance' => $radius . $unit,
            $property => [
                'lat' => (double)$referenceLatitude,
                'lon' => (double)$referenceLongitude
            ]
        ];

        return $this;
    }

    /**
     * Append $data to the given array at $path inside $this->request.
     *
     * Low-level method to manipulate the ElasticSearch Query
     *
     * @param string $path
     * @param array $data
     * @throws \Flowpack\ElasticSearch\ContentRepositoryAdaptor\Exception\QueryBuildingException
     * @return ElasticSearchQueryBuilder
     */
    public function appendAtPath($path, array $data)
    {
        $currentElement =& $this->request;
        foreach (explode('.', $path) as $pathPart) {
            if (!isset($currentElement[$pathPart])) {
                throw new QueryBuildingException('The element at path "' . $path . '" was not an array (failed at "' . $pathPart . '").', 1383716367);
            }
            $currentElement =& $currentElement[$pathPart];
        }
        $currentElement[] = $data;

        return $this;
    }

    /**
     * Add multiple filters to query.filtered.filter
     *
     * Example Usage:
     *
     *   searchFilter = TYPO3.TypoScript:RawArray {
     *      author = 'Max'
     *      tags = TYPO3.TypoScript:RawArray {
     *        0 = 'a'
     *        1 = 'b'
     *      }
     *   }
     *
     *   searchQuery = ${Search.queryFilterMultiple(this.searchFilter)}
     *
     * @param array $data An associative array of keys as variable names and values as variable values
     * @param string $clauseType one of must, should, must_not
     * @return ElasticSearchQueryBuilder
     * @throws QueryBuildingException
     * @api
     */
    public function queryFilterMultiple($data, $clauseType = 'must')
    {
        foreach ($data as $key => $value) {
            if ($value !== null) {
                if (is_array($value)) {
                    $this->queryFilter('terms', [$key => $value], $clauseType);
                } else {
                    $this->queryFilter('term', [$key => $value], $clauseType);
                }
            }
        }

        return $this;
    }

    /**
     * This method adds a field based aggregation configuration. This can be used for simple
     * aggregations like terms
     *
     * Example Usage to create a terms aggregation for a property color:
     * nodes = ${Search....fieldBasedAggregation("colors", "color").execute()}
     *
     * Access all aggregation data with {nodes.aggregations} in your fluid template
     *
     * @param $name
     * @param $field
     * @param string $type
     * @param null $parentPath
     * @return $this
     */
    public function fieldBasedAggregation($name, $field, $type = "terms", $parentPath = null)
    {
        $aggregationDefinition = [
            $type => [
                'field' => $field
            ]
        ];

        $this->aggregation($name, $aggregationDefinition, $parentPath);

        return $this;
    }

    /**
     * This method is used to create any kind of aggregation.
     *
     * Example Usage to create a terms aggregation for a property color:
     *
     * aggregationDefinition = TYPO3.TypoScript:RawArray {
     *   terms = TYPO3.TypoScript:RawArray {
     *     field = "color"
     *   }
     * }
     *
     * nodes = ${Search....aggregation("color", this.aggregationDefinition).execute()}
     *
     * Access all aggregation data with {nodes.aggregations} in your fluid template
     *
     * @param string $name
     * @param array $aggregationDefinition
     * @param null $parentPath
     * @return $this
     * @throws QueryBuildingException
     */
    public function aggregation($name, array $aggregationDefinition, $parentPath = null)
    {
        if (!array_key_exists("aggregations", $this->request)) {
            $this->request['aggregations'] = [];
        }

        if ($parentPath !== null) {
            $this->addSubAggregation($parentPath, $name, $aggregationDefinition);
        } else {
            $this->request['aggregations'][$name] = $aggregationDefinition;
        }

        return $this;
    }

    /**
     * This is an low level method for internal usage.
     * You can add a custom $aggregationConfiguration under a given $parentPath. The $parentPath foo.bar would
     * insert your $aggregationConfiguration under
     * $this->request['aggregations']['foo']['aggregations']['bar']['aggregations'][$name]
     *
     * @param $parentPath
     * @param $name
     * @param array $aggregationConfiguration
     * @return $this
     * @throws QueryBuildingException
     */
    protected function addSubAggregation($parentPath, $name, $aggregationConfiguration)
    {
        // Find the parentPath
        $path =& $this->request['aggregations'];

        foreach (explode(".", $parentPath) as $subPart) {
            if ($path == null || !array_key_exists($subPart, $path)) {
                throw new QueryBuildingException("The parent path " . $subPart . " could not be found when adding a sub aggregation");
            }
            $path =& $path[$subPart]['aggregations'];
        }

        $path[$name] = $aggregationConfiguration;

        return $this;
    }

    /**
     * This method is used to create a simple term suggestion.
     *
     * Example Usage of a term suggestion
     *
     * nodes = ${Search....termSuggestions("aTerm")}
     *
     * Access all suggestions data with {nodes.suggestions} in your fluid template
     *
     * @param string $text
     * @param string $field
     * @param string $name
     * @return $this
     */
    public function termSuggestions($text, $field = '_all', $name = 'suggestions')
    {
        $suggestionDefinition = [
            'text' => $text,
            'term' => [
                'field' => $field
            ]
        ];

        $this->suggestions($name, $suggestionDefinition);

        return $this;
    }

    /**
     * This method is used to create any kind of suggestion.
     *
     * Example Usage of a term suggestion for the fulltext search
     *
     * suggestionDefinition = TYPO3.TypoScript:RawArray {
     *     text = "some text"
     *     terms = TYPO3.TypoScript:RawArray {
     *         field = "body"
     *     }
     * }
     *
     * nodes = ${Search....suggestion("my-suggestions", this.suggestionDefinition).execute()}
     *
     * Access all suggestions data with {nodes.suggestions} in your fluid template
     *
     * @param string $name
     * @param array $suggestionDefinition
     * @return $this
     */
    public function suggestions($name, array $suggestionDefinition)
    {
        if (!array_key_exists('suggest', $this->request)) {
            $this->request['suggest'] = [];
        }

        $this->request['suggest'][$name] = $suggestionDefinition;

        return $this;
    }

    /**
     * Get the ElasticSearch request as we need it
     *
     * @return array
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Log the current request to the ElasticSearch log for debugging after it has been executed.
     *
     * @param string $message an optional message to identify the log entry
     * @return $this
     * @api
     */
    public function log($message = null)
    {
        $this->logThisQuery = true;
        $this->logMessage = $message;

        return $this;
    }

    /**
     * @return integer
     */
    public function getTotalItems()
    {
        if (isset($this->result['hits']['total'])) {
            return (int)$this->result['hits']['total'];
        }

        return 0;
    }

    /**
     * @return array
     */
    public function getAggregations()
    {
        return $this->aggregations;
    }

    /**
     * @param $nodeIdentifier
     * @return float|null
     */
    public function getDistance($nodeIdentifier)
    {
        return isset($this->distances[$nodeIdentifier]) ? $this->distances[$nodeIdentifier] : null;
    }

    /**
     * @return array
     */
    public function getDistances()
    {
        return $this->distances;
    }

    /**
     * @return integer
     */
    public function getLimit()
    {
        return $this->limit;
    }

    /**
     * @return integer
     */
    public function getFrom()
    {
        return $this->from;
    }

    /**
     * This low-level method can be used to look up the full ElasticSearch hit given a certain node.
     *
     * @param NodeInterface $node
     * @return array the ElasticSearch hit for the node as array, or NULL if it does not exist.
     */
    public function getFullElasticSearchHitForNode(NodeInterface $node)
    {
        if (isset($this->elasticSearchHitsIndexedByNodeFromLastRequest[$node->getIdentifier()])) {
            return $this->elasticSearchHitsIndexedByNodeFromLastRequest[$node->getIdentifier()];
        }

        return null;
    }

    /**
     * Execute the query and return the list of nodes as result.
     *
     * This method is rather internal; just to be called from the ElasticSearchQueryResult. For the public API, please use execute()
     *
     * @return array<\Neos\ContentRepository\Domain\Model\NodeInterface>
     */
    public function fetch()
    {
        $request = $this->getRequest();
        $timeBefore = microtime(true);
        $response = $this->elasticSearchClient->getIndex()->request('GET', '/_search', [], json_encode($request));
        $timeAfterwards = microtime(true);

        $this->result = $response->getTreatedContent();
        $this->aggregations = (isset($this->result['aggregations']) ? $this->result['aggregations'] : []);
        $this->distances = [];

        $this->result['nodes'] = [];
        if ($this->logThisQuery === true) {
            $this->logger->log(sprintf('Query Log (%s): %s -- execution time: %s ms -- Limit: %s -- Number of results returned: %s -- Total Results: %s',
                $this->logMessage, json_encode($request), (($timeAfterwards - $timeBefore) * 1000), $this->limit, count($this->result['hits']['hits']), $this->result['hits']['total']), LOG_DEBUG);
        }
        if (array_key_exists('hits', $this->result) && is_array($this->result['hits']) && count($this->result['hits']) > 0) {
            $this->result['nodes'] = $this->convertHitsToNodes($this->result['hits']);
        }

        return $this->result;
    }

    /**
     * Get a query result object for lazy execution of the query
     *
     * @return \Traversable<\Neos\Flow\Persistence\QueryResultInterface>
     * @api
     */
    public function execute()
    {
        $elasticSearchQuery = new ElasticSearchQuery($this);

        $result = $elasticSearchQuery->execute(true);

        return $result;
    }

    /**
     * Return the total number of hits for the query.
     *
     * @return integer
     * @api
     */
    public function count()
    {
        $timeBefore = microtime(true);

        $request = $this->getRequest();

        foreach ($this->unsupportedFieldsInCountRequest as $field) {
            if (isset($request[$field])) {
                unset($request[$field]);
            }
        }

        $response = $this->elasticSearchClient->getIndex()->request('GET', '/_count', [], json_encode($request));
        $timeAfterwards = microtime(true);

        $treatedContent = $response->getTreatedContent();
        $count = $treatedContent['count'];

        if ($this->logThisQuery === true) {
            $this->logger->log('Count Query Log (' . $this->logMessage . '): ' . json_encode($request) . ' -- execution time: ' . (($timeAfterwards - $timeBefore) * 1000) . ' ms -- Total Results: ' . $count, LOG_DEBUG);
        }

        return $count;
    }

    /**
     * Match the searchword against the fulltext index
     *
     * @param string $searchWord
     * @return QueryBuilderInterface
     * @api
     */
    public function fulltext($searchWord)
    {
        $query = [
            'query_string' => [
                'query' => json_encode((string)$searchWord)
            ]
        ];
        $this->appendAtPath('query.filtered.query.bool.must', $query);

        // add the query_string filter to all facets
        if (isset($this->request['aggregations']['facets']['aggregations'])) {
            foreach ($this->request['aggregations']['facets']['aggregations'] as $aggregationName => &$facetAggregation) {
                $facetAggregation['filter']['bool']['must'][]['query']['query_string'] = $query['query_string'];
            }
        }

        // We automatically enable result highlighting when doing fulltext searches. It is up to the user to use this information or not use it.
        return $this->highlight(150, 2);
    }

    /**
     * Match the searchword against arbitrary search fields
     *
     * @param string $searchWord
     * @param array $fields
     * @param array $fieldsToHighlight
     * @return QueryBuilderInterface
     * @api
     */
    public function multiFieldFulltext($searchWord, array $fields, array $fieldsToHighlight = [])
    {
        $query = [
            'query_string' => [
                'fields' => $fields,
                'query' => $searchWord
            ]
        ];
        $this->appendAtPath('query.filtered.query.bool.must', $query);

        // add the query_string filter to all facets
        if (isset($this->request['aggregations']['facets']['aggregations'])) {
            foreach ($this->request['aggregations']['facets']['aggregations'] as $aggregationName => &$facetAggregation) {
                $facetAggregation['filter']['bool']['must'][]['query']['query_string'] = $query['query_string'];
            }
        }

        // We automatically enable result highlighting when doing fulltext searches. It is up to the user to use this information or not use it.
        $fragmentSize = 150;
        $fragmentCount = 2;
        $this->highlight($fragmentSize, $fragmentCount);
        if (count($fieldsToHighlight) > 0) {
            foreach ($fieldsToHighlight as $fieldName) {
                $this->request['highlight']['fields'][$fieldName] = [
                    'fragment_size' => $fragmentSize,
                    'no_match_size' => $fragmentSize,
                    'number_of_fragments' => $fragmentCount
                ];
            }
        }

        return $this;
    }

    /**
     * Configure Result Highlighting. Only makes sense in combination with fulltext(). By default, highlighting is enabled.
     * It can be disabled by calling "highlight(FALSE)".
     *
     * @param integer|boolean $fragmentSize The result fragment size for highlight snippets. If this parameter is FALSE, highlighting will be disabled.
     * @param integer $fragmentCount The number of highlight fragments to show.
     * @return $this
     * @api
     */
    public function highlight($fragmentSize, $fragmentCount = null)
    {
        if ($fragmentSize === false) {
            // Highlighting is disabled.
            unset($this->request['highlight']);
        } else {
            $this->request['highlight'] = [
                'fields' => [
                    '__fulltext*' => [
                        'fragment_size' => $fragmentSize,
                        'no_match_size' => $fragmentSize,
                        'number_of_fragments' => $fragmentCount
                    ]
                ]
            ];
        }

        return $this;
    }

    /**
     * Sets the starting point for this query. Search result should only contain nodes that
     * match the context of the given node and have it as parent node in their rootline.
     *
     * @param NodeInterface $contextNode
     * @return QueryBuilderInterface
     * @api
     */
    public function query(NodeInterface $contextNode)
    {
        // on indexing, the __parentPath is tokenized to contain ALL parent path parts,
        // e.g. /foo, /foo/bar/, /foo/bar/baz; to speed up matching.. That's why we use a simple "term" filter here.
        // http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/query-dsl-term-filter.html
        $this->queryFilter('term', ['__parentPath' => $contextNode->getPath()]);

        //
        // http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/query-dsl-terms-filter.html
        $this->queryFilter('terms', ['__workspace' => array_unique(['live', $contextNode->getContext()->getWorkspace()->getName()])]);

        // match exact dimension values for each dimension, this works because the indexing flattens the node variants for all dimension preset combinations
        $dimensionCombinations = $contextNode->getContext()->getDimensions();
        if (is_array($dimensionCombinations)) {
            $this->queryFilter('term', ['__dimensionCombinationHash' => md5(json_encode($dimensionCombinations))]);
        }

        $this->contextNode = $contextNode;

        return $this;
    }

    /**
     * Modify a part of the Elasticsearch Request denoted by $path, merging together
     * the existing values and the passed-in values.
     *
     * @param string $path
     * @param mixed $requestPart
     * @return $this
     */
    public function request($path, $requestPart)
    {
        $valueAtPath = Arrays::getValueByPath($this->request, $path);
        if (is_array($valueAtPath)) {
            $result = Arrays::arrayMergeRecursiveOverrule($valueAtPath, $requestPart);
        } else {
            $result = $requestPart;
        }

        $this->request = Arrays::setValueByPath($this->request, $path, $result);

        return $this;
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

    /**
     * @param array $hits
     * @return array Array of Node objects
     */
    protected function convertHitsToNodes(array $hits)
    {
        $nodes = [];
        $elasticSearchHitPerNode = [];

        /**
         * TODO: This code below is not fully correct yet:
         *
         * We always fetch $limit * (numerOfWorkspaces) records; so that we find a node:
         * - *once* if it is only in live workspace and matches the query
         * - *once* if it is only in user workspace and matches the query
         * - *twice* if it is in both workspaces and matches the query *both times*. In this case we filter the duplicate record.
         * - *once* if it is in the live workspace and has been DELETED in the user workspace (STILL WRONG)
         * - *once* if it is in the live workspace and has been MODIFIED to NOT MATCH THE QUERY ANYMORE in user workspace (STILL WRONG)
         *
         * If we want to fix this cleanly, we'd need to do an *additional query* in order to filter all nodes from a non-user workspace
         * which *do exist in the user workspace but do NOT match the current query*. This has to be done somehow "recursively"; and later
         * we might be able to use https://github.com/elasticsearch/elasticsearch/issues/3300 as soon as it is merged.
         */
        foreach ($hits['hits'] as $hit) {
            $nodePath = current($hit['fields']['__path']);
            $node = $this->contextNode->getNode($nodePath);
            if ($node instanceof NodeInterface && !isset($nodes[$node->getIdentifier()])) {
                $nodes[$node->getIdentifier()] = $node;
                $elasticSearchHitPerNode[$node->getIdentifier()] = $hit;
                if (isset($hit['sort']) && is_array($hit['sort'])) {
                    $this->distances[$node->getIdentifier()] = current($hit['sort']);
                }
                if ($this->limit > 0 && count($nodes) >= $this->limit) {
                    break;
                }
            }
        }

        if ($this->logThisQuery === true) {
            $this->logger->log('Returned nodes (' . $this->logMessage . '): ' . count($nodes), LOG_DEBUG);
        }

        $this->elasticSearchHitsIndexedByNodeFromLastRequest = $elasticSearchHitPerNode;

        return array_values($nodes);
    }

}
