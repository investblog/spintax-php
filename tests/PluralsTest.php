<?php
/**
 * Plurals — the error model the golden corpus cannot express.
 *
 * The corpus certifies the bucket math itself (it carries the full sr/hr/bs ladder), so this file
 * deliberately does NOT re-assert which form a count picks. What the corpus has no vocabulary for
 * is the error model: a fixture can assert an output or a validation verdict, never a thrown
 * exception, and `@spintax/core` has no strict mode to throw from — so strict/lenient behaviour is
 * per-engine by construction and has to be pinned here.
 *
 * That gap is exactly where adding a locale to the 3-form family is observable: a 2-form Croatian
 * template was valid before and is an arity error after. Both halves are pinned — the strict throw,
 * and the lenient path that production actually runs, which does not throw and instead emits the
 * block verbatim in fullwidth braces.
 *
 * @package Spintax\Tests
 */

declare(strict_types=1);

namespace Spintax\Tests;

use PHPUnit\Framework\TestCase;
use Spintax\Core\Engine\PluralArityError;
use Spintax\Core\Engine\Plurals;

final class PluralsTest extends TestCase {

	private function plurals(): Plurals {
		return new Plurals();
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function bcsLocaleProvider(): array {
		return array(
			'sr' => array( 'sr' ),
			'hr' => array( 'hr' ),
			'bs' => array( 'bs' ),
		);
	}

	/**
	 * The breaking half of adding BCS: two forms used to be valid for these locales.
	 *
	 * @dataProvider bcsLocaleProvider
	 */
	public function test_2_form_construct_throws_arity_error( string $lang ): void {
		$this->expectException( PluralArityError::class );
		$this->plurals()->apply( 'ima {plural 3: kolačić|kolačići}', $lang );
	}

	/**
	 * The same break on the production path, which does not throw. A stale 2-form BCS template
	 * puts fullwidth braces into live output rather than failing — pinned so the consequence is
	 * visible in the suite, not just in the changelog.
	 *
	 * @dataProvider bcsLocaleProvider
	 */
	public function test_lenient_2_form_construct_renders_verbatim( string $lang ): void {
		$this->assertSame(
			"ima \u{FF5B}plural 3: kolačić|kolačići\u{FF5D}",
			$this->plurals()->apply(
				'ima {plural 3: kolačić|kolačići}',
				$lang,
				array( 'lenient' => true )
			)
		);
	}

	/**
	 * Three forms resolve normally — the counterpart to the two tests above, so a rule that threw
	 * on everything could not pass them.
	 *
	 * @dataProvider bcsLocaleProvider
	 */
	public function test_3_form_construct_resolves( string $lang ): void {
		$this->assertSame(
			'ima 3 sata',
			$this->plurals()->apply( 'ima 3 {plural 3: sat|sata|sati}', $lang )
		);
	}

	/**
	 * Script and region subtags carry no plural grammar. `normalize_base_lang` did not change, but
	 * its pairing with a newly-3-form locale is what was never covered.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function serbianTagProvider(): array {
		return array(
			'script Latin'   => array( 'sr-Latn' ),
			'script Cyrillic' => array( 'sr-Cyrl' ),
			'region underscore' => array( 'sr_RS' ),
			'script + region' => array( 'sr-Latn-RS' ),
			'uppercase'      => array( 'SR' ),
		);
	}

	/**
	 * @dataProvider serbianTagProvider
	 */
	public function test_serbian_subtags_normalise_to_three_forms( string $tag ): void {
		$plurals = $this->plurals();
		$this->assertSame( 3, $plurals->plural_arity( $plurals->normalize_base_lang( $tag ) ) );
	}

	// ── No locale: no verdict, but not silence either (spintax-js#65) ──────────
	//
	// The corpus cannot gate this half. Its PHP runner asserts the VERDICT only —
	// diagnostics here carry human messages, not machine codes — and a warning by
	// definition does not move a verdict. So the warning and, more importantly, its
	// ABSENCE on a 2-form block are pinned here or nowhere.

	private function validate( string $template, string $locale = '' ): array {
		$validator = new \Spintax\Core\Engine\Validator();
		return $validator->validate( $template, array(), array(), $locale );
	}

	public function test_no_locale_warns_when_the_form_count_is_not_the_render_default(): void {
		$result = $this->validate( '{plural 3: a|b|c}' );

		$this->assertCount( 0, $result['errors'], 'no locale means no VERDICT — the template may be right for the locale it will be rendered with' );
		$this->assertCount( 1, $result['warnings'] );
		$this->assertStringContainsString( 'no locale was supplied', $result['warnings'][0]['message'] );
	}

	public function test_the_warning_agrees_with_what_rendering_actually_does(): void {
		// The sentence claims the block will not resolve. Check that against the engine
		// rather than trusting the wording — and against the PLURAL STAGE, not
		// `Parser::process()`, which by its own docblock runs neither conditionals nor
		// plurals (a plural block reaching it is eaten by the synonym stage as a 3-way
		// choice, which is why the first version of this test read `Plural 3: a`).
		$lenient = array( 'lenient' => true );

		$this->assertStringContainsString(
			"\u{FF5B}",
			$this->plurals()->apply( '{plural 3: a|b|c}', '', $lenient ),
			'the block really does fail to resolve at the default arity'
		);
		// The mirror: two forms DO resolve at the default, which is why they get no warning.
		$this->assertSame( 'many', $this->plurals()->apply( '{plural 3: one|many}', '', $lenient ) );
	}

	public function test_no_locale_stays_silent_on_a_two_form_block(): void {
		$result = $this->validate( '{plural 3: one|many}' );

		$this->assertCount( 0, $result['errors'] );
		$this->assertCount( 0, $result['warnings'], 'the render default resolves a 2-form block, so there is nothing to warn about' );
	}

	public function test_supplying_a_locale_replaces_the_warning_with_the_real_verdict(): void {
		$ru = $this->validate( '{plural 3: a|b|c}', 'ru' );
		$this->assertCount( 0, $ru['errors'] );
		$this->assertCount( 0, $ru['warnings'] );

		$en = $this->validate( '{plural 3: a|b|c}', 'en' );
		$this->assertCount( 1, $en['errors'], 'three forms are an arity ERROR for a 2-form locale' );
		$this->assertCount( 0, $en['warnings'] );
	}

	public function test_a_structurally_broken_block_reports_only_that(): void {
		$result = $this->validate( '{plural 3: {a|b}|c|d}' );

		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( 'nested spintax brackets', $result['errors'][0]['message'] );
		$this->assertCount( 0, $result['warnings'], 'no second, invented problem on a block that is already malformed' );
	}
}
