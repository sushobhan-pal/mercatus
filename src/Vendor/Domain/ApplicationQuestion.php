<?php
/**
 * A question on the vendor application form (VEN-13).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Domain;

use InvalidArgumentException;

/**
 * Text, long text, a choice or a checkbox, optionally required. Its label travels with every answer, so later
 * changes to the form never alter what an applicant answered (VEN-11).
 */
final readonly class ApplicationQuestion {

	public const TYPES = array( 'text', 'textarea', 'choice', 'checkbox' );

	private const MAX_TEXT = array(
		'text'     => 191,
		'textarea' => 5000,
	);

	/**
	 * Creates the question.
	 *
	 * @param string       $id       Lower-case ID, unique on the form.
	 * @param string       $label    Label.
	 * @param string       $type     One of TYPES.
	 * @param bool         $required Whether it must be answered (for a checkbox: ticked).
	 * @param list<string> $choices  A choice question's options.
	 * @throws InvalidArgumentException When the question is malformed.
	 */
	public function __construct( public string $id, public string $label, public string $type, public bool $required = false, public array $choices = array() ) {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $id ) || '' === trim( $label ) || ! in_array( $type, self::TYPES, true ) || ( 'choice' === $type ) === ( array() === $choices ) ) {
			throw new InvalidArgumentException( 'A question has an ID, a label, a known type, and choices exactly when it is a choice.' );
		}
	}

	/**
	 * A valid answer, normalised: trimmed text, one of the choices, or a Boolean; null when unanswered.
	 *
	 * @param mixed $value Submitted value.
	 * @return string|bool|null
	 * @throws InvalidArgumentException When the answer isn't valid.
	 */
	public function answer( mixed $value ): string|bool|null {
		if ( 'checkbox' === $this->type ) {
			$ticked = true === $value || '1' === $value || 1 === $value || 'true' === $value;
			if ( $this->required && ! $ticked ) {
				throw new InvalidArgumentException( 'required' );
			}

			return $ticked;
		}
		$text = is_string( $value ) ? trim( $value ) : ( null === $value ? '' : null );
		if ( null === $text ) {
			throw new InvalidArgumentException( 'type' );
		}
		if ( '' === $text ) {
			if ( $this->required ) {
				throw new InvalidArgumentException( 'required' );
			}
			return null;
		}
		if ( 'choice' === $this->type ) {
			if ( ! in_array( $text, $this->choices, true ) ) {
				throw new InvalidArgumentException( 'choice' );
			}
			return $text;
		}
		if ( mb_strlen( $text ) > self::MAX_TEXT[ $this->type ] ) {
			throw new InvalidArgumentException( 'length' );
		}

		return $text;
	}
}
