<?php
/**
 * The golden corpus — the cross-engine acceptance suite.
 *
 * These fixtures are the SAME files consumed by `@spintax/core` (TypeScript) and by the WordPress
 * plugin's conformance runner. They are the contract: an engine is correct when it reproduces them,
 * and two engines agree when both are green against the same file. That is why the corpus is not
 * vendored into this repository — a copy would drift, and a drifting contract is not a contract.
 *
 * Point `SPINTAX_FIXTURES` at a checkout of the fixtures; CI does it automatically.
 *
 * @package Spintax\Tests
 */

declare(strict_types=1);

namespace Spintax\Tests;

use PHPUnit\Framework\TestCase;
use Spintax\Core\Engine\Parser;
use Spintax\Core\Engine\Validator;
use Spintax\Core\Render\Pipeline;

final class GoldenCorpusTest extends TestCase {

	/**
	 * Locate the corpus. `SPINTAX_FIXTURES` wins; otherwise the CI checkout path.
	 */
	private static function fixturesDir(): ?string {
		$dir = getenv( 'SPINTAX_FIXTURES' );
		if ( is_string( $dir ) && '' !== $dir && is_dir( $dir ) ) {
			return $dir;
		}

		$default = __DIR__ . '/../.corpus/packages/conformance/fixtures';
		return is_dir( $default ) ? $default : null;
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function corpusProvider(): array {
		$dir = self::fixturesDir();
		if ( null === $dir ) {
			return array();
		}

		$cases = array();
		foreach ( (array) glob( rtrim( $dir, '/\\' ) . '/*.json' ) as $file ) {
			$data = json_decode( (string) file_get_contents( (string) $file ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			foreach ( $data as $case ) {
				// `engines` absent => both engines. Otherwise run only when 'php' is listed.
				$engines = $case['engines'] ?? null;
				if ( is_array( $engines ) && ! in_array( 'php', $engines, true ) ) {
					continue;
				}
				$cases[ $case['id'] ] = array( $case );
			}
		}
		return $cases;
	}

	public function test_the_corpus_is_present(): void {
		$this->assertNotNull(
			self::fixturesDir(),
			"Golden corpus not found. It is the cross-engine contract and this suite is meaningless "
			. "without it.\nPoint SPINTAX_FIXTURES at the fixtures directory, e.g.\n"
			. "  SPINTAX_FIXTURES=/path/to/spintax-js/packages/conformance/fixtures vendor/bin/phpunit"
		);
	}

	/**
	 * @dataProvider corpusProvider
	 * @param array<string, mixed> $c Fixture.
	 */
	public function test_corpus( array $c ): void {
		switch ( $c['op'] ) {
			case 'validate':
				$this->runValidate( $c );
				break;
			case 'extract':
				$this->runExtract( $c );
				break;
			case 'render':
				$this->runRender( $c );
				break;
			case 'neutralize':
				// TS-only divergence: `@spintax/core` restores literal glyphs, the PHP engine
				// entity-encodes and never decodes. Such fixtures carry engines:["ts"].
				$this->markTestSkipped( 'neutralize is a TS-only surface' );
				break;
			default:
				$this->markTestSkipped( "unknown op: {$c['op']}" );
		}
	}

	/**
	 * The verdict is the parity gate. Per-diagnostic codes are a TypeScript-side surface — this
	 * engine emits human messages, not machine codes — so they are deliberately not asserted.
	 *
	 * @param array<string, mixed> $c Fixture.
	 */
	private function runValidate( array $c ): void {
		$result = ( new Validator() )->validate(
			$c['template'],
			$c['knownIncludes'] ?? array(),
			$c['knownVariables'] ?? array(),
			$c['locale'] ?? ''
		);

		$verdict = empty( $result['errors'] ) ? 'valid' : 'invalid';
		$this->assertSame( $c['expect']['verdict'], $verdict, "verdict for {$c['id']}" );
	}

	/**
	 * @param array<string, mixed> $c Fixture.
	 */
	private function runExtract( array $c ): void {
		$parser   = new Parser();
		$expect   = $c['expect'];
		$asserted = false;

		if ( array_key_exists( 'sets', $expect ) ) {
			$sets = array_keys( $parser->extract_set_directives( $c['template'] )['variables'] );
			$this->assertSameSet( $expect['sets'], $sets, "sets for {$c['id']}" );
			$asserted = true;
		}
		if ( array_key_exists( 'includes', $expect ) ) {
			$includes = array_map(
				static fn( array $d ): string => $d['slug'],
				$parser->find_include_directives( $c['template'] )
			);
			$this->assertSameSet( $expect['includes'], $includes, "includes for {$c['id']}" );
			$asserted = true;
		}
		if ( array_key_exists( 'refs', $expect ) ) {
			$body = $parser->extract_set_directives( $c['template'] )['body'];
			$this->assertSameSet( $expect['refs'], $this->extractRefs( $body ), "refs for {$c['id']}" );
			$asserted = true;
		}
		if ( ! $asserted ) {
			$this->markTestSkipped( "no PHP-checkable extract expectation for {$c['id']}" );
		}
	}

	/**
	 * Render through the SHIPPED pipeline — not a test-local replica of it. That is the whole point
	 * of extracting `Pipeline`: the corpus now certifies the class users actually run.
	 *
	 * @param array<string, mixed> $c Fixture.
	 */
	private function runRender( array $c ): void {
		$is_rng   = ( $c['kind'] ?? 'deterministic' ) === 'rng';
		$strategy = $is_rng ? 'first' : ( $c['rng'] ?? 'first' );

		$pipeline = new Pipeline( new Parser( $this->rng( $strategy ) ) );
		$out      = $pipeline->render(
			$c['template'],
			$c['context'] ?? array(),
			null,
			$c['locale'] ?? '',
			$c['postProcess'] ?? true
		);

		if ( ! $is_rng ) {
			$this->assertSame( $c['expect']['output'], $out, "render {$c['id']}" );
			return;
		}

		// RNG cases assert structural invariants only — cross-engine RNG-sequence parity is a
		// deliberate non-goal.
		$e = $c['expect'];
		if ( isset( $e['oneOf'] ) ) {
			$this->assertContains( $out, $e['oneOf'], "oneOf for {$c['id']}" );
		}
		if ( isset( $e['subsetOf'] ) || isset( $e['sizeRange'] ) ) {
			$sep    = $e['separator'] ?? ' ';
			$tokens = '' === $out ? array() : explode( $sep, $out );

			if ( isset( $e['subsetOf'] ) ) {
				foreach ( $tokens as $t ) {
					$this->assertContains( $t, $e['subsetOf'], "subsetOf for {$c['id']}" );
				}
				// Permutations draw without replacement, so the tokens are distinct.
				$this->assertSame( count( $tokens ), count( array_unique( $tokens ) ), "distinct for {$c['id']}" );
			}
			if ( isset( $e['sizeRange'] ) ) {
				$this->assertGreaterThanOrEqual( $e['sizeRange'][0], count( $tokens ), "sizeRange min {$c['id']}" );
				$this->assertLessThanOrEqual( $e['sizeRange'][1], count( $tokens ), "sizeRange max {$c['id']}" );
			}
		}
	}

	/**
	 * @param string|array<string, mixed> $strategy RNG strategy from the fixture.
	 */
	private function rng( $strategy ): callable {
		if ( 'last' === $strategy ) {
			return static fn( int $min, int $max ): int => $max;
		}
		if ( is_array( $strategy ) && isset( $strategy['sequence'] ) ) {
			$seq = $strategy['sequence'];
			$i   = 0;
			return static function ( int $min, int $max ) use ( $seq, &$i ): int {
				$last  = count( $seq ) - 1;
				$value = $seq[ min( $i, $last ) ] ?? $min;
				++$i;
				return max( $min, min( $max, (int) $value ) );
			};
		}
		return static fn( int $min, int $max ): int => $min;
	}

	/**
	 * @return string[]
	 */
	private function extractRefs( string $text ): array {
		$refs = array();
		if ( preg_match_all( '/%(\w+)%/', $text, $m ) ) {
			foreach ( $m[1] as $r ) {
				$refs[ strtolower( $r ) ] = true;
			}
		}
		if ( preg_match_all( '/\{\?!?([A-Za-z_]\w*)\?/', $text, $m ) ) {
			foreach ( $m[1] as $r ) {
				$refs[ strtolower( $r ) ] = true;
			}
		}
		return array_keys( $refs );
	}

	/**
	 * @param array<int, string> $expected Expected values.
	 * @param array<int, string> $actual   Actual values.
	 */
	private function assertSameSet( array $expected, array $actual, string $message ): void {
		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual, $message );
	}
}
