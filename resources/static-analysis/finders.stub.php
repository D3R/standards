<?php

// Analysis-only patches for the reviewed, source-hash-guarded finder contracts.
// Never include this file in a runtime autoload list.
class D3R_Model
{
    /**
     * Preserve failure and collection results instead of promising only static.
     *
     * @param mixed $id Scalar identifier or identifier array.
     * @return ($id is array ? D3R_Model_Collection<static>|false : static|false)
     */
    public static function findById($id)
    {
    }

    /**
     * @param mixed $id Scalar identifier or identifier array.
     * @return ($id is array ? D3R_Model_Collection<static>|false : static|false)
     */
    public static function findFirstById($id)
    {
    }

    /**
     * @param mixed $whereOrId Query or identifier.
     * @param array $params Bindings.
     * @return static|false
     */
    public static function findFirst($whereOrId = 'all', $params = [])
    {
    }

    /**
     * @param mixed $sql SQL or select object.
     * @param array $params Bindings.
     * @return static|false
     */
    public static function findFirstBySql($sql, $params = [])
    {
    }

    /**
     * @param mixed $sql SQL or select object.
     * @param array $params Bindings.
     * @return D3R_Model_Collection<static>|false
     */
    public static function findBySql($sql, $params = [])
    {
    }

    /** @return D3R_Model_Collection<static> */
    public static function getCollection()
    {
    }
}

/**
 * @template T of D3R_Model = D3R_Model
 * @implements ArrayAccess<int, T|false>
 * @implements IteratorAggregate<int, T|false>
 */
class D3R_Model_Collection implements ArrayAccess, IteratorAggregate, Countable
{
    /** @param T $model Collection model prototype. */
    public function __construct(D3R_Model $model)
    {
    }

    /** @return Traversable<int, T|false> */
    public function getIterator(): Traversable
    {
    }

    /** @return array<int, T|false> */
    public function toArray()
    {
    }

    /** @return T|false */
    public function offsetGet($key)
    {
    }

    /** @return T|false */
    public function current()
    {
    }

    /** @return T|false */
    public function firstByField($field, $value)
    {
    }
}
