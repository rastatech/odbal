<?php

/*
 * @package UDS
 * @subpackage models
 * @author todd.hochman
 * 
 * Interface to guarantee consistency accross DB type Model instances
 * 
 * Mostly will be of use when we implement MySQL &c
 * 
 */

namespace models;

/**
 *
 * @author todd.hochman
 */
interface model_interface
{
    /**
     * this should include a call to the parent /dbal/main method at some point and return a mixed array
     * 
     * @param type $bindVars
     * @param mixed $outvar
     */
    public function run_sql($bindVars = FALSE, $outvar = NULL);
    
    /**
     * this should put together the execution string and the array of bind vars, both for the DBAL $_sql_elements array 
     * 
     * @param Request $request
     * @param Container $DIcontainer
     */
    public function create_packageCall($request, $DIcontainer);
}
