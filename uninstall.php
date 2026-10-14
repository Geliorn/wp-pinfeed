<?php
/**
 * Removes everything the plugin created: its options, the feed posts (they
 * are plugin configuration, not site content), the per-post and per-term
 * meta and the generated pin images. Pins already created on Pinterest are
 * unaffected — Pinterest stores its own copy of the image.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( 'wppf_settings', 'wppf_log', 'wppf_auto_feed', 'wppf_image_presets', 'wppf_infographic_presets', 'wppf_cache_ver', 'wppf_rewrite_ver', 'wppf_last_tick' ) as $option ) {
	delete_option( $option );
}

// Feed XML caches and LLM back-off markers.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_wppf\_%' OR option_name LIKE '\_transient\_timeout\_wppf\_%'" ); // phpcs:ignore

$feeds = get_posts(
	array(
		'post_type'      => 'pinfeed',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $feeds as $feed_id ) {
	wp_delete_post( $feed_id, true );
}

foreach ( array( '_wppf_llm', '_wppf_theses', '_wppf_theses_manual', '_wppf_image_id', '_wppf_pin_title', '_wppf_pin_description', '_wppf_exclude' ) as $key ) {
	delete_post_meta_by_key( $key );
}
$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key IN ('wppf_image_id', 'wppf_last_fetch_at')" ); // phpcs:ignore

wp_clear_scheduled_hook( 'wppf_tick_event' );

// Generated images.
$upload = wp_upload_dir();
$base   = trailingslashit( $upload['basedir'] ) . 'wppf-pins';
if ( is_dir( $base ) ) {
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() ); // phpcs:ignore
	}
	@rmdir( $base ); // phpcs:ignore
}
