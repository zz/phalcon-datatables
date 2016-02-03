<?php

namespace DataTables\Adapters;

use Phalcon\Paginator\Adapter\QueryBuilder as PQueryBuilder;

class QueryBuilder extends AdapterInterface
{

    /**
     *
     * @var \Phalcon\Mvc\Model\Query\Builder
     */
    protected $builder;
    protected $originalColumns;

    public function setBuilder($builder)
    {
        $this->builder = $builder;
    }

    public function setColumns(array $columns)
    {
        $this->originalColumns = $columns;

        foreach ($columns as $i => $column) {
            if (is_array($column)) {
                $columns[$i] = array_keys($column)[0];
            }
        }

        $this->columns = $columns;
    }

    public function getResponse()
    {
        $builder = new PQueryBuilder([
            'builder' => $this->builder,
            'limit' => 1,
            'page' => 1,
        ]);

        $total = $builder->getPaginate();

        $this->bind('global_search', function($column, $search) {
            $this->builder->orWhere("{$column} LIKE :key_{$column}:", ["key_{$column}" => "%{$search}%"]);
        });

        $this->bind('column_search', function($column, $search) {
            $this->builder->andWhere("{$column} LIKE :key_{$column}:", ["key_{$column}" => "%{$search}%"]);
        });

        $this->bind('order', function($order) {

            if (!empty($order)) {
                $this->builder->orderBy(implode(', ', $order));
            }
        });

        $builder = new PQueryBuilder([
            'builder' => $this->builder,
            'limit' => $this->parser->getLimit(),
            'page' => $this->parser->getPage(),
        ]);


        /* @var $filtered \Phalcon\Mvc\Model\Resultset  */
        $filtered = $builder->getPaginate();

        /* @var $metadata \Phalcon\Mvc\Model\MetaData  */
        $metadata = \Phalcon\Di::getDefault()->get('modelsMetadata');

        $item = $filtered->items->getFirst();
        if ($item instanceof \Phalcon\Mvc\Model) {
            $filtered->items->rewind();
            $columnMap = $metadata->getColumnMap($item);
            $columnMap = array_combine($columnMap, $columnMap);

            $extractMethods = function ($item) {
                $reflection = new \ReflectionClass($item);
                $itemMethods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
                $itemMethods = array_map(function(\ReflectionMethod $reflectionMethod) {
                    return $reflectionMethod->getName();
                }, $itemMethods);
                return array_combine($itemMethods, $itemMethods);
            };

            // if use array_diff we can catch error, because $this->originalColumns can have array item
            $attributes = $methods = [];
            foreach ($this->originalColumns as $itemColumn) {
                $itemData = [];
                if (is_string($itemColumn)) {
                    // check that it is item attribute
                    if (isset($columnMap[$itemColumn])) {
                        $attributes[] = $itemColumn;
                    }
                } elseif (is_array($itemColumn)) {
                    /**
                     * Possible variants
                     * itemColumn => [methodName => [param1, param2]] - method with parameters
                     * itemColumn => methodName] - method without parameters
                     * 
                     */
                    $columnName = array_keys($itemColumn)[0];
                    $methodData = $itemColumn[$columnName];

                    if (!isset($columnMap[$columnName])) {
                        // undefined columnName
                        continue;
                    }
                    $parameters = null;
                    if (is_array($methodData)) {
                        $methodName = array_keys($methodData)[0];
                        $parameters = $methodData[$methodName];
                    } else {
                        $methodName = $methodData;
                    }
                    // check that it is existed method
                    if (empty($itemMethods)) {
                        $itemMethods = $extractMethods($item);
                    }

                    if (isset($itemMethods[$methodName])) {
                        $methods[$columnName] = compact('methodName', 'parameters');
                    }
                }
            }

            $data = [];
            foreach ($filtered->items as $item) {
                $itemData = [];
                foreach ($attributes as $attr) {
                    $itemData[$attr] = $item->readAttribute($attr);
                }

                foreach ($methods as $columnName => $method) {
                    $parameters = !empty($method['parameters']) ? $method['parameters'] : null;
                    $itemData[$columnName] = call_user_func_array([$item, $method['methodName']], $parameters);
                }

                $data[] = $itemData;
            }
        } else {
            $data = $filtered->items->toArray();
        }

        return $this->formResponse([
                    'total' => $total->total_items,
                    'filtered' => $filtered->total_items,
                    'data' => $data,
        ]);
    }

}
