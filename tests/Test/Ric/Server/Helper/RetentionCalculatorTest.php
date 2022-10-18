<?php

require_once __DIR__.'/../../../../bootstrap.php';


class Test_Ric_Server_Helper_RetentionCalculatorTest extends \PHPUnit\Framework\TestCase {

	public function test_getVersionsForRetentionString_empty(){
		$allVersions = [
				'v3' => 1439010784,
				'v2' => 1438853184,
				'v1' => 1438835877,
		];
		self::assertEquals(['v3', 'v2', 'v1'], Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetentionString($allVersions, ''));
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
				'v0' => strtotime('today -8 day'),
		];
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetention($allVersions, Ric_Server_Definition::RETENTION_TYPE__DAYS, 2);
		self::assertEquals(['v4', 'v2', 'v1'], $versionsForRetention);
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetention($allVersions, Ric_Server_Definition::RETENTION_TYPE__DAYS, 1);
		self::assertEquals(['v4', 'v2'], $versionsForRetention);
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetention($allVersions, Ric_Server_Definition::RETENTION_TYPE__DAYS, 3);
		self::assertEquals(['v4', 'v2', 'v1'], $versionsForRetention);
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetention($allVersions, Ric_Server_Definition::RETENTION_TYPE__DAYS, 5);
		self::assertEquals(['v4', 'v2', 'v1'], $versionsForRetention);
	}

	public function test_getVersionForTimeRange(){
		$allVersions = [
				'v3' => 1439010784,
				'v2' => 1438853184,
				'v1' => 1438835877,
		];
		self::assertEquals('v3', Test_Ric_Server_Helper_RetentionCalculator::getVersionForTimeRange($allVersions, 1438840000, 1500000000));
	}

	public function test_getVersionForTimeRange2022MindBugTest(){
		$allVersions = [
				'v3' => strtotime('2022-10-16'),
				'v2' => strtotime('2022-10-15'),
				'v1' => strtotime('2022-10-14'),
		];
		self::assertEquals('v1', Test_Ric_Server_Helper_RetentionCalculator::getVersionForTimeRange($allVersions, strtotime('2022-09-01'), strtotime('2022-10-01')));
	}

	public function test_getVersionForTimeRange2022(){
		/*
		the newest in requested time span
		OR the oldest before requested time span if there is no version IN time span
		OR false if there is no version before the $endTimestamp_excluded
		*/
		$allVersions = [
				'v3' => strtotime('2022-10-16'),
				'v2' => strtotime('2022-10-15'),
				'v1' => strtotime('2022-10-14'),
		];
		self::assertEquals('v1', Test_Ric_Server_Helper_RetentionCalculator::getVersionForTimeRange($allVersions, strtotime('2022-09-01'), strtotime('2022-10-01')));
	}

	public function test_getStartOfWeek(){
		self::assertEquals(1438552800, self::getStartOfWeek(1439136385));
		self::assertEquals(1438552800, self::getStartOfWeek(1439036385));
		self::assertEquals(1437948000, self::getStartOfWeek(1438552799));
		self::assertEquals(1437948000, self::getStartOfWeek(1437948001));
		self::assertEquals(1437948000, self::getStartOfWeek(1438048001));
	}

	public function test_getVersionsForRetention_week(){
		$allVersions = [
				'v4' => strtotime('-1 hour', strtotime('2022-10-16')), // 2022-10-16 sunday -> 23:00 sat
				'v3' => strtotime('-2 hour', strtotime('2022-10-16')), // -> 22:00 sat
				'v2' => strtotime('-1 week +3 days', self::getStartOfWeek(strtotime('2022-10-16'))),  // ->  thursday 2. week
				'v1' => strtotime('-3 week +2 day', self::getStartOfWeek(strtotime('2022-10-16'))), // ->  mittwoch 4. week
				'v0' => strtotime('-4 week +4 day', self::getStartOfWeek(strtotime('2022-10-16'))), // ->  friday 5. week
		];
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetention($allVersions, Ric_Server_Definition::RETENTION_TYPE__WEEKS, 1);
		self::assertEquals(['v4'], $versionsForRetention);
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetention($allVersions, Ric_Server_Definition::RETENTION_TYPE__WEEKS, 2);
		self::assertEquals(['v4', 'v2'], $versionsForRetention);
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetention($allVersions, Ric_Server_Definition::RETENTION_TYPE__WEEKS, 3);
		self::assertEquals(['v4', 'v2'], $versionsForRetention);
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetention($allVersions, Ric_Server_Definition::RETENTION_TYPE__WEEKS, 4);
		self::assertEquals(['v4', 'v2', 'v1'], $versionsForRetention);
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetention($allVersions, Ric_Server_Definition::RETENTION_TYPE__WEEKS, 8);
		self::assertEquals(['v4', 'v2', 'v1', 'v0'], $versionsForRetention);
	}

	public function test_getVersionsForRetention_monthLongTerm(){
		$allVersions = [];

		$testTimestamp = strtotime('2022-10-17 10:06:15');
		for( $i = 1; $i<=60; $i++ ){
			$testTimestamp = strtotime('+3 day', $testTimestamp);
			$allVersions['v'.$i] = $testTimestamp;
			Ric_Server_Helper_RetentionCalculator::setNow($testTimestamp + 10);
			$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetentionString($allVersions, '3m');
			foreach( $allVersions as $version => $timestamp ){
				if( !in_array($version, $versionsForRetention) ){
					unset($allVersions[$version]);
				}
			}
			if( $i==2 ){
				self::assertSame(['v1', 'v2'], array_keys($allVersions), date('Y-m-d', $testTimestamp).' wrong version: '.print_R($allVersions, true));
			}
			if( $i==6 ){
				self::assertSame(['v1', 'v4', 'v6'], array_keys($allVersions), date('Y-m-d', $testTimestamp).' wrong version: '.print_R($allVersions, true));
			}
			if( $i==12 ){
				self::assertSame(['v1', 'v4', 'v12'], array_keys($allVersions), date('Y-m-d', $testTimestamp).' wrong version: '.print_R($allVersions, true));
			}
			if( $i==15 ){
				self::assertSame(['v1', 'v4', 'v14', 'v15'], array_keys($allVersions), date('Y-m-d', $testTimestamp).' wrong version: '.print_R($allVersions, true));
			}
			if( $i==26 ){
				self::assertSame(['v4', 'v14', 'v25', 'v26'], array_keys($allVersions), date('Y-m-d', $testTimestamp).' wrong version: '.print_R($allVersions, true));
			}

		}
		self::assertSame(['v35', 'v44', 'v55', 'v60'], array_keys($allVersions), date('Y-m-d', $testTimestamp).' wrong version: '.print_R($allVersions, true));
	}

	public function test_getVersionsForRetentionString_keepLastVersion(){
		$allVersions = [];
		$timestamp = strtotime('2020-10-18 11:54:45'); // 2 jahre vor now
		Ric_Server_Helper_RetentionCalculator::setNow(strtotime('2022-10-18 11:54:45')); // now
		// write some urlalt versions
		for( $i = 5; $i>0; $i-- ){
			$allVersions['v'.$i] = $timestamp;
			$timestamp = strtotime('-1 day', $timestamp);
		}
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetentionString($allVersions, '3d');
		self::assertEquals(['v5'], $versionsForRetention);
	}

	public function test_getVersionsForRetentionString(){
		$allVersions = [];
		$timestamp = strtotime('2022-10-18 11:54:45'); // DIENSTAG
		Ric_Server_Helper_RetentionCalculator::setNow($timestamp);
		for( $i = 35; $i>0; $i-- ){
			$allVersions['v'.$i] = $timestamp;
			$timestamp = strtotime('-1 day', $timestamp);
		}
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetentionString($allVersions, '3l');
		self::assertEquals(['v35', 'v34', 'v33'], $versionsForRetention);
		// same versions
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetentionString($allVersions, '3l2l');
		self::assertEquals(['v35', 'v34', 'v33'], $versionsForRetention);
		// same version, because of daily   2 days back
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetentionString($allVersions, '3l2d');
		self::assertEquals(['v35', 'v34', 'v33'], $versionsForRetention);
		// same version, because same week
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetentionString($allVersions, '3l2d1w');
		self::assertEquals(['v35', 'v34', 'v33'], $versionsForRetention);
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetentionString($allVersions, '3l2d1w1m');
		self::assertEquals(['v35', 'v34', 'v33', 'v17'], array_values($versionsForRetention));
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetentionString($allVersions, '3l2d1w1y');
		self::assertEquals(['v35', 'v34', 'v33', 'v1'], array_values($versionsForRetention));
		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetentionString($allVersions, '1y');
		self::assertEquals(['v35', 'v1'], $versionsForRetention);
		#3l7d4w12m
#		$versionsForRetention = Test_Ric_Server_Helper_RetentionCalculator::getVersionsForRetentionString($allVersions, '3l7d4w12m');
#		self::assertEquals(['v35'], $versionsForRetention);
	}

	protected function tearDown(){
		Ric_Server_Helper_RetentionCalculator::setNow(null);
	}

	/**
	 * @param int $refTimestamp
	 * @return int
	 */
	static public function getStartOfWeek($refTimestamp){
		return strtotime('last monday', strtotime('next monday', $refTimestamp));
	}

}

class Test_Ric_Server_Helper_RetentionCalculator extends Ric_Server_Helper_RetentionCalculator {

	static public function getVersionForTimeRange($allVersions, $startTimestamp, $endTimestamp_excluded){
		return parent::getVersionForTimePeriod($allVersions, $startTimestamp, $endTimestamp_excluded);
	}

	static public function getVersionsForRetention($allVersions, $retentionType, $retentionCount){
		return parent::getVersionsForRetention($allVersions, $retentionType, $retentionCount);
	}
}