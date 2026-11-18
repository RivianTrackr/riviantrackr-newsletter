<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Newsletter\Template;

final class TemplateTest extends TestCase {

	/** @return array<string,mixed> */
	private function issue( array $overrides = [] ): array {
		return array_merge(
			[
				'name'            => 'RivianTrackr Weekly',
				'subject'         => 'RivianTrackr Weekly: R2 wins SUV of the Year',
				'preview_text'    => 'MotorTrend named the R2 its 2027 SUV of the Year.',
				'date_label'      => 'Nov 20, 2026',
				'intro'           => "Big week.\n\nSecond paragraph\nwith a break.",
				'updates'         => [
					[ 'title' => '2026.41', 'url' => 'https://riviantrackr.com/2026-41/', 'summary' => 'Alternative routes and spoken alerts.' ],
				],
				'stories'         => [
					[ 'title' => 'R2 wins <SUV> of the Year', 'url' => 'https://riviantrackr.com/r2/', 'summary' => 'MotorTrend named it.', 'image' => 'https://riviantrackr.com/r2.jpg', 'image_alt' => 'R2' ],
					[ 'title' => 'Autonomy invites', 'url' => 'https://riviantrackr.com/autonomy/', 'summary' => 'Early access emails.' ],
				],
				'site_url'        => 'https://riviantrackr.com/',
				'postal_address'  => "RivianTrackr\nPO Box 123\nAnytown, NY 10001",
				'reason'          => 'You’re getting RivianTrackr Weekly because you signed up at riviantrackr.com.',
				'unsubscribe_url' => '{{ unsubscribe }}',
				'mirror_url'      => '{{ mirror }}',
			],
			$overrides
		);
	}

	public function test_contains_sections_and_escapes_titles(): void {
		$html = Template::issue( $this->issue() );
		$this->assertStringContainsString( '>Software update</p>', $html );
		$this->assertStringContainsString( '>Top story</p>', $html );
		$this->assertStringContainsString( '>More this week</p>', $html );
		$this->assertStringContainsString( 'R2 wins &lt;SUV&gt; of the Year', $html );
		$this->assertStringNotContainsString( '<SUV>', $html );
		$this->assertStringContainsString( '2026.41', $html );
	}

	public function test_logo_signature_and_thumbnails(): void {
		$stories                = $this->issue()['stories'];
		$stories[1]['thumb']    = 'https://riviantrackr.com/a-300x169.jpg';
		$html                   = Template::issue( $this->issue( [ 'stories' => $stories, 'logo_url' => 'https://riviantrackr.com/logo.png', 'signature' => 'Jose' ] ) );
		$this->assertStringContainsString( 'src="https://riviantrackr.com/logo.png"', $html );
		$this->assertStringContainsString( '>Jose</p>', $html );
		$this->assertStringContainsString( 'src="https://riviantrackr.com/a-300x169.jpg" width="112"', $html );
	}

	public function test_footer_social_icons(): void {
		$html = Template::issue( $this->issue( [ 'socials' => [ [ 'name' => 'YouTube', 'url' => 'https://www.youtube.com/@RivianTrackr', 'icon' => 'https://riviantrackr.com/yt.png' ], [ 'name' => 'X', 'url' => 'https://x.com/RivianTrackr', 'icon' => 'https://riviantrackr.com/x.png' ] ] ] ) );
		$this->assertStringContainsString( 'href="https://www.youtube.com/@RivianTrackr"', $html );
		$this->assertStringContainsString( 'alt="YouTube"', $html );
		$this->assertSame( 2, substr_count( $html, 'width="22" height="22"' ) );
		$this->assertSame( '', Template::socials( [] ) );
	}

	public function test_brevo_merge_tags_survive_escaping(): void {
		$html = Template::issue( $this->issue() );
		$this->assertStringContainsString( 'href="{{ unsubscribe }}"', $html );
		$this->assertStringContainsString( 'href="{{ mirror }}"', $html );
	}

	public function test_intro_paragraphs_and_line_breaks(): void {
		$html = Template::issue( $this->issue() );
		$this->assertStringContainsString( '>Big week.</p>', $html );
		$this->assertStringContainsString( 'Second paragraph<br>with a break.</p>', $html );
	}

	public function test_only_lead_story_has_image(): void {
		$stories   = $this->issue()['stories'];
		$stories[] = [ 'title' => 'Third', 'url' => 'https://riviantrackr.com/3/', 'summary' => '', 'image' => 'https://riviantrackr.com/3.jpg' ];
		$html      = Template::issue( $this->issue( [ 'stories' => $stories ] ) );
		$this->assertSame( 1, substr_count( $html, '<img ' ) );
		$this->assertStringContainsString( 'alt="R2"', $html );
	}

	public function test_empty_sections_are_left_out(): void {
		$html = Template::issue( $this->issue( [ 'updates' => [], 'intro' => '' ] ) );
		$this->assertStringNotContainsString( '>Software update</p>', $html );
		$this->assertStringContainsString( '>Top story</p>', $html );
	}

	public function test_footer_has_address_lines(): void {
		$html = Template::issue( $this->issue() );
		$this->assertStringContainsString( 'RivianTrackr<br>PO Box 123<br>Anytown, NY 10001', $html );
	}

	public function test_gold_is_never_text_color_in_light_mode(): void {
		$html  = Template::issue( $this->issue() );
		$light = explode( '<style>', $html )[0] . explode( '</style>', $html )[1];
		$this->assertDoesNotMatchRegularExpression( '/(?<![-a-z])color:\s*#fba919/i', $light );
	}

	public function test_dark_mode_styles_present(): void {
		$html = Template::issue( $this->issue() );
		$this->assertStringContainsString( '@media (prefers-color-scheme:dark)', $html );
		$this->assertStringContainsString( '[data-ogsc] .rtnl-page', $html );
		$this->assertStringContainsString( 'name="color-scheme" content="light dark"', $html );
	}

	public function test_every_outlook_dark_selector_is_scoped(): void {
		$html = Template::issue( $this->issue() );
		$css  = explode( '</style>', explode( '<style>', $html )[1] )[0];
		$ogsc = substr( $css, strpos( $css, '[data-ogsc]' ) );
		$this->assertDoesNotMatchRegularExpression( '/(^|\}|,)\.rtnl-/', $ogsc );
	}

	public function test_web_version_invites_instead_of_unsubscribe(): void {
		$html = Template::issue( $this->issue( [ 'signup_url' => 'https://riviantrackr.com/newsletter/' ] ) );
		$this->assertStringContainsString( 'Get RivianTrackr Weekly in your inbox', $html );
		$this->assertStringContainsString( 'href="https://riviantrackr.com/newsletter/"', $html );
		$this->assertStringNotContainsString( '{{ unsubscribe }}', $html );
		$this->assertStringNotContainsString( 'PO Box 123', $html );
	}

	public function test_message_has_button_and_heading(): void {
		$html = Template::message(
			[
				'name'     => 'RivianTrackr Weekly',
				'subject'  => 'Confirm',
				'heading'  => 'One click',
				'body'     => 'Hello',
				'button'   => 'Confirm my email',
				'url'      => 'https://riviantrackr.com/newsletter/?rtnl_confirm=abc',
				'footnote' => 'Ignore if not you.',
			]
		);
		$this->assertStringContainsString( 'Confirm my email', $html );
		$this->assertStringContainsString( 'rtnl_confirm=abc', $html );
		$this->assertStringContainsString( 'Ignore if not you.', $html );
	}

	public function test_href_only_passes_known_merge_tag_shape(): void {
		$this->assertSame( '{{ unsubscribe }}', Template::href( '{{ unsubscribe }}' ) );
		$this->assertStringStartsWith( 'http://', Template::href( '{{ x }}"><script>' ) );
	}
}
