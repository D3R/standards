<?php

// Analysis-only patches for the reviewed, source-hash-guarded finder contracts.
// Never include this file in a runtime autoload list.
class D3R_Model
{
    /**
     * Preserve failure and collection results instead of promising only static.
     *
     * @param mixed $id Scalar identifier or identifier array.
     * @return ($id is array ? D3R_Model_Collection|false : static|false)
     */
    public static function findById($id)
    {
    }

    /**
     * @param mixed $id Scalar identifier or identifier array.
     * @return ($id is array ? D3R_Model_Collection|false : static|false)
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
     * @return D3R_Model_Collection|false
     */
    public static function findBySql($sql, $params = [])
    {
    }
}

class D3R_Model_Collection
{
}
