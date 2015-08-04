<?php

class Ric_Server_Helper_RegexPatternValidation{

    static public  $errno = 0;
    static public  $errstr = '';
    static public  $errfile = '';
    static public  $errline = '';
    static public  $errcontext = array();

    /**
     * @param int $errno
     * @param string $errstr
     * @param string $errfile
     * @param int $errline
     * @param array $errcontext
     * @return bool
     */
    static public function handleError($errno, $errstr, $errfile, $errline, $errcontext){
        self::$errno = $errno;
        self::$errstr = $errstr;
        self::$errfile = $errfile;
        self::$errline = $errline;
        self::$errcontext = $errcontext;
        return true;
    }

    /**
     * reset last error
     */
    static public function reset(){
        self::$errno = 0;
        self::$errstr = '';
        self::$errfile = '';
        self::$errline = 0;
        self::$errcontext = array();
    }

    /**
     * return same regEx if valid
     * or throws Exception if not valid
     * @param string $regEx
     * @return string
     * @throws RuntimeException
     */
    static public function validateRegex($regEx){
        // check is valid regex
        set_error_handler('Ric_Server_ErrorHandler_Static::handleError');
        Ric_Server_ErrorHandler_Static::reset();
        preg_match($regEx,'');
        $error = Ric_Server_ErrorHandler_Static::$errstr;
        restore_error_handler();
        if( $error ){
            throw new RuntimeException('not a valid regex: '.$error, 400);
        }
        return $regEx;
    }
}

class Ric_Server_ErrorHandler_Static {

    static public  $errno = 0;
    static public  $errstr = '';
    static public  $errfile = '';
    static public  $errline = '';
    static public  $errcontext = array();

    /**
     * @param int $errno
     * @param string $errstr
     * @param string $errfile
     * @param int $errline
     * @param array $errcontext
     * @return bool
     */
    static public function handleError($errno, $errstr, $errfile, $errline, $errcontext){
        self::$errno = $errno;
        self::$errstr = $errstr;
        self::$errfile = $errfile;
        self::$errline = $errline;
        self::$errcontext = $errcontext;
        return true;
    }

    /**
     * reset last error
     */
    static public function reset(){
        self::$errno = 0;
        self::$errstr = '';
        self::$errfile = '';
        self::$errline = 0;
        self::$errcontext = array();
    }
}