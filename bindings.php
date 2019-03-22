<?php
namespace \rastatech\odbal;
/**
* Abstraction of the Oracle variable binding process and related functionality for better maintainability/readability
* 
 * @package URL_ShortR
 * @subpackage dbal
 * @author todd.hochman
* @uses dbal_configurator a trait containing some configuration setting code that is used across several of the above classes
* @link http://us1.php.net/manual/en/function.oci-bind-by-name.php see list of bind types for regular parameters
* @link http://us1.php.net/manual/en/function.oci-bind-array-by-name.php see list of bind types for array parameters
* @author todd.hochman
 * 
 * @todo this whole dbal might need to be moved in namespace to accomodate MySQL dbal if/when we make one of those
*/
class bindings
{      
    /**
    *
    * @var Container_object    the Dependency Injection Container  
    */
    protected $ci;
    
    /**
    *
    * @var public variable so we can access bound variables; will be a numerically indexed array in order of the array of bound variables
    */
    public $bound_vars = array();
    
    /**
    *
    * @var mixed-array array of variables to bind and the values to which to bind them 
    */
    public $vars2bind = array();

    /**
    * the single or array of cursor objects for binding
    * 
    * @var mixed-array associative array of varname2bindVS => bound value
    */
    public $cursors2bind = array();
    
    /**
    * Configuration loading Trait
    */
    use configurator;

    /**
    * 
    * @param mixed-array $model_sql_elements    associative array of varname2bindVS => bound value, or false if you'd rather do that separately
    */
    public function __construct($ci, $model_sql_elements = FALSE)
    {
        $this->ci = $ci;
        $loadedConfigs = $this->_get_configs($model_sql_elements);
        $this->_assign2classVars($loadedConfigs);
    }    

    /**
     * Either sets the variables & values to bind if an array of such is passed, 
     * OR binds the variables to the oracle resource via __bindVars2SQL() if not. 
     * 
     * <b>dynamically creates public class attributes corresponding to the keys in the 'bind_vars' array, via which the caller can access the bound variables directly</b>
     *
     *
     * @uses __bindVars2SQL()
     * 
     * @param type $sqlType the type of SQL being processed, e.g. stored procedure
     * @param	boolean|mixed-array $bind_var_array	optional array of values to bind; 
     *              must be an associative array where the string key is the name of the bind variable and the value
     * is, well the value; can be a compound value as mentioned in $_sql_elements; 
     *              defaults to FALSE which will use any bind vars already instantiated as part of previously called methods or object construction
     * @param OCI Resource $stmt    the parsed statement resource
     * @return boolean|boolean-array    the results of the binding operation(s)
     */
    public function bind_vars($sqlType, $bind_var_array = FALSE, $stmt = FALSE)
    {
        if($bind_var_array){
            foreach($bind_var_array as $key2bind => $value2bind){
                $this->vars2bind[$key2bind] = $value2bind;
            }
            $return = ( ! $stmt) ? $this->vars2bind : $this->_bindVars2SQL($stmt, $sqlType);
        }
        $return = (( ! isset($return)) AND ($stmt)) ? $this->_bindVars2SQL($stmt, $sqlType) : $return;
        return $return;     
    }      
    
    /**
    * determines the SQL type and passes off the actual variable binding to the appropriate method for binding.
    * 
    * @param OCI Resource $stmt    the parsed statement resource
    * @param integer $sqlType   the type of SQL statement being bound
    * @return integer|integer-array the array of boolean return values for the binding operation(s); 
    * @throws \Exception if no variables were available for binding
    */
    protected function _bindVars2SQL($stmt, $sqlType)
    {
        if( ! $stmt){
            throw new \Exception('no statement found to bind on!', 521);
        }
        if($this->vars2bind){
            $b = ($sqlType < 2) ? $this->_bind_pkg($stmt) : $this->bind_passThruSQL($stmt);
            $this->bound_vars = ($b) ? $this->vars2bind : [];
            return $b;
        }
    }
    
    /**
    * iterates through the variable array and binds them to the statement
    * This function also will assign each key/ value pair to a dynamically-created public class attribute,
    * so you can reference those variables from outside the class, say if you have a function that returns a string or integer.
    * 
    * This function will also account for arrayed parameter values, i.e. if the passed parameter value is an array.
    * 
    * @param OCI_statement_resource $stmt   the Oracle parsed statement resource handle
    * @return boolean-array the array of binding results
    * @throws \Exception failure to bind variables will throw an exception
    */
    protected function _bind_pkg($stmt)
    {
        $b = array();
//        $bind_ctr = -1;
        $placeholder = array();
        foreach($this->vars2bind as $bind_var => $bind_value){
//            ++$bind_ctr;//increment here
            $this->bound_vars[$bind_var] = $bind_value;
            //dynamically add a public class attribute by which to access the bound var:
            $this->$bind_var = ((is_array($bind_value)) AND (array_key_exists('value', $bind_value))) ? $this->bound_vars[$bind_var]['value'] : $this->bound_vars[$bind_var];
            $b[$bind_var]= $this->_bind_pkg_arrayedParams($stmt, $bind_var, $bind_var, $bind_value); //TEST USING: $b[$bind_var] =  oci_bind_by_name($stmt, $bind_var, $bind_value);
            if( ! $b[$bind_var]){
                $err = oci_error($stmt);
                $errMsg = 'bind by name failed! Variable' . $bind_var . '; error message: ' . $err['message'] . '; error code: ' .$err['code'];
                $errMsg .= '; statement to bind was: {' . $this->sql . '} placeholder to bind: {:' . $bind_var . '}; value to bind: {' . $bind_value . '}';
                throw new \Exception($errMsg, 522);
            }
        }
        return $b;
    }    
    
    /**
    * Placeholder function for some functionality that was not commonly used and was too much trouble to port over if we weren't going to need it.
    * 
    * @param OCI_statement_resource $stmt   the Oracle parsed statement resource handle
    * @return type
    */
    protected function bind_passThruSQL($stmt)
    {
        throw new \Exception('pass-thru SQL not currently supported in this version of NMED Oracle DBAL; Please make a PL/SQL stored procedure instead.', 525);
    }  
        
    /**
    * merges any existing array of variables to bind with a new array of 'em
    * 
    * typically accessed publically; no internal usage
    * 
    * @param mixed-array    $newVars2bind  the array of variables to bind in the format of name => value 
    * @return mixed-array the merged array
    */
    public function merge_vars($newVars2bind)
    {
        $mergedVars = (is_array($newVars2bind)) ? array_merge($this->vars2bind, $newVars2bind) : $this->vars2bind;
        return $mergedVars;
    }
    
    /**
    * Handles variable binding, optionally using length, type parameters, and handling arrayed variables with oci_bind_array_by_name
    * @param type $stmt
    * @param type $bind_var
    * @param type $bind_var
    * @param type $bind_value
    * @return type
    */
    protected function _bind_pkg_arrayedParams($stmt, $bind_var, $bind_var, $bind_value)
    {
        $length = $this->_parse_bindvar($bind_value, 'length');
        $type = $this->_parse_bindvar($bind_value, 'type');
        $value2bind = $this->_parse_bindvar($bind_value, 'value');
        $placeholder[$bind_var] = ":$bind_var";
//        echo 'length is ' . var_export($length, TRUE) . ', type is ' . $type . ', value is ' . var_export($value2bind, TRUE) . ' <br/>';
        if((is_array($value2bind)) OR (is_array($length))){//arrayed param
//            echo 'binding array!<br/>';
            $length = $this->__get_lengthArray($length, $value2bind); //make sure we have valid length for bind_array_by name 
//            echo 'length is ' . var_export($length, TRUE);
            $b[$bind_var] = oci_bind_array_by_name($stmt, $placeholder[$bind_var], $this->$bind_var, $length[0], $length[1], $type);            
        }
        elseif((is_null($length)) AND (is_null($type))){
            $b[$bind_var] = oci_bind_by_name($stmt, $placeholder[$bind_var], $this->$bind_var);
//             if(strpos($bind_var, '_date_')){
//                echo 'binding using default length and type!<br/>';
//             }
            if($o_err = oci_error($stmt)){
                $err = $o_err['code'] . '; ' . $o_err['message'] . ';<br/>';
                $err .= $bind_var. ': ' . $placeholder[$bind_var] . '<br/>';
                $err .= 'SQL was: ' . $o_err['sqltext'] . '<br/>';
                throw new \Exception($err, 524);
            }
        }
        else{
             $b[$bind_var] = oci_bind_by_name($stmt, $placeholder[$bind_var], $this->$bind_var, $length, $type);
//             echo 'default binding; length is ' . var_export($length, TRUE) . ', type is ' . $type . ', value is ' . var_export($value2bind, TRUE) . ' <br/>';
        }
        return $b;
    }    
    
    /**
    * Allows us to pass an array as the bind var which enables us to dynamically set MAXLENGTH and TYPE parameters for the
    * oci_bind_by_name call
    *
    * @param mixed|mixed-array $bind_value	the simple or complex bind value
    * @param string $key	a particular key to retrieve from the complex bind value or defaults
    * @throws \Exception
    * @return mixed either the maxlength, the type, or the value to bind
    * @uses show_error()
    * @uses __parse_bindVar_length()
    * @uses __parse_bindVar_type()
    */
    protected function _parse_bindvar($bind_value, $key = FALSE)
    {
        $parseKeys = (is_numeric($key)) ? array(0, 1, 2) : array('length', 'type', 'value');
        switch($key){
            case $parseKeys[0]:                
                $return = $this->__parse_bindVar_length($key, $bind_value);
                break;
            case $parseKeys[1]:
                $return = $this->__parse_bindVar_type($key, $bind_value);
                break;
            case $parseKeys[2]:
                $return = ((is_array($bind_value)) AND (array_key_exists($key, $bind_value))) ? $bind_value[$key] : (( ! is_array($bind_value)) ? $bind_value : FALSE);
                if($return === FALSE){
                    throw new \Exception('No actual value found to bind to in compound bind value!', 523);
                }
                break;
            default:
                throw new \Exception('bound value parsing call is messed up - encountered a malformed compound bind value!', 524);
        }
        return $return;
    }    
    
    /**
    * calculates or derives the bind key length for oracle binding purposes
    *
    * @param string|integer	$key	the bindVar key, in this case either 0 or 'length'
    * @param mixed|mixed-array	 $bind_value	the simple or compound bind_value
    * @return integer	the calculated or derived value length for binding
    */
    private function __parse_bindVar_length($key, $bind_value)
    {
        $length = ((is_array($bind_value)) AND (array_key_exists($key, $bind_value))) ? $bind_value[$key] : (( ! is_array($bind_value)) ? FALSE : -1);
        if($length == -1){
            $valueKey = (is_numeric($key)) ? 2 : 'value';
            $length = ((is_array($bind_value)) AND (array_key_exists($valueKey, $bind_value))) ? $this->_get_maxLength($bind_value[$valueKey]) : $length;
        }
        $returnlength = ($length === FALSE) ? NULL : $length;
        return $returnlength;
    }    
    
    /**
     * gets either a calculated or default value for maxlength for oci_bind_by_name
     * 
     * @param mixed	$value	the value to bind from which we will get length
     * @return number
     * @see _parse_bindvar()
     */
    protected function _get_maxLength($value)
    {
        $length_calc = ($value) ? strlen($value) : -1;
        return strlen($length_calc);
    }
   
    /**
    * calculates or derives the bind key type for oracle binding purposes
    *
    * @param string|integer	$key	the bindVar key, in this case either 1 or 'type'
    * @param mixed|mixed-array	 $bind_value	the simple or compound bind_value
    * @return long		the calculated or derived value type for binding
    */
    private function __parse_bindVar_type($key, $bind_value)
    {
        $type = ((is_array($bind_value)) AND (array_key_exists($key, $bind_value))) ?  strtoupper($bind_value[$key]) : (( ! is_array($bind_value)) ? FALSE : NULL);
        if(is_null($type)){
            $valueKey = (is_numeric($key)) ? 2 : 'value';
            $type_calc = ($bind_value[$valueKey]) ? ((is_string($bind_value[$valueKey])) ? SQLT_CHR : ((is_numeric($bind_value[$valueKey])) ? SQLT_INT : NULL)) : NULL;
            $type = ((is_array($bind_value)) AND (is_null($type))) ? ((array_key_exists($valueKey, $bind_value)) ? $type_calc : NULL) : $type;
        }
        if(is_string($type)){//verify the type is correctly formatted
            $type = constant((strpos($type, 'SQLT_') === FALSE) ? 'SQLT_' . $type : $type);
        }
        $returnType = ($type === FALSE) ? NULL : $type;
        return $returnType;
    }   
    
    /**
     * gets the table length and max item length for bind_array_by_name functionality
     * 
     * @param integer|integer-array|NULL	 $length	the $bind_value length, either
     * @param mixed	 $bind_value	the value of the variable being bound
     * @return integer-array	the array of table length and max item length
     */
    private function __get_lengthArray($length, $bind_value)
    {
        if(is_array($length)){//assumes length of 2 for the $length array, indicating max_table_length & max_item_length
            return $this->__handleArrayedVarLength($length, $bind_value);
        }
        //auto-determine table length if none specified:
        if( ! $length){
            $lengthArray[0] = $this->__parse_varLength($bind_value);
            $lengthArray[1] = ($strlength = strlen($bind_value)) ? $strlength : NULL;
            return $lengthArray;
        }
        //table length specified, use -1 for max_item_length:
        $lengthArray[0] = $length;
        $lengthArray[1] = -1;
        return $lengthArray;
    }
    
    /**
     * Handles complex variable lengths e.g. for arrayed variables
     * 
     * @param integer $length   the length as represented from the bind_vars
     * @param mixed $bind_value the value of the bind var
     * @return integer-array    the max table length or the max table length + max item length
     */
    private function __handleArrayedVarLength($length, $bind_value)
    {
        if(( ! $length[0]) OR ( ! $length[1])){//auto-determine table length /max item length if none specified:
            if($length[1]){//preserve any existing item length included
                $itemLength = $length[1];
            }
            $lengthArray = $this->__parse_varLength($bind_value, TRUE);//returns length array of 2 items
            if((isset($itemLength)) AND ($lengthArray[1] < $itemLength)){
                $lengthArray[1] = $itemLength;
            }
        }
        $lengthArray = (isset($lengthArray)) ? $lengthArray : $length;
        return $lengthArray;
    }
    
    /**
     *
     * @param mixed-array	 $bind_value	the array of values we're about to bind
     * @param boolean	 $asArray	whether to process $length as an array or not
     * @return integer|integer-array	the max table length or the max table length + max item length
     */
    private function __parse_varLength($bind_value, $asArray = FALSE)
    {
        if( ! $bind_value){
            throw new \Exception('unable to determine table length for oci_bind_array_by_name');  
        }
        if($asArray){
            $length[0] = count($bind_value);
            //get the largest value of the array & use that for max item length:
            for($i = 0; $i < $length[0]; $i++){
                $value_length = strlen($bind_value[$i]);
                if($i > 0){
                    $length[1] = ($value_length > $length[1]) ? $value_length : $length[1];
                    continue;
                }
                $length[1] = $value_length;
            }
        }
        $length = (isset($length)) ? $length : count($bind_value);
        return $length;
    }
}