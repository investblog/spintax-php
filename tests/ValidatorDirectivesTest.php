<?php
/**
 * Validator — `#set` / `#def` directives, and the lint that replaces collapse-once.
 *
 * @package Spintax\Tests
 */

declare(strict_types=1);

namespace Spintax\Tests;

use PHPUnit\Framework\TestCase;
use Spintax\Core\Engine\Validator;

final class ValidatorDirectivesTest extends TestCase {

	/**
	 * @return array{errors: array, warnings: array}
	 */
	private function validate( string $template, string $locale = '' ): array {
		return ( new Validator() )->validate( $template, array(), array(), $locale );
	}

	private function assertClean( string $template ): void {
		$this->assertSame( array(), $this->validate( $template )['errors'] );
	}

	private function assertRejected( string $template ): void {
		$this->assertNotSame( array(), $this->validate( $template )['errors'] );
	}

	// ── grammar ──────────────────────────────────────────────────────────────

	public function test_an_empty_value_validates_for_both_directives(): void {
		// The parser accepts an empty value and a test locks that. The validator used to disagree
		// and call it malformed, unless a trailing space happened to be present.
		$this->assertClean( "#set %x% =\n%x%" );
		$this->assertClean( "#def %y% =\n%y%" );
	}

	public function test_a_directive_without_an_equals_sign_is_malformed(): void {
		$this->assertRejected( '#set %v% hello' );
		$this->assertRejected( '#def %v% hello' );
	}

	// ── the validator sees #def ───────────────────────────────────────────────

	public function test_a_def_defined_name_is_not_reported_as_unknown(): void {
		$this->assertSame( array(), $this->validate( "#def %x% = a\n%x%" )['warnings'] );
	}

	public function test_a_def_can_self_reference_and_is_caught(): void {
		$this->assertRejected( '#def %a% = x %a% y' );
	}

	public function test_a_cycle_is_caught_even_when_it_crosses_directive_kinds(): void {
		$this->assertRejected( "#set %a% = %b%\n#def %b% = %a%" );
	}

	// ── duplicates ────────────────────────────────────────────────────────────

	/**
	 * @dataProvider duplicate_definitions
	 */
	public function test_a_name_defined_twice_is_rejected( string $template ): void {
		$this->assertRejected( $template );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function duplicate_definitions(): array {
		return array(
			'set then def' => array( "#set %x% = a\n#def %x% = b" ),
			'set then set' => array( "#set %x% = a\n#set %x% = b" ),
			'def then def' => array( "#def %x% = a\n#def %x% = b" ),
		);
	}

	public function test_the_duplicate_is_reported_on_its_own_line_not_the_first(): void {
		$errors = $this->validate( "body\n#set %x% = a\n#def %x% = b" )['errors'];

		$this->assertSame( 3, $errors[0]['line'] );
	}

	public function test_distinct_names_are_fine(): void {
		$this->assertClean( "#set %x% = a\n#def %y% = b\n%x%%y%" );
	}

	// ── #include in a #def value ──────────────────────────────────────────────

	public function test_include_in_a_def_value_is_rejected(): void {
		$this->assertRejected( "#def %x% = #include \"y\"\n%x%" );
	}

	public function test_include_in_a_set_value_is_allowed(): void {
		// A macro is substituted verbatim, so its #include reaches the include stage in the body.
		$this->assertClean( "#set %x% = #include \"y\"\n%x%" );
	}

	// ── macro in a plural count slot ──────────────────────────────────────────

	/**
	 * @dataProvider tainted_counts
	 */
	public function test_a_macro_count_is_rejected( string $template ): void {
		$this->assertRejected( $template );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function tainted_counts(): array {
		return array(
			'direct enumeration'  => array( "#set %n% = {1|4|9}\n{plural %n%: a|b}" ),
			'direct permutation'  => array( "#set %n% = [1|2]\n{plural %n%: a|b}" ),
			'one hop'             => array( "#set %m% = {1|4|9}\n#set %n% = %m%\n{plural %n%: a|b}" ),
			'three hops'          => array( "#set %a% = {1|2}\n#set %b% = %a%\n#set %c% = %b%\n{plural %c%: x|y}" ),
		);
	}

	/**
	 * @dataProvider sound_counts
	 */
	public function test_a_sound_count_is_accepted( string $template ): void {
		$this->assertClean( $template );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function sound_counts(): array {
		return array(
			'def holds a literal by the time plurals run' => array( "#def %n% = {1|4|9}\n{plural %n%: a|b}" ),
			'a literal #set'                             => array( "#set %n% = 5\n{plural %n%: a|b}" ),
			'no variable at all'                         => array( '{plural 5: a|b}' ),
			'a def one hop away'                         => array( "#def %m% = {1|2}\n#def %n% = %m%\n{plural %n%: a|b}" ),
		);
	}

	public function test_a_self_referential_macro_does_not_hang_the_taint_walk(): void {
		// Fixed-point iteration over a cyclic graph terminates because names only ever get added.
		$this->assertRejected( "#set %a% = {1|2} %a%\n{plural %a%: x|y}" );
	}
}
