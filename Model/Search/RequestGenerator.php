<?php
namespace Jh\CoreBugElasticSearch\Model\Search;

use Magento\Framework\Search\Request\BucketInterface;
use Magento\Framework\Search\Request\FilterInterface;
use Magento\Framework\Search\Request\QueryInterface;
use Magento\CatalogSearch\Model\Search\RequestGenerator as MagentoRequestGenerator;

/**
 * Class RequestGenerator
 * @package Jh\CoreBugElasticSearch\Model\Search
 * @author Anthony Bates <anthony@wearejh.com>
 */
class RequestGenerator extends MagentoRequestGenerator
{
    /** Filter name suffix */
    const FILTER_SUFFIX = '_filter';

    /** Bucket name suffix */
    const BUCKET_SUFFIX = '_bucket';

    /**
     * Generate search request
     *
     * @param string $attributeType
     * @param string $container
     * @param bool $useFulltext
     * @return array
     */
    private function generateRequest($attributeType, $container, $useFulltext)
    {
        $request = [];
        foreach ($this->getSearchableAttributes() as $attribute) {
            if ($attribute->getData($attributeType)) {
                if (!in_array($attribute->getAttributeCode(), ['price', 'category_ids'])) {
                    $queryName = $attribute->getAttributeCode() . '_query';

                    $request['queries'][$container]['queryReference'][] = [
                        'clause' => 'must',
                        'ref' => $queryName,
                    ];
                    $filterName = $attribute->getAttributeCode() . self::FILTER_SUFFIX;
                    $request['queries'][$queryName] = [
                        'name' => $queryName,
                        'type' => QueryInterface::TYPE_FILTER,
                        'filterReference' => [
                            [
                                'clause' => 'must',
                                'ref' => $filterName,
                            ]
                        ],
                    ];
                    $bucketName = $attribute->getAttributeCode() . self::BUCKET_SUFFIX;
                    if ($attribute->getBackendType() == 'decimal') {
                        $request['filters'][$filterName] = [
                            'type' => FilterInterface::TYPE_RANGE,
                            'name' => $filterName,
                            'field' => $attribute->getAttributeCode(),
                            'from' => '$' . $attribute->getAttributeCode() . '.from$',
                            'to' => '$' . $attribute->getAttributeCode() . '.to$',
                        ];
                        $request['aggregations'][$bucketName] = [
                            'type' => BucketInterface::TYPE_DYNAMIC,
                            'name' => $bucketName,
                            'field' => $attribute->getAttributeCode(),
                            'method' => 'manual',
                            'metric' => [["type" => "count"]],
                        ];
                    } else {
                        $request['filters'][$filterName] = [
                            'type' => FilterInterface::TYPE_TERM,
                            'name' => $filterName,
                            'field' => $attribute->getAttributeCode(),
                            'value' => '$' . $attribute->getAttributeCode() . '$',
                        ];
                        $request['aggregations'][$bucketName] = [
                            'type' => BucketInterface::TYPE_TERM,
                            'name' => $bucketName,
                            'field' => $attribute->getAttributeCode(),
                            'metric' => [["type" => "count"]],
                        ];
                    }
                }
            }
            /** @var $attribute Attribute */
            if (in_array($attribute->getAttributeCode(), ['price', 'sku'])
                || !$attribute->getIsSearchable()
            ) {
                //same fields have special semantics
                continue;
            }
            if ($useFulltext) {
                $request['queries']['search']['match'][] = [
                    'field' => $attribute->getAttributeCode(),
                    'boost' => $attribute->getSearchWeight() ?: 1,
                ];
            }
        }
        return $request;
    }

    /**
     * Generate advanced search request
     *
     * @return array
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function generateAdvancedSearchRequest()
    {
        $request = [];
        foreach ($this->getSearchableAttributes() as $attribute) {
            /** @var $attribute Attribute */
            if (!$attribute->getIsVisibleInAdvancedSearch()) {
                continue;
            }
            if (in_array($attribute->getAttributeCode(), ['price', 'sku'])) {
                //same fields have special semantics
                continue;
            }

            $queryName = $attribute->getAttributeCode() . '_query';
            $request['queries']['advanced_search_container']['queryReference'][] = [
                'clause' => 'should',
                'ref' => $queryName,
            ];
            switch ($attribute->getBackendType()) {
                case 'static':
                    break;
                case 'text':
                case 'varchar':
                    if ($attribute->getFrontendInput() === 'multiselect') {
                        $filterName = $attribute->getAttributeCode() . self::FILTER_SUFFIX;
                        $request['queries'][$queryName] = [
                            'name' => $queryName,
                            'type' => QueryInterface::TYPE_FILTER,
                            'filterReference' => [
                                [
                                    'clause' => 'must',
                                    'ref' => $filterName,
                                ]
                            ],
                        ];

                        $request['filters'][$filterName] = [
                            'type' => FilterInterface::TYPE_TERM,
                            'name' => $filterName,
                            'field' => $attribute->getAttributeCode(),
                            'value' => '$' . $attribute->getAttributeCode() . '$',
                        ];
                    } else {
                        $request['queries'][$queryName] = [
                            'name' => $queryName,
                            'type' => 'matchQuery',
                            'value' => '$' . $attribute->getAttributeCode() . '$',
                            'match' => [
                                [
                                    'field' => $attribute->getAttributeCode(),
                                    'boost' => $attribute->getSearchWeight() ?: 1,
                                ],
                            ],
                        ];
                    }
                    break;
                case 'decimal':
                case 'datetime':
                case 'date':
                    $filterName = $attribute->getAttributeCode() . self::FILTER_SUFFIX;
                    $request['queries'][$queryName] = [
                        'name' => $queryName,
                        'type' => QueryInterface::TYPE_FILTER,
                        'filterReference' => [
                            [
                                'clause' => 'must',
                                'ref' => $filterName,
                            ]
                        ],
                    ];
                    $request['filters'][$filterName] = [
                        'field' => $attribute->getAttributeCode(),
                        'name' => $filterName,
                        'type' => FilterInterface::TYPE_RANGE,
                        'from' => '$' . $attribute->getAttributeCode() . '.from$',
                        'to' => '$' . $attribute->getAttributeCode() . '.to$',
                    ];
                    break;
                default:
                    $filterName = $attribute->getAttributeCode() . self::FILTER_SUFFIX;
                    $request['queries'][$queryName] = [
                        'name' => $queryName,
                        'type' => QueryInterface::TYPE_FILTER,
                        'filterReference' => [
                            [
                                'clause' => 'must',
                                'ref' => $filterName,
                            ]
                        ],
                    ];

                    $request['filters'][$filterName] = [
                        'type' => FilterInterface::TYPE_TERM,
                        'name' => $filterName,
                        'field' => $attribute->getAttributeCode(),
                        'value' => '$' . $attribute->getAttributeCode() . '$',
                    ];
            }
        }
        return $request;
    }
}
