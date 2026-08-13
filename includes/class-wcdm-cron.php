<?php
defined( 'ABSPATH' ) || exit;

final class WCDM_Cron {
	const DAILY_HOOK  = 'wcdm_daily_scan';
	const EMAIL_HOOK  = 'wcdm_email_digest';
	const INITIAL_HOOK = 'wcdm_initial_scan';
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::DAILY_HOOK, array( $this, 'scan_all' ) );
		add_action( self::INITIAL_HOOK, array( $this, 'scan_all' ) );
		add_action( self::EMAIL_HOOK, array( $this, 'send_digest' ) );
		add_filter( 'cron_schedules', array( $this, 'add_weekly_schedule' ) );
	}

	public function add_weekly_schedule( $schedules ) {
		$schedules['wcdm_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly', 'wordpress-content-decay-monitor' ),
		);
		return $schedules;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}
		self::reschedule_email();
	}

	public static function reschedule_email() {
		wp_clear_scheduled_hook( self::EMAIL_HOOK );
		$settings = WCDM_Analyzer::settings();
		if ( ! empty( $settings['email_enabled'] ) ) {
			$frequency = 'daily' === $settings['email_frequency'] ? 'daily' : 'wcdm_weekly';
			wp_schedule_event( time() + DAY_IN_SECONDS, $frequency, self::EMAIL_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::DAILY_HOOK );
		wp_clear_scheduled_hook( self::EMAIL_HOOK );
		wp_clear_scheduled_hook( self::INITIAL_HOOK );
	}

	public function scan_all() {
		$analyzer = WCDM_Analyzer::instance();
		$page     = 1;
		$done    = 0;

		do {
			$query = new WP_Query( array(
				'post_type'              => WCDM_Analyzer::monitored_post_types(),
				'post_status'            => 'publish',
				'posts_per_page'         => 100,
				'paged'                  => $page,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => WCDM_Analyzer::EXCLUDE_META,
						'compare' => 'NOT EXISTS',
					),
				),
			) );

			foreach ( $query->posts as $post_id ) {
				$analyzer->analyze_and_store( $post_id );
				$done++;
			}
			$page++;
		} while ( $page <= (int) $query->max_num_pages );

		update_option( 'wcdm_last_scan', current_time( 'mysql' ), false );
		update_option( 'wcdm_last_scan_count', $done, false );
	}

	public function send_digest() {
		$settings = WCDM_Analyzer::settings();
		if ( empty( $settings['email_enabled'] ) || ! is_email( $settings['email_recipient'] ) ) return;

		$query = new WP_Query( array(
			'post_type'      => WCDM_Analyzer::monitored_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'meta_key'       => WCDM_Analyzer::SCORE_META,
			'orderby'        => 'meta_value_num',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => WCDM_Analyzer::SCORE_META,
					'value'   => (int) $settings['email_min_score'],
					'compare' => '>=',
					'type'    => 'NUMERIC',
				),
			),
		) );

		if ( ! $query->have_posts() ) return;
		$lines = array( __( 'Your highest-priority content updates:', 'wordpress-content-decay-monitor' ), '' );
		foreach ( $query->posts as $post ) {
			$score   = (int) get_post_meta( $post->ID, WCDM_Analyzer::SCORE_META, true );
			$lines[] = sprintf( '%d/100 — %s — %s', $score, get_the_title( $post ), get_edit_post_link( $post->ID, '' ) );
		}
		$lines[] = '';
		$lines[] = admin_url( 'admin.php?page=wcdm-dashboard' );

		wp_mail(
			sanitize_email( $settings['email_recipient'] ),
			sprintf( __( '[%s] Content Decay Report', 'wordpress-content-decay-monitor' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			implode( "\n", $lines )
		);
	}
}
