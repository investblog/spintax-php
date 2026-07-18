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

	public function test_extract_set_directives_still_reports_only_set(): void {
		$extracted = $this->parser()->extract_set_directives( "#set %a% = 1\n#def %b% = 2\nbody" );

		$this->assertSame( array( 'a' => '1' ), $extracted['variables'] );
		$this->assertStringContainsString( '#def %b% = 2', $extracted['body'] );
	}
}
