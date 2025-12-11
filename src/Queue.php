<?php
/**
 * Queue Class
 *
 * Generic job queue system for Optti plugins.
 * Supports configurable table names and hooks per plugin.
 *
 * @package Optti\Framework
 */

namespace Optti\Framework;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Queue
 *
 * Lightweight job queue for background processing.
 */
class Queue {
	/**
	 * Plugin slug for table/hook naming.
	 *
	 * @var string
	 */
	protected static $plugin_slug = '';

	/**
	 * Initialize queue with plugin slug.
	 *
	 * @param string $plugin_slug Plugin slug (e.g., 'bbai').
	 */
	public static function init( $plugin_slug ) {
		self::$plugin_slug = sanitize_key( $plugin_slug );
	}

	/**
	 * Get queue table name.
	 *
	 * @return string Table name.
	 */
	public static function table() {
		global $wpdb;
		$slug = self::$plugin_slug ?: 'optti';
		return $wpdb->prefix . 'optti_queue_' . $slug;
	}

	/**
	 * Get cron hook name.
	 *
	 * @return string Hook name.
	 */
	public static function get_cron_hook() {
		$slug = self::$plugin_slug ?: 'optti';
		return 'optti_process_queue_' . $slug;
	}

	/**
	 * Create queue table on activation.
	 *
	 * @param string $plugin_slug Optional plugin slug (if not initialized).
	 */
	public static function create_table( $plugin_slug = null ) {
		if ( $plugin_slug ) {
			self::init( $plugin_slug );
		}

		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "
            CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                entity_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                source VARCHAR(50) NOT NULL DEFAULT 'auto',
                last_error TEXT NULL,
                enqueued_at DATETIME NOT NULL,
                locked_at DATETIME NULL,
                completed_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY status (status),
                KEY entity_status (entity_id, status),
                KEY enqueued_at (enqueued_at),
                KEY locked_at (locked_at)
            ) {$charset};
        ";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Schedule queue processing event.
	 *
	 * @param int $delay Delay in seconds.
	 */
	public static function schedule_processing( $delay = 30 ) {
		$hook = self::get_cron_hook();
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_single_event( time() + max( 5, (int) $delay ), $hook );
		}
	}

	/**
	 * Enqueue a single entity.
	 *
	 * @param int    $entity_id Entity ID.
	 * @param string $source    Source identifier.
	 * @return bool Success.
	 */
	public static function enqueue( $entity_id, $source = 'auto' ) {
		global $wpdb;
		$entity_id = intval( $entity_id );
		if ( $entity_id <= 0 ) {
			return false;
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is validated and escaped
		$table_escaped = esc_sql( $table );
		$exists        = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$table_escaped}` WHERE entity_id = %d AND status IN ('pending','processing') LIMIT 1",
				$entity_id
			)
		);

		if ( $exists ) {
			self::schedule_processing();
			return true;
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'entity_id'  => $entity_id,
				'status'     => 'pending',
				'source'     => sanitize_key( $source ),
				'enqueued_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( $inserted ) {
			self::schedule_processing();
			return true;
		}

		return false;
	}

	/**
	 * Clear existing queue entries for specific entity IDs.
	 *
	 * @param array $ids Entity IDs.
	 * @return int Number of deleted rows.
	 */
	public static function clear_for_entities( array $ids ) {
		global $wpdb;
		if ( empty( $ids ) ) {
			return 0;
		}

		$table = self::table();
		$ids   = array_map( 'intval', $ids );
		$ids   = array_filter(
			$ids,
			function ( $id ) {
				return $id > 0;
			}
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		$ids_clean = array_map( 'absint', $ids );

		if ( empty( $ids_clean ) ) {
			return 0;
		}

		$placeholders        = array_fill( 0, count( $ids_clean ), '%d' );
		$placeholders_string = implode( ',', $placeholders );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is validated and escaped
		$table_escaped = esc_sql( $table );

		$query         = "DELETE FROM `{$table_escaped}` WHERE entity_id IN ({$placeholders_string})";
		$prepared_query = $wpdb->prepare( $query, ...$ids_clean );
		$deleted        = $wpdb->query( $prepared_query );

		return $deleted !== false ? $deleted : 0;
	}

	/**
	 * Bulk enqueue entities.
	 *
	 * @param array  $ids    Entity IDs.
	 * @param string $source Source identifier.
	 * @return int Count of enqueued items.
	 */
	public static function enqueue_many( array $ids, $source = 'bulk' ) {
		// For regeneration, clear existing queue entries first
		if ( $source === 'bulk-regenerate' ) {
			self::clear_for_entities( $ids );
		}

		$count = 0;
		foreach ( $ids as $id ) {
			if ( self::enqueue( $id, $source ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Claim a batch of jobs for processing.
	 *
	 * @param int $limit Batch size.
	 * @return array Claimed jobs.
	 */
	public static function claim_batch( $limit = 5 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is validated and escaped
		$table_escaped = esc_sql( $table );
		$limit         = max( 1, intval( $limit ) );

		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table_escaped}` WHERE status = 'pending' ORDER BY id ASC LIMIT %d",
				$limit * 3
			),
			ARRAY_A
		);

		if ( ! $candidates ) {
			return array();
		}

		$claimed = array();
		foreach ( $candidates as $row ) {
			$updated = $wpdb->update(
				$table,
				array(
					'status'    => 'processing',
					'locked_at' => current_time( 'mysql' ),
					'attempts'  => intval( $row['attempts'] ) + 1,
				),
				array(
					'id'     => intval( $row['id'] ),
					'status' => 'pending',
				),
				array( '%s', '%s', '%d' ),
				array( '%d', '%s' )
			);

			if ( $updated ) {
				$row['status']    = 'processing';
				$row['attempts']  = intval( $row['attempts'] ) + 1;
				$row['locked_at'] = current_time( 'mysql' );
				$claimed[]        = (object) $row;
				if ( count( $claimed ) >= $limit ) {
					break;
				}
			}
		}

		return $claimed;
	}

	/**
	 * Mark job as completed.
	 *
	 * @param int $job_id Job ID.
	 */
	public static function mark_complete( $job_id ) {
		global $wpdb;
		$table = self::table();
		$wpdb->update(
			$table,
			array(
				'status'       => 'completed',
				'locked_at'    => null,
				'completed_at' => current_time( 'mysql' ),
				'last_error'   => null,
			),
			array( 'id' => intval( $job_id ) ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Retry a single job by ID.
	 *
	 * @param int $job_id Job ID.
	 */
	public static function retry_job( $job_id ) {
		global $wpdb;
		$table = self::table();
		$wpdb->update(
			$table,
			array(
				'status'     => 'pending',
				'locked_at'  => null,
				'last_error' => null,
			),
			array( 'id' => intval( $job_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mark job for retry.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $message Error message.
	 */
	public static function mark_retry( $job_id, $message = '' ) {
		global $wpdb;
		$table = self::table();
		$wpdb->update(
			$table,
			array(
				'status'     => 'pending',
				'locked_at'  => null,
				'last_error' => wp_trim_words( wp_strip_all_tags( (string) $message ), 120, '…' ),
			),
			array( 'id' => intval( $job_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mark job as failed.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $message Error message.
	 */
	public static function mark_failed( $job_id, $message ) {
		global $wpdb;
		$table = self::table();
		$wpdb->update(
			$table,
			array(
				'status'     => 'failed',
				'locked_at'  => null,
				'last_error' => wp_trim_words( wp_strip_all_tags( (string) $message ), 120, '…' ),
			),
			array( 'id' => intval( $job_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Retry all failed jobs.
	 */
	public static function retry_failed() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is validated and escaped
		$table_escaped = esc_sql( $table );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table_escaped}`
                 SET status = %s, locked_at = NULL, last_error = NULL
                 WHERE status = %s",
				'pending',
				'failed'
			)
		);
	}

	/**
	 * Clear completed jobs (optionally only older than age).
	 *
	 * @param int $age_seconds Age in seconds.
	 */
	public static function clear_completed( $age_seconds = 0 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is validated and escaped
		$table_escaped = esc_sql( $table );
		if ( $age_seconds > 0 ) {
			$threshold = gmdate( 'Y-m-d H:i:s', time() - intval( $age_seconds ) );
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `{$table_escaped}` WHERE status = %s AND completed_at IS NOT NULL AND completed_at < %s",
					'completed',
					$threshold
				)
			);
		} else {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `{$table_escaped}` WHERE status = %s",
					'completed'
				)
			);
		}
	}

	/**
	 * Reset stale processing jobs back to pending.
	 *
	 * @param int $timeout Timeout in seconds.
	 */
	public static function reset_stale( $timeout = 600 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is validated and escaped
		$table_escaped = esc_sql( $table );
		$threshold     = gmdate( 'Y-m-d H:i:s', time() - max( 60, intval( $timeout ) ) );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table_escaped}`
             SET status = 'pending', locked_at = NULL
             WHERE status = 'processing' AND locked_at IS NOT NULL AND locked_at < %s",
				$threshold
			)
		);
	}

	/**
	 * Get queue statistics.
	 *
	 * @return array Stats.
	 */
	public static function get_stats() {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is validated and escaped
		$table_escaped = esc_sql( $table );
		$counts        = $wpdb->get_results( "SELECT status, COUNT(*) as total FROM `{$table_escaped}` GROUP BY status", OBJECT_K );
		$pending       = isset( $counts['pending'] ) ? intval( $counts['pending']->total ) : 0;
		$processing    = isset( $counts['processing'] ) ? intval( $counts['processing']->total ) : 0;
		$failed        = isset( $counts['failed'] ) ? intval( $counts['failed']->total ) : 0;
		$completed     = isset( $counts['completed'] ) ? intval( $counts['completed']->total ) : 0;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is validated and escaped
		$recent_completed = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table_escaped}` WHERE status = 'completed' AND completed_at IS NOT NULL AND completed_at > %s",
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);

		return array(
			'pending'          => $pending,
			'processing'       => $processing,
			'failed'           => $failed,
			'completed'        => $completed,
			'completed_recent'  => intval( $recent_completed ),
			'has_jobs'         => ( $pending + $processing ) > 0,
		);
	}

	/**
	 * Get failed jobs with details.
	 *
	 * @return array Failed jobs.
	 */
	public static function get_failures() {
		return self::get_recent_failures( 10 );
	}

	/**
	 * Fetch recent queue entries for display.
	 *
	 * @param int $limit Limit.
	 * @return array Recent entries.
	 */
	public static function get_recent( $limit = 20 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is validated and escaped
		$table_escaped = esc_sql( $table );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table_escaped}` ORDER BY id DESC LIMIT %d",
				max( 1, intval( $limit ) )
			),
			ARRAY_A
		);
	}

	/**
	 * Fetch recent failed jobs.
	 *
	 * @param int $limit Limit.
	 * @return array Failed jobs.
	 */
	public static function get_recent_failures( $limit = 10 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is validated and escaped
		$table_escaped = esc_sql( $table );
		$limit         = max( 1, intval( $limit ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, entity_id, status, attempts, source, last_error, enqueued_at, locked_at, completed_at
                 FROM `{$table_escaped}`
                 WHERE status = %s
                 ORDER BY id DESC
                 LIMIT %d",
				'failed',
				$limit
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Delete completed jobs older than given seconds.
	 *
	 * @param int $age_seconds Age in seconds.
	 */
	public static function purge_completed( $age_seconds = 86400 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is validated and escaped
		$table_escaped = esc_sql( $table );
		$threshold     = gmdate( 'Y-m-d H:i:s', time() - max( 300, intval( $age_seconds ) ) );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table_escaped}` WHERE status = 'completed' AND completed_at IS NOT NULL AND completed_at < %s",
				$threshold
			)
		);
	}

	/**
	 * Legacy method: Clear for attachments (for backward compatibility).
	 * Maps to clear_for_entities.
	 *
	 * @param array $ids Attachment IDs.
	 * @return int Number deleted.
	 */
	public static function clear_for_attachments( array $ids ) {
		return self::clear_for_entities( $ids );
	}
}

