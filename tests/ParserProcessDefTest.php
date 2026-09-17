<?php
/**
 * `Parser::process()` — the convenience path, and its obligations towards `#def`.
 *
 * `process()` is a shipped public method. It runs a subset of the pipeline (no conditionals, no
 * plurals, no includes), and that subset is a documented limitation. Silently swallowing a
 * directive is not: for one commit `extract_set_directives()` stripped `#def` lines while
 * returning no value for them, so a template lost its definition and printed `%x%` instead.
 *
 * @package Spintax\Tests
 */

declare(strict_types=1);

namespace Spintax\Tests;

use PHPUnit\Framework\TestCase;
use Spintax\Core\Engine\Parser;

final class ParserProcessDefTest extends TestCase {

	private function parser(): Parser {
		return new Parser( static fn( int $min, int $max ): int => $min );
	}

	public function test_process_resolves_def_rather_than_eating_the_directive(): void {
		// `process()` ends with post-processing, which capitalises the opening letter — hence 'A'.
		// What matters is that it is a resolved value at all: before the fix this rendered '%x%'.
		$this->assertSame( 'A', trim( $this->parser()->process( "#def %x% = {a|b}\n%x%" ) ) );
	}

	public function test_process_freezes_a_def_across_references(): void {
		$out = trim( $this->parser()->process( "#def %x% = {a|b}\n%x%-%x%" ) );

		[ $left, $right ] = explode( '-', $out );
		$this->assertSame( strtolower( $left ), strtolower( $right ) );
	}

	public function test_process_keeps_set_a_macro(): void {
		// Both references resolve independently; with a first-option RNG they agree in value, so
		// what this pins is that the directive is still extracted and substituted at all.
		$this->assertSame( 'A a', trim( $this->parser()->process( "#set %x% = {a|b}\n%x% %x%" ) ) );
	}

	public function test_process_orders_definitions_through_a_set_alias(): void {
		$this->assertSame(
			'1 1',
			trim( $this->parser()->process( "#def %b% = %s%\n#set %s% = %a%\n#def %a% = {1|2}\n%b% %a%" ) )
		);
	}

	public function test_caller_supplied_variables_outrank_a_def(): void {
		$this->assertSame(
			'CALLER',
			trim( $this->parser()->process( "#def %x% = {a|b}\n%x%", array( 'x' => 'CALLER' ) ) )
		);
	}

	public function test_a_caller_variable_outranks_a_def_whatever_its_case(): void {
		// `%var%` references are case-insensitive everywhere else in the language, so the caller's
		// 'X' has to win against `#def %x%` exactly as a lowercase 'x' does.
		$this->assertSame(
			'CALLER',
			trim( $this->parser()->process( "#def %x% = {a|b}\n%x%", array( 'X' => 'CALLER' ) ) )
		);
	}

	public function test_process_orders_definitions_through_a_caller_variable_alias(): void {
		$this->assertSame(
			'1',
			trim( $this->parser()->process( "#def %b% = %s%\n#def %a% = {1|2}\n%b%", array( 's' => '%a%' ) ) )
		);
	}

	public function test_extract_set_directives_still_reports_only_set(): void {
		$extracted = $this->parser()->extract_set_directives( "#set %a% = 1\n#def %b% = 2\nbody" );

		$this->assertSame( array( 'a' => '1' ), $extracted['variables'] );
		$this->assertStringContainsString( '#def %b% = 2', $extracted['body'] );
	}

	/**
	 * The roll order is this engine's own, and these are the shapes that say which one ran.
	 *
	 * `order_definitions()` reproduces a left-to-right sweep: a name whose last dependency was
	 * placed earlier in the same walk goes out immediately. `@spintax/core` and `spintax-core`
	 * place whole ROUNDS instead and answer `a, c, b` and `a, d, b, c` to the first two — which is
	 * visible in rendered text, because every roll draws from the RNG.
	 *
	 * Pinned here because the difference is invisible to the shared corpus: an `rng` case asserts
	 * within-engine reproducibility only, and under a first-choice RNG every order renders the
	 * same string. A future edit that ports the reference's rounds goes red here rather than
	 * quietly re-rolling every multi-definition template (investblog/spintax-js#82).
	 *
	 * @dataProvider orderProvider
	 * @param array<string, string> $definitions Directive values, name => raw value.
	 * @param array<string, string> $aliases     `#set` values for alias hops.
	 * @param list<string>          $expected    The order this engine rolls them in.
	 */
	public function test_definition_order_is_the_sweep_this_engine_has_always_used(
		array $definitions,
		array $aliases,
		array $expected
	): void {
		$this->assertSame( $expected, $this->parser()->order_definitions( $definitions, $aliases ) );
	}

	/**
	 * @return array<string, array{0: array<string, string>, 1: array<string, string>, 2: list<string>}>
	 */
	public static function orderProvider(): array {
		return array(
			// The two shapes where the sweep and the reference's rounds disagree.
			'a dependent name placed before a later independent one' => array(
				array(
					'a' => 'x',
					'b' => '%a%',
					'c' => 'y',
				),
				array(),
				array( 'a', 'b', 'c' ),
			),
			'a whole chain drains before a later independent name'   => array(
				array(
					'a' => 'x',
					'b' => '%a%',
					'c' => '%b%',
					'd' => 'y',
				),
				array(),
				array( 'a', 'b', 'c', 'd' ),
			),
			// Shapes where the two agree — here so a rewrite cannot trade one for the other.
			'a chain written backwards takes one pass per name'      => array(
				array(
					'a' => '%b%',
					'b' => '%c%',
					'c' => 'z',
				),
				array(),
				array( 'c', 'b', 'a' ),
			),
			'a dependency reached through a #set alias'              => array(
				array(
					'b' => '%s%',
					'a' => '{1|2}',
				),
				array( 's' => '%a%' ),
				array( 'a', 'b' ),
			),
			'a self-reference is not a dependency'                   => array(
				array(
					'a' => '%a% tail',
					'b' => 'x',
				),
				array(),
				array( 'a', 'b' ),
			),
			'a cycle, and the name waiting behind it, come last'     => array(
				array(
					'a' => '%b%',
					'b' => '%a%',
					'c' => 'free',
					'd' => '%a%',
				),
				array(),
				array( 'c', 'a', 'b', 'd' ),
			),
		);
	}
}
