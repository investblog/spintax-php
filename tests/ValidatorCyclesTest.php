<?php
/**
 * Validator — the circular-reference walk: emission shape and the non-hang canaries.
 *
 * The 0.5.1 rewrite (references_of / names_that_reach_a_cycle / walk_cycles_from) was
 * gated by a 464-document differential that lives outside this repository, so CI could
 * not re-prove it. These tests are the runnable half of that proof:
 *
 * - the exact-message cases pin emission ORDER, COUNT and path text — a future edit
 *   that deduplicates repeated edges, memoizes per root, or reorders roots goes red
 *   here, not silently green (the corpus asserts codes and verdicts, never counts);
 * - the big silent shapes pin "returns at all": before the rewrite the depth-30
 *   diamond never returned and the 2000-definition chain took tens of seconds, so a
 *   complexity regression fails the suite by timeout rather than by numbers.
 *
 * The per-path emission itself (exponential on a diamond that FEEDS a cycle) is the
 * family contract under discussion in spintax-js#59 — these tests pin what ships.
 *
 * @package Spintax\Tests
 */

declare(strict_types=1);

namespace Spintax\Tests;

use PHPUnit\Framework\TestCase;
use Spintax\Core\Engine\Validator;

final class ValidatorCyclesTest extends TestCase {

	/**
	 * @return array{errors: array, warnings: array}
	 */
	private function validate( string $template, string $locale = '' ): array {
		return ( new Validator() )->validate( $template, array(), array(), $locale );
	}

	/**
	 * @return string[] Circular-reference messages, in emission order.
	 */
	private function circular_messages( string $template ): array {
		$messages = array_column( $this->validate( $template )['errors'], 'message' );
		return array_values(
			array_filter(
				$messages,
				static fn( string $m ): bool => str_starts_with( $m, 'Circular' )
			)
		);
	}

	public function test_a_three_cycle_reports_once_per_root_with_the_full_path(): void {
		$this->assertSame(
			array(
				'Circular variable reference detected: c0 → c1 → c2 → c0.',
				'Circular variable reference detected: c1 → c2 → c0 → c1.',
				'Circular variable reference detected: c2 → c0 → c1 → c2.',
			),
			$this->circular_messages( "#set %c0% = %c1%\n#set %c1% = %c2%\n#set %c2% = %c0%" )
		);
	}

	public function test_a_duplicated_edge_reports_per_occurrence(): void {
		// References are NOT deduplicated and a report abandons only the frame that made
		// it — so %b% %b% in a 2-cycle reports three times, twice from a and once from b.
		$this->assertSame(
			array(
				'Circular variable reference detected: a → b → a.',
				'Circular variable reference detected: a → b → a.',
				'Circular variable reference detected: b → a → b.',
			),
			$this->circular_messages( "#set %a% = %b% %b%\n#set %b% = %a%" )
		);
	}

	public function test_a_diamond_feeding_a_cycle_reports_per_path(): void {
		// 2^2 from a0, 2^1 from a1, one each from a2, p and q: nine in all.
		$template = "#set %a2% = %p%\n#set %a1% = %a2% %a2%\n#set %a0% = %a1% %a1%\n"
			. "#set %p% = %q%\n#set %q% = %p%";
		$this->assertSame(
			array(
				'Circular variable reference detected: a2 → p → q → p.',
				'Circular variable reference detected: a1 → a2 → p → q → p.',
				'Circular variable reference detected: a1 → a2 → p → q → p.',
				'Circular variable reference detected: a0 → a1 → a2 → p → q → p.',
				'Circular variable reference detected: a0 → a1 → a2 → p → q → p.',
				'Circular variable reference detected: a0 → a1 → a2 → p → q → p.',
				'Circular variable reference detected: a0 → a1 → a2 → p → q → p.',
				'Circular variable reference detected: p → q → p.',
				'Circular variable reference detected: q → p → q.',
			),
			$this->circular_messages( $template )
		);
	}

	public function test_a_silent_chain_of_2000_definitions_is_clean(): void {
		$lines = array( '#set %v0% = x' );
		for ( $i = 1; $i < 2000; $i++ ) {
			$lines[] = "#set %v{$i}% = %v" . ( $i - 1 ) . '%';
		}
		$this->assertSame( array(), $this->validate( implode( "\n", $lines ) )['errors'] );
	}

	public function test_a_silent_diamond_of_depth_30_is_clean(): void {
		// 2^30 paths if actually walked — the prune must keep this instant AND silent.
		$n     = 30;
		$lines = array( "#set %a{$n}% = leaf" );
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			$lines[] = "#set %a{$i}% = %a" . ( $i + 1 ) . '% %a' . ( $i + 1 ) . '%';
		}
		$this->assertSame( array(), $this->validate( implode( "\n", $lines ) )['errors'] );
	}

	public function test_taint_walks_a_300_macro_chain_into_the_count(): void {
		// The worklist must compute the same closure the fixed-point sweep did: the
		// bracket at %t0% taints the whole chain, and the count at its far end reports.
		$lines = array( '#set %t0% = {x|y}' );
		for ( $i = 1; $i < 300; $i++ ) {
			$lines[] = "#set %t{$i}% = %t" . ( $i - 1 ) . '%';
		}
		$template = implode( "\n", $lines ) . "\n{plural %t299%: one|many}";
		$this->assertSame(
			array(
				"{plural ...}: the count 't299' is a #set macro, so it is still unresolved spintax when the plural is decided and the block renders empty. Define it with #def instead.",
			),
			array_column( $this->validate( $template, 'en' )['errors'], 'message' )
		);

		// Control: the same chain seeded with a literal carries no taint and is clean.
		$lines[0] = '#set %t0% = plain';
		$control  = implode( "\n", $lines ) . "\n{plural %t299%: one|many}";
		$this->assertSame( array(), $this->validate( $control, 'en' )['errors'] );
	}
}
