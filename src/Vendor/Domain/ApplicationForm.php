<?php
/**
 * The vendor application form and its answers (VEN-11, VEN-13).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Domain;

use InvalidArgumentException;
use Mercatus\Core\Shared\Errors\DomainError;

/**
 * Answers are validated against the questions in force at submission and saved as a snapshot, each with its
 * question's ID, label and type.
 */
final readonly class ApplicationForm {

	/**
	 * IDs the fixed fields use, which administrators' questions can't take.
	 */
	public const RESERVED = array( 'store_name', 'phone', 'message', 'accept_terms' );

	/**
	 * Creates the form.
	 *
	 * @param list<ApplicationQuestion> $questions Questions, in order.
	 */
	public function __construct( public array $questions ) {}

	/**
	 * The administrator's questions from the setting's JSON. Malformed or duplicated questions, and ones using a
	 * reserved ID, are left out.
	 *
	 * @param string $json Setting value.
	 */
	public static function fromJson( string $json ): self {
		$items     = json_decode( '' === $json ? '[]' : $json, true );
		$questions = array();
		$seen      = self::RESERVED;
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( ! is_array( $item ) || ! is_string( $item['id'] ?? null ) || in_array( $item['id'], $seen, true ) ) {
				continue;
			}
			try {
				$questions[] = new ApplicationQuestion(
					$item['id'],
					is_string( $item['label'] ?? null ) ? $item['label'] : '',
					is_string( $item['type'] ?? null ) ? $item['type'] : '',
					true === ( $item['required'] ?? false ),
					is_array( $item['choices'] ?? null ) ? array_values( array_filter( $item['choices'], 'is_string' ) ) : array()
				);
				$seen[]      = $item['id'];
			} catch ( InvalidArgumentException ) {
				continue;
			}
		}

		return new self( $questions );
	}

	/**
	 * The validated answers as a snapshot, one entry per question.
	 *
	 * @param array<mixed> $answers Submitted answers by question ID.
	 * @return list<array{id: string, label: string, type: string, answer: string|bool|null}>
	 * @throws DomainError When an answer isn't valid (mercatus_vendor_registration_invalid, naming the question).
	 */
	public function snapshot( array $answers ): array {
		$snapshot = array();
		foreach ( $this->questions as $question ) {
			try {
				$answer = $question->answer( $answers[ $question->id ] ?? null );
			} catch ( InvalidArgumentException $problem ) {
				throw DomainError::of(
					'mercatus_vendor_registration_invalid',
					array(
						'field'   => 'answers.' . $question->id,
						'problem' => $problem->getMessage(),
					)
				);
			}
			$snapshot[] = array(
				'id'     => $question->id,
				'label'  => $question->label,
				'type'   => $question->type,
				'answer' => $answer,
			);
		}

		return $snapshot;
	}

	/**
	 * What changed between two attempts: the store name and the IDs of answers that differ, appeared or
	 * disappeared (VEN-11).
	 *
	 * @param VendorApplication $previous The earlier attempt.
	 * @param VendorApplication $current  The later attempt.
	 * @return list<string>
	 */
	public static function changes( VendorApplication $previous, VendorApplication $current ): array {
		$before  = array_column( $previous->answers, 'answer', 'id' );
		$after   = array_column( $current->answers, 'answer', 'id' );
		$changed = $previous->storeName === $current->storeName ? array() : array( 'store_name' );
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $id ) {
			if ( ! array_key_exists( $id, $before ) || ! array_key_exists( $id, $after ) || $before[ $id ] !== $after[ $id ] ) {
				$changed[] = (string) $id;
			}
		}

		return $changed;
	}
}
