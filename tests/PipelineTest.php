<?php
/**
 * Pipeline — the code the golden corpus does NOT cover.
 *
 * The corpus certifies stages 3-8 and 10 (it deliberately skips `#include`, because fetching a
 * template is a host concern and the corpus is host-free). Everything the pipeline adds on top of
 * the primitives therefore needs its own tests: the include seam, the guards that keep a naive host
 * from hanging, scope isolation, and the two host hooks.
 *
 * @package Spintax\Tests
 */

declare(strict_types=1);

namespace Spintax\Tests;

use PHPUnit\Framework\TestCase;
use Spintax\Core\Engine\Parser;
use Spintax\Core\Render\Pipeline;
use Spintax\Core\Render\RenderContext;

final class PipelineTest extends TestCase {

	/**
	 * A parser that always takes the first option — renders become reproducible.
	 */
	private function parser(): Parser {
		return new Parser( static fn( int $min, int $max ): int => $min );
	}

	/**
	 * @param array<string, string> $templates Name => raw source.
	 * @param array<string, string> $globals   Host-wide variables.
	 */
	private function pipeline( array $templates, array $globals = array() ): Pipeline {
		return new Pipeline(
			$this->parser(),
			$globals,
			static fn( string $name ): ?string => $templates[ $name ] ?? null
		);
	}

	public function test_include_is_spun_in_its_own_right(): void {
		$p   = $this->pipeline( array( 'partial' => 'inner {a|b}' ) );
		$out = trim( $p->render( "outer\n#include \"partial\"", array(), null, '', false ) );

		$this->assertSame( "outer\ninner a", $out );
	}

	public function test_child_inherits_globals_and_runtime_but_not_the_parents_set_locals(): void {
		$p = $this->pipeline(
			array( 'child' => 'g=%g% r=%r% local=%local%' ),
			array( 'g' => 'GLOBAL' )
		);

		$out = trim(
			$p->render(
				"#set %local% = LOCAL\n#include \"child\"",
				array( 'r' => 'RUNTIME' ),
				null,
				'',
				false
			)
		);

		// The parent's #set never reaches the child, so `%local%` finds no value and stays literal.
		$this->assertSame( 'g=GLOBAL r=RUNTIME local=%local%', $out );
	}

	public function test_a_circular_include_resolves_to_nothing_instead_of_hanging(): void {
		$p = $this->pipeline(
			array(
				'a' => "A\n#include \"b\"",
				'b' => "B\n#include \"a\"",
			)
		);

		$this->assertSame( "A\nB", trim( $p->render( '#include "a"', array(), null, '', false ) ) );
	}

	public function test_a_template_that_includes_itself_resolves_to_nothing(): void {
		$p = $this->pipeline( array( 'a' => "A\n#include \"a\"" ) );

		$this->assertSame( 'A', trim( $p->render( '#include "a"', array(), null, '', false ) ) );
	}

	public function test_the_include_chain_stops_at_the_depth_limit(): void {
		$templates = array();
		for ( $i = 1; $i <= 15; $i++ ) {
			$next            = $i + 1;
			$templates[ "t{$i}" ] = "t{$i}\n#include \"t{$next}\"";
		}

		$p   = $this->pipeline( $templates );
		$out = $p->render( '#include "t1"', array(), null, '', false );

		$this->assertStringContainsString( 't10', $out, 'the chain should run to the depth limit' );
		$this->assertStringNotContainsString( 't11', $out, 'and stop there' );
	}

	public function test_an_exponential_include_tree_is_bounded_by_the_fan_out_budget(): void {
		// Billion laughs: no name ever repeats on a single chain, so the cycle guard never fires —
		// yet each level doubles the work. 2^8 = 256 expansions if nothing bounds it.
		$templates = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$next            = $i + 1;
			$templates[ "n{$i}" ] = "#include \"n{$next}\"\n#include \"n{$next}\"";
		}
		$templates['n8'] = 'x';

		$calls = 0;
		$p     = new Pipeline(
			$this->parser(),
			array(),
			static function ( string $name ) use ( $templates, &$calls ): ?string {
				++$calls;
				return $templates[ $name ] ?? null;
			}
		);

		$out = $p->render( '#include "n0"', array(), null, '', false );

		$this->assertLessThanOrEqual( Pipeline::MAX_INCLUDES, $calls );
		$this->assertNotSame( '', trim( $out ), 'the bounded part still renders' );
	}

	public function test_an_unknown_include_resolves_to_nothing(): void {
		$p = $this->pipeline( array( 'known' => 'here' ) );

		$this->assertSame( '', trim( $p->render( '#include "missing"', array(), null, '', false ) ) );
	}

	public function test_a_host_without_a_source_simply_drops_includes(): void {
		$p = new Pipeline( $this->parser() );

		$this->assertSame( '', trim( $p->render( '#include "anything"', array(), null, '', false ) ) );
	}

	public function test_protected_host_constructs_survive_the_permutation_resolver(): void {
		// Without shielding, `[host id="1"]` is square-bracketed and the permutation resolver eats
		// the brackets. With it, the construct reaches the stage-9 hook intact.
		$p = new Pipeline(
			$this->parser(),
			array(),
			null,
			array( '/\[host\s+[^\]]+\]/i' ),
			static fn( string $text, RenderContext $ctx ): string => str_replace( '[host id="1"]', 'HOSTED', $text )
		);

		$out = $p->render( 'before [host id="1"] after {x|y}', array(), null, '', false );

		$this->assertSame( 'before HOSTED after x', $out );
	}

	public function test_an_unprotected_bracket_construct_is_consumed_by_the_permutation_resolver(): void {
		// The negative half of the case above — it is why `protect` exists at all.
		$p = new Pipeline( $this->parser() );

		$this->assertSame( 'before host after', $p->render( 'before [host] after', array(), null, '', false ) );
	}

	public function test_the_stage_nine_hook_receives_the_child_context(): void {
		$seen = null;
		$p    = new Pipeline(
			$this->parser(),
			array( 'g' => 'G' ),
			null,
			array(),
			static function ( string $text, RenderContext $ctx ) use ( &$seen ): string {
				$seen = $ctx->get_merged_variables();
				return $text;
			}
		);

		$p->render( "#set %loc% = L\nbody", array( 'r' => 'R' ), null, '', false );

		$this->assertSame( array( 'g' => 'G', 'r' => 'R' ), $seen );
	}

	public function test_post_process_runs_once_over_the_assembled_document(): void {
		$p = $this->pipeline( array( 'frag' => 'wait... what?' ) );

		// With the tail on, the fragment is cleaned up as part of the whole document.
		$this->assertSame( 'Wait... What?', $p->render( '#include "frag"' ) );

		// With it off, the fragment comes back untouched — proving the child was NOT post-processed
		// on its own on the way in. One cosmetic pass over the final text, never one per fragment.
		$this->assertSame( 'wait... what?', trim( $p->render( '#include "frag"', array(), null, '', false ) ) );
	}

	public function test_the_locale_is_inherited_by_included_templates(): void {
		$p = $this->pipeline( array( 'count' => '5 {plural 5: файл|файла|файлов}' ) );

		$this->assertSame( '5 файлов', trim( $p->render( '#include "count"', array(), null, 'ru_RU', false ) ) );
	}
}
