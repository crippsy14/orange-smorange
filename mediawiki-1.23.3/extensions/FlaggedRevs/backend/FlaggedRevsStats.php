<?php
/**
 * FlaggedRevs stats functions
 */
class FlaggedRevsStats {
	/**
	 * Get FR-related stats at a designated snapshot in time.
	 * If no $timestamp is specified, then the latest will be used.
	 *
	 * @param $timestamp string|bool false TS_ timestamp
	 * @return Array of current FR stats
	 */
	public static function getStats( $timestamp = false ) {
		$data = array(); // initialize
		$data['reviewLag-anon-sampleSize'] = '-';
		$data['reviewLag-anon-average'] = '-';
		$data['reviewLag-anon-median'] = '-';
		$data['reviewLag-anon-percentile'] = array();
		$data['reviewLag-user-sampleSize'] = '-';
		$data['reviewLag-user-average'] = '-';
		$data['reviewLag-user-median'] = '-';
		$data['reviewLag-user-percentile'] = array();
		$data['totalPages-NS'] = array();
		$data['reviewedPages-NS'] = array();
		$data['syncedPages-NS'] = array();
		$data['pendingLag-average'] = '-';
		$data['statTimestamp'] = '-';

		$dbr = wfGetDB( DB_SLAVE );
		if ( $timestamp === false ) { // use latest
			$timestamp = $dbr->selectField( 'flaggedrevs_statistics', 'MAX(frs_timestamp)' );
		}

		if ( $timestamp !== false ) {
			$data['statTimestamp'] = wfTimestamp( TS_MW, $timestamp );

			$res = $dbr->select( 'flaggedrevs_statistics',
				array( 'frs_stat_key', 'frs_stat_val' ),
				array( 'frs_timestamp' => $dbr->timestamp( $timestamp ) ),
				__METHOD__
			);
			foreach ( $res as $row ) {
				$key = explode( ':', $row->frs_stat_key );
				switch ( $key[0] ) {
					case 'reviewLag-anon-sampleSize':
					case 'reviewLag-anon-average':
					case 'reviewLag-anon-median':
					case 'reviewLag-user-sampleSize':
					case 'reviewLag-user-average':
					case 'reviewLag-user-median':
					case 'pendingLag-average':
						$data[$key[0]] = (int)$row->frs_stat_val;
						break;
					case 'reviewLag-anon-percentile': // <stat name,percentile>
					case 'reviewLag-user-percentile': // <stat name,percentile>
						$data[$key[0]][$key[1]] = (int)$row->frs_stat_val;
						break;
					case 'totalPages-NS': // <stat name,namespace>
					case 'reviewedPages-NS': // <stat name,namespace>
					case 'syncedPages-NS': // <stat name,namespace>
						$data[$key[0]][$key[1]] = (int)$row->frs_stat_val;
						break;
				}
			}
		}

		return $data;
	}

	/*
	 * Run a stats update and update the DB
	 * Note: this can easily be too expensive to run live
	*
	 * @return void
	 */
	public static function updateCache() {
		global $wgFlaggedRevsStatsAge;
		$rNamespaces = FlaggedRevs::getReviewNamespaces();
		if ( empty( $rNamespaces ) ) {
			return; // no SQL errors please :)
		}

		// Set key to limit duplicate updates...
		$dbCache = wfGetCache( CACHE_DB );
		$keySQL = wfMemcKey( 'flaggedrevs', 'statsUpdating' );
		$dbCache->set( $keySQL, '1', $wgFlaggedRevsStatsAge );

		// Get total, reviewed, and synced page count for each namespace
		list( $ns_total, $ns_reviewed, $ns_synced ) = self::getPerNamespaceTotals();

		// Getting mean pending edit time
		// @TODO: percentiles?
		$avePET = self::getMeanPendingEditTime();

		# Get wait (till review) time samples for anon edits...
		$reviewDataAnon = self::getEditReviewTimes( $dbCache, 'anons' );
		# Get wait (till review) time samples for logged-in user edits...
		$reviewDataUser = self::getEditReviewTimes( $dbCache, 'users' );

		$dbw = wfGetDB( DB_MASTER );
		// The timestamp to identify this whole batch of data
		$encDataTimestamp = $dbw->timestamp();

		$dataSet = array();
		// Data range for samples...
		$dataSet[] = array(
			'frs_stat_key'  => 'reviewLag-anon-sampleStartTimestamp',
			'frs_stat_val'  => $reviewDataAnon['sampleStartTS'], // unix
			'frs_timestamp' => $encDataTimestamp );
		$dataSet[] = array(
			'frs_stat_key'  => 'reviewLag-user-sampleStartTimestamp',
			'frs_stat_val'  => $reviewDataUser['sampleStartTS'], // unix
			'frs_timestamp' => $encDataTimestamp );
		$dataSet[] = array(
			'frs_stat_key'  => 'reviewLag-anon-sampleEndTimestamp',
			'frs_stat_val'  => $reviewDataAnon['sampleEndTS'], // unix
			'frs_timestamp' => $encDataTimestamp );
		$dataSet[] = array(
			'frs_stat_key'  => 'reviewLag-user-sampleEndTimestamp',
			'frs_stat_val'  => $reviewDataUser['sampleEndTS'], // unix
			'frs_timestamp' => $encDataTimestamp );
		// All-namespace percentiles...
		foreach( $reviewDataAnon['percTable'] as $percentile => $seconds ) {
			$dataSet[] = array(
				'frs_stat_key'  => 'reviewLag-anon-percentile:'.(int)$percentile,
				'frs_stat_val'  => $seconds,
				'frs_timestamp' => $encDataTimestamp );
		}
		foreach( $reviewDataUser['percTable'] as $percentile => $seconds ) {
			$dataSet[] = array(
				'frs_stat_key'  => 'reviewLag-user-percentile:'.(int)$percentile,
				'frs_stat_val'  => $seconds,
				'frs_timestamp' => $encDataTimestamp );
		}
		// Sample sizes...
		$dataSet[] = array(
			'frs_stat_key'  => 'reviewLag-anon-sampleSize',
			'frs_stat_val'  => $reviewDataAnon['sampleSize'],
			'frs_timestamp' => $encDataTimestamp );
		$dataSet[] = array(
			'frs_stat_key'  => 'reviewLag-user-sampleSize',
			'frs_stat_val'  => $reviewDataUser['sampleSize'],
			'frs_timestamp' => $encDataTimestamp );

		// All-namespace ave/med review lag & ave pending lag stats...
		$dataSet[] = array(
			'frs_stat_key'  => 'reviewLag-anon-average',
			'frs_stat_val'  => $reviewDataAnon['average'],
			'frs_timestamp' => $encDataTimestamp );
		$dataSet[] = array(
			'frs_stat_key'  => 'reviewLag-user-average',
			'frs_stat_val'  => $reviewDataUser['average'],
			'frs_timestamp' => $encDataTimestamp );
		$dataSet[] = array(
			'frs_stat_key'  => 'reviewLag-anon-median',
			'frs_stat_val'  => $reviewDataAnon['median'],
			'frs_timestamp' => $encDataTimestamp );
		$dataSet[] = array(
			'frs_stat_key'  => 'reviewLag-user-median',
			'frs_stat_val'  => $reviewDataUser['median'],
			'frs_timestamp' => $encDataTimestamp );
		$dataSet[] = array(
			'frs_stat_key'  => 'pendingLag-average',
			'frs_stat_val'  => $avePET,
			'frs_timestamp' => $encDataTimestamp );

		// Per-namespace total/reviewed/synced stats...
		foreach( $rNamespaces as $namespace ) {
			$dataSet[] = array(
				'frs_stat_key'  => 'totalPages-NS:'.(int)$namespace,
				'frs_stat_val'  => isset($ns_total[$namespace]) ? $ns_total[$namespace] : 0,
				'frs_timestamp' => $encDataTimestamp );
			$dataSet[] = array(
				'frs_stat_key'  => 'reviewedPages-NS:'.(int)$namespace,
				'frs_stat_val'  => isset($ns_reviewed[$namespace]) ? $ns_reviewed[$namespace] : 0,
				'frs_timestamp' => $encDataTimestamp );
			$dataSet[] = array(
				'frs_stat_key'  => 'syncedPages-NS:'.(int)$namespace,
				'frs_stat_val'  => isset($ns_synced[$namespace]) ? $ns_synced[$namespace] : 0,
				'frs_timestamp' => $encDataTimestamp );
		}

		// Save the data...
		$dbw->begin();
		$dbw->insert( 'flaggedrevs_statistics', $dataSet, __FUNCTION__, array( 'IGNORE' ) );
		$dbw->commit();

		// Stats are now up to date!
		$key = wfMemcKey( 'flaggedrevs', 'statsUpdated' );
		$dbCache->set( $key, '1', $wgFlaggedRevsStatsAge );
		$dbCache->delete( $keySQL );
	}

	private static function getPerNamespaceTotals() {
		$ns_total = $ns_reviewed = $ns_synced = array();
		// Get total, reviewed, and synced page count for each namespace
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( array( 'page', 'flaggedpages' ),
			array( 'page_namespace',
				'COUNT(*) AS total',
				'COUNT(fp_page_id) AS reviewed',
				'COUNT(fp_pending_since) AS pending' ),
			array( 'page_is_redirect' => 0,
				'page_namespace' => FlaggedRevs::getReviewNamespaces() ),
			__METHOD__,
			array( 'GROUP BY' => 'page_namespace' ),
			array( 'flaggedpages' => array( 'LEFT JOIN', 'fp_page_id = page_id' ) )
		);
		foreach ( $res as $row ) {
			$ns_total[$row->page_namespace] = (int)$row->total;
			$ns_reviewed[$row->page_namespace] = (int)$row->reviewed;
			$ns_synced[$row->page_namespace] = (int)$row->reviewed - (int)$row->pending;
		}
		return array( $ns_total, $ns_reviewed, $ns_synced );
	}

	// @TODO: maybe put in core?
	private static function dbUnixTime( $db, $column ) {
		return $db->getType() === 'sqlite' ? "strftime('%s',$column)" : "UNIX_TIMESTAMP($column)";
	}

	private static function getMeanPendingEditTime() {
		$dbr = wfGetDB( DB_SLAVE );
		$nowUnix = wfTimestamp( TS_UNIX ); // current time in UNIX TS
		$unixTimeCall = self::dbUnixTime( $dbr, 'fp_pending_since' );
		return (int)$dbr->selectField(
			array( 'flaggedpages', 'page' ),
			"AVG( $nowUnix - $unixTimeCall )",
			array( 'fp_pending_since IS NOT NULL',
				'fp_page_id = page_id',
				'page_namespace' => FlaggedRevs::getReviewNamespaces() // sanity
			),
			__METHOD__
		);
	}

	/**
	 * Get edit review time statistics (as recent as possible)
	 * @param $dbCache cache object
	 * @param $users string "anons" or "users"
	 * @throws MWException
	 * @return Array associative
	 */
	private static function getEditReviewTimes( $dbCache, $users = 'anons' ) {
		$result = array(
			'average'       => 0,
			'median'        => 0,
			'percTable'     => array(),
			'sampleSize'    => 0,
			'sampleStartTS' => null,
			'sampleEndTS'   => null
		);
		if ( FlaggedRevs::useOnlyIfProtected() ) {
			return $result; // disabled
		}

		$rPerTable = array(); // review wait percentiles
		# Only go so far back...otherwise we will get garbage values due to
		# the fact that FlaggedRevs wasn't enabled until after a while.
		$dbr = wfGetDB( DB_SLAVE );
		$installedUnix = (int)$dbr->selectField( 'logging',
			self::dbUnixTime( $dbr, 'MIN(log_timestamp)' ),
			array('log_type' => 'review')
		);
		if ( !$installedUnix ) {
			$installedUnix = wfTimestamp( TS_UNIX ); // now
		}
		$encInstalled = $dbr->addQuotes( $dbr->timestamp( $installedUnix ) );
		# Skip the most recent recent revs as they are likely to just
		# be WHERE condition misses. This also gives us more data to use.
		# Lastly, we want to avoid bias that would make the time too low
		# since new revisions could not have "took a long time to sight".
		$worstLagTS = $dbr->timestamp(); // now
		$encLastTS = $encInstalled;
		while ( true ) { // should almost always be ~1 pass
			# Get the page with the worst pending lag...
			$row = $dbr->selectRow( array( 'flaggedpage_pending', 'flaggedrevs' ),
				array( 'fpp_page_id', 'fpp_rev_id', 'fpp_pending_since', 'fr_timestamp' ),
				array(
					'fpp_quality' => 0, // "checked"
					'fpp_pending_since > '.$encInstalled, // needs actual display lag
					'fr_page_id = fpp_page_id AND fr_rev_id = fpp_rev_id',
					'fpp_pending_since > '.$encLastTS, // skip failed rows
				),
				__METHOD__,
				array( 'ORDER BY' => 'fpp_pending_since ASC' )
			);
			if ( !$row ) {
				break;
			}
			# Find the newest revision at the time the page was reviewed,
			# this is the one that *should* have been reviewed.
			$idealRev = (int)$dbr->selectField( 'revision', 'rev_id',
				array( 'rev_page' => $row->fpp_page_id,
					'rev_timestamp < '.$dbr->addQuotes( $row->fr_timestamp ) ),
				__METHOD__,
				array( 'ORDER BY' => 'rev_timestamp DESC', 'LIMIT' => 1 )
			);
			if ( $row->fpp_rev_id >= $idealRev ) {
				$worstLagTS = $row->fpp_pending_since;
				break; // sane $worstLagTS found
			# Fudge factor to prevent deliberate reviewing of non-current revisions
			# from squeezing the range. Shouldn't effect anything otherwise.
			} else {
				$encLastTS = $dbr->addQuotes( $row->fpp_pending_since ); // next iteration
			}
		}
		# User condition (anons/users)
		if ( $users === 'anons' ) {
			$userCondition = 'rev_user = 0';
		} elseif ( $users === 'users' ) {
			$userCondition = 'rev_user > 0';
		} else {
			throw new MWException( 'Invalid $users param given.' );
		}
		# Avoid having to censor data
		# Note: if no edits pending, $worstLagTS is the cur time just before we checked
		# for the worst lag. Thus, new edits *right* after the check are properly excluded.
		$maxTSUnix = wfTimestamp( TS_UNIX, $worstLagTS ) - 1; // all edits later reviewed
		$encMaxTS = $dbr->addQuotes( $dbr->timestamp( $maxTSUnix ) );
		# Use a one week time range
		$days = 7;
		$minTSUnix = $maxTSUnix - $days*86400;
		$encMinTS = $dbr->addQuotes( $dbr->timestamp( $minTSUnix ) );
		# Approximate the number rows to scan
		$rows = $dbr->estimateRowCount( 'revision', '1',
			array( $userCondition, "rev_timestamp BETWEEN $encMinTS AND $encMaxTS" ) );
		# If the range doesn't have many rows (like on small wikis), use 30 days
		if ( $rows < 500 ) {
			$days = 30;
			$minTSUnix = $maxTSUnix - $days*86400;
			$encMinTS = $dbr->addQuotes( $dbr->timestamp( $minTSUnix ) );
			# Approximate rows to scan
			$rows = $dbr->estimateRowCount( 'revision', '1',
				array( $userCondition, "rev_timestamp BETWEEN $encMinTS AND $encMaxTS" ) );
			# If the range doesn't have many rows (like on really tiny wikis), use 90 days
			if ( $rows < 500 ) {
				$days = 90;
				$minTSUnix = $maxTSUnix - $days*86400;
			}
		}
		$sampleSize = 1500; // sample size
		# Sanity check the starting timestamp
		$minTSUnix = max($minTSUnix,$installedUnix);
		$encMinTS = $dbr->addQuotes( $dbr->timestamp( $minTSUnix ) );
		# Get timestamp boundaries
		$timeCondition = "rev_timestamp BETWEEN $encMinTS AND $encMaxTS";
		# Get mod for edit spread
		$ecKey = wfMemcKey( 'flaggedrevs', 'rcEditCount', $users, $days );
		$edits = (int)$dbCache->get( $ecKey );
		if ( !$edits ) {
			$edits = (int)$dbr->selectField( array('page','revision'),
				'COUNT(*)',
				array(
					$userCondition,
					$timeCondition, // in time range
					'page_id = rev_page',
					'page_namespace' => FlaggedRevs::getReviewNamespaces()
				)
			);
			$dbCache->set( $ecKey, $edits, 14*24*3600 ); // cache for 2 weeks
		}
		$mod = max( floor( $edits/$sampleSize ), 1 ); # $mod >= 1
		# For edits that started off pending, how long do they take to get reviewed?
		# Edits started off pending if made when a flagged rev of the page already existed.
		# Get the *first* reviewed rev *after* each edit and get the time difference.
		$sql = $dbr->selectSQLText(
			array( 'revision' ),
			array(
				'rev_timestamp AS rt', // time revision was made
				'(' . $dbr->selectSQLText( 'flaggedrevs',
					'MIN(fr_timestamp)',
					array(
						'fr_page_id = rev_page',
						'fr_rev_timestamp >= rev_timestamp' ),
					__METHOD__
				) . ') AS nft' // time when revision was first reviewed
			),
			array(
				$userCondition,
				$timeCondition,
				"(rev_id % $mod) = 0",
				'rev_parent_id > 0', // optimize (exclude new pages)
				'EXISTS (' . $dbr->selectSQLText( 'flaggedrevs',
					'*',
					array( // page was reviewed when this revision was made
						'fr_page_id = rev_page',
						'fr_rev_timestamp < rev_timestamp', // before this revision
						'fr_rev_id < rev_id', // not imported later
						'fr_timestamp < rev_timestamp' ), // page reviewed before revision
					__METHOD__
				) . ')'
			),
			__METHOD__
		);
		// foreach ( $dbr->query( "EXPLAIN $sql" ) as $row ) { print_r( $row ); }
		$res = $dbr->query( $sql );

		$secondsR = 0; // total wait seconds for edits later reviewed
		$secondsP = 0; // total wait seconds for edits still pending
		$times = array();
		if ( $dbr->numRows( $res ) ) {
			# Get the elapsed times revs were pending (flagged time - edit time)
			foreach ( $res as $row ) {
				$time = wfTimestamp(TS_UNIX,$row->nft) - wfTimestamp(TS_UNIX,$row->rt);
				$time = max( $time, 0 ); // sanity
				$secondsR += $time;
				$times[] = $time;
			}
			$sampleSize = count($times);
			$aveRT = ($secondsR + $secondsP)/$sampleSize; // sample mean
			sort($times); // order smallest -> largest
			// Sample median
			$rank = round( count($times)/2 + .5 ) - 1;
			$medianRT = $times[$rank];
			// Make percentile tabulation data
			$doPercentiles = array( 35, 45, 55, 65, 75, 85, 90, 95 );
			foreach ( $doPercentiles as $percentile ) {
				$rank = round( $percentile*count($times)/100 + .5 ) - 1;
				$rPerTable[$percentile] = $times[$rank];
			}
			$result['average']       = $aveRT;
			$result['median']        = $medianRT;
			$result['percTable']     = $rPerTable;
			$result['sampleSize']    = count( $times );
			$result['sampleStartTS'] = $minTSUnix;
			$result['sampleEndTS']   = $maxTSUnix;
		}

		return $result;
	}
}
