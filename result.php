<?php
namespace rastatech\odbal;
use Exception;

/**
* Abstraction of the OCI functions dealing with fetching the result array.
* Also provides a public row_count variable for determining the size of the result set
* 
*
 * @package URL_ShortR
 * @subpackage dbal
 * @author todd.hochman
* @uses dbal_configurator a trait containing some configuration setting code that is used across several of the above classes
* @todo this whole dbal might need to be moved in namespace to accomodate MySQL dbal if/when we make one of those
*/
class result
{
    /**
    *
    * @var Container_object    the Dependency Injection Container  
    */
    protected $ci;
    
    /**
    *
    *
    * @var array of parameters to modify the oci_fetch_all command in our fetch_all method;
    * uses keys that correspond to the parameter names
    * -skip: rows to skip
    * -maxrows: maximum number of rows to return
    * -flags: OCI constants appropriate to this function call
    * @link http://us3.php.net/manual/en/function.oci-fetch-all.php oci_fetch_all
    * @see dbal/db_config.php
    */
    protected $_fetch_all_params;
    
    /**
    *
    * @var integer publically-accessible row count for the result set
    */
    public $row_count = 0;

    /**
    * some factored-out-for-reuse configuration setting code in the form of a Trait
    */
    use configurator;
    
    /**
    * Allows you to set a public attribute for an atomic (i.e. non-cursor) result
    * 
    * @param Slim/Container $ci The slim Dependency Injection Container
     * @param string    the name of the atomic_result we expect back instead of a cursor, if so
    * @param string $model_sql_elements the array of DB configurations; must contain the same array keys as found in the db_creds file
    * 
    * @see dbconfigs.php
    */
    public function __construct($ci, $atomic_result = FALSE, $model_sql_elements = [])
    {
        $this->ci = $ci;
//        if($atomic_result){
//            $this->$atomic_result = $this->ci->$atomic_result;
//        }        
        $loadedConfigs = $this->_get_configs($model_sql_elements);
        $this->_assign2classVars($loadedConfigs);     
    }    
    
    /**
    * Handles the oci_fetch_all, accounting for multiple cursors
    * 
    * Should handle the parsed statement too, for sql without a return CURSOR or OUT CURSOR
    * 
    * @param Oracle statement|Oracle Cursor|multiple Oracle Cursors    $resource2fetch the statement, cursor, or array of cursors upon which to fetch_all
    * 
    * @return mixed-array the result of the fetch
    */
    public function get_result($resource2fetch)
    {
        if(is_string($resource2fetch)){
            return $this->ci->$resource2fetch;
        }
        if(is_array($resource2fetch)){
            $result = [];
            foreach($resource2fetch as $cursorKey => $cursorObj){
                $success[$cursorKey] = oci_fetch_all($cursorObj, $result[$cursorKey], $this->_fetch_all_params['skip'], $this->_fetch_all_params['maxrows'], $this->_fetch_all_params['flags']);
            }
            $this->row_count = count($success);
        }
        else{
            $success = oci_fetch_all($resource2fetch, $result, $this->_fetch_all_params['skip'], $this->_fetch_all_params['maxrows'], $this->_fetch_all_params['flags']);
            $this->row_count = $success;
        }
        if(($success === FALSE) OR ((is_array($success)) AND (in_array(FALSE, $success, TRUE)))){//strict comparison to distinguish 0 rows returned from FALSE (failure)
            $failedKey = (is_array($success)) ? array_search(FALSE, $success, TRUE) : FALSE;
            $erroredResource = ( ! $failedKey) ? $resource2fetch : $resource2fetch[$failedKey];
            $exception = oci_error($erroredResource);
            $message = preg_replace('~[[:cntrl:]]~', '', htmlentities($exception['message']));
            $this->ci['errorMessage'] = $message;
            $this->ci['errorCode'] = htmlentities($exception['code']);
            throw new Exception('oci fetch all failed!', 527);
        }
        return $result;
    }
}