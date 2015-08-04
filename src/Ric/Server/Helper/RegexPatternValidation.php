<?php

class Ric_Server_Helper_RegexPatternValidation{
    /**
     * return same regEx if valid
     * or throws Exception if not valid
     * @param string $regEx
     * @return string
     * @throws RuntimeException
     */
    static public function validateRegex($regEx){
        // check is valid regex
        //$errorString = '';
        set_error_handler(function($errno, $errstr, $errfile, $errline, $errcontext) use(&$errorString){
            $errorString = $errstr;
        });
        preg_match($regEx,'');
        restore_error_handler();
        if($errorString){
            throw new RuntimeException('not a valid regex: '.$errorString, 400);
        }
        return $regEx;
    }
}
