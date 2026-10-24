<?php
/**
 * The application form's questions, answers and changes (VEN-11, VEN-13).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Unit\Vendor;

use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Vendor\Domain\ApplicationForm;
use Mercatus\Core\Vendor\Domain\VendorApplication;
use PHPUnit\Framework\TestCase;

/**
 * Malformed questions are left out, answers are validated and snapshotted with their labels, and the changes
 * between two attempts name the answers that differ.
 */
final class ApplicationFormTest extends TestCase {

	private function form(): ApplicationForm {
		return ApplicationForm::fromJson(
			(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- builds the setting in a unit test.
				array(
					array(
						'id'       => 'category',
						'label'    => 'What will you sell?',
						'type'     => 'choice',
						'required' => true,
						'choices'  => array( 'Books', 'Toys' ),
					),
					array(
						'id'    => 'website',
						'label' => 'Website',
						'type'  => 'text',
					),
					array(
						'id'    => 'phone',
						'label' => 'Reserved for the fixed field',
						'type'  => 'text',
					),
					array(
						'id'    => 'Bad ID',
						'label' => 'Malformed',
						'type'  => 'text',
					),
					array(
						'id'    => 'website',
						'label' => 'Duplicate',
						'type'  => 'text',
					),
				)
			)
		);
	}

	public function testKeepsOnlyWellFormedQuestions(): void {
		$this->assertSame( array( 'category', 'website' ), array_map( static fn( $question ): string => $question->id, $this->form()->questions ) );
	}

	public function testSnapshotsAnswersWithTheirLabels(): void {
		$snapshot = $this->form()->snapshot(
			array(
				'category' => 'Books',
				'website'  => '  https://example.org  ',
			)
		);

		$this->assertSame(
			array(
				array(
					'id'     => 'category',
					'label'  => 'What will you sell?',
					'type'   => 'choice',
					'answer' => 'Books',
				),
				array(
					'id'     => 'website',
					'label'  => 'Website',
					'type'   => 'text',
					'answer' => 'https://example.org',
				),
			),
			$snapshot
		);
	}

	/**
	 * Answers the form refuses, and the field each refusal names.
	 *
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function refused(): array {
		return array(
			'missing required' => array( array(), 'answers.category' ),
			'unknown choice'   => array( array( 'category' => 'Cars' ), 'answers.category' ),
			'wrong type'       => array( array( 'category' => array( 'Books' ) ), 'answers.category' ),
			'too long'         => array(
				array(
					'category' => 'Books',
					'website'  => str_repeat( 'x', 192 ),
				),
				'answers.website',
			),
		);
	}

	/**
	 * Refuses invalid answers, naming the question.
	 *
	 * @dataProvider refused
	 * @param array<string, mixed> $answers Answers.
	 * @param string               $field   Field the refusal names.
	 */
	public function testRefusesInvalidAnswers( array $answers, string $field ): void {
		try {
			$this->form()->snapshot( $answers );
			$this->fail( 'Expected a refusal.' );
		} catch ( DomainError $error ) {
			$this->assertSame( array( 'mercatus_vendor_registration_invalid', $field ), array( $error->errorCode, $error->details['field'] ?? null ) );
		}
	}

	public function testNamesWhatChangedBetweenAttempts(): void {
		$first  = $this->attempt(
			1,
			'Shop',
			array(
				'category' => 'Books',
				'website'  => 'a.example',
			)
		);
		$second = $this->attempt(
			2,
			'Better shop',
			array(
				'category' => 'Books',
				'website'  => 'b.example',
				'extra'    => 'new',
			)
		);

		$this->assertSame( array( 'store_name', 'website', 'extra' ), ApplicationForm::changes( $first, $second ) );
	}

	/**
	 * An attempt with answers.
	 *
	 * @param int                   $attempt Attempt number.
	 * @param string                $store   Store name.
	 * @param array<string, string> $answers Answers by question ID.
	 */
	private function attempt( int $attempt, string $store, array $answers ): VendorApplication {
		$snapshot = array();
		foreach ( $answers as $id => $answer ) {
			$snapshot[] = array(
				'id'     => $id,
				'label'  => ucfirst( $id ),
				'type'   => 'text',
				'answer' => $answer,
			);
		}

		return new VendorApplication( $attempt, 7, $attempt, 'rejected', $store, $snapshot, null, null, null, '2026-10-24 10:00:00.000', '2026-10-24 10:00:00.000', 1 );
	}
}
