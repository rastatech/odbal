<?php

namespace rastatech\odbal;

use \Exception;

/**
 * Abstraction of the Oracle variable binding process and related functionality for better maintainability/readability
 *
 * Refactored 2019.10.30 to handle arrayed parameters for Oracle Table types or VARRAYs
 *
 * @package    \ODBAL
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
     * @var Container    the Dependency Injection Container
     */
    protected $ci;

    /**
     *
     * @var array public variable so we can access bound variables; will be a numerically indexed array in order of the array of bound variables
     */
    public $bound_vars = array();

    /**
     *
     * @var mixed-array array of variables to bind and the values to which to bind them
     */
    public $vars2bind = array();

    /**
     * @var string-array    the array of regexes to use to parse for arrayed paramter bind types; trickier than it sounds....
     *      comes from the .ini file for improvement....
     */
    protected $_type_regexes = [];

    /**
     * Configuration loading Trait
     */
    use configurator;

    /**
     * some factored-out-for-length payload handling code in the form of a Trait
     */
    use payload;

    /**
     * some factored-out-for-length type parsing code in the form of a Traitrecur
     */
    use bindtype;

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
     * Either sets the variables & values to bind if an array of such is passed, OR binds the variables to the oracle resources via __bindVars2SQL() if not.
     *
     * <b>dynamically creates public class attributes corresponding to the keys in the 'bind_vars' array, via which the caller can access the bound variables directly</b>
     *
     *
     * @param string $sqlType the type of SQL being processed, e.g. stored procedure (which is all that is currently supported, but leaving it open....)
     * @param bool   $bind_var_array
     * @param bool   $stmt    the parsed OCI statement resource
     * @return array|void boolean-array    the results of the binding operation(s)
     * @throws Exception it doesn't, tho.....
     * @uses           __bindVars2SQL()
     * @link           https://www.php.net/manual/en/function.oci-bind-by-name.php valid OCI datatypes for atomic values
     * @link           https://www.php.net/manual/en/function.oci-bind-array-by-name.php valid OCI datatypes for arrayed values
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
     * @param         $stmt
     * @param integer $sqlType the type of SQL statement being bound
     * @return array|void integer-array the array of boolean return values for the binding operation(s);
     * @throws Exception if no variables were available for binding
     */
    protected function _bindVars2SQL($stmt, $sqlType)
    {
        if (!$stmt) {
            throw new Exception('no statement found to bind on!', 517);
        }
        if ($this->vars2bind) {
            $b = ($sqlType < 2) ? $this->_bind_pkg($stmt) : $this->bind_passThruSQL($stmt); //not currently supported but leaving it open....
            $this->bound_vars = $b;
            return $b;
        }
    }

    /**
     * Iterates through the variable array and binds them to the statement
     * This function also will assign each key/ value pair to a dynamically-created public class attribute,
     * so you can reference those variables from outside the class - like say if you have a function that returns a string or integer,
     * or other non-cursor OUT parameters from a procedure.
     *
     * This function will also account for arrayed parameter values, i.e. if the passed parameter value is an array of raw values.
     * This enables support of array-type Oracle parameters, such as a parameter of type table or VARRAY.
     *
     * @param statement $stmt the Oracle parsed statement resource handle
     * @return array boolean-array the array of binding results
     * @throws Exception failure to bind variables will throw an exception
     */
    protected function _bind_pkg($stmt)
    {
        if (! $stmt){
            throw new Exception('no valid OCI statement received in _bind_pkg', 520);
        }
        $b = array();
        foreach ($this->vars2bind as $bind_varname => $bind_value) {
            $this->bound_vars[$bind_varname] = $bind_value;
//            //dynamically add a public class attribute by which to access the bound var:
            $this->$bind_varname = ((is_array($bind_value)) AND (array_key_exists('value', $bind_value))) ? $bind_value['value'] : $bind_value;
            $b[$bind_varname] = $this->_bind_pkg_parameter($stmt, $bind_varname, $bind_value);
        }
        return $b;
    }

    /**
     * Placeholder function for some functionality that was not commonly used and was too much trouble to port over if we weren't going to need it.
     *
     * @param statement $stmt the Oracle parsed statement resource handle
     * @return void
     * @throws Exception this method is not yet implemented
     */
    protected function bind_passThruSQL($stmt)
    {
        $errMsg = 'pass-thru SQL not currently supported in this version of Oracle DBAL; Please make a PL/SQL stored procedure instead, or fork Rastatech/ODBAL on GitHub.';
        if (! $stmt){
            throw new Exception($errMsg, 520);
        }
        $b = FALSE; //replace this with actual binding for the pass-through sql
        if($b === FALSE){
            throw new Exception($errMsg, 520);
        }
    }

    /**
     * merges any existing array of variables to bind with a new array of 'em
     *
     * typically accessed publicly; no internal usage
     *
     * @param  mixed-array    $newVars2bind  the array of variables to bind in the format of name => value
     * @return array mixed-array the merged array
     */
    public function merge_vars($newVars2bind)
    {
        $mergedVars = (is_array($newVars2bind)) ? array_merge($this->vars2bind, $newVars2bind) : $this->vars2bind;
        return $mergedVars;
    }

    /**
     * Handles variable binding, optionally using length, type parameters, and handling arrayed variables with oci_bind_array_by_name
     *
     * @param statement $stmt       the Oracle statement object
     * @param string                    $bind_varname   the variable name to bind
     * @param array                     $bind_value the value of the variable to bind, in this case a compound array of length, type, value (if any)
     * @return array the bound variable
     * @throws Exception
     */
    protected function _bind_pkg_parameter($stmt, $bind_varname, $bind_value)
    {
        $placeholder = [];
        $placeholder[$bind_varname] = ":$bind_varname";
        $is_outvar = $this->_is_outVar($bind_varname);
        if($is_outvar){//test for out/return var-ness
            $binding_info = $this->_parse_4compoundAttribs($bind_value);
            $this->$bind_varname = $binding_info['value'];
            //only need set length & type for OUT params:
            $boundVar = oci_bind_by_name($stmt, $placeholder[$bind_varname], $this->$bind_varname, $binding_info['length'], $binding_info['type']);
        }
        elseif (is_array($bind_value)){//test for arrayed value-ness;
            $binding_info = $this->_parse4arrayINparam($bind_value);
            if((array_key_exists('value', $binding_info)) AND ($binding_info['value'] != $this->$bind_varname)){
                $this->$bind_varname = $binding_info['value']; //provide a means to fix positive exponents in floats that Slim turns into spaces from the parameter
            }
            $boundVar = oci_bind_array_by_name($stmt, $placeholder[$bind_varname], $this->$bind_varname, $binding_info["length"]['max_table_length'], $binding_info["length"]['max_item_length'], $binding_info['type']);
        }
        else{ //otherwise it's a normal IN param; act accordingly:
            $this->$bind_varname = $bind_value;
            $boundVar = oci_bind_by_name($stmt, $placeholder[$bind_varname], $this->$bind_varname); //let oracle decide length and type for IN parameters
        }
        if ($o_err = oci_error($stmt)) {
            $this->_throwBindingError($o_err, $bind_varname, 'Oracle bind by name failed!', 523);
        }
        return $boundVar;
    }

    /**
     *  Abstraction of Oracle error capture process & associated Exception throwing
     *
     * @param $o_err    Oracle_Error_Object
     * @param $bind_varname string  the name of the failed variable
     * @param $message_preamble string  the message for the Exception
     * @param $code integer the Exception code
     * @throws Exception
     */
    protected function _throwBindingError($o_err, $bind_varname, $message_preamble, $code)
    {
        $err = $o_err['message'] . ';<br/>' . $bind_varname . '<br/>' . 'SQL was: ' . $o_err['sqltext'] . '<br/>';
        $message = preg_replace('~[[:cntrl:]]~', '', $err);
        $this->ci['errorMessage'] = $message;
        $this->ci['errorCode'] = htmlentities($o_err['code']);
        throw new Exception($message_preamble, $code);
    }
}