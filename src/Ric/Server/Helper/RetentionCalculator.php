<?php

class Ric_Server_Helper_RetentionCalculator {
	protected static $debug = false;

	/**
	 * @param boolean $debug
	 */
	public static function setDebug($debug){
		self::$debug = $debug;
	}

	/**
	 * returns wanted versions by retentionsString (like: 3l7d4w12m)
	 * @param array $allVersions
	 * @param string $retentionString
	 * @return array
	 */
	static public function getVersionsForRetentionString($allVersions, $retentionString){
		$retentionVersions = array_keys($allVersions); // for safety reason, default is allVersions if $retentionString is empty
		if( preg_match_all('~(\d+)([a-z])~', $retentionString, $matches) ){
			$retentionVersions = []; // okay retention found, clear the list, no problem if its completely wrong, because this will throw an exception in getVersionsForRetention, so we are save
			foreach( $matches[1] as $index => $retentionCount ){
				$retentionType = $matches[2][$index];
				$retentionVersions = array_merge($retentionVersions, self::getVersionsForRetention($allVersions, $retentionType, $retentionCount));
			}
		}
		$retentionVersions = array_unique($retentionVersions);
		return $retentionVersions;
	}

	/**
	 * @param array $allVersions
	 * @param string $retentionType
	 * @param int $retentionCount
	 * @throws RuntimeException
	 * @return array
	 */
	static public function getVersionsForRetention($allVersions, $retentionType, $retentionCount){
		$versions = [];
		switch($retentionType){
			case Ric_Server_Definition::RETENTION_TYPE__LAST:
				$versions = array_slice(array_keys($allVersions), 0, $retentionCount);
				break;
			case Ric_Server_Definition::RETENTION_TYPE__DAYS:
				$startTimestamp = strtotime("next day", mktime(0, 0, 0));
				$versions = self::getVersionsForTimePeriods($allVersions, $retentionCount, $startTimestamp, '-1 day');
				break;
			case Ric_Server_Definition::RETENTION_TYPE__WEEKS:
				$startTimestamp = strtotime("+1 week", self::getStartOfWeek());
				$versions = self::getVersionsForTimePeriods($allVersions, $retentionCount, $startTimestamp, '-1 week');
				break;
			case Ric_Server_Definition::RETENTION_TYPE__MONTHS:
				$startTimestamp = strtotime("next month", mktime(0, 0, 0, date('m'), 1));
				$versions = self::getVersionsForTimePeriods($allVersions, $retentionCount, $startTimestamp, '-1 month');
				break;
			case Ric_Server_Definition::RETENTION_TYPE__YEARS:
				$startTimestamp = strtotime("next year", mktime(0, 0, 0, 1, 1));
				$versions = self::getVersionsForTimePeriods($allVersions, $retentionCount, $startTimestamp, '-1 year');
				break;
			default:
				throw new RuntimeException('unknown retentionType: '.$retentionType);
		}
		return $versions;
	}

	/**
	 * @param int $refTimestamp
	 * @return int
	 */
	static public function getStartOfWeek($refTimestamp = 0){
		if( $refTimestamp==0 ){
			$refTimestamp = time();
		}
		return strtotime('last monday', strtotime('next monday', $refTimestamp));
	}

	/**
	 * $endTimestamp_excluded is not IN the Range, eg next day 00:00:00
	 * @param string[] $allVersions
	 * @param int $startTimestamp
	 * @param int $endTimestamp_excluded
	 * @param string $firstOrLast
	 * @return string
	 */
	static protected function getVersionForTimePeriod($allVersions, $startTimestamp, $endTimestamp_excluded, $firstOrLast = 'last'){
		$resultVersion = false;
		$timestampVersions = array_flip($allVersions);
		ksort($timestampVersions);
		if( $firstOrLast!='last' ){
			krsort($timestampVersions);
		}
		if( self::$debug ){
			echo 'start: '.$startTimestamp.' '.date('Y-m-d H:i:s', $startTimestamp).PHP_EOL;
			echo 'end: '.$endTimestamp_excluded.' '.date('Y-m-d H:i:s', $endTimestamp_excluded).PHP_EOL;
			echo 'rAll: '.PHP_EOL;
			foreach( $timestampVersions as $timestamp => $version ){
				echo $timestamp.' '.date('Y-m-d H:i:s', $timestamp).' '.$version.PHP_EOL;
			}
		}
		foreach( $timestampVersions as $timestamp => $version ){
			if( $timestamp<$endTimestamp_excluded AND $timestamp>=$startTimestamp ){
				$resultVersion = $version;
			}
		}
		if( self::$debug ){
			echo 'resultVer: '.$resultVersion.PHP_EOL;
		}
		return $resultVersion;
	}

	/**
	 * @param array $allVersions
	 * @param int $retentionCount
	 * @param int $startTimestamp
	 * @param string $diffString
	 * @return array
	 */
	protected static function getVersionsForTimePeriods($allVersions, $retentionCount, $startTimestamp, $diffString){
		$versions = [];
		for( ; $retentionCount--; ){
			$endTimestamp = $startTimestamp;
			$startTimestamp = strtotime($diffString, $endTimestamp);
			$version = self::getVersionForTimePeriod($allVersions, $startTimestamp, $endTimestamp);
			if( $version ){
				$versions[] = $version;
			}
		}
		return $versions;
	}
}