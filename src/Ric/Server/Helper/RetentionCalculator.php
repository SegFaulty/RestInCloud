<?php

class Ric_Server_Helper_RetentionCalculator {
	protected static $debug = false;
	protected static $now = null;

	/**
	 * @param boolean $debug
	 */
	public static function setDebug($debug){
		self::$debug = $debug;
	}

	/**
	 * for test purposes
	 * @param $time
	 */
	static public function setNow($time){
		self::$now = $time;
	}

	/**
	 * enable set now for testing
	 * @return int|null
	 */
	static protected function time(){
		$time = self::$now;
		if( $time===null ){
			$time = time();
		}
		return $time;
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
			// for safety we always keep the newest version
			#		$retentionVersions[] = key($allVersions);
			foreach( $matches[1] as $index => $retentionCount ){
				$retentionType = $matches[2][$index];
				$retentionVersions = array_merge($retentionVersions, self::getVersionsForRetention($allVersions, $retentionType, $retentionCount));
			}
		}
		$retentionVersions = array_unique($retentionVersions);
		return $retentionVersions;
	}

	/**
	 * for time related retentions we keep n versions in the past, plus current version : 2m => current version AND -1 month AND -2 month
	 * @param array $allVersions
	 * @param string $retentionType
	 * @param int $retentionCount
	 * @return array
	 * @throws RuntimeException
	 */
	static public function getVersionsForRetention($allVersions, $retentionType, $retentionCount){
		$versions = [];
		switch($retentionType){
			case Ric_Server_Definition::RETENTION_TYPE__LAST:
				$versions = array_slice(array_keys($allVersions), 0, $retentionCount);
				break;
			case Ric_Server_Definition::RETENTION_TYPE__DAYS:
				$startTimestamp = strtotime("tomorrow", self::time());
				$versions = self::getVersionsForTimePeriods($allVersions, $retentionCount, $startTimestamp, '-1 day');
				break;
			case Ric_Server_Definition::RETENTION_TYPE__WEEKS:
				$startTimestamp = strtotime("+1 week", self::getStartOfWeek());
				$versions = self::getVersionsForTimePeriods($allVersions, $retentionCount, $startTimestamp, '-1 week');
				break;
			case Ric_Server_Definition::RETENTION_TYPE__MONTHS: // first day of this month
				$startTimestamp = strtotime("today", strtotime("first day of next month", self::time()));
				$versions = self::getVersionsForTimePeriods($allVersions, $retentionCount, $startTimestamp, '-1 month');
				break;
			case Ric_Server_Definition::RETENTION_TYPE__YEARS:
				$startTimestamp = strtotime("today", strtotime("first day of january next year", self::time()));
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
			$refTimestamp = self::time();
		}
		return strtotime('last monday', strtotime('next monday', $refTimestamp));
	}

	/**
	 * returns best matching version for this time span,
	 *
	 * the newest in requested time span
	 * OR the oldest before requested time span if there is no version IN time span
	 * OR false if there is no version before the $endTimestamp_excluded
	 *
	 *
	 * this means, if ther is no version in time span, the oldest version before this time span will returned,
	 * to keep this version in retention until it reaches the requested time span
	 *
	 * e.g. retention "2m"   two month, if we have version 15.thisMonth and 03 thisMonth   and here comes the request for last (2.) month,
	 *      we have no version in last month  BUT her we retrun 03.thisMonth  because this is best version to keep
	 *
	 * $endTimestamp_excluded is not IN the Range, eg next day 00:00:00
	 *
	 * 2022-10-16: there was A MEGA CRiTiCAL MIND BUG until now
	 * we keept only version included in the given time span,
	 * but if we have no version in this time span we have TO keep the next version to this time span,
	 * so wenn time goes by this version wir come in these requested time
	 * other wise we never get a version in this time span
	 *
	 * @param string[] $allVersions
	 * @param int $startTimestamp
	 * @param int $endTimestamp_excluded
	 * @return string
	 */
	static protected function getVersionForTimePeriod($allVersions, $startTimestamp, $endTimestamp_excluded){
		$resultVersion = false;
		if( $startTimestamp>=$endTimestamp_excluded ){
			throw new RuntimeException('$startTimestamp '.date('Y-m-d H:i:s', $startTimestamp).' must less / earlier then $endTimestamp '.date('Y-m-d H:i:s', $endTimestamp_excluded));
		}

		$timestampVersions = array_flip($allVersions);
		krsort($timestampVersions); // newest first

		if( self::$debug ){
			echo 'start: '.$startTimestamp.' '.date('Y-m-d H:i:s', $startTimestamp).PHP_EOL;
			echo 'end: '.$endTimestamp_excluded.' '.date('Y-m-d H:i:s', $endTimestamp_excluded).PHP_EOL;
			echo 'rAll: '.PHP_EOL;
			foreach( $timestampVersions as $timestamp => $version ){
				echo $timestamp.' '.date('Y-m-d H:i:s', $timestamp).' '.$version.PHP_EOL;
			}
		}

		foreach( $timestampVersions as $timestamp => $version ){
			if( $timestamp>=$startTimestamp ){
				$resultVersion = $version;
				if( $timestamp<$endTimestamp_excluded ){ // if not in request range it is the fall back version
					break; // the latest/newest version in time span
				}
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
		for( ; $retentionCount>=0; $retentionCount-- ){
			$endTimestamp = $startTimestamp;
			$startTimestamp = strtotime($diffString, $endTimestamp);
			$version = self::getVersionForTimePeriod($allVersions, $startTimestamp, $endTimestamp);
			if( $version and !in_array($version, $versions) ){
				$versions[] = $version;
			}
		}
		return $versions;
	}
}