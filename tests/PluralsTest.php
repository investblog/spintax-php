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
}
