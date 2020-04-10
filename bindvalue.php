<?php
namespace rastatech\odbal;

use OCI_Collection;
use ReflectionException;

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
     * process a compound array for length, type, value
     *
     * @param      $bind_value
     * @param bool $outvar
     * @return array|bool
     * @throws Exception
     */
    protected function _process_compound_array($bind_value, $outvar = TRUE)
    {
//        echo "testing bind value :" . var_export( $bind_value, TRUE) . "<br/>\n";
        //should combine _parse_4compoundAttribs & _parseCompoundValues, & leverage new methods for type & length & value processing
        foreach ((array_keys($this->_compound_value_keys)) as $key){
            $isCompoundValue = $this->_process_compound_value($bind_value,$key, $outvar);
            if($isCompoundValue !== FALSE){
                $parsedAttributes[$key] = $isCompoundValue;
                continue;
            }
            if(($parsedAttributes[$key] === FALSE) AND ($outvar)){
                throw new Exception('Parameter name indicates an OUT var or function return. You must provide [length, type, value] array.');
            }
        }
        //if any of the compound values are present (i.e. length, type, value), it's a valid compound array; otherwise no.
//        echo "testing compound processing :" . var_export( $parsedAttributes, TRUE) . "<br/>\n";
        return (array_filter(array_values($parsedAttributes))) ? $parsedAttributes : FALSE;
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
                $parsedKey = $this->_handle_array_value($bind_info[$key]);
                break;
            default:
                $parsedKey = FALSE;
        }
        return isset($parsedKey) ? ($parsedKey) : FALSE;
        return FALSE;
    }

    /**
     * Check an array for NULL values
     *
     * @param $bind_value_array
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
     * @param array|mixed $bind_value the bind value
     * @return array|mixed the massaged array or the original scalar value
     */
    protected function _handle_array_value($bind_value)
    {
        if(is_array($bind_value)){
//            return $this->_homogenize_array_value($bind_value);
            $parsedAttributes['value'] = $this->_handle_arrayedValues_wnulls($bind_value);
        }
        return $bind_value;
    }

    /**
     * Abstraction of null handling so we can be flexible depending on what the testing looks like
     *
     * currently doing nothing but returning the value, since I could not get arrays with NULL items among their contents to bind successfully in any scenario
     *
     * @param array $binding_info the array of bind info; (length, type, value)
     * @return array the bind_value
     */
    protected function _handle_arrayedValues_wnulls($binding_info)
    {
//        if((is_array($binding_info['value'])) AND ($binding_info['type'] == SQLT_ODT)){
//        if(is_array($binding_info['value'])){
////            $refl_class = new \ReflectionClass (__CLASS__);
////            $constants = array_flip($refl_class->getConstants());
////            $constants = get_defined_constants();
////            die(var_export($constants, TRUE));
////            $binding_info['type'] = $constants[$binding_info['type']];
////            $binding_info['type'] = 'SQLT_ODT';
////             echo "doing a date array!: <br/>\n";
////            $binding_info['value'] = $this->_create_collection($binding_info);
//            foreach ($binding_info['value'] as $array_index => $array_item) {
//                if(is_null($array_item)){
//                     echo "found a null! FIxing...: <br/>\n";
////                    $binding_info['value'][$array_index] = 'null';
////                    $binding_info['value'][$array_index] = 'NULL';
////                    $binding_info['value'][$array_index] = '';
////                    $binding_info['value'][$array_index] = "";
////                    $binding_info['value'][$array_index] = NULL;
//                    $binding_info['value'][$array_index] = FALSE;
////                    $binding_info['value'][$array_index] = 0;
//                }
//            }
//        }
        return $binding_info['value'];
    }

    /**
     * creates an oci collection object out of the array and append the array items to that collection
     *
     * @param array $bind_info the array of bind info; (length, type, value)
     * @return false|OCI_Collection
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
        foreach ($bind_info['value'] as $array_item){
            $collection->append($array_item);
        }
        return $collection; //re-assign the class attribute for retrieval; I *think* this will work....
    }
}