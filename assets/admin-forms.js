( function () {
	'use strict';
	const root = document.querySelector( '[data-onesmtp-workspaces]' );
	if ( ! root ) {
		return;
	}
	const updateChannels = () => {
		root.querySelectorAll( '[data-onesmtp-enabled-by]' ).forEach(
			( input ) => {
				const controller = input.form?.elements.namedItem(
					input.dataset.onesmtpEnabledBy
				);
				input.disabled = ! controller?.checked;
				const label = input
					.closest( '.onesmtp-field' )
					?.querySelector( '.onesmtp-disabled-label' );
				if ( label ) {
					label.hidden = ! input.disabled;
				}
			}
		);
	};
	root.addEventListener( 'change', updateChannels );
	updateChannels();
	root.addEventListener( 'input', ( event ) => {
		const input = event.target;
		if ( input.name === 'from_name' || input.name === 'from_email' ) {
			const preview = root.querySelector(
				input.name === 'from_name'
					? '[data-onesmtp-preview-name]'
					: '[data-onesmtp-preview-email]'
			);
			if (
				preview &&
				input.closest( '[data-onesmtp-settings-group="general"]' )
			) {
				preview.textContent = input.value;
			}
		}
		if ( input.validity?.valid ) {
			input.removeAttribute( 'aria-invalid' );
			const error = input.parentElement.querySelector(
				'[data-onesmtp-field-error]'
			);
			if ( error ) {
				error.textContent = '';
			}
		}
	} );
	root.addEventListener(
		'invalid',
		( event ) => {
			const input = event.target;
			input.setAttribute( 'aria-invalid', 'true' );
			if (
				input.form &&
				! input.form.querySelector( '[data-onesmtp-invalid-summary]' )
			) {
				const summary = document.createElement( 'p' );
				summary.dataset.onesmtpInvalidSummary = '';
				summary.className = 'onesmtp-field-error';
				summary.setAttribute( 'role', 'alert' );
				summary.textContent = root.dataset.onesmtpInvalidMessage;
				input.form.prepend( summary );
			}

			let error = input.parentElement.querySelector(
				'[data-onesmtp-field-error]'
			);
			if ( ! error ) {
				error = document.createElement( 'p' );
				error.className = 'onesmtp-field-error';
				error.dataset.onesmtpFieldError = '';
				error.id = `${ input.id || input.name }-error`;
				input.after( error );
				input.setAttribute(
					'aria-describedby',
					`${ input.getAttribute( 'aria-describedby' ) || '' } ${
						error.id
					}`.trim()
				);
			}
			error.textContent = input.validationMessage;
		},
		true
	);
	root.querySelectorAll( '[data-onesmtp-provider-search]' ).forEach(
		( search ) => {
			search.addEventListener( 'input', () => {
				const term = search.value.trim().toLocaleLowerCase();
				let count = 0;
				root.querySelectorAll( '[data-provider-type]' ).forEach(
					( row ) => {
						const label =
							row.dataset.providerLabel ||
							row.dataset.providerType;
						row.hidden = ! label
							.toLocaleLowerCase()
							.includes( term );
						if ( ! row.hidden ) {
							count++;
						}
					}
				);
				const empty = root.querySelector(
					'[data-onesmtp-provider-empty]'
				);
				if ( empty ) {
					empty.hidden = count !== 0;
				}
			} );
		}
	);
	root.querySelectorAll( '[data-onesmtp-provider-clear]' ).forEach(
		( button ) => {
			button.addEventListener( 'click', () => {
				const search = root.querySelector(
					'[data-onesmtp-provider-search]'
				);
				search.value = '';
				search.dispatchEvent( new Event( 'input' ) );
				search.focus();
			} );
		}
	);
	const dialog = root.querySelector( '[data-onesmtp-confirm-dialog]' );
	if ( ! dialog ) {
		return;
	}
	let pending = null;
	let trigger = null;
	root.addEventListener( 'submit', ( event ) => {
		const form = event.target;
		if (
			! form.matches( '[data-onesmtp-confirm]' ) ||
			form.dataset.onesmtpConfirmed === 'true'
		) {
			return;
		}
		event.preventDefault();
		pending = form;
		trigger = event.submitter;
		dialog.showModal();
		dialog.querySelector( '[data-onesmtp-cancel]' ).focus();
	} );
	dialog
		.querySelector( '[data-onesmtp-cancel]' )
		.addEventListener( 'click', () => dialog.close() );
	dialog.addEventListener( 'close', () => trigger?.focus() );
	dialog
		.querySelector( '[data-onesmtp-confirm-send]' )
		.addEventListener( 'click', () => {
			if ( ! pending ) {
				return;
			}
			pending.dataset.onesmtpConfirmed = 'true';
			dialog.close();
			pending.requestSubmit( trigger );
			delete pending.dataset.onesmtpConfirmed;
			pending = null;
		} );
} )();
