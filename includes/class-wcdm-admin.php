<?php
defined( 'ABSPATH' ) || exit;

final class WCDM_Admin {
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'update_option_wcdm_settings', array( $this, 'settings_updated' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_wcdm_export_csv', array( $this, 'export_csv' ) );
		add_action( 'admin_post_wcdm_mark_reviewed', array( $this, 'mark_reviewed' ) );
		add_action( 'admin_post_wcdm_toggle_exclude', array( $this, 'toggle_exclude' ) );
		add_action( 'admin_post_wcdm_run_scan', array( $this, 'run_scan' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ), 30, 2 );
		add_action( 'admin_notices', array( $this, 'overdue_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WCDM_FILE ), array( $this, 'plugin_links' ) );

		foreach ( WCDM_Analyzer::monitored_post_types() as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( $this, 'add_column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
			add_filter( "bulk_actions-edit-{$type}", array( $this, 'bulk_actions' ) );
			add_filter( "handle_bulk_actions-edit-{$type}", array( $this, 'handle_bulk_actions' ), 10, 3 );
		}
		add_action( 'restrict_manage_posts', array( $this, 'status_filter' ) );
		add_action( 'pre_get_posts', array( $this, 'apply_status_filter' ) );
	}

	public function add_meta_box() {
		foreach ( WCDM_Analyzer::monitored_post_types() as $type ) {
			add_meta_box( 'wcdm-content-health', __( 'Content Health & Review', 'wordpress-content-decay-monitor' ), array( $this, 'meta_box' ), $type, 'side', 'high' );
		}
	}

	public function meta_box( $post ) {
		wp_nonce_field( 'wcdm_save_meta_' . $post->ID, 'wcdm_meta_nonce' );
		$score    = get_post_meta( $post->ID, WCDM_Analyzer::SCORE_META, true );
		$status   = get_post_meta( $post->ID, WCDM_Analyzer::STATUS_META, true );
		$details  = (array) get_post_meta( $post->ID, WCDM_Analyzer::DETAILS_META, true );
		$interval = (int) get_post_meta( $post->ID, WCDM_Analyzer::INTERVAL_META, true );
		$due      = get_post_meta( $post->ID, WCDM_Analyzer::DUE_META, true );
		$notes    = get_post_meta( $post->ID, WCDM_Analyzer::NOTES_META, true );
		$excluded = (bool) get_post_meta( $post->ID, WCDM_Analyzer::EXCLUDE_META, true );
		?>
		<div class="wcdm-metabox">
			<?php if ( '' !== $score && ! $excluded ) : ?><p><span class="wcdm-score wcdm-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $score ); ?></span> <strong><?php echo esc_html( WCDM_Analyzer::status_label( $status ) ); ?></strong></p><?php endif; ?>
			<?php if ( ! empty( $details['reasons'] ) ) : ?><ul class="wcdm-signals"><?php foreach ( array_slice( (array) $details['reasons'], 0, 4 ) as $reason ) : ?><li><?php echo esc_html( $reason ); ?></li><?php endforeach; ?></ul><?php endif; ?>
			<p><label for="wcdm_review_interval"><strong><?php esc_html_e( 'Review every', 'wordpress-content-decay-monitor' ); ?></strong></label><select class="widefat" id="wcdm_review_interval" name="wcdm_review_interval"><option value="0"><?php esc_html_e( 'Use age thresholds only', 'wordpress-content-decay-monitor' ); ?></option><?php foreach ( array( 30, 60, 90, 180, 365, 730 ) as $days ) : ?><option value="<?php echo esc_attr( $days ); ?>" <?php selected( $interval, $days ); ?>><?php printf( esc_html__( '%d days', 'wordpress-content-decay-monitor' ), $days ); ?></option><?php endforeach; ?></select></p>
			<?php if ( $due ) : ?><p><strong><?php esc_html_e( 'Next review:', 'wordpress-content-decay-monitor' ); ?></strong> <?php echo esc_html( $due ); ?></p><?php endif; ?>
			<p><label for="wcdm_notes"><strong><?php esc_html_e( 'SEO maintenance notes', 'wordpress-content-decay-monitor' ); ?></strong></label><textarea class="widefat" rows="4" id="wcdm_notes" name="wcdm_notes" maxlength="2000"><?php echo esc_textarea( $notes ); ?></textarea></p>
			<p><label><input type="checkbox" name="wcdm_excluded" value="1" <?php checked( $excluded ); ?>> <?php esc_html_e( 'Exclude from monitoring', 'wordpress-content-decay-monitor' ); ?></label></p>
			<?php if ( 'publish' === $post->post_status && ! $excluded ) : ?><p><a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcdm_mark_reviewed&post_id=' . $post->ID ), 'wcdm_mark_reviewed_' . $post->ID ) ); ?>"><?php esc_html_e( 'Mark reviewed today', 'wordpress-content-decay-monitor' ); ?></a></p><?php endif; ?>
		</div><?php
	}

	public function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['wcdm_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcdm_meta_nonce'] ) ), 'wcdm_save_meta_' . $post_id ) ) return;
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) return;
		if ( ! in_array( $post->post_type, WCDM_Analyzer::monitored_post_types(), true ) ) return;
		$interval = absint( $_POST['wcdm_review_interval'] ?? 0 );
		$old_interval = (int) get_post_meta( $post_id, WCDM_Analyzer::INTERVAL_META, true );
		if ( $interval !== $old_interval ) WCDM_Analyzer::set_review_schedule( $post_id, $interval );
		$notes = sanitize_textarea_field( wp_unslash( $_POST['wcdm_notes'] ?? '' ) );
		if ( '' === $notes ) delete_post_meta( $post_id, WCDM_Analyzer::NOTES_META ); else update_post_meta( $post_id, WCDM_Analyzer::NOTES_META, $notes );
		if ( ! empty( $_POST['wcdm_excluded'] ) ) update_post_meta( $post_id, WCDM_Analyzer::EXCLUDE_META, 1 ); else delete_post_meta( $post_id, WCDM_Analyzer::EXCLUDE_META );
		WCDM_Analyzer::instance()->analyze_and_store( $post_id );
	}

	public function menu() {
		add_menu_page(
			__( 'Content Decay', 'wordpress-content-decay-monitor' ),
			__( 'Content Decay', 'wordpress-content-decay-monitor' ),
			'edit_others_posts',
			'wcdm-dashboard',
			array( $this, 'dashboard' ),
			'dashicons-chart-line',
			58
		);
		add_submenu_page( 'wcdm-dashboard', __( 'Dashboard', 'wordpress-content-decay-monitor' ), __( 'Dashboard', 'wordpress-content-decay-monitor' ), 'edit_others_posts', 'wcdm-dashboard', array( $this, 'dashboard' ) );
		add_submenu_page( 'wcdm-dashboard', __( 'Settings', 'wordpress-content-decay-monitor' ), __( 'Settings', 'wordpress-content-decay-monitor' ), 'manage_options', 'wcdm-settings', array( $this, 'settings_page' ) );
	}

	public function assets( $hook ) {
		if ( false === strpos( $hook, 'wcdm' ) && 'edit.php' !== $hook ) return;
		wp_enqueue_style( 'wcdm-admin', WCDM_URL . 'assets/admin.css', array(), WCDM_VERSION );
		wp_enqueue_style( 'wcdm-workflow', WCDM_URL . 'assets/workflow.css', array( 'wcdm-admin' ), WCDM_VERSION );
	}

	public function plugin_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wcdm-dashboard' ) ) . '">' . esc_html__( 'Dashboard', 'wordpress-content-decay-monitor' ) . '</a>' );
		return $links;
	}

	public function register_settings() {
		register_setting( 'wcdm_settings_group', 'wcdm_settings', array( $this, 'sanitize_settings' ) );
	}

	public function sanitize_settings( $input ) {
		$defaults   = WCDM_Analyzer::default_settings();
		$valid_types = get_post_types( array( 'show_ui' => true ), 'names' );
		$output = array(
			'post_types'          => array_values( array_intersect( (array) ( $input['post_types'] ?? array() ), $valid_types ) ),
			'watch_days'          => max( 30, absint( $input['watch_days'] ?? $defaults['watch_days'] ) ),
			'stale_days'          => max( 60, absint( $input['stale_days'] ?? $defaults['stale_days'] ) ),
			'critical_days'       => max( 90, absint( $input['critical_days'] ?? $defaults['critical_days'] ) ),
			'min_words'           => max( 0, absint( $input['min_words'] ?? $defaults['min_words'] ) ),
			'email_enabled'       => empty( $input['email_enabled'] ) ? 0 : 1,
			'email_frequency'     => 'daily' === ( $input['email_frequency'] ?? '' ) ? 'daily' : 'weekly',
			'email_recipient'     => sanitize_email( $input['email_recipient'] ?? get_option( 'admin_email' ) ),
			'email_min_score'     => min( 100, max( 0, absint( $input['email_min_score'] ?? 55 ) ) ),
			'delete_on_uninstall' => empty( $input['delete_on_uninstall'] ) ? 0 : 1,
		);
		if ( $output['stale_days'] <= $output['watch_days'] ) $output['stale_days'] = $output['watch_days'] + 30;
		if ( $output['critical_days'] <= $output['stale_days'] ) $output['critical_days'] = $output['stale_days'] + 90;
		if ( empty( $output['post_types'] ) ) $output['post_types'] = array( 'post' );
		return $output;
	}

	public function settings_updated( $old_value, $new_value ) {
		WCDM_Cron::reschedule_email();
		if ( $old_value !== $new_value && ! wp_next_scheduled( WCDM_Cron::INITIAL_HOOK ) ) {
			wp_schedule_single_event( time() + 10, WCDM_Cron::INITIAL_HOOK );
		}
	}

	private function counts() {
		$counts = array( 'fresh' => 0, 'watch' => 0, 'stale' => 0, 'critical' => 0, 'overdue' => 0, 'excluded' => 0 );
		global $wpdb;
		$types = WCDM_Analyzer::monitored_post_types();
		if ( empty( $types ) ) return $counts;
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql = "SELECT pm.meta_value AS decay_status, COUNT(*) AS total
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s AND p.post_status = 'publish' AND p.post_type IN ($placeholders)
			GROUP BY pm.meta_value";
		$params = array_merge( array( WCDM_Analyzer::STATUS_META ), $types );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		foreach ( $rows as $row ) if ( isset( $counts[ $row->decay_status ] ) ) $counts[ $row->decay_status ] = (int) $row->total;
		$excluded_args = array_merge( array( WCDM_Analyzer::EXCLUDE_META ), $types );
		$counts['excluded'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = '1' AND p.post_status = 'publish' AND p.post_type IN ($placeholders)", $excluded_args ) );
		$overdue_args = array_merge( array( WCDM_Analyzer::DUE_META, current_time( 'Y-m-d' ) ), $types );
		$counts['overdue'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value < %s AND p.post_status = 'publish' AND p.post_type IN ($placeholders)", $overdue_args ) );
		return $counts;
	}

	public function dashboard() {
		if ( ! current_user_can( 'edit_others_posts' ) ) wp_die( esc_html__( 'You do not have permission to access this page.', 'wordpress-content-decay-monitor' ) );
		$counts = $this->counts();
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$status = sanitize_key( $_GET['decay_status'] ?? '' );
		$args   = array(
			'post_type'      => WCDM_Analyzer::monitored_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'paged'          => $paged,
			'meta_key'       => WCDM_Analyzer::SCORE_META,
			'orderby'        => 'meta_value_num',
			'order'          => 'DESC',
		);
		if ( 'excluded' === $status ) {
			unset( $args['meta_key'], $args['orderby'] );
			$args['orderby'] = 'modified';
			$args['meta_query'] = array( array( 'key' => WCDM_Analyzer::EXCLUDE_META, 'value' => 1 ) );
		} elseif ( 'overdue' === $status ) {
			$args['meta_query'] = array( array( 'key' => WCDM_Analyzer::DUE_META, 'value' => current_time( 'Y-m-d' ), 'compare' => '<', 'type' => 'DATE' ) );
		} elseif ( in_array( $status, array( 'fresh', 'watch', 'stale', 'critical' ), true ) ) {
			$args['meta_query'] = array( array( 'key' => WCDM_Analyzer::STATUS_META, 'value' => $status ) );
		}
		$query = new WP_Query( $args );
		?>
		<div class="wrap wcdm-wrap">
			<div class="wcdm-heading"><div><h1><?php esc_html_e( 'Content Decay Monitor', 'wordpress-content-decay-monitor' ); ?></h1><p><?php esc_html_e( 'Prioritize content updates using a transparent, actionable decay score.', 'wordpress-content-decay-monitor' ); ?></p></div>
				<div class="wcdm-actions"><?php if ( current_user_can( 'manage_options' ) ) : ?><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcdm_run_scan' ), 'wcdm_run_scan' ) ); ?>"><?php esc_html_e( 'Run full scan', 'wordpress-content-decay-monitor' ); ?></a><?php endif; ?><a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcdm_export_csv' ), 'wcdm_export_csv' ) ); ?>"><?php esc_html_e( 'Export CSV', 'wordpress-content-decay-monitor' ); ?></a></div>
			</div>
			<?php if ( isset( $_GET['wcdm_scanned'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Content scan completed.', 'wordpress-content-decay-monitor' ); ?></p></div><?php endif; ?>
			<div class="wcdm-cards">
				<?php foreach ( array( 'critical', 'stale', 'watch', 'fresh', 'overdue', 'excluded' ) as $key ) : ?>
				<a class="wcdm-card wcdm-<?php echo esc_attr( $key ); ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'wcdm-dashboard', 'decay_status' => $key ), admin_url( 'admin.php' ) ) ); ?>"><strong><?php echo esc_html( number_format_i18n( $counts[ $key ] ) ); ?></strong><span><?php echo esc_html( WCDM_Analyzer::status_label( $key ) ); ?></span></a>
				<?php endforeach; ?>
			</div>
			<div class="wcdm-meta"><?php printf( esc_html__( 'Last scan: %1$s — %2$s items analyzed', 'wordpress-content-decay-monitor' ), esc_html( get_option( 'wcdm_last_scan', __( 'Not yet', 'wordpress-content-decay-monitor' ) ) ), esc_html( number_format_i18n( (int) get_option( 'wcdm_last_scan_count', 0 ) ) ) ); ?></div>
			<div class="wcdm-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Content', 'wordpress-content-decay-monitor' ); ?></th><th><?php esc_html_e( 'Type', 'wordpress-content-decay-monitor' ); ?></th><th><?php esc_html_e( 'Score', 'wordpress-content-decay-monitor' ); ?></th><th><?php esc_html_e( 'Last reviewed', 'wordpress-content-decay-monitor' ); ?></th><th><?php esc_html_e( 'Signals', 'wordpress-content-decay-monitor' ); ?></th><th><?php esc_html_e( 'Actions', 'wordpress-content-decay-monitor' ); ?></th></tr></thead><tbody>
			<?php if ( $query->have_posts() ) : foreach ( $query->posts as $post ) : $details = (array) get_post_meta( $post->ID, WCDM_Analyzer::DETAILS_META, true ); $score = (int) get_post_meta( $post->ID, WCDM_Analyzer::SCORE_META, true ); $decay = get_post_meta( $post->ID, WCDM_Analyzer::STATUS_META, true ); ?>
			<tr><td><strong><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post ) ?: __( '(no title)', 'wordpress-content-decay-monitor' ) ); ?></a></strong><div class="row-actions"><a href="<?php echo esc_url( get_permalink( $post ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'wordpress-content-decay-monitor' ); ?></a></div></td><td><?php echo esc_html( get_post_type_object( $post->post_type )->labels->singular_name ); ?></td><td><?php if ( 'excluded' === $status ) : ?>—<?php else : ?><span class="wcdm-score wcdm-<?php echo esc_attr( $decay ); ?>"><?php echo esc_html( $score ); ?></span><?php endif; ?></td><td><?php echo esc_html( (int) ( $details['days_old'] ?? 0 ) ); ?> <?php esc_html_e( 'days ago', 'wordpress-content-decay-monitor' ); ?></td><td><?php echo esc_html( implode( ' • ', array_slice( (array) ( $details['reasons'] ?? array() ), 0, 2 ) ) ); ?></td><td><?php if ( 'excluded' !== $status ) : ?><a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcdm_mark_reviewed&post_id=' . $post->ID ), 'wcdm_mark_reviewed_' . $post->ID ) ); ?>"><?php esc_html_e( 'Mark reviewed', 'wordpress-content-decay-monitor' ); ?></a> <?php endif; ?><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcdm_toggle_exclude&post_id=' . $post->ID ), 'wcdm_toggle_exclude_' . $post->ID ) ); ?>"><?php echo esc_html( 'excluded' === $status ? __( 'Include', 'wordpress-content-decay-monitor' ) : __( 'Exclude', 'wordpress-content-decay-monitor' ) ); ?></a></td></tr>
			<?php endforeach; else : ?><tr><td colspan="6"><?php esc_html_e( 'No analyzed content found. Run a full scan to get started.', 'wordpress-content-decay-monitor' ); ?></td></tr><?php endif; ?>
			</tbody></table></div>
			<?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $query->max_num_pages, 'type' => 'list' ) ) ); ?>
		</div><?php
	}

	public function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$s = WCDM_Analyzer::settings();
		$types = get_post_types( array( 'show_ui' => true ), 'objects' );
		?>
		<div class="wrap wcdm-wrap"><h1><?php esc_html_e( 'Content Decay Settings', 'wordpress-content-decay-monitor' ); ?></h1><form method="post" action="options.php"><?php settings_fields( 'wcdm_settings_group' ); ?>
		<table class="form-table"><tr><th><?php esc_html_e( 'Content types', 'wordpress-content-decay-monitor' ); ?></th><td><?php foreach ( $types as $type ) : if ( 'attachment' === $type->name ) continue; ?><label class="wcdm-check"><input type="checkbox" name="wcdm_settings[post_types][]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, (array) $s['post_types'], true ) ); ?>> <?php echo esc_html( $type->labels->name ); ?></label><?php endforeach; ?></td></tr>
		<tr><th><?php esc_html_e( 'Age thresholds', 'wordpress-content-decay-monitor' ); ?></th><td><label><?php esc_html_e( 'Watch', 'wordpress-content-decay-monitor' ); ?> <input type="number" min="30" name="wcdm_settings[watch_days]" value="<?php echo esc_attr( $s['watch_days'] ); ?>"> <?php esc_html_e( 'days', 'wordpress-content-decay-monitor' ); ?></label><br><label><?php esc_html_e( 'Stale', 'wordpress-content-decay-monitor' ); ?> <input type="number" min="60" name="wcdm_settings[stale_days]" value="<?php echo esc_attr( $s['stale_days'] ); ?>"> <?php esc_html_e( 'days', 'wordpress-content-decay-monitor' ); ?></label><br><label><?php esc_html_e( 'Critical', 'wordpress-content-decay-monitor' ); ?> <input type="number" min="90" name="wcdm_settings[critical_days]" value="<?php echo esc_attr( $s['critical_days'] ); ?>"> <?php esc_html_e( 'days', 'wordpress-content-decay-monitor' ); ?></label></td></tr>
		<tr><th><?php esc_html_e( 'Preferred minimum words', 'wordpress-content-decay-monitor' ); ?></th><td><input type="number" min="0" name="wcdm_settings[min_words]" value="<?php echo esc_attr( $s['min_words'] ); ?>"></td></tr>
		<tr><th><?php esc_html_e( 'Email digest', 'wordpress-content-decay-monitor' ); ?></th><td><label><input type="checkbox" name="wcdm_settings[email_enabled]" value="1" <?php checked( $s['email_enabled'] ); ?>> <?php esc_html_e( 'Enable prioritized email reports', 'wordpress-content-decay-monitor' ); ?></label><p><input type="email" class="regular-text" name="wcdm_settings[email_recipient]" value="<?php echo esc_attr( $s['email_recipient'] ); ?>"></p><select name="wcdm_settings[email_frequency]"><option value="weekly" <?php selected( $s['email_frequency'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'wordpress-content-decay-monitor' ); ?></option><option value="daily" <?php selected( $s['email_frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'wordpress-content-decay-monitor' ); ?></option></select> <?php esc_html_e( 'Minimum score', 'wordpress-content-decay-monitor' ); ?> <input type="number" min="0" max="100" name="wcdm_settings[email_min_score]" value="<?php echo esc_attr( $s['email_min_score'] ); ?>"></td></tr>
		<tr><th><?php esc_html_e( 'Data removal', 'wordpress-content-decay-monitor' ); ?></th><td><label><input type="checkbox" name="wcdm_settings[delete_on_uninstall]" value="1" <?php checked( $s['delete_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete plugin settings and analysis metadata when uninstalled', 'wordpress-content-decay-monitor' ); ?></label></td></tr></table><?php submit_button(); ?></form></div><?php
	}

	public function add_column( $columns ) {
		$columns['wcdm_decay'] = __( 'Decay', 'wordpress-content-decay-monitor' );
		return $columns;
	}

	public function render_column( $column, $post_id ) {
		if ( 'wcdm_decay' !== $column ) return;
		if ( get_post_meta( $post_id, WCDM_Analyzer::EXCLUDE_META, true ) ) { echo '<span>—</span>'; return; }
		$score = get_post_meta( $post_id, WCDM_Analyzer::SCORE_META, true );
		$status = get_post_meta( $post_id, WCDM_Analyzer::STATUS_META, true );
		if ( '' === $score ) { echo '<span>—</span>'; return; }
		echo '<span class="wcdm-score wcdm-' . esc_attr( $status ) . '" title="' . esc_attr( WCDM_Analyzer::status_label( $status ) ) . '">' . esc_html( $score ) . '</span>';
	}

	public function status_filter( $post_type ) {
		if ( ! in_array( $post_type, WCDM_Analyzer::monitored_post_types(), true ) ) return;
		$current = sanitize_key( $_GET['wcdm_status'] ?? '' );
		echo '<select name="wcdm_status"><option value="">' . esc_html__( 'All decay statuses', 'wordpress-content-decay-monitor' ) . '</option>';
		foreach ( array( 'critical', 'stale', 'watch', 'fresh', 'overdue', 'excluded' ) as $status ) {
			$label = 'overdue' === $status ? __( 'Review overdue', 'wordpress-content-decay-monitor' ) : WCDM_Analyzer::status_label( $status );
			echo '<option value="' . esc_attr( $status ) . '" ' . selected( $current, $status, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public function apply_status_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) return;
		$status = sanitize_key( $_GET['wcdm_status'] ?? '' );
		if ( in_array( $status, array( 'critical', 'stale', 'watch', 'fresh' ), true ) ) {
			$query->set( 'meta_query', array( array( 'key' => WCDM_Analyzer::STATUS_META, 'value' => $status ) ) );
		} elseif ( 'overdue' === $status ) {
			$query->set( 'meta_query', array( array( 'key' => WCDM_Analyzer::DUE_META, 'value' => current_time( 'Y-m-d' ), 'compare' => '<', 'type' => 'DATE' ) ) );
		} elseif ( 'excluded' === $status ) {
			$query->set( 'meta_query', array( array( 'key' => WCDM_Analyzer::EXCLUDE_META, 'value' => 1 ) ) );
		}
	}

	public function bulk_actions( $actions ) {
		$actions['wcdm_mark_reviewed'] = __( 'Content Decay: Mark reviewed', 'wordpress-content-decay-monitor' );
		$actions['wcdm_exclude']       = __( 'Content Decay: Exclude', 'wordpress-content-decay-monitor' );
		$actions['wcdm_include']       = __( 'Content Decay: Include', 'wordpress-content-decay-monitor' );
		return $actions;
	}

	public function handle_bulk_actions( $redirect_url, $action, $post_ids ) {
		if ( ! in_array( $action, array( 'wcdm_mark_reviewed', 'wcdm_exclude', 'wcdm_include' ), true ) ) return $redirect_url;
		$changed = 0;
		foreach ( array_map( 'absint', $post_ids ) as $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) continue;
			if ( 'wcdm_mark_reviewed' === $action ) {
				update_post_meta( $post_id, WCDM_Analyzer::REVIEW_META, current_time( 'mysql', true ) );
				$interval = (int) get_post_meta( $post_id, WCDM_Analyzer::INTERVAL_META, true );
				if ( $interval ) WCDM_Analyzer::set_review_schedule( $post_id, $interval );
				WCDM_Analyzer::instance()->analyze_and_store( $post_id );
			} elseif ( 'wcdm_exclude' === $action ) {
				update_post_meta( $post_id, WCDM_Analyzer::EXCLUDE_META, 1 );
				delete_post_meta( $post_id, WCDM_Analyzer::SCORE_META );
				delete_post_meta( $post_id, WCDM_Analyzer::STATUS_META );
				delete_post_meta( $post_id, WCDM_Analyzer::DETAILS_META );
			} else {
				delete_post_meta( $post_id, WCDM_Analyzer::EXCLUDE_META );
				WCDM_Analyzer::instance()->analyze_and_store( $post_id );
			}
			$changed++;
		}
		return add_query_arg( 'wcdm_bulk_changed', $changed, $redirect_url );
	}

	public function overdue_notice() {
		if ( ! current_user_can( 'edit_others_posts' ) || get_current_screen() && 'toplevel_page_wcdm-dashboard' === get_current_screen()->id ) return;
		$count = $this->overdue_count();
		if ( $count < 1 ) return;
		$url = add_query_arg( array( 'page' => 'wcdm-dashboard', 'decay_status' => 'overdue' ), admin_url( 'admin.php' ) );
		echo '<div class="notice notice-warning"><p>' . wp_kses_post( sprintf( __( 'Content Decay Monitor: <strong>%1$d items</strong> are past their scheduled review date. <a href="%2$s">Review them now</a>.', 'wordpress-content-decay-monitor' ), $count, esc_url( $url ) ) ) . '</p></div>';
	}

	private function overdue_count() {
		$query = new WP_Query( array(
			'post_type'      => WCDM_Analyzer::monitored_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'meta_query'     => array(
				array( 'key' => WCDM_Analyzer::DUE_META, 'value' => current_time( 'Y-m-d' ), 'compare' => '<', 'type' => 'DATE' ),
				array( 'key' => WCDM_Analyzer::EXCLUDE_META, 'compare' => 'NOT EXISTS' ),
			),
		) );
		return (int) $query->found_posts;
	}

	public function mark_reviewed() {
		$post_id = absint( $_GET['post_id'] ?? 0 );
		check_admin_referer( 'wcdm_mark_reviewed_' . $post_id );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) wp_die( esc_html__( 'Permission denied.', 'wordpress-content-decay-monitor' ) );
		update_post_meta( $post_id, WCDM_Analyzer::REVIEW_META, current_time( 'mysql', true ) );
		$interval = (int) get_post_meta( $post_id, WCDM_Analyzer::INTERVAL_META, true );
		if ( $interval ) WCDM_Analyzer::set_review_schedule( $post_id, $interval );
		WCDM_Analyzer::instance()->analyze_and_store( $post_id );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=wcdm-dashboard' ) ); exit;
	}

	public function toggle_exclude() {
		$post_id = absint( $_GET['post_id'] ?? 0 );
		check_admin_referer( 'wcdm_toggle_exclude_' . $post_id );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) wp_die( esc_html__( 'Permission denied.', 'wordpress-content-decay-monitor' ) );
		if ( get_post_meta( $post_id, WCDM_Analyzer::EXCLUDE_META, true ) ) {
			delete_post_meta( $post_id, WCDM_Analyzer::EXCLUDE_META );
			WCDM_Analyzer::instance()->analyze_and_store( $post_id );
		} else {
			update_post_meta( $post_id, WCDM_Analyzer::EXCLUDE_META, 1 );
			delete_post_meta( $post_id, WCDM_Analyzer::SCORE_META );
			delete_post_meta( $post_id, WCDM_Analyzer::STATUS_META );
			delete_post_meta( $post_id, WCDM_Analyzer::DETAILS_META );
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=wcdm-dashboard' ) ); exit;
	}

	public function run_scan() {
		check_admin_referer( 'wcdm_run_scan' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Permission denied.', 'wordpress-content-decay-monitor' ) );
		WCDM_Cron::instance()->scan_all();
		wp_safe_redirect( add_query_arg( array( 'page' => 'wcdm-dashboard', 'wcdm_scanned' => 1 ), admin_url( 'admin.php' ) ) ); exit;
	}

	public function export_csv() {
		check_admin_referer( 'wcdm_export_csv' );
		if ( ! current_user_can( 'edit_others_posts' ) ) wp_die( esc_html__( 'Permission denied.', 'wordpress-content-decay-monitor' ) );
		$posts = get_posts( array( 'post_type' => WCDM_Analyzer::monitored_post_types(), 'post_status' => 'publish', 'numberposts' => -1, 'meta_key' => WCDM_Analyzer::SCORE_META, 'orderby' => 'meta_value_num', 'order' => 'DESC' ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=content-decay-report-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'ID', 'Title', 'Post Type', 'URL', 'Score', 'Status', 'Days Since Review', 'Next Review', 'Overdue Days', 'Review Interval', 'Word Count', 'Internal Links', 'External Links', 'Maintenance Notes', 'Reasons' ) );
		foreach ( $posts as $post ) {
			$d = (array) get_post_meta( $post->ID, WCDM_Analyzer::DETAILS_META, true );
			fputcsv( $out, array( $post->ID, get_the_title( $post ), $post->post_type, get_permalink( $post ), get_post_meta( $post->ID, WCDM_Analyzer::SCORE_META, true ), get_post_meta( $post->ID, WCDM_Analyzer::STATUS_META, true ), $d['days_old'] ?? 0, get_post_meta( $post->ID, WCDM_Analyzer::DUE_META, true ), $d['overdue_days'] ?? 0, get_post_meta( $post->ID, WCDM_Analyzer::INTERVAL_META, true ), $d['word_count'] ?? 0, $d['internal_links'] ?? 0, $d['external_links'] ?? 0, get_post_meta( $post->ID, WCDM_Analyzer::NOTES_META, true ), implode( ' | ', (array) ( $d['reasons'] ?? array() ) ) ) );
		}
		fclose( $out ); exit;
	}
}
