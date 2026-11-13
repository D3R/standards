<?php

// Analysis-only patch for the inspected D3R_Model::findById contract.
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
}

class D3R_Model_Collection
{
}
