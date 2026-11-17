<?php
declare(strict_types=1);

namespace RivianTrackr\Newsletter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email HTML. Tables and inline styles for the light (paper and ink) look,
 * plus a prefers-color-scheme block that switches to ink and brass where the
 * mail app supports it (Apple Mail, iOS, Outlook.com via data-ogsc).
 * Gold is only ever a fill. Gold-as-text is brass in light mode.
 */
final class Template {

	public const LIGHT = [
		'page'    => '#f6f5f2',
		'card'    => '#ffffff',
		'raised'  => '#eeece7',
		'line'    => '#e3e0d9',
		'heading' => '#121316',
		'text'    => '#1a1c20',
		'text2'   => '#53565c',
		'muted'   => '#686b71',
		'accent'  => '#8a5800',
		'gold'    => '#fba919',
		'on_gold' => '#15130e',
	];

	public const DARK = [
		'page'    => '#0e1014',
		'card'    => '#16191e',
		'raised'  => '#1f2228',
		'line'    => '#3a3e45',
		'heading' => '#f6f4f0',
		'text'    => '#ece9e4',
		'text2'   => '#a6a39c',
		'muted'   => '#8f8c85',
		'accent'  => '#fba919',
	];

	private const SANS = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif";
	private const MONO = "'SF Mono',Monaco,'Cascadia Code','Roboto Mono',Consolas,'Courier New',monospace";

	/**
	 * Plain text intro → escaped paragraphs. Blank lines split paragraphs,
	 * single line breaks stay as <br>.
	 */
	public static function paragraphs( string $text, string $style ): string {
		$text  = trim( str_replace( "\r", '', $text ) );
		$parts = '' === $text ? [] : preg_split( "/\n\s*\n/", $text );
		$html  = '';
		foreach ( (array) $parts as $part ) {
			$part = trim( (string) $part );
			if ( '' === $part ) {
				continue;
			}
			$lines = array_map( 'esc_html', explode( "\n", $part ) );
			$html .= '<p class="rtnl-t" style="' . $style . '">' . implode( '<br>', $lines ) . '</p>';
		}
		return $html;
	}

	/**
	 * The weekly issue.
	 *
	 * @param array<string,mixed> $d name, subject, preview_text, date_label,
	 *                               intro, updates[], stories[], site_url,
	 *                               site_label, postal_address, reason,
	 *                               unsubscribe_url, mirror_url, and
	 *                               signup_url for the web version.
	 *                               Items: title, url, summary, image?, image_alt?, label?.
	 */
	public static function issue( array $d ): string {
		$c       = self::LIGHT;
		$sans    = self::SANS;
		$mono    = self::MONO;
		$updates = (array) ( $d['updates'] ?? [] );
		$stories = (array) ( $d['stories'] ?? [] );

		$body = '';

		$intro = self::paragraphs( (string) ( $d['intro'] ?? '' ), "margin:0 0 16px;font:400 16px/1.6 {$sans};color:{$c['text']};" );
		if ( '' !== $intro ) {
			$body .= '<tr><td class="rtnl-pad" style="padding:28px 32px 12px;">' . $intro . '</td></tr>';
		}

		if ( ! empty( $updates ) ) {
			$body .= self::section_title( __( 'Software updates', 'riviantrackr-newsletter' ) );
			foreach ( $updates as $item ) {
				$body .= '<tr><td class="rtnl-pad" style="padding:0 32px 16px;">'
					. '<a class="rtnl-h" href="' . esc_url( (string) $item['url'] ) . '" style="font:600 17px/1.35 ' . $mono . ';color:' . $c['heading'] . ';text-decoration:none;">' . esc_html( (string) $item['title'] ) . '</a>'
					. ( '' !== (string) ( $item['summary'] ?? '' ) ? '<p class="rtnl-t2" style="margin:4px 0 0;font:400 15px/1.55 ' . $sans . ';color:' . $c['text2'] . ';">' . esc_html( (string) $item['summary'] ) . '</p>' : '' )
					. '</td></tr>';
			}
		}

		if ( ! empty( $stories ) ) {
			$body .= self::section_title( __( 'This week on RivianTrackr', 'riviantrackr-newsletter' ) );
			foreach ( array_values( $stories ) as $i => $item ) {
				$body .= self::story( $item, 0 === $i, $i > 0 );
			}
		}

		$site_url = esc_url( (string) ( $d['site_url'] ?? '' ) );
		$body    .= '<tr><td class="rtnl-pad" style="padding:12px 32px 32px;">'
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td style="border-radius:8px;background:' . $c['gold'] . ';">'
			. '<a href="' . $site_url . '" style="display:inline-block;padding:12px 20px;font:600 15px/1 ' . $sans . ';color:' . $c['on_gold'] . ';text-decoration:none;border-radius:8px;">'
			. esc_html__( 'More on RivianTrackr', 'riviantrackr-newsletter' ) . '</a></td></tr></table></td></tr>';

		$address = trim( (string) ( $d['postal_address'] ?? '' ) );
		if ( '' !== (string) ( $d['signup_url'] ?? '' ) ) {
			// Web version: invite the reader to subscribe instead of unsubscribe links.
			$footer = '<p class="rtnl-m" style="margin:0 0 8px;">'
				. '<a class="rtnl-a" href="' . esc_url( (string) $d['signup_url'] ) . '" style="color:' . $c['accent'] . ';text-decoration:underline;">'
				/* translators: %s: newsletter name */
				. esc_html( sprintf( __( 'Get %s in your inbox', 'riviantrackr-newsletter' ), (string) ( $d['name'] ?? '' ) ) ) . '</a></p>';
			return self::document(
				(string) ( $d['subject'] ?? '' ),
				'',
				self::masthead( (string) ( $d['name'] ?? '' ), (string) ( $d['date_label'] ?? '' ), $site_url ),
				$body,
				$footer
			);
		}
		$footer = '<p class="rtnl-m" style="margin:0 0 8px;">' . esc_html( (string) ( $d['reason'] ?? '' ) ) . '</p>'
			. '<p class="rtnl-m" style="margin:0 0 8px;">'
			. '<a class="rtnl-a" href="' . self::href( (string) ( $d['mirror_url'] ?? '' ) ) . '" style="color:' . $c['accent'] . ';text-decoration:underline;">' . esc_html__( 'View in browser', 'riviantrackr-newsletter' ) . '</a>'
			. ' &nbsp;·&nbsp; '
			. '<a class="rtnl-a" href="' . self::href( (string) ( $d['unsubscribe_url'] ?? '' ) ) . '" style="color:' . $c['accent'] . ';text-decoration:underline;">' . esc_html__( 'Unsubscribe', 'riviantrackr-newsletter' ) . '</a>'
			. '</p>'
			. ( '' !== $address ? '<p class="rtnl-m" style="margin:0;">' . implode( '<br>', array_map( 'esc_html', explode( "\n", $address ) ) ) . '</p>' : '' );

		return self::document(
			(string) ( $d['subject'] ?? '' ),
			(string) ( $d['preview_text'] ?? '' ),
			self::masthead( (string) ( $d['name'] ?? '' ), (string) ( $d['date_label'] ?? '' ), $site_url ),
			$body,
			$footer
		);
	}

	/**
	 * A one-message transactional email (confirm signup, member invite,
	 * draft ready) in the same look.
	 *
	 * @param array<string,string> $m name, subject, heading, body, button, url, footnote.
	 */
	public static function message( array $m ): string {
		$c    = self::LIGHT;
		$sans = self::SANS;
		$body = '<tr><td class="rtnl-pad" style="padding:28px 32px 8px;">'
			. '<h1 class="rtnl-h" style="margin:0 0 12px;font:700 22px/1.3 ' . $sans . ';letter-spacing:-0.3px;color:' . $c['heading'] . ';">' . esc_html( $m['heading'] ?? '' ) . '</h1>'
			. self::paragraphs( $m['body'] ?? '', "margin:0 0 16px;font:400 16px/1.6 {$sans};color:{$c['text']};" )
			. '</td></tr>';
		if ( '' !== ( $m['url'] ?? '' ) ) {
			$body .= '<tr><td class="rtnl-pad" style="padding:0 32px 28px;">'
				. '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td style="border-radius:8px;background:' . $c['gold'] . ';">'
				. '<a href="' . esc_url( $m['url'] ) . '" style="display:inline-block;padding:12px 20px;font:600 15px/1 ' . $sans . ';color:' . $c['on_gold'] . ';text-decoration:none;border-radius:8px;">' . esc_html( $m['button'] ?? '' ) . '</a>'
				. '</td></tr></table></td></tr>';
		}
		$footer = '' !== ( $m['footnote'] ?? '' ) ? '<p class="rtnl-m" style="margin:0;">' . esc_html( $m['footnote'] ) . '</p>' : '';
		return self::document( $m['subject'] ?? '', '', self::masthead( $m['name'] ?? '', '', '' ), $body, $footer );
	}

	/**
	 * Escaped URL, letting Brevo merge tags such as {{ unsubscribe }} through.
	 */
	public static function href( string $url ): string {
		if ( preg_match( '/^\{\{\s*[a-z_]+\s*\}\}$/', $url ) ) {
			return $url;
		}
		return esc_url( $url );
	}

	private static function section_title( string $title ): string {
		$c = self::LIGHT;
		return '<tr><td class="rtnl-pad" style="padding:8px 32px 12px;">'
			. '<h2 class="rtnl-h rtnl-line" style="margin:0;padding-top:20px;border-top:1px solid ' . $c['line'] . ';font:700 18px/1.3 ' . self::SANS . ';color:' . $c['heading'] . ';">' . esc_html( $title ) . '</h2>'
			. '</td></tr>';
	}

	/**
	 * @param array<string,mixed> $item title, url, summary, image?, image_alt?, label?.
	 */
	private static function story( array $item, bool $lead, bool $rule ): string {
		$c       = self::LIGHT;
		$sans    = self::SANS;
		$url     = esc_url( (string) $item['url'] );
		$image   = (string) ( $item['image'] ?? '' );
		$summary = (string) ( $item['summary'] ?? '' );
		$label   = (string) ( $item['label'] ?? '' );

		$html = '<tr><td class="rtnl-pad" style="padding:0 32px 18px;">';
		if ( $rule ) {
			$html .= '<div class="rtnl-line" style="border-top:1px solid ' . $c['line'] . ';height:0;line-height:0;font-size:0;margin:0 0 18px;">&nbsp;</div>';
		}
		if ( $lead && '' !== $image ) {
			$html .= '<a href="' . $url . '"><img src="' . esc_url( $image ) . '" width="536" alt="' . esc_attr( (string) ( $item['image_alt'] ?? '' ) ) . '" style="display:block;width:100%;max-width:536px;height:auto;border:0;border-radius:8px;margin:0 0 14px;background:' . $c['raised'] . ';"></a>';
		}
		if ( '' !== $label ) {
			$html .= '<p class="rtnl-a" style="margin:0 0 4px;font:600 13px/1.3 ' . $sans . ';color:' . $c['accent'] . ';">' . esc_html( $label ) . '</p>';
		}
		$size  = $lead ? '21px/1.3' : '17px/1.35';
		$html .= '<a class="rtnl-h" href="' . $url . '" style="font:700 ' . $size . ' ' . $sans . ';color:' . $c['heading'] . ';text-decoration:none;' . ( $lead ? 'letter-spacing:-0.3px;' : '' ) . '">' . esc_html( (string) $item['title'] ) . '</a>';
		if ( '' !== $summary ) {
			$html .= '<p class="rtnl-t2" style="margin:6px 0 0;font:400 15px/1.55 ' . $sans . ';color:' . $c['text2'] . ';">' . esc_html( $summary ) . '</p>';
		}
		return $html . '</td></tr>';
	}

	private static function masthead( string $name, string $date_label, string $site_url ): string {
		$c    = self::LIGHT;
		$name = '' !== $name ? esc_html( $name ) : 'RivianTrackr';
		$name = '' !== $site_url ? '<a class="rtnl-h" href="' . $site_url . '" style="color:' . $c['heading'] . ';text-decoration:none;">' . $name . '</a>' : $name;
		return '<tr><td class="rtnl-pad" style="padding:24px 32px 0;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
			. '<td class="rtnl-h" style="font:700 20px/1.2 ' . self::SANS . ';letter-spacing:-0.3px;color:' . $c['heading'] . ';">' . $name . '</td>'
			. ( '' !== $date_label ? '<td align="right" class="rtnl-m" style="font:500 13px/1.2 ' . self::MONO . ';color:' . $c['muted'] . ';white-space:nowrap;">' . esc_html( $date_label ) . '</td>' : '' )
			. '</tr></table></td></tr>';
	}

	private static function document( string $subject, string $preview, string $masthead, string $body, string $footer ): string {
		$c       = self::LIGHT;
		$d       = self::DARK;
		$sans    = self::SANS;
		$preview = '' !== $preview
			? '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:' . $c['page'] . ';">' . esc_html( $preview ) . str_repeat( '&#8204;&nbsp;', 40 ) . '</div>'
			: '';

		$dark_css = ".rtnl-page{background:{$d['page']} !important}"
			. ".rtnl-card{background:{$d['card']} !important;border-color:{$d['line']} !important;border-top-color:" . self::LIGHT['gold'] . ' !important}'
			. ".rtnl-h,.rtnl-h a{color:{$d['heading']} !important}"
			. ".rtnl-t{color:{$d['text']} !important}"
			. ".rtnl-t2{color:{$d['text2']} !important}"
			. ".rtnl-m{color:{$d['muted']} !important}"
			. ".rtnl-a{color:{$d['accent']} !important}"
			. ".rtnl-line{border-color:{$d['line']} !important}";
		$ogsc     = (string) preg_replace( '/(^|\}|,)\./', '$1[data-ogsc] .', $dark_css );

		return '<!DOCTYPE html><html lang="en"><head>'
			. '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
			. '<title>' . esc_html( $subject ) . '</title>'
			. '<style>:root{color-scheme:light dark;supported-color-schemes:light dark}'
			. 'body{margin:0;padding:0;-webkit-text-size-adjust:100%}'
			. 'a{text-decoration-thickness:2px}'
			. '@media (max-width:600px){.rtnl-pad{padding-left:20px !important;padding-right:20px !important}}'
			. '@media (prefers-color-scheme:dark){' . $dark_css . '}'
			. $ogsc
			. '</style></head>'
			. '<body class="rtnl-page" style="margin:0;padding:0;background:' . $c['page'] . ';">'
			. $preview
			. '<table role="presentation" class="rtnl-page" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $c['page'] . ';">'
			. '<tr><td align="center" style="padding:24px 12px;">'
			. '<table role="presentation" class="rtnl-card" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:' . $c['card'] . ';border:1px solid ' . $c['line'] . ';border-radius:12px;border-top:4px solid ' . $c['gold'] . ';">'
			. $masthead
			. $body
			. '</table>'
			. ( '' !== $footer ? '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;"><tr><td class="rtnl-m" style="padding:20px 32px;font:400 12px/1.6 ' . $sans . ';color:' . $c['muted'] . ';">' . $footer . '</td></tr></table>' : '' )
			. '</td></tr></table></body></html>';
	}
}
