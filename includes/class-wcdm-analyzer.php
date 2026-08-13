<?php
defined( 'ABSPATH' ) || exit;

final class WCDM_Analyzer {
	const SCORE_META   = '_wcdm_score';
	const STATUS_META  = '_wcdm_status';
	const DETAILS_META = '_wcdm_details';
	const REVIEW_META  = '_wcdm_last_reviewed';
	const EXCLUDE_META = '_wcdm_excluded';
	const INTERVAL_META = '_wcdm_review_interval';
	const DUE_META      = '_wcdm_next_review';
	const NOTES_META    = '_wcdm_notes';

	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'save_post', array( $this, 'analyze_on_save' ), 20, 2 );
		add_action( 'before_delete_post', array( $this, 'clear_post_meta' ) );
	}

	public static function default_settings() {
		return array(
			'post_types'          => array( 'post', 'page', 'product' ),
			'watch_days'          => 180,
			'stale_days'          => 365,
			'critical_days'       => 730,
			'min_words'           => 600,
			'email_enabled'       => 0,
			'email_frequency'     => 'weekly',
			'email_recipient'     => get_option( 'admin_email' ),
			'email_min_score'     => 55,
			'delete_on_uninstall' => 0,
		);
	}

	public static function settings() {
		return wp_parse_args( get_option( 'wcdm_settings', array() ), self::default_settings() );
	}

	public static function monitored_post_types() {
		$settings = self::settings();
		$valid    = get_post_types( array( 'show_ui' => true ), 'names' );
		$types    = array_intersect( (array) $settings['post_types'], $valid );
		return array_values( array_filter( $types ) );
	}

	public function analyze_on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::monitored_post_types(), true ) ) {
			return;
		}
		$this->analyze_and_store( $post_id );
	}

	public function analyze_and_store( $post_id ) {
		$result = $this->analyze( $post_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		update_post_meta( $post_id, self::SCORE_META, $result['score'] );
		update_post_meta( $post_id, self::STATUS_META, $result['status'] );
		update_post_meta( $post_id, self::DETAILS_META, $result );
		return $result;
	}

	public function analyze( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'wcdm_missing_post', __( 'Post not found.', 'wordpress-content-decay-monitor' ) );
		}

		$settings       = self::settings();
		$reviewed       = get_post_meta( $post_id, self::REVIEW_META, true );
		$next_review    = get_post_meta( $post_id, self::DUE_META, true );
		$reference_time = $reviewed ? strtotime( $reviewed ) : strtotime( $post->post_modified_gmt . ' GMT' );
		$reference_time = $reference_time ? $reference_time : current_time( 'timestamp', true );
		$days           = max( 0, (int) floor( ( current_time( 'timestamp', true ) - $reference_time ) / DAY_IN_SECONDS ) );
		$content        = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		$words          = self::word_count( $content );
		$links          = self::link_counts( $post->post_content );
		$reasons        = array();
		$score          = 0;
		$overdue_days   = 0;

		if ( $next_review ) {
			$due_time = strtotime( $next_review . ' 23:59:59 UTC' );
			if ( $due_time && $due_time < current_time( 'timestamp', true ) ) {
				$overdue_days = max( 1, (int) floor( ( current_time( 'timestamp', true ) - $due_time ) / DAY_IN_SECONDS ) );
				$score += min( 20, 10 + (int) floor( $overdue_days / 30 ) );
				$reasons[] = __( 'The scheduled review date is overdue.', 'wordpress-content-decay-monitor' );
			}
		}

		if ( $days >= (int) $settings['critical_days'] ) {
			$score += 60;
			$reasons[] = __( 'Content has not been reviewed for a critical period.', 'wordpress-content-decay-monitor' );
		} elseif ( $days >= (int) $settings['stale_days'] ) {
			$score += 45;
			$reasons[] = __( 'Content is older than the stale threshold.', 'wordpress-content-decay-monitor' );
		} elseif ( $days >= (int) $settings['watch_days'] ) {
			$score += 25;
			$reasons[] = __( 'Content is approaching its review date.', 'wordpress-content-decay-monitor' );
		}

		if ( $words < (int) $settings['min_words'] ) {
			$deficit = 1 - min( 1, $words / max( 1, (int) $settings['min_words'] ) );
			$score  += (int) round( 15 * $deficit );
			$reasons[] = __( 'Content is below the preferred word count.', 'wordpress-content-decay-monitor' );
		}

		if ( ! has_post_thumbnail( $post_id ) ) {
			$score += 5;
			$reasons[] = __( 'Featured image is missing.', 'wordpress-content-decay-monitor' );
		}
		if ( ! has_excerpt( $post_id ) ) {
			$score += 5;
			$reasons[] = __( 'Excerpt is missing.', 'wordpress-content-decay-monitor' );
		}
		if ( 0 === $links['internal'] ) {
			$score += 8;
			$reasons[] = __( 'No internal links were detected.', 'wordpress-content-decay-monitor' );
		}
		if ( 0 === $links['external'] ) {
			$score += 7;
			$reasons[] = __( 'No external references were detected.', 'wordpress-content-decay-monitor' );
		}

		$score  = min( 100, max( 0, $score ) );
		$status = self::status_for_score( $score );

		return array(
			'score'          => $score,
			'status'         => $status,
			'days_old'       => $days,
			'word_count'     => $words,
			'internal_links' => $links['internal'],
			'external_links' => $links['external'],
			'next_review'    => $next_review,
			'overdue_days'   => $overdue_days,
			'reasons'        => $reasons,
			'analyzed_at'    => current_time( 'mysql', true ),
		);
	}

	public static function status_for_score( $score ) {
		if ( $score >= 75 ) return 'critical';
		if ( $score >= 55 ) return 'stale';
		if ( $score >= 30 ) return 'watch';
		return 'fresh';
	}

	public static function status_label( $status ) {
		$labels = array(
			'fresh'    => __( 'Fresh', 'wordpress-content-decay-monitor' ),
			'watch'    => __( 'Watch', 'wordpress-content-decay-monitor' ),
			'stale'    => __( 'Stale', 'wordpress-content-decay-monitor' ),
			'critical' => __( 'Critical', 'wordpress-content-decay-monitor' ),
			'excluded' => __( 'Excluded', 'wordpress-content-decay-monitor' ),
			'overdue'  => __( 'Overdue', 'wordpress-content-decay-monitor' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( $status );
	}

	private static function word_count( $content ) {
		$content = trim( preg_replace( '/\s+/u', ' ', $content ) );
		if ( '' === $content ) return 0;
		preg_match_all( '/[\p{L}\p{N}]+(?:[\x{200C}\'’-][\p{L}\p{N}]+)*/u', $content, $matches );
		return count( $matches[0] );
	}

	private static function link_counts( $content ) {
		$internal = 0;
		$external = 0;
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\']/i', $content, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$link_host = wp_parse_url( html_entity_decode( $url ), PHP_URL_HOST );
				if ( ! $link_host || $link_host === $host ) $internal++;
				else $external++;
			}
		}
		return array( 'internal' => $internal, 'external' => $external );
	}

	public function clear_post_meta( $post_id ) {
		delete_post_meta( $post_id, self::SCORE_META );
		delete_post_meta( $post_id, self::STATUS_META );
		delete_post_meta( $post_id, self::DETAILS_META );
		delete_post_meta( $post_id, self::REVIEW_META );
		delete_post_meta( $post_id, self::EXCLUDE_META );
		delete_post_meta( $post_id, self::INTERVAL_META );
		delete_post_meta( $post_id, self::DUE_META );
		delete_post_meta( $post_id, self::NOTES_META );
	}

	public static function set_review_schedule( $post_id, $interval_days, $from_timestamp = null ) {
		$interval_days = absint( $interval_days );
		if ( 0 === $interval_days ) {
			delete_post_meta( $post_id, self::INTERVAL_META );
			delete_post_meta( $post_id, self::DUE_META );
			return;
		}
		$interval_days = min( 1095, max( 30, $interval_days ) );
		$from_timestamp = $from_timestamp ?: current_time( 'timestamp', true );
		update_post_meta( $post_id, self::INTERVAL_META, $interval_days );
		update_post_meta( $post_id, self::DUE_META, gmdate( 'Y-m-d', $from_timestamp + ( $interval_days * DAY_IN_SECONDS ) ) );
	}
}
