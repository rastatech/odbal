<?php


namespace rastatech\odbal;
use \ArrayObject;
use Cassandra\Exception\TruncateException;
use Exception;
use Iterator;

/**
 * Trait bindtype; abstraction of type-related functionality for OCI8 binding
 *
 * capable of parsing given types, in the form of compound values (length, type, value), as well as determining type based on provided values
 * handles both scalar values and arrayed values
 *
 * @package rastatech\odbal
 */
trait bindtype
{
    /**
     * @var array array of allowed PHP-OCI8 bind types for arrays and scalars
     *            SQLT_NUM - for arrays of NUMBER.
     *   SQLT_INT - for arrays of INTEGER (Note: INTEGER it is actually a synonym for NUMBER(38), but SQLT_NUM type won't work in this case even though they are synonyms).
     *              Sure why not?
     *   SQLT_FLT - for arrays of FLOAT. Yes, I think
     *   SQLT_AFC - for arrays of CHAR. Nah.
     *   SQLT_CHR - for arrays of VARCHAR2. Yes
     *   SQLT_VCS - for arrays of VARCHAR. Nah.
     *   SQLT_AVC - for arrays of CHARZ. Nah.
     *   SQLT_STR - for arrays of STRING. Nah.
     *   SQLT_LVC - for arrays of LONG VARCHAR. Nah.
     *   SQLT_ODT - for arrays of DATE.  Yes.
     *
     *   SQLT_NUM  Converts the PHP parameter to a 'C' long type, and binds to that value. Untested.
     *   SQLT_CHR and any other type    Converts the PHP parameter to a string type and binds as a string.
     *      This is the default for OUT vars since other types do not seem to bind correctly.
     */
    protected $_allowed_bindTypes = [   'in_arrays' => ['SQLT_INT', 'SQLT_FLT', 'SQLT_CHR', 'SQLT_ODT'],
                                        'outvars'   => ['SQLT_CHR', 'SQLT_NUM'] //need to double-check if we ever need SQLT_NUM, SQLT_CHR seems to work for everything .....
                                    ];

    /**
    /**
     * @var string-array    the array of keys used in defining attributes for OUT parameter binding
     */
    protected $_compound_value_keys = array('length' => NULL, 'type' => NULL, 'value' => NULL);


    /**
     * gets the needed binding attributes for an arrayed value
     *
     * ########################################## OBSOLETE ####################################################################################
     * @param $bind_valueArray the bound array variable, which should obviously be an array
     * @return mixed
     * @throws Exception
     */
    protected function _OBS_parse4arrayINparam($bind_valueArray)
    {
        if(array_key_exists('value', $bind_valueArray)){
            $parsedAttributes['type'] = $this->_getSQLTtype($bind_valueArray['type'],FALSE);
            $parsedAttributes['length']['max_table_length'] = ( count($bind_valueArray['value'])) ? count($bind_valueArray['value']) : 1;
            $parsedAttributes['length']['max_item_length'] = -1;
            $parsedAttributes['value'] = $this->_homogenize_array_value($bind_valueArray['value']);
//            echo "final parsing of attribute under _parse4arrayINparam compound branch :" . var_export( $parsedAttributes['value'], TRUE) . "<br/>\n";
            return $parsedAttributes;
        }
        $length_info['max_table_length'] = ( count($bind_valueArray)) ? count($bind_valueArray) : 1;
        $length_info['max_item_length'] = ( count($bind_valueArray)) ? -1 : 0; //let oci figure out the longest individual item itself unless it's an empty array
        $parsedAttributes['length'] = $length_info;
        if( count($bind_valueArray)){
            $parsedAttributes = $this->_parse_bindVar_4type($bind_valueArray, $parsedAttributes);
        }
        if( ! array_key_exists('type', $parsedAttributes)){
            $parsedAttributes['type'] =  constant('SQLT_CHR');
        }
//        $parsedAttributes['value'] = (is_array($bind_valueArray)) ? (count($bind_valueArray)) ? $bind_valueArray : [0 => NULL] : $bind_valueArray;
        $parsedAttributes['value'] = $this->_homogenize_array_value($bind_valueArray);
//        echo "final parsing of attribute under _parse4arrayINparam raw branch :" . var_export( $parsedAttributes['value'], TRUE) . "<br/>\n";

        return  $parsedAttributes;
    }
########################################## OBSOLETE ####################################################################################
########################################## OBSOLETE ####################################################################################

    /**
     * parses the value of the identified OUT or function return param for the needed binding attributes:
     * - length
     * - type
     * - value (always NULL for OUT params)
     * ########################################## OBSOLETE ####################################################################################
     * @param      $bind_value
     * @param bool $outvar whether the variable to be processed is an OUT var; makes a difference for binding....
     *
     * @return array the binding attributes needed
     * @throws Exception
     */
    protected function _parse_4compoundAttribs($bind_value, $outvar = TRUE)
    {
        $parsedAttributes = $this->_compound_value_keys;
        $parsedKeys = array_keys($parsedAttributes);
        foreach ($parsedKeys as $key){
            $isCompoundValue = $this->_OBS_parseCompoundValues($bind_value,$key);
            if($isCompoundValue){
                $parsedAttributes[$key] = $isCompoundValue;
                continue;
            }
            if(($parsedAttributes[$key] === FALSE) AND ($outvar)){
                throw new Exception('Parameter name indicates an OUT var or function return. You must provide [length, type, value] array.');
            }
        }
        //if any of the compound values are present (i.e. length, type, value), it's a valid compound array; otherwise no.
        return (array_filter(array_values($parsedAttributes))) ? $parsedAttributes : FALSE;
    }
########################################## OBSOLETE ####################################################################################
########################################## OBSOLETE ####################################################################################

    /**
     * parses arrayed values for compound values;
     *
     * A compound value is in this format: `$key2bind = ['length' = 250, 'type' => 'chr', 'value' => '']; type must correspond to one of the
     * valid OCI datatypes ($this->_allowed_bindTypes) , or an abbreviation of same where you just leave off the "SQLT_" part and that'll get filled in for you. OR the correct Numeric
     * equivalent of the SQLT_* constant will work also.
     * ########################################## OBSOLETE ####################################################################################
     *
     * @param array  $bind_value the compound value to process
     * @param string $key        the bind_var being processed
     * @param bool   $outvar     whether the variable to be processed is an OUT var; makes a difference for binding....
     * @return bool|int|mixed mixed-array|bool the processed binding attributes for the value if the compound value was successfully processed, FALSE if otherwise
     * @throws Exception
     */
    protected function _OBS_parseCompoundValues($bind_value, $key, $outvar = TRUE)
    {
        if ((is_array($bind_value)) AND  (array_key_exists($key,$bind_value))){//compound values should tell us what to set for each parameter
            switch ($key){
                case "type":
                    $parsedKey = $this->_getSQLTtype($bind_value[$key], $outvar);
                    break;
                case "length":
                    //factor this out, using some bits from _parse4arrayINparam
                    $length = (is_int($bind_value[$key])) ? $bind_value[$key] : (int)$bind_value[$key];

                    $parsedKey = $length;
                    break;
                case "value":
                    if($outvar){
//                        $parsedKey = $bind_value[$key];
                        $parsedKey = $this->_OBS_parse4arrayINparam($bind_value[$key]);
                        break;
                    }
//                    echo "parsing compound value :" . var_export( $bind_value[$key], TRUE) . "<br/>\n";
//                    $this->_parse4arrayINparam($bind_value[$key]);
                    break;
                default:
                    $parsedKey = FALSE;
            }
            return isset($parsedKey) ? ($parsedKey) : FALSE;
        }
        return FALSE;
    }
########################################## OBSOLETE ####################################################################################
########################################## OBSOLETE ####################################################################################


    /**
     * Returns the <b>provided</b> SQLT_ type to use in binding
     *
     * Accepts any of:
     * - a valid integer PHP SQLT_ constant;
     * - SHOULD support any valid <b>SQLT_</b> string specified in $_allowed_bindTypes; single dates should be passed as CHR, date arrays as SQLT_ODT
     * - any valid SQLT_ <b>suffix</b>, e.g. CHR, INT, NUM etc
     *
     * @link https://www.php.net/manual/en/function.oci-bind-by-name.php oci_bind_by_name
     * @link https://www.php.net/manual/en/function.oci-bind-array-by-name  oci_bind_array_by_name
     * @link https://www.php.net/manual/en/oci8.constants.php OCI 8 constants
     * @param  string $bind_type    the provided bind type
     * @param   bool   $outvar  whether the variable is an OUT var or not, used in the determination of type
     * @return int  the SQLT_ constant
     * @throws Exception
     */
    protected function _getSQLTtype($bind_type, $outvar)
    {
//        $is_outvar = ($outvar) ? 'outvar' : 'not outvar';
//         echo "processing bind type of $bind_type as $is_outvar: <br/>\n";
        if($outvar){
            $parsedKey = constant('SQLT_CHR'); //it does not freaking work otherwise, no matter what the underlying datatype, trust me.....
            return $parsedKey;
        }
        if( ! is_numeric($bind_type)){
            $uc_type = strtoupper($bind_type);
            $currentBindValue = (strpos($uc_type, 'DATE') === 0) ? 'SQLT_ODT' : $uc_type; //ACCOUNT FOR DATES
            $type = (strpos($currentBindValue, 'SQLT_') === FALSE) ? 'SQLT_' .$currentBindValue : $currentBindValue; //account for abbreviated var types
            if(( ! in_array($type, $this->_allowed_bindTypes['outvars'])) AND ( ! in_array($type, $this->_allowed_bindTypes['in_arrays']))){
                //put OCI-Collection handling here; assumes schema.type
                if(strpos($bind_type, '.')){
                    return $type;
                }
                throw new Exception($type . ' is not a valid OCI8 bind type!');
            }
            $parsedKey = constant($type);
            return $parsedKey;
        }
        $parsedKey = $bind_type; //assumes a valid numeric constant passed for the SQLT_* type
        return $parsedKey;
    }

    /**
     * derives the bind key type from sample array values, for oracle array binding purposes
     *
     *  ###################################### OBSOLETE #############################################
     * @uses _iterate_bindTypes()
     * @param mixed-array     $bind_valueArray    assumes an arrayed bind_value
     * @param mixed-array     $parsedAttributes      the attributes array
     * @return long        the calculated or derived value type for binding
     *
     */
    protected function _parse_bindVar_4type($bind_valueArray, $parsedAttributes)
    {
        $arrObj = new ArrayObject($bind_valueArray);
        $arrIterator = $arrObj->getIterator();
        $valueMatches = [   'float'     => 0,
                            'num'       => 0,
                            'int'       => 0,
                            'date'      => 0,
                            'varchar'   => 0
                        ];
        $valueMatches = $this->_iterate_bindTypes($arrIterator, $valueMatches);
        //hierarchy of types
        if($valueMatches['varchar']){
            $parsedAttributes['type'] = SQLT_CHR;
            return $parsedAttributes;
        }
        $numArray = array($valueMatches['float'], $valueMatches['num'], $valueMatches['int']);
        $hasNumsToo = count(array_filter($numArray));
        if(( ! $hasNumsToo) AND $valueMatches['date']){
            $parsedAttributes['type'] = SQLT_ODT;
            return $parsedAttributes;
        }
        if($hasNumsToo){
            $numType = ($valueMatches['float']) ? SQLT_FLT : (($valueMatches['num']) ? SQLT_NUM : SQLT_INT);
            $parsedAttributes['type'] = $numType;
            return $parsedAttributes;
        }
        $parsedAttributes['type'] = SQLT_CHR; //could be both dates and numbers, but nothing outside of that, in which case VarChar2...
        return $parsedAttributes;
    }
    ###################################### OBSOLETE #############################################
    ###################################### OBSOLETE #############################################

    /**
     * derives the bind key type from sample array values, for oracle array binding purposes
     *
     * @uses _iterate_bindTypes()
     * @param mixed-array     $bind_valueArray    assumes an arrayed bind_value
     * @param mixed-array     $parsedAttributes      the attributes array
     * @return long        the calculated or derived value type for binding
     *
     */
    protected function _derive_bindVar_type($bind_valueArray)
    {
        $arrObj = new ArrayObject($bind_valueArray);
        $arrIterator = $arrObj->getIterator();
        $valueMatches = [   'float'     => 0,
                            'num'       => 0,
                            'int'       => 0,
                            'date'      => 0,
                            'varchar'   => 0
                        ];
        $valueMatches = $this->_iterate_bindTypes($arrIterator, $valueMatches);
        //hierarchy of types
        if($valueMatches['varchar']){
            return SQLT_CHR;
        }
        $numArray = array($valueMatches['float'], $valueMatches['num'], $valueMatches['int']);
        $hasNumsToo = count(array_filter($numArray));
        if(( ! $hasNumsToo) AND $valueMatches['date']){
            return SQLT_ODT;
        }
        if($hasNumsToo){
            $numType = ($valueMatches['float']) ? SQLT_FLT : (($valueMatches['num']) ? SQLT_NUM : SQLT_INT);
            return $numType;
        }
        //could be both dates and numbers, but nothing outside of that, in which case VarChar2...
        return SQLT_CHR;
    }

    /**
     * iterates through the possible bind types and attempts to match the bind value type
     *
     * @param Iterator $arrIterator the iterator object
     * @param  array $valueMatches  the array of possible type matches for the value
     * @return  array  the array of successful type matches for the value
     */
    protected function _iterate_bindTypes($arrIterator, $valueMatches)
    {
        while($arrIterator->valid()) {
            $current = trim($arrIterator->current(),'"\''); //trim any quotes
            $matches = 0;
            foreach ($this->_type_regexes as $regexkey => $regexPattern){
                $matching =  preg_match($regexPattern, $current, $matches);
                if($matching){
                    switch ($regexkey){
                        case 'float':
                            $valueMatches['float']++;
                            if(strpos($current, ' ')){
                                $parsedAttributes['value'] = str_replace(' ', '+', $current);
                            }
                            break;
                        case 'num':
                            $valueMatches['num']++;
                            break;
                        case 'int':
                            $valueMatches['int']++;
                            break;
                        case 'date':
                            $valueMatches['date']++;
                            break;
                    }
                    $matches++;
                    break;
                }
            }
            if( ! $matches){
                $valueMatches['varchar']++;
            }
            $arrIterator->next();
        }
        return $valueMatches;
    }

    /**
     *  Tests the bind variable name vs the configs to see if it contains an OUT variable or function return variable suffix
     *
     * @param $bind_var
     * @return bool
     */
    protected function _is_outVar($bind_var)
    {
        $outVar_suffixes = $this->ci->configs['out_params'];
        $funcRet_suffixes = $this->ci->configs['function_return_params'];
        $check_suffixes = array_merge($outVar_suffixes, $funcRet_suffixes);
        foreach ($check_suffixes as $out_param_suffix) {
            if(strpos($bind_var, $out_param_suffix) !== FALSE){
                return TRUE;
            }
        }
        return FALSE;
    }

    /**
     * processes an OUT parameter;
     * same as a compound IN parameter, with the addition of throwing an exception if the compound value structure is not found
     *
     * @param $bind_value
     * @return mixed
     * @throws Exception
     */
    protected function _process_outvar($bind_value)
    {
        if( ! $this->_is_compoundVar($bind_value)){
            throw new Exception('OUT vars must be a compound (length, type, value) array.');
        }
        return $this->_process_compound_array($bind_value);
    }

    /**
     * branches between two different methods of obtaining the bind type
     *
     * @param  array $bind_info the array of bind information, length, type, value
     * @param  bool $is_outvar whether the variable is an OUT var or not - needed for exception handling
     * @return int|long the SQLT_ constant
     * @throws Exception
     */
    protected function _determine_SQLT_type($bind_info, $is_outvar)
    {
        if((array_key_exists('type', $bind_info)) AND ($bind_info['type'])){
//            return $this->_getSQLTtype($bind_info['type'], $is_outvar);
            $type =  $this->_getSQLTtype($bind_info['type'], $is_outvar);
            echo "testing given type:" . var_export( $type, TRUE) . "<br/>\n";
            return $type;
        }
//        return $this->_derive_bindVar_type($bind_info['value']);
        $type =   $this->_derive_bindVar_type($bind_info['value']);
        echo "testing derived type:" . var_export( $type, TRUE) . "<br/>\n";
        return $type;
    }

    /**
     * parses type for a 'schema.type' construction indicating a custom type & therefore the need for an OCI8 Collection object
     *
     * @param array $binding_info the array of bind info; length, type, value
     * @return array|bool either schema & type if a custom type provided, or FALSE
     */
    protected function _check_4_customType($binding_info)
    {
        //parse $type for schema
        if(strpos($binding_info['type'], '.')){
            $schema_and_type = explode('.', $binding_info['type']);
            return $schema_and_type;
        }
        return FALSE;
    }

}