<?php

namespace rastatech\odbal;

use DateTime;
use \Exception;

/**
 * Abstraction of the Oracle variable binding process and related functionality for better maintainability/readability
 *
 * @package    ODBAL
 * @subpackage dbal
 * @author     todd.hochman
 * @uses       dbal_configurator a trait containing some configuration setting code that is used across several of the above classes
 * @link       http://us1.php.net/manual/en/function.oci-bind-by-name.php see list of bind types for regular parameters
 * @link       http://us1.php.net/manual/en/function.oci-bind-array-by-name.php see list of bind types for array parameters
 * @author     todd.hochman
 *
 * @todo       doesn't support pass-thru SQL yet
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
     * OR binds the variables to the oracle resources via __bindVars2SQL() if not.
     *
     * <b>dynamically creates public class attributes corresponding to the keys in the 'bind_vars' array, via which the caller can access the bound variables directly</b>
     *
     *
     * @param type $sqlType the type of SQL being processed, e.g. stored procedure (which is all that is currently supported, but leaving it open....)
     * @param boolean| mixed-array $bind_var_array    optional array of values to bind;
     *                      must be an associative array where the string key is the name of the bind variable and the value
     *                      is, well the value; The value can be an array of raw values, or a 'compound value':
     *                      a compund value is in this format: `$key2bind = ['length' = 250, 'type' => 'chr', 'value' => '']; type must correspond to one of the
     *                      valid OCI datatypes (links below), or an abbreviation of same where you just leave off the "SQLT_" part and that'll get filled in for you.
     * @param OCI Resource $stmt    the parsed statement resource
     * @return boolean|boolean-array    the results of the binding operation(s)
     * @uses           __bindVars2SQL()
     * @link https://www.php.net/manual/en/function.oci-bind-by-name.php valid OCI datatypes for atomic values
     * @link https://www.php.net/manual/en/function.oci-bind-array-by-name.php valid OCI datatypes for arrayed values
     *
     */
    public function bind_vars($sqlType, $bind_var_array = FALSE, $stmt = FALSE)
    {
        if ($bind_var_array) {
            foreach ($bind_var_array as $key2bind => $value2bind) {
                $this->vars2bind[$key2bind] = $value2bind;
            }
            $return = (!$stmt) ? $this->vars2bind : $this->_bindVars2SQL($stmt, $sqlType);
        }
        $return = ((!isset($return)) AND ($stmt)) ? $this->_bindVars2SQL($stmt, $sqlType) : $return;
        return $return;
    }

    /**
     * Determines the SQL type and passes off the actual variable binding to the appropriate method for binding.
     * Only one method (for packages) is supported currently, but this method is designed to support pass-thru SQL when that is added, if ever
     *
     * @param OCI Resource $stmt    the parsed statement resource
     * @param integer $sqlType the type of SQL statement being bound
     * @return integer|integer-array the array of boolean return values for the binding operation(s);
     * @throws Exception if no variables were available for binding
     */
    protected function _bindVars2SQL($stmt, $sqlType)
    {
        if (!$stmt) {
            throw new Exception('no statement found to bind on!', 517);
        }
        if ($this->vars2bind) {
            $b = ($sqlType < 2) ? $this->_bind_pkg($stmt) : $this->bind_passThruSQL($stmt); //not currently supported but leaving it open....
            $this->bound_vars = ($b) ? $this->vars2bind : []; //this makes sure we have an empty array for bound_vars at the very least so nothing explodes
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
     * @param OCI_statement_resource $stmt the Oracle parsed statement resource handle
     * @return boolean-array the array of binding results
     * @throws Exception failure to bind variables will throw an exception
     */
    protected function _bind_pkg($stmt)
    {
        $b = array();
        $placeholder = array();
        foreach ($this->vars2bind as $bind_var => $bind_value) {
            $this->bound_vars[$bind_var] = $bind_value;
//            //dynamically add a public class attributse by which to access the bound var:
            $this->$bind_var = ((is_array($bind_value)) AND (array_key_exists('value', $bind_value))) ? $bind_value['value'] : $bind_value;
            $b[$bind_var] = $this->_bind_pkg_parameter($stmt, $bind_var, $bind_value);

        }
        return $b;
    }

    /**
     * Placeholder function for some functionality that was not commonly used and was too much trouble to port over if we weren't going to need it.
     *
     * @param OCI_statement_resource $stmt the Oracle parsed statement resource handle
     * @return type
     */
    protected function bind_passThruSQL($stmt)
    {
        $errMsg = 'pass-thru SQL not currently supported in this version of Oracle DBAL; Please make a PL/SQL stored procedure instead, or fork Rastatech/ODBAL on GitHub.';
        throw new Exception($errMsg, 520);
    }

    /**
     * merges any existing array of variables to bind with a new array of 'em
     *
     * typically accessed publically; no internal usage
     *
     * @param  mixed-array    $newVars2bind  the array of variables to bind in the format of name => value
     * @return mixed-array the merged array
     */
    public function merge_vars($newVars2bind)
    {
        $mergedVars = (is_array($newVars2bind)) ? array_merge($this->vars2bind, $newVars2bind) : $this->vars2bind;
        return $mergedVars;
    }

    /**
     * Handles variable binding, optionally using length, type parameters, and handling arrayed variables with oci_bind_array_by_name
     *
     * @param rastatech\odbal\statement $stmt the Oracle statement object
     * @param string $bind_var the variable name to bind
     * @param array $bind_value the value of the variable to bind, in this case a compound array of length, type, value (if any)
     * @return array the bound variable
     */
    protected function _bind_pkg_parameter($stmt, $bind_var, $bind_value)
    {
        $binding_info = $this->_parse_bindvar_4attributes($bind_value);
        $placeholder[$bind_var] = ":$bind_var";
        $this->$bind_var = $binding_info['value'];
        if(is_array($binding_info['value'])){
            $boundArray = oci_bind_array_by_name($stmt, $placeholder[$bind_var], $this->$bind_var, $binding_info["length"]['max_table_length'], $binding_info["length"]['max_item_length'], $binding_info['type']);
            if ($o_err = oci_error($stmt)) {
                $this->_throwBindingError($o_err, $bind_var, 'Oracle bind array by name failed!', 522);
            }
            return $boundArray;
        }
        $boundVar = oci_bind_by_name($stmt, $placeholder[$bind_var], $this->$bind_var, $binding_info['length'], $binding_info['type']);
        if ($o_err = oci_error($stmt)) {
            $this->_throwBindingError($o_err, $bind_var, 'Oracle bind by name failed!', 523);
        }
        return $boundVar;
    }

    /**
     *  Abstraction of Oracle error capture process & associated Exception throwing
     *
     * @param $o_err    Oracle_Error_Object
     * @param $bind_var string  the name of the failed variable
     * @param $message_preamble string  the message for the Exception
     * @param $code integer the Exception code
     * @throws Exception
     */
    protected function _throwBindingError($o_err, $bind_var, $message_preamble, $code)
    {
        $err = $o_err['message'] . ';<br/>' . $bind_var . '<br/>' . 'SQL was: ' . $o_err['sqltext'] . '<br/>';
        $message = preg_replace('~[[:cntrl:]]~', '', $err);
        $this->ci['errorMessage'] = $message;
        $this->ci['errorCode'] = htmlentities($o_err['code']);
        throw new Exception($message_preamble, $code);
    }

    /**
     * Parses the $bind_var to dynamically set LENGTH and TYPE parameters for the oci_bind_by_name | oci_bind_array_by_name call
     *
     * @param   mixed|mixed-array $bind_value    the simple or complex bind value
     * @return  mixed-array the processed binding attributes for the value;
     * @throws Exception
     * @uses        _parseCompoundValues()
     * @uses        _parseNonCompoundValues()
     */
    protected function _parse_bindvar_4attributes($bind_value)
    {
        $parsedAttributes = array('length' => NULL, 'type' => NULL, 'value' => NULL);
        $parsedKeys = array_keys($parsedAttributes);
        foreach ($parsedKeys as $key => $value){
            if($this->_parseCompoundValues($bind_value,$key,$parsedAttributes) !== FALSE){
                continue;
            }
            $parsedAttributes = $this->_parseNonCompoundValues($bind_value, $key,$parsedAttributes);
        }
        if(! array_key_exists('value', $parsedAttributes)){
            $this->ci['errorMessage'] = 'No actual value found or to bind to or malformed compound bind value!';
            $this->ci['errorCode'] = 523;
            throw new Exception( $this->ci->errorMessage, 523);
        }
        return $parsedAttributes;
    }

    /**
     * Analyzes an atomic or raw-data-array value for the binding attributes
     *
     * @param $bind_value   the compound value to process
     * @param $key  string the bind_var being processed
     * @param $parsedAttributes    the array of compound values to search for and/or process
     * @return mixed-array the processed binding attributes for the value;
     * @uses _parse_bindVar_4length()
     * @uses _parse_bindVar_4type()
     */
    protected function _parseNonCompoundValues($bind_value, $key, $parsedAttributes)
    {
        switch ($key) {
            case 'length':
                $parsedAttributes[$key] = $this->_parse_bindVar_4length($bind_value);
                break;
            case 'type':
                $parsedAttributes[$key] = $this->_parse_bindVar_4type($bind_value);
                break;
            case 'value':
                $parsedAttributes[$key] = $bind_value;
                break;
        }
        return $parsedAttributes;
    }

    /**
     * parses arrayed values for compound values;  a compund value is in this format: `$key2bind = ['length' = 250, 'type' => 'chr', 'value' => '']; type must correspond to one of the
     * valid OCI datatypes (links below), or an abbreviation of same where you just leave off the "SQLT_" part and that'll get filled in for you.
     *
     * @param $bind_value   the compound value to process
     * @param $key  string the bind_var being processed
     * @param $parsedKeys    the array of compound keys to search for and/or process
     * @return mixed-array|bool the processed binding attributes for the value if the compound value was successfully processed, FALSE if otherwise
     */
    protected function _parseCompoundValues($bind_value, $key, $parsedKeys)
    {
        if ((is_array($bind_value)) AND  (array_key_exists($key,$bind_value))){//compound values should tell us what to set for each parameter
            switch ($key){
                case 'type':
                    $type = (strpos($bind_value[$key], 'SQLT_') === FALSE) ? 'SQLT_' . $bind_value[$key] : $bind_value[$key]; //account for abbreviated var types
                    $parsedKeys[$key] = constant(strtoupper($type));
                    break;
                case 'length':
                    $length = (is_int($bind_value[$key])) ? $bind_value[$key] : (int)$bind_value[$key];
                    $parsedKeys[$key] = $length;
                    break;
                case 'value':
                    $parsedKeys[$key] = $bind_value[$key];
                    break;
            }
            return $parsedKeys;
        }
        return FALSE;
    }
    /**
     * calculates or derives the bind key length for oracle binding purposes
     *
     * @param mixed|mixed-array     $bind_value    the simple or compound bind_value
     * @return integer|array    the calculated or derived value length for binding or an array of length info for arrayed parameters
     */
    protected function _parse_bindVar_4length($bind_value)
    {
        $length_info = [];
        if (is_array($bind_value)){ //calculating max_table_length
            $length_info['max_table_length'] = count($bind_value);
            $length_info['max_item_length'] = -1; //let oci figure out the longest one itself
            return $length_info;
        }
        $length_info =  ($bind_value) ? strlen($bind_value) : -1;
        return $length_info;
    }

    /**
     * calculates or derives the bind key type for oracle binding purposes
     *
     * @param mixed|mixed-array     $bind_value    the simple or compound bind_value
     * @param boolean   $arrayed    whether we're recursing or not; important for arrayed data types
     * @return long        the calculated or derived value type for binding
     * @uses _parse_bindVar_4type() recursively used
     */
    protected function _parse_bindVar_4type($bind_value, $arrayed = FALSE)
    {
        if (is_array($bind_value)){// arrayed raw values, not compound values
            $testArray = array_filter($bind_value); //get rid of NULL, FALSE, 0 values
            $testValue = $testArray[0];
            $type = $this->_parse_bindVar_4type($testValue, TRUE);//recurse
            return $type;
        }
        $type = (is_float($bind_value)) ? SQLT_FLT : ((is_integer($bind_value)) OR (is_bool($bind_value))) ?
            SQLT_INT : (is_numeric($bind_value)) ? SQLT_NUM : $this->_check4_dateArrayFormat($bind_value, $arrayed);
        return $type;
    }

    /**
     * Check if the string value is a valid date, and - if this is an arrayed parameter -- return it as a SQLT_ODT
     *
     * @param mixed $bind_value the string value to check for datefulness
     * @param boolean   $arrayed    whether we're recursing or not; important for arrayed data types
     * @return Integer the SQLT_* constant
     */
    protected function _check4_dateArrayFormat($bind_value, $arrayed)
    {
        if($arrayed){
            try {
                new DateTime($bind_value);
                return SQLT_ODT;
            } catch (Exception $e) {
                return SQLT_CHR ;
            }
        }
        return SQLT_CHR;
    }
}