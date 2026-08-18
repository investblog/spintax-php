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

	// ── Plural forms are counted as the RENDERER sees them (spintax-js#66) ─────
	//
	// Rendering expands `%variables%` and only THEN splits the form list, while this
	// validator split the raw source — so a reference inside a form list was judged on
	// the wrong number, in both directions. The property these tests hold to is that the
	// verdict agrees with what rendering does.

	public function test_a_def_holding_extra_forms_no_longer_fails_a_correct_template(): void {
		$result = $this->validate( "#def %tail% = few|many\n{plural 2: one|%tail%}", 'ru' );

		$this->assertSame( array(), $result['errors'], 'three forms after expansion is right for ru' );
	}

	public function test_a_def_holding_extra_forms_still_fails_a_wrong_locale(): void {
		$result = $this->validate( "#def %tail% = few|many\n{plural 2: one|%tail%}", 'en' );

		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( 'expected', $result['errors'][0]['message'] );
	}

	public function test_a_def_holding_the_whole_list_stops_inventing_a_count(): void {
		$result = $this->validate( "#def %forms% = one|many\n{plural 2: %forms%}", 'en' );

		$this->assertSame( array(), $result['errors'], 'one raw pipe, two real forms' );
		$this->assertSame( array(), $result['warnings'] );
	}

	public function test_a_def_whose_value_carries_a_construct_is_not_counted(): void {
		// A first version of this fix predicted the roll — counting pipes at bracket depth
		// zero, on the theory that a construct always collapses to one form. Review found
		// two shapes where it does not, so the rule retreated to counting only values that
		// are invariant. These two are the reason.
		$synonym_en = $this->validate( "#def %x% = {a|b}\n{plural 1: one|%x%}", 'en' );
		$this->assertSame( array(), $synonym_en['errors'] );

		$synonym_ru = $this->validate( "#def %x% = {a|b}\n{plural 1: one|%x%}", 'ru' );
		$this->assertSame( array(), $synonym_ru['errors'], 'the roll is not knowable, so no verdict' );

		// A conditional's branches can differ in top-level pipes: `b|c` on the false one.
		$conditional = $this->validate( "#set %flag% =\n#def %x% = {?flag?a|b|c}\n{plural 1: one|%x%}", 'ru' );
		$this->assertSame( array(), $conditional['errors'] );
	}

	public function test_a_def_that_rolls_a_set_is_not_reported_as_nested_brackets(): void {
		// The #def roll consumes the macro's `{a|b}` before the plural is decided, so the
		// block renders normally. Following the reference into the raw macro reported it.
		$result = $this->validate( "#set %s% = {a|b}\n#def %x% = %s%\n{plural 1: one|%x%}", 'en' );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	public function test_every_reference_is_expanded_per_pass(): void {
		// Replacing one occurrence per iteration spent the whole budget on a list that
		// merely repeats a name, and the completed expansion was then called unresolvable.
		$result = $this->validate( "#set %x% = a|b\n{plural 1: " . str_repeat( '%x%', 51 ) . '}', 'en' );

		$this->assertCount( 1, $result['errors'], 'fifty-two forms is an arity error for en' );
	}

	public function test_a_set_carrying_spintax_is_reported_not_silently_broken(): void {
		$result = $this->validate( "#set %x% = {a|b}\n{plural 2: one|%x%}" );

		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( 'nested spintax brackets', $result['errors'][0]['message'] );
	}

	public function test_a_set_of_plain_text_counts_its_pipes(): void {
		$result = $this->validate( "#set %x% = a|b\n{plural 1: one|%x%}", 'ru' );

		$this->assertSame( array(), $result['errors'], 'substitution really does make three forms' );
	}

	public function test_an_undefined_reference_suppresses_the_count_verdicts(): void {
		// A host variable has no static form count; judging it would file a verdict on a
		// fact the caller never claimed.
		$result = $this->validate( '{plural 2: one|%host%}', 'ru' );

		$this->assertSame( array(), $result['errors'] );
		foreach ( $result['warnings'] as $warning ) {
			$this->assertStringNotContainsString( 'forms', $warning['message'] );
		}
	}

	public function test_a_cyclic_reference_stops_at_the_budget(): void {
		$result = $this->validate( "#set %a% = %b%\n#set %b% = %a%\n{plural 2: one|%a%}", 'ru' );

		foreach ( $result['errors'] as $error ) {
			$this->assertStringNotContainsString( 'expected', $error['message'], 'no arity verdict on an unresolvable list' );
		}
	}
}
