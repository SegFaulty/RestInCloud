<?php

class Ric_Server_Helper_RegexValidator {

	/**
	 * validates regex, returns true if valid and false if not
	 *  when a validation error occurs lastErrorMessage gets set
	 * @param string $regEx
	 * @param string $errorMessage get error message (reference)
	 * @return bool
	 */
	static public function isValid($regEx, &$errorMessage = ''){
		$isValid = true;
		// check is valid regex
		set_error_handler(function ($errno, $errstr, $errfile, $errline, $errcontext) use (&$errorMessage){
			$errorMessage = $errstr;
		});
		preg_match($regEx, '');
		restore_error_handler();
		if( $errorMessage ){
			$isValid = false;
		}
		return $isValid;
	}
}
