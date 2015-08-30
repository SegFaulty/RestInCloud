<?php

class Ric_Server_Definition {
	const MAGIC_DELETION_TIMESTAMP = 1422222222; // 2015-01-25 22:43:42

	const RETENTION__ALL = '';
	const RETENTION__OFF = '1l';
	const RETENTION__LAST3 = '3l';
	const RETENTION__LAST7 = '7l';
	const RETENTION__3L7D4W12M = '3l7d4w12m';

	const RETENTION_TYPE__LAST = 'l';
	const RETENTION_TYPE__HOURS = 'h';
	const RETENTION_TYPE__DAYS = 'd';
	const RETENTION_TYPE__WEEKS = 'w';
	const RETENTION_TYPE__MONTHS = 'm';
	const RETENTION_TYPE__YEARS = 'y';
}