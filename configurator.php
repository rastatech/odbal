<?php
namespace \rastatech\odbal;
/*
 * @package cwsrf
 * @subpackage dbal
 * @author todd.hochman
 */
/**
 * Trait to abstract some configuration-loading code that we needed >1 place
 *
 * @package URL_ShortR
 * @subpackage dbal
 * @author todd.hochman
 * @todo this whole dbal might need to be moved in namespace to accomodate MySQL dbal if/when we make one of those
 */
trait configurator
{
    /**
    * merges the various sources of configuration information
    * 
    * @param string-array $model_sql_elements  the array of _sql_elements from the model
    * 
    * @return string-array the merged configuration array
    */
    protected function _get_configs($model_sql_elements)
    {
        $relevantConfigSet = substr(__CLASS__, 5);//use the host class name to get the config set to use
        if(array_key_exists($relevantConfigSet, $this->ci['dbconfigs'])){
            $relevantConfigs = $this->ci['dbconfigs'][$relevantConfigSet];  
            $configs2assign = $this->_mergeConfigs($relevantConfigs, $model_sql_elements);
        }
        return (isset($configs2assign)) ? $configs2assign : [];
    }
    
    /**
    * iterates through the relevant configs and assigns them to class attributes as appropriate
    * 
    * @param string-array $configs2assign  the relevant subset from the configuration array 
    */
    protected function _assign2classVars($configs2assign)
    {
        $prefixArray = array('', '_', '__');
        foreach($configs2assign as $config_key => $config_value){
            foreach($prefixArray as $prefix){
                $key = $prefix . $config_key;
                if((property_exists(__CLASS__, $key)) AND ($config_value)){
                    $this->$key = $config_value;
                    continue 2;
                }
            }
        }
    }
        
    /**
    * handles the merging of config values so nothing from upstream is lost 
    * but we get the model-specific values assigned to $_sql_elements
    * 
    * @param string    $relevantConfigs   the loaded configs from the container
    * @param mixed     $model_sql_elements   the specific configs from the model
    * @return NULL
    */
    protected function _mergeConfigs($relevantConfigs, $model_sql_elements)
    {
        $combinedConfigs = [];
        foreach($relevantConfigs as $key => $value){
            $combinedConfigs[$key] = $value;
            if(array_key_exists($key, $model_sql_elements)){
                $combinedConfigs[$key] = $model_sql_elements[$key];
            }
        }
        return $combinedConfigs;
    }    
}