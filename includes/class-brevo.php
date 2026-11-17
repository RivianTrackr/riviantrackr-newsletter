<?php
declare(strict_types=1);

namespace RivianTrackr\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin client for the parts of the Brevo v3 API the newsletter uses:
 * email campaigns (create, test, send, schedule, suspend) and contacts
 * (add to list, remove from list, look up).
 */
final class Brevo {

	public const BASE = 'https://api.brevo.com/v3';

	public function __construct( private Settings $settings ) {}

	/**
	 * @param string                   $method HTTP method.
	 * @param string                   $path   Path under /v3.
	 * @param array<string,mixed>|null $body   JSON body.
	 * @return array<string,mixed>|\WP_Error Decoded response (empty array on 204).
	 */
	public function request( string $method, string $path, ?array $body = null ): array|\WP_Error {
		$key = $this->settings->api_key();
		if ( '' === $key ) {
			return new \WP_Error( 'rtnl_no_key', __( 'No Brevo API key is configured.', 'riviantrackr-newsletter' ) );
		}
		$args = [
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'api-key'      => $key,
				'accept'       => 'application/json',
				'content-type' => 'application/json',
			],
		];
		if ( null !== $body ) {
			$args['body'] = (string) wp_json_encode( $body );
		}
		$response = wp_remote_request( self::BASE . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = '' !== $raw ? json_decode( $raw, true ) : [];
		$decoded = is_array( $decoded ) ? $decoded : [];
		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $decoded['message'] ) ? (string) $decoded['message'] : sprintf( 'HTTP %d', $code );
			return new \WP_Error( 'rtnl_brevo_' . $code, $message, [ 'status' => $code ] );
		}
		return $decoded;
	}

	/**
	 * Campaign body for POST /emailCampaigns. Pure, for tests.
	 *
	 * @param array<string,mixed> $issue   subject, preview_text, html, name.
	 * @param array<string,mixed> $options Settings values.
	 * @return array<string,mixed>
	 */
	public static function campaign_payload( array $issue, array $options, ?string $scheduled_at = null ): array {
		$payload  = [
			'name'         => (string) $issue['name'],
			'subject'      => (string) $issue['subject'],
			'previewText'  => (string) ( $issue['preview_text'] ?? '' ),
			'sender'       => [
				'name'  => (string) $options['sender_name'],
				'email' => (string) $options['sender_email'],
			],
			'htmlContent'  => (string) $issue['html'],
			'recipients'   => [ 'listIds' => [ (int) $options['list_id'] ] ],
			'mirrorActive' => true,
			'utmCampaign'  => 'riviantrackr-weekly',
		];
		$reply_to = (string) ( $options['reply_to'] ?? '' );
		if ( '' !== $reply_to ) {
			$payload['replyTo'] = $reply_to;
		}
		if ( null !== $scheduled_at ) {
			$payload['scheduledAt'] = $scheduled_at;
		}
		return $payload;
	}

	/**
	 * @return int|\WP_Error Campaign ID.
	 */
	public function create_campaign( array $payload ): int|\WP_Error {
		$result = $this->request( 'POST', '/emailCampaigns', $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return (int) ( $result['id'] ?? 0 );
	}

	public function send_test( int $campaign_id, string $email ): true|\WP_Error {
		$result = $this->request( 'POST', '/emailCampaigns/' . $campaign_id . '/sendTest', [ 'emailTo' => [ $email ] ] );
		return is_wp_error( $result ) ? $result : true;
	}

	public function send_now( int $campaign_id ): true|\WP_Error {
		$result = $this->request( 'POST', '/emailCampaigns/' . $campaign_id . '/sendNow' );
		return is_wp_error( $result ) ? $result : true;
	}

	/** Stops a scheduled campaign from going out. */
	public function suspend( int $campaign_id ): true|\WP_Error {
		$result = $this->request( 'PUT', '/emailCampaigns/' . $campaign_id . '/status', [ 'status' => 'suspended' ] );
		return is_wp_error( $result ) ? $result : true;
	}

	public function delete_campaign( int $campaign_id ): true|\WP_Error {
		$result = $this->request( 'DELETE', '/emailCampaigns/' . $campaign_id );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_list( int $list_id ): array|\WP_Error {
		return $this->request( 'GET', '/contacts/lists/' . $list_id );
	}

	/**
	 * Adds the address to the list. Someone who unsubscribed before and now
	 * opts in again is taken off Brevo's blocklist, since this only runs on
	 * an explicit opt-in.
	 */
	public function subscribe( string $email, int $list_id ): true|\WP_Error {
		$result = $this->request(
			'POST',
			'/contacts',
			[
				'email'            => $email,
				'listIds'          => [ $list_id ],
				'updateEnabled'    => true,
				'emailBlacklisted' => false,
			]
		);
		return is_wp_error( $result ) ? $result : true;
	}

	public function unsubscribe( string $email, int $list_id ): true|\WP_Error {
		$result = $this->request( 'POST', '/contacts/lists/' . $list_id . '/contacts/remove', [ 'emails' => [ $email ] ] );
		if ( is_wp_error( $result ) ) {
			// Not on the list (or no such contact) already means unsubscribed.
			$status = (int) ( $result->get_error_data()['status'] ?? 0 );
			return in_array( $status, [ 400, 404 ], true ) ? true : $result;
		}
		return true;
	}

	/**
	 * Whether the address gets the newsletter: true, false, or WP_Error when
	 * Brevo can't be reached.
	 */
	public function is_subscribed( string $email, int $list_id ): bool|\WP_Error {
		$result = $this->request( 'GET', '/contacts/' . rawurlencode( $email ) );
		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 0 );
			return 404 === $status ? false : $result;
		}
		return self::contact_on_list( $result, $list_id );
	}

	/**
	 * @param array<string,mixed> $contact GET /contacts/{email} response.
	 */
	public static function contact_on_list( array $contact, int $list_id ): bool {
		if ( ! empty( $contact['emailBlacklisted'] ) ) {
			return false;
		}
		$lists = array_map( 'intval', (array) ( $contact['listIds'] ?? [] ) );
		return in_array( $list_id, $lists, true );
	}
}
