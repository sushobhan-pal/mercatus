/**
 * Every screen's loading, empty and error states (core.vendor §6).
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, test, vi } from 'vitest';
import { ApiError } from '../../ui/src/api';
import {
	Empty,
	ErrorState,
	Loading,
} from '../../ui/src/components/ScreenState';

describe( 'screen states', () => {
	test( 'loading is announced as busy', () => {
		render( <Loading rows={ 2 } /> );

		expect( screen.getByRole( 'status' ) ).toHaveAttribute(
			'aria-busy',
			'true'
		);
	} );

	test( 'empty says what is empty and what to do next', () => {
		render(
			<Empty title="No vendors here">
				<p>Approve an application first.</p>
			</Empty>
		);

		expect(
			screen.getByRole( 'heading', { name: 'No vendors here' } )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Approve an application first.' )
		).toBeInTheDocument();
	} );

	test( 'an error shows the API message and the request ID, and tries again', async () => {
		const retry = vi.fn();
		render(
			<ErrorState
				error={
					new ApiError( {
						code: 'mercatus_forbidden',
						message: 'You can’t do that.',
						requestId: 'req_42',
					} )
				}
				onRetry={ retry }
			/>
		);

		expect( screen.getByText( 'You can’t do that.' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Request ID: req_42' ) ).toBeInTheDocument();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Try again' } )
		);
		expect( retry ).toHaveBeenCalledTimes( 1 );
	} );
} );
