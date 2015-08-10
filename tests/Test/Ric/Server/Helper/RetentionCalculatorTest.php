<?php

require_once __DIR__.'/../../../../bootstrap.php';


class Test_Ric_Server_Helper_RetentionCalculatorTest extends PHPUnit_Framework_TestCase {

	public function test_getVersionsForRetentionString_empty(){
		$allVersions = [
				'v3' => 1439010784,
				'v2' => 1438853184,
				'v1' => 1438835877,
		];
		self::assertEquals(['v3','v2','v1'], Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetentionString($allVersions, ''));
	}
	public function test_getVersionsForRetentionString_off(){
		$allVersions = [
				'v3' => 1439010784,
				'v2' => 1438853184,
				'v1' => 1438835877,
		];
		self::assertEquals(['v3'], Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetentionString($allVersions, Ric_Server_Definition::RETENTION__OFF));
	}

	public function test_getVersionsForRetention_last(){
		$allVersions = [
			'v3' => 1439010784,
			'v2' => 1438853184,
			'v1' => 1438835877,
		];
		self::assertEquals(['v3','v2'], Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetention($allVersions, Ric_Server_Definition::RETENTION_TYPE__LAST, 2));
		self::assertEquals(['v3'], Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetention($allVersions, Ric_Server_Definition::RETENTION_TYPE__LAST, 1));
	}
	public function test_getVersionsForRetention_day(){
		$allVersions = [
			'v4' => strtotime('today +2 hour'),
			'v3' => strtotime('today +1 hour'),
			'v2' => strtotime('today -1 day'),
			'v1' => strtotime('today -2 day'),
			'v0' => strtotime('today -4 day'),
		];
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetention($allVersions, Ric_Server_Definition::RETENTION_TYPE__DAYS, 2);
		self::assertEquals(['v4','v2'], $versionsForRetention);
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetention($allVersions, Ric_Server_Definition::RETENTION_TYPE__DAYS, 1);
		self::assertEquals(['v4'], $versionsForRetention);
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetention($allVersions, Ric_Server_Definition::RETENTION_TYPE__DAYS, 3);
		self::assertEquals(['v4','v2','v1'], $versionsForRetention);
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetention($allVersions, Ric_Server_Definition::RETENTION_TYPE__DAYS, 4);
		self::assertEquals(['v4','v2','v1'], $versionsForRetention);
	}

	public function test_getVersionForTimeRange(){
		$allVersions = [
			'v3' => 1439010784,
			'v2' => 1438853184,
			'v1' => 1438835877,
		];
		self::assertEquals('v3', Test_Ric_Server_Helper_RetentionCalculator::getVersionForTimeRange_public($allVersions, 1438840000, 1500000000));
	}

	public function test_getStartOfWeek(){
		self::assertEquals(1438552800, Test_Ric_Server_Helper_RetentionCalculator::getStartOfWeek(1439136385));
		self::assertEquals(1438552800, Test_Ric_Server_Helper_RetentionCalculator::getStartOfWeek(1439036385));
		self::assertEquals(1437948000, Test_Ric_Server_Helper_RetentionCalculator::getStartOfWeek(1438552799));
		self::assertEquals(1437948000, Test_Ric_Server_Helper_RetentionCalculator::getStartOfWeek(1437948001));
		self::assertEquals(1437948000, Test_Ric_Server_Helper_RetentionCalculator::getStartOfWeek(1438048001));
	}

	public function test_getVersionsForRetention_week(){
		$allVersions = [
			'v4' => strtotime('+2 hour'),
			'v3' => strtotime('+1 hour'),
			'v2' => strtotime('-1 week +3 days', Ric_Server_Helper_RetentionCalculator::getStartOfWeek()),
			'v1' => strtotime('-3 week +2 day', Ric_Server_Helper_RetentionCalculator::getStartOfWeek()),
			'v0' => strtotime('-4 week +4 day', Ric_Server_Helper_RetentionCalculator::getStartOfWeek()),
		];
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetention($allVersions, Ric_Server_Definition::RETENTION_TYPE__WEEKS, 2);
		self::assertEquals(['v4','v2'], $versionsForRetention);
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetention($allVersions, Ric_Server_Definition::RETENTION_TYPE__WEEKS, 1);
		self::assertEquals(['v4'], $versionsForRetention);
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetention($allVersions, Ric_Server_Definition::RETENTION_TYPE__WEEKS, 3);
		self::assertEquals(['v4','v2'], $versionsForRetention);
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetention($allVersions, Ric_Server_Definition::RETENTION_TYPE__WEEKS, 4);
		self::assertEquals(['v4','v2','v1'], $versionsForRetention);
	}

	public function test_getVersionsForRetention_month(){
		$allVersions = [];
		$timestamp = time();
		for($i=1; $i<35; $i++ ){
			$allVersions['v'.$i] = $timestamp;
			$timestamp = strtotime('-1 day', $timestamp);
		}
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetention($allVersions, Ric_Server_Definition::RETENTION_TYPE__MONTHS, 1);
		self::assertEquals(['v1'], $versionsForRetention);
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetention($allVersions, Ric_Server_Definition::RETENTION_TYPE__MONTHS, 2);
		self::assertEquals(2, count($versionsForRetention));
	}
	public function test_getVersionsForRetentionString(){
		$allVersions = [];
		$timestamp = time();
		for($i=35; $i>0; $i-- ){
			$allVersions['v'.$i] = $timestamp;
			$timestamp = strtotime('-1 day', $timestamp);
		}
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetentionString($allVersions, '3l');
		self::assertEquals(['v35','v34','v33'], $versionsForRetention);
		// same versions
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetentionString($allVersions, '3l2l');
		self::assertEquals(['v35','v34','v33'], $versionsForRetention);
		// same version, because of daily
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetentionString($allVersions, '3l3d');
		self::assertEquals(['v35','v34','v33'], $versionsForRetention);
		// same version, because same week
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetentionString($allVersions, '3l2d1w');
		self::assertEquals(['v35','v34','v33'], $versionsForRetention);
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetentionString($allVersions, '3l2d1w1m');
		self::assertEquals(['v35','v34','v33'], $versionsForRetention);
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetentionString($allVersions, '3l2d1w1y');
		self::assertEquals(['v35','v34','v33'], $versionsForRetention);
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetentionString($allVersions, '1y');
		self::assertEquals(['v35'], $versionsForRetention);
		#3l7d4w12m
#		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetentionString($allVersions, '3l7d4w12m');
#		self::assertEquals(['v35'], $versionsForRetention);
	}
}

class Test_Ric_Server_Helper_RetentionCalculator extends Ric_Server_Helper_RetentionCalculator{

	static public function getVersionForTimeRange_public($allVersions, $startTimestamp, $endTimestamp, $firstOrLast='last'){
		return parent::getVersionForTimePeriod($allVersions, $startTimestamp, $endTimestamp, $firstOrLast);
	}

}