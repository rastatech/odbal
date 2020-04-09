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
     * @param int|string  $length      a provided length, assumes a numeric
     * @param mixed|array $value       the value to use for length determination, if needed
     * @param bool        $is_compound whether or not the value is part of a compoind
     * @return int the bind item length
     * @throws Exception
     * @throws Exception
     * @link https://github.com/php/php-src/tree/master/ext/oci8/tests the OCI tests from the actual PHP source; very illminating
     */
    protected function _determine_length($length, $value, $is_compound = FALSE)
    {
//        echo "testing  _determine_length :" . var_export( $length, TRUE) . "<br/>\n";
        if(is_array($value)){
            if($is_compound){
                if( ! $length){
                    $length_info = $this->_calculate_arrayedValue_length($value);
                    return $length_info;
                }
                if( ! is_numeric($length)){
                    throw new Exception('provided length must be a number');
                }
                $length = (is_int($length)) ? $length : (int) $length;
                return $length;
            }//raw array:
            $length_info = $this->_calculate_arrayedValue_length($value);
            return $length_info;
        }//not an array:
        $length = (is_int($length)) ? $length : (( ! $length ) ? -1  : (int) $length);
        return $length;
    }

    /**
     * calculates the max_table_length and max_item_length for an array value from the value itself
     *
     * - max_table_length is either the count of items, or 1 for empty arrays
     * - max_item_length is either -1, which lets Oracle figure it out itself, or 1 for empty arrays
     *
     * @param array $value the array value for which we want to calculate length
     * @return array    the array of max_table_length and max_item_length
     */
    protected function _calculate_arrayedValue_length($value)
    {
        $length_info['max_table_length'] = ( count($value)) ? count($value) : 1;
        $length_info['max_item_length'] = ( count($value)) ? -1 : 1; //let oci figure out the longest individual item itself unless it's an empty array, in which case the docs seem to indicate in must be 1
        return $length_info;
    }
}