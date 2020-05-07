<?php
namespace rastatech\odbal;

use OCI_Collection;

/**
 * Trait bindvalue; abstraction of value-related functionality for OCI8 binding
 *
 * parses scalar and arrayed values, both in the form of compound values (length, type, value), as well as raw arrays
 *
 *
 * @package rastatech\odbal
 */
trait bindvalue
{

    /**
     * tests a value to see if it is a compound (length, type, value) array value
     *
     * @param array|mixed $bind_value the bind value array or scalar value
     * @return bool TRUE if compound value, FALSE if not
     */
    protected function _is_compoundVar($bind_value)
    {
        if(is_array($bind_value)){
            $compound_key_names = $this->_compound_value_keys;
            $parsedKeys = array_keys($compound_key_names);
            foreach ($parsedKeys as $parsedKey) {
                if( ! array_key_exists($parsedKey, $bind_value)){
                    return FALSE;
                }
            }
            return TRUE;
        }
        return FALSE;
    }

    /**
     * Processes a compound array for length, type, value
     *
     * @param array $bind_value the compound value (length=,type=,value=)
     * @param bool $outvar whether this compound variable is an OUT parameter or not
     * @return array    the bind_info (length=,type=,value=)
     * @throws Exception
     */
    protected function _process_compound_array($bind_value, $outvar = TRUE)
    {
        $parsedAttributes = [];
        foreach ((array_keys($this->_compound_value_keys)) as $key){
            $isCompoundValue = $this->_process_compound_value($bind_value,$key, $outvar);
            if($isCompoundValue !== FALSE){
                $parsedAttributes[$key] = $isCompoundValue;
                continue;
            }
        } //if any of the compound values are present (i.e. length, type, value), it's a valid compound array; otherwise no.
        if( ! (array_filter(array_values($parsedAttributes)))){
            throw new Exception('compound vars must provide (length=,type=,value=).');
        }
        return $parsedAttributes;
    }

    /**
     * Processes the compound value array element; length, type, value
     *
     * @param array  $bind_info the array of oci_bind information needed -- length, type, value are the keys
     * @param string $key       the bind info key being processed
     * @param bool   $outvar    whether this compound value is an OUT var or not (IN var)
     * @return bool|int|long|mixed|array|void   the result of the processing operation; varies
     * @throws Exception
     */
    protected function _process_compound_value($bind_info, $key, $outvar = TRUE)
    {
        switch ($key){
            case "type":
                $parsedKey = $this->_determine_SQLT_type($bind_info, $outvar);
                break;
            case "length":
                $parsedKey = $this->_determine_length($bind_info,TRUE);
                break;
            case "value":
                $parsedKey = $this->_handle_array_value($bind_info);
                break;
            default:
                $parsedKey = FALSE;
        }
        return isset($parsedKey) ? ($parsedKey) : FALSE;
    }

    /**
     * Check an array for NULL values
     *
     * @param array $bind_value_array the arrayed value to check for NULLs
     * @return bool TRUE if a NULL value was found in the array, FALSE otherwise
     */
    protected function _check_4nulls($bind_value_array)
    {
        foreach ($bind_value_array as $array_key => $array_item) {
            if($array_item === NULL){
                return TRUE;
            }
        }
        return FALSE;
    }

    /**
     * Abstraction of massaging functions for Arrayed values
     *
     * @param array|mixed $binding_info the bind value
     * @return array|mixed the massaged array or the original scalar value
     */
    protected function _handle_array_value($binding_info)
    {
        if(is_array($binding_info['value'])){
           return $this->_handle_arrayedValues_wnulls($binding_info);
        }
        return $binding_info['value'];
    }

    /**
     * Abstraction of null handling so we can be flexible depending on what the testing looks like
     *
     * currently doing nothing but returning the value, since I could not get arrays with NULL items among their contents to bind successfully in any scenario
     *
     * @param array $binding_info the arrayed value
     * @return array the bind_value
     */
    protected function _handle_arrayedValues_wnulls($binding_info)
    {
        return $binding_info['value'];
    }


    /**
     * creates an oci collection object out of the array and append the array items to that collection
     *
     * oci8 type for collections for binding must always be SQLT_NTY
     *
     * @param array $bind_info the array of bind info; (length, type, value)
     * @return OCI_Collection
     */
    protected function _create_collection($bind_info)
    {
        // Create an OCI-Collection object
        if(is_array($bind_info['type'])){
            $schema_and_type = $bind_info['type'];
            $collection = oci_new_collection($this->ci->conn,$schema_and_type[1],  $schema_and_type[0]);
        }
        else{
            $collection = oci_new_collection($this->ci->conn,$bind_info['type']);
        }
        foreach ($bind_info['value'] as $array_item){ //add the items to the collection
            $collection->append($array_item);
        }
        return $collection;
    }
}