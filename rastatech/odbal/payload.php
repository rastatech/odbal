<?php


namespace rastatech\odbal;

/**
 *
 * Abstraction of the payload modifying operations
 *
 * these were taking up too much room in the main class
 *
 *
 * @package \ODBAL
 * @subpackage dbal
 * @author todd.hochman
 *
 * @todo this whole dbal might need to be moved in namespace to accomodate MySQL dbal if/when we make one of those
 */
class payload
{
    /**
     *
     * @var string  the string used for the DBAL to identify PL/SQL OUT variables; this string must be a suffix to the variable name
     */
    protected $_outvarIDstring = '';

    /**
     * potentially allows you to override or add additional configuration / functionality upon construction if you extend this class with your model
     *
     * @param Slim/Container $ci The slim Dependency Injection Container
     * @param integer the connection flavor -- see /dbal/connection
     *
     * @see dbconfigs.php
     */
    public function __construct($ci)
    {
        $this->ci = $ci; //establish the container object; currently used for the DB configs only, but anything we need from the routes or anyplace we can get from here
    }

    /**
     * Function to validate payloads for POST, PUT operations vs. what is described in the models that are children of this class.
     *
     * @param mixed-array $payload    the array of key/value pairs that comprises the PUT or POST payload
     * @param mixed-array $model      the array of key/value pairs for the PUT or POST that are defined in the model
     * @return boolean  TRUE if the two arrays properly match
     * @throws Exception    if they don't match
     * @todo debug this thoroughly as it's not being used right now and probably should be
     */
    public function validatePayload(array $payload, array $model){
        $payload_keys = array_keys($payload);
        $model_keys = array_keys($model);
        foreach($model_keys as $key){
            if( ! strpos($key, $this->_outvarIDstring)){ //don't include the return vars
                $model_keys_noOutvar[] = $key;
            }
        }
        $diffs = array_diff($model_keys_noOutvar, $payload_keys);
        if( ! empty($diffs)){
            if(count($diffs) == count($model_keys)){//if they are totally different
                $this->ci['errorMessage'] = 'payload differs from model! Cannot process data. Differences were: <br/>{{' . var_export($diffs, TRUE) . '}}<br/>';
                $this->ci['errorCode'] = 514;
                throw new Exception('payload differs from model', 514);
            }
        }
        return $this->_cleanVars($model_keys, $payload);
    }

    /**
     * cleanse the values of stuff that will make Oracle explode
     *
     * @param string-array $model_keys  the variable names of the model
     * @param mixed-array $payload  the data submitted for POST or PUT
     * @return mixed-array
     * @TODO fix the $numberRegEx portion to account for float, double, single, integer
     */
    protected function _cleanVars($model_keys, $payload){
        $cleaned_vars = [];
        $standardized_payload = $this->_standardizePayload($payload);//lowercase all keys
        die('do we have a way to ID outVars before binding? ' . var_export($this->_outvarIDstring, TRUE));
        foreach($model_keys as $key){
            if(strpos($key, $this->_outvarIDstring)){
                continue;
            }
            if($this->_testPayload($standardized_payload, $key)){
                $cleaned_vars[$key] = $standardized_payload[$key];
                continue;
            }
            if( ! array_key_exists($key, $standardized_payload)){
                $cleaned_vars[$key] = NULL;//add the key to the payload
                continue;
            }
            $numberRegEx = '~^\$?((\d+,?\d+\.\d+)|(0\.00)|(\d+))$~';
            $value = (preg_match($numberRegEx, $standardized_payload[$key])) ? preg_replace("/[^0-9.]/", "", $standardized_payload[$key]) : $standardized_payload[$key];
            $cleaned_vars[$key] = $value;
        }
        return $cleaned_vars;
    }

    /**
     * makes sure all array keys are lowercased to avoid missing key issues
     *
     * @param mixed-array $payload the data to standardize
     * @return mixed-array  the payload with lower-cased keys
     */
    protected function _standardizePayload($payload){
        $standardized_payload = [];
        foreach($payload as $key => $value){
            $standardized_payload[strtolower($key)] = $value;
        }
        return $standardized_payload;
    }

    /**
     * determines if the array element is a special case for cleaning purposes.
     *
     * @param mixed-array $payload the data
     * @param string $key   the key to check
     * @return boolean TRUE if it matches the special case
     */
    protected function _testPayload($payload, $key){
        $exempt_vars = ['zipcode', 'id', 'phone'];
        $dateRegEx = '~(\d{1,4}[\/.-](\d{1,2}|\w{3})[\/.-]\d{2,4})~';
        if(strpos($key, '_id')){
            if(array_key_exists($key, $payload)){
                return TRUE;
            }
        }
        if((in_array($key, $exempt_vars)) OR ((array_key_exists($key, $payload)) AND (is_null($payload[$key])))
            OR ((array_key_exists($key, $payload)) AND (preg_match($dateRegEx, $payload[$key])))){
            return TRUE;
        }
    }
}