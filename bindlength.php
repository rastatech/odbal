<?php
namespace rastatech\odbal;

use Exception;

/**
 * Trait bindlength;  abstraction of length-related functionality for OCI8 binding
 *
 * can parse given integer lengths or determine the length of an array via the value
 *
 * @package rastatech\odbal
 */
trait bindlength
{
    /**
     * Determine the length of the scalar value or array;
     *
     * handles empty arrays specifically according to what I found in the link below;
     *
     * @param array     $bind_info  the array of bind info, so we have access to all of it in case circumstances necessitate
     * @param bool $is_compound whether or not the value is part of a compoind
     * @return array|int
     * @throws Exception
     * @link https://github.com/php/php-src/tree/master/ext/oci8/tests the OCI tests from the actual PHP source; very illminating
     */
    protected function _determine_length($bind_info, $is_compound = FALSE)
    {
//        echo "testing  _determine_length :" . var_export( $length, TRUE) . "<br/>\n";
        if(is_array($bind_info['value'])){
            if($is_compound){
                if( ! $bind_info['length']){
                    return $this->_calculate_arrayedValue_length($bind_info);
                }
                if( ! is_numeric($bind_info['length'])){
                    throw new Exception('provided length must be a number');
                }
                return (is_int($bind_info['length'])) ? $bind_info['length'] : (int) $bind_info['length'];
            }//raw array:
            return $this->_calculate_arrayedValue_length($bind_info);
        }//not an array:
        return (is_int($bind_info['length'])) ? $bind_info['length'] : (( ! $bind_info['length'] ) ? -1  : (int) $bind_info['length']);
    }

    /**
     * Calculates the max_table_length and max_item_length for an array value from the value itself
     *
     * - max_table_length is either the count of items, or 1 for empty arrays
     * - max_item_length is either -1, which lets Oracle figure it out itself, or 1 for empty arrays
     *
     * @param array $bind_info
     * @return array    the array of max_table_length and max_item_length
     */
    protected function _calculate_arrayedValue_length($bind_info)
    {
        $arrayed_value = $bind_info['value'];
        if($this->_check_4nulls($arrayed_value)){
            $max_length = 0;
            foreach ($arrayed_value as $array_item) {
                try {
                    $item_length = strlen($array_item);
                    $max_length = ($item_length > $max_length) ? $item_length : $max_length;
                }
                catch (exception $e) {
                    //code to handle the exception
                    echo "wee problem here, laddie! " . $e->getMessage();
                }
            }
        }
        $length_info['max_table_length'] = ( count($arrayed_value)) ? count($arrayed_value) : 1;
        $length_info['max_item_length'] = ( count($arrayed_value)) ? ((! isset($max_length)) ? -1 : $max_length) : 1; //let oci figure out the longest individual item itself unless it's an empty array, in which case the docs seem to indicate in must be 1
        return $length_info;
    }
}