<?php
class Test_Ric_Server_TestConfig extends Ric_Server_Config{
    protected $runtimeConfig = [];

    public function set($key, $value) {
        $this->config[$key] = $value;
    }

    /**
     * set, update, remove (null) a value in runtimeConfig (and config)
     * @param string $key
     * @param string $value
     */
    public function setRuntimeValue($key, $value) {
        $this->runtimeConfig[$key] = $value;
        $this->config[$key] = $value;
    }

    /**
     * @return array array(string => string)
     */
    public function getRuntimeConfig()
    {
        return $this->runtimeConfig;
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function getFromRuntimeConfig($key){
        $result = null;
        if(isset($this->runtimeConfig[$key])){
            $result = $this->runtimeConfig[$key];
        }
        return $result;
    }

}