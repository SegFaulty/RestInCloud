<?php

require_once __DIR__.'/../../../../bootstrap.php';


class Test_Ric_Server_Helper_RegexValidatorTest extends \PHPUnit\Framework\TestCase {

	public function test_isValid(){
		self::assertTrue(Ric_Server_Helper_RegexValidator::isValid('~.~'));
		self::assertFalse(Ric_Server_Helper_RegexValidator::isValid('moin'));
	}

}