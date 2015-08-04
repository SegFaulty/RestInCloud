<?php

class Ric_Server_RegexValidation_Validator{
    protected $lastErrorMessage = '';

    /**
     * @return string
     */
    public function getLastErrorMessage()
    {
        return $this->lastErrorMessage;
    }


    /**
     * validates regex, returns true if valid and false if not
     *  when a validation error occurs lastErrorMessage gets set
     * @param string $regEx
     * @return boolean
     */
    public function validateRegex($regEx){
        $isValid = true;
        // check is valid regex
        $this->lastErrorMessage = '';
        set_error_handler(function($errno, $errstr, $errfile, $errline, $errcontext) use(&$errorMessage){
            $this->lastErrorMessage = $errstr;
        });
        preg_match($regEx,'');
        restore_error_handler();
        if($this->lastErrorMessage){
            $isValid = false;
        }
        return $isValid;
    }
}
