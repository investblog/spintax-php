<?php
/**
 * The placeholder restore — which of the two restores runs, and why it matters.
 *
 * Both the post-process shield (`\x00URL_0\x00` and friends) and the pipeline's host-construct
 * shield (`\x00HOST_0\x00`) put their placeholders back at the end. There are two ways to do that
 * and they are NOT interchangeable:
 *
 *   - SEQUENTIAL — `str_replace()` over arrays: every occurrence of the first key throughout the
 *     text, then the second, and so on. O(text x keys), which is what made both stages quadratic.
 *   - SINGLE PASS — `strtr()` with the map: one left-to-right scan, no rescanning of what it wrote.
 *     Linear, and 340x faster on a 950 KB render.
 *
 * They disagree on inputs nobody renders on purpose, and the engine picks between them by a guard:
 * no NUL from outside the shield => single pass, otherwise sequential. Every case below fails if
 * that guard is dropped in either direction — they are the discrimination, not decoration. The
 * golden corpus covers none of this, which is why they live here.
 *
 * @package Spintax\Tests
 */

declare(strict_types=1);

namespace Spintax\Tests;

use PHPUnit\Framework\TestCase;
use Spintax\Core\Engine\Parser;
use Spintax\Core\Render\Pipeline;

final class RestoreParityTest extends TestCase {

	/**
	 * A pipeline that shields `[host …]` and always takes the first branch.
	 */
	private function pipeline(): Pipeline {
		return new Pipeline(
			new Parser( static fn( int $min, int $max ): int => $min ),
			array(),
			null,
			array( '/\[host[^\]]*\]/' ),
			null
		);
	}

	/**
	 * A NUL the caller wrote sends post-process back to the sequential restore.
	 *
	 * Both inputs spell out a key the shield really minted. Sequential replaces every occurrence of
	 * that key — including the caller's own copy, and including copies that only appear once an
	 * earlier replacement has been made. A single pass sees neither, so both assertions fail if the
	 * guard is removed.
	 */
	public function test_a_caller_supplied_nul_keeps_the_sequential_restore(): void {
		$p = new Parser();

		// The URI shield swallows the caller's tokens into its own value, and restoring it puts
		// `\x00ABBR_1\x00` back into the text — where the ABBR key, replaced after it, finds it.
		$this->assertSame(
			"tel:+1-555-0100e.g.\x00NOPE_1\x00! e.g.",
			$p->post_process( "   tel:+1-555-0100\x00ABBR_1\x00\x00NOPE_1\x00! e.g." )
		);

		$this->assertSame(
			"http://x.io/p?q=1e.g. e.g.\x00DOM_3",
			$p->post_process( "http://x.io/p?q=1\x00ABBR_1\x00\t  e.g.\x00DOM_3\x00\t" )
		);
	}

	/**
	 * A key forged across two real tokens — with no NUL in the input at all.
	 *
	 * Placeholder delimiters are not owned by the token that placed them. Here the shield leaves
	 * `\x00ABBR_2\x00URL_0\x00URI_1\x00`, and the closing NUL of `ABBR_2` plus the caller's literal
	 * `URL_0` plus the opening NUL of `URI_1` spell `\x00URL_0\x00` — a key the shield did mint.
	 * Sequential replaces it and destroys both real tokens; the single pass tokenises `ABBR_2`
	 * first and never sees it.
	 *
	 * This is the one shape where the guard is not enough to make the two restores agree, so the
	 * engine has to choose. It restores as `@spintax/core` does — the reference takes the single
	 * pass here too.
	 */
	public function test_a_forged_key_restores_as_the_reference_does(): void {
		$this->assertSame(
			'https://a.io e.g. URL_0mailto:x@y.io',
			( new Parser() )->post_process( 'https://a.io e.g. URL_0mailto:x@y.io' )
		);
	}

	/**
	 * The same forged-key shape in the pipeline's host shield, and the same answer.
	 *
	 * The first `[host …]` mints `\x00HOST_0\x00`; the caller's literal `HOST_0`, sandwiched
	 * between the next two, spells that key a second time. Under the sequential restore the render
	 * comes back as `[host id="0"] \x00HOST_1[host id="0"]HOST_2\x00`.
	 */
	public function test_a_forged_host_key_restores_as_the_single_pass_does(): void {
		$this->assertSame(
			'[host id="0"] [host id="1"]HOST_0[host id="2"]',
			$this->pipeline()->render( '[host id="0"] [host id="1"]HOST_0[host id="2"]', array(), null, '', false )
		);
	}

	/**
	 * A NUL inside a host construct keeps the pipeline on the sequential restore.
	 *
	 * The first construct's own text spells the SECOND construct's key. Sequential restores
	 * `HOST_0` first and then rewrites what it just wrote, so the second construct lands inside the
	 * first. The single pass leaves the inner token alone.
	 */
	public function test_a_nul_in_a_host_construct_keeps_the_sequential_restore(): void {
		$this->assertSame(
			'[host id="[host id="2"]"] [host id="2"]',
			$this->pipeline()->render( "[host id=\"\x00HOST_1\x00\"] [host id=\"2\"]", array(), null, '', false )
		);
	}

	/**
	 * The guard reads the variable values too, not just the body.
	 *
	 * Expansion is the one place new text enters after the first shield — which is why a second
	 * shield pass runs there. A `#set` or `#def` value carrying a NUL therefore reaches the working
	 * text exactly as a NUL in the body would, and has to send the restore down the same path.
	 * Both bodies below are `%v%`: nothing but the value is carrying the NUL.
	 */
	public function test_the_guard_covers_a_nul_arriving_through_a_variable(): void {
		$expected = "\n" . '[host id="[host id="z"]"] [host id="z"]';

		$this->assertSame(
			$expected,
			$this->pipeline()->render( "#set %v% = [host id=\"\x00HOST_1\x00\"] [host id=\"z\"]\n%v%", array(), null, '', false )
		);

		$this->assertSame(
			$expected,
			$this->pipeline()->render( "#def %v% = [host id=\"\x00HOST_1\x00\"] [host id=\"z\"]\n%v%", array(), null, '', false )
		);
	}

	/**
	 * The restore is linear, and this is the tripwire.
	 *
	 * Every shieldable construct mints a key, so on shield-heavy prose the placeholder count grows
	 * with the text and the sequential restore costs O(text x keys). The bound is deliberately far
	 * above the measured cost — 0.03 s here, against 2.7 s for the same input before the fix — so it
	 * says nothing about how fast the machine is and everything about which restore ran.
	 */
	public function test_the_restore_does_not_go_quadratic_again(): void {
		$unit = 'Contact sales@example.com or open https://example.com/catalog/page?q=1&ref=2 today. '
			. 'Call tel:+1-555-0100, or write mailto:info@example.org for the details. '
			. 'The site example.org serves 3.14 million requests, i.e. 12.5 per cent more, etc. ';
		$text = str_repeat( $unit, (int) ceil( 237 * 1024 / strlen( $unit ) ) );

		$started = hrtime( true );
		( new Parser() )->post_process( $text );
		$elapsed = ( hrtime( true ) - $started ) / 1e9;

		$this->assertLessThan( 1.5, $elapsed, sprintf( 'post_process() on 237 KB took %.3f s', $elapsed ) );
	}
}
