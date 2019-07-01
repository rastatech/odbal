<?php
namespace models;
/*
 * @package UDS
 * @subpackage dbal
 * @author todd.hochman
 * 
 * Parent class giving access to input cleansing as well as future cross-DB-type model needs
 *
 * @author todd.hochman
 */
class modelbase extends \rastatech\odbal\main implements model_interface
{
    /**
     * @var string the element array required by DBAL main, constructed on-the-fly from URL elements
     */
    protected $_sql_elements;
            
    /**
     *
     * @var HTMLPurifier    HTMLPurifier object for data cleansing 
     */
    protected $_html_purifier;  
        
    /**
     * The Dependency Injection container from your framework or whatever
     * 
     * @param Container $ci the DI container
     */
    public function __construct($ci)
    {
        $this->_special_param_regEx['replace_string'] = $ci->configs_array['replace_string'];
        $this->_special_param_regEx['regex'] = $ci->configs_array['regex'];
        $this->_special_query_parameters['cursor_params'] = $ci->configs_array['cursor_params'];
        $this->_special_query_parameters['out_params'] = $ci->configs_array['out_params'];
        $this->_special_query_parameters['function_return_params'] = $ci->configs_array['function_return_params'];
        parent::__construct($ci);
    }
    /**
     * Override of parent function allows us to do stuff in between if we need to
     */
    public function run_sql($bindVars = FALSE, $outvar = NULL)
    {
        //invoke parent for actual package execution:
        return parent::run_sql($bindVars, $outvar);
    }
    
    /**
     * input cleansing for parameters
     * 
     * @param array $input_parameter_array
     * @param array $model
     * @return mixed-array  the SQL element Bind Vars
     */
    protected function _validatePayload(array $input_parameter_array, array $model = [])
    {
        $config = \HTMLPurifier_Config::createDefault();
        $this->_html_purifier = new \HTMLPurifier($config);
        foreach($input_parameter_array as $key => $value){
            $purifiedvalue = $this->_html_purifier->purify($value);
            $purified_payload[$key] = $purifiedvalue;
        }      
       return parent::_validatePayload($purified_payload, $this->_sql_elements['bind_vars']);
    }  
    
    /**
     * Creates the string used to execute a package; just a placeholder since this needs to happen at the specific DB type model (e.g. Oracle)
     * and since we need this to fulfill the obligations of the model_interface
     * 
     * @param Request $request
     * @param Response $response
     * @uses _parse_args_4execution
     * @uses _create_bindVarsString_4execution
     * @return mixed-array $_sql_elements array the SQL elements needed by the ODBAL for execution
     */    
    public function create_packageCall($request, $DIcontainer) //I suggest $DIcontainer = NULL in implementation to give you flexibility & save RAM
    {
       
    }                
}