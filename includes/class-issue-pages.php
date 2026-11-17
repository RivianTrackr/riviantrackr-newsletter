<?php
declare(strict_types=1);

namespace RivianTrackr\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Web versions of sent issues at /newsletter/issue/<date>/, and the
 * "Read the latest issue" link (?rtnl_latest=1 on the newsletter page),
 * which redirects at click time so a cached page never points at an old one.
 * Issue pages are noindex: they repeat article summaries.
 */
final class IssuePages {

	public const LATEST_ARG = 'rtnl_latest';

	public function __construct( private Settings $settings, private IssueBuilder $builder ) {}

	public function register_hooks(): void {
		add_action( 'template_redirect', [ $this, 'route' ], 1 );
		add_filter( 'wp_robots', [ $this, 'robots' ] );
		add_filter( 'wp_sitemaps_post_types', [ $this, 'sitemaps' ] );
		add_filter( 'cloudflare_purge_by_url', [ $this, 'purge_urls' ], 10, 2 );
	}

	public static function latest_url( string $page_url ): string {
		return add_query_arg( self::LATEST_ARG, '1', $page_url );
	}

	public function route(): void {
		if ( isset( $_GET[ self::LATEST_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect.
			$latest = IssueBuilder::latest_sent();
			nocache_headers();
			wp_safe_redirect( $latest ? (string) get_permalink( $latest ) : ( $this->settings->page_url() ?: home_url( '/' ) ), 302 );
			exit;
		}

		if ( ! is_singular( IssueBuilder::POST_TYPE ) ) {
			return;
		}
		$issue = (int) get_queried_object_id();
		if ( 'sent' !== IssueBuilder::status( $issue ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}

		$data               = $this->builder->data( $issue, true );
		$data['signup_url'] = $this->settings->page_url() ?: home_url( '/' );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, follow' );
		echo Template::issue( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template escapes its parts.
		exit;
	}

	/**
	 * @param array<string,mixed> $robots Robots directives.
	 * @return array<string,mixed>
	 */
	public function robots( array $robots ): array {
		if ( is_singular( IssueBuilder::POST_TYPE ) ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}

	/**
	 * @param array<string,mixed> $types Post types in core sitemaps.
	 * @return array<string,mixed>
	 */
	public function sitemaps( array $types ): array {
		unset( $types[ IssueBuilder::POST_TYPE ] );
		return $types;
	}

	/**
	 * When an issue is scheduled or sent, also purge the newsletter page so
	 * its "Read the latest issue" link appears for everyone.
	 *
	 * @param string[]   $urls    URLs the Cloudflare plugin will purge.
	 * @param int|string $post_id The post that changed.
	 * @return string[]
	 */
	public function purge_urls( $urls, $post_id ): array {
		$urls = is_array( $urls ) ? $urls : [];
		if ( IssueBuilder::POST_TYPE === get_post_type( (int) $post_id ) ) {
			$page = $this->settings->page_url();
			if ( '' !== $page ) {
				$urls[] = $page;
			}
		}
		return $urls;
	}
}
