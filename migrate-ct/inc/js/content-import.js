/*
* Ajax call for the content-import.
*/
jQuery( function ($) {
	const $form = $( '#ct-import-form' );
	const $progress = $( '#ct-import-progress' );
	const $submit = $( '#ct-import-submit' );
	let isProcessing = false;

	$form.on( 'submit', function ( e ) {
		e.preventDefault( );
		if ( isProcessing ) return;
		$submit.prop( 'disabled', true );

		const formData = new FormData( this );
		formData.append( 'action', 'ct_start_import' );
		formData.append( 'security', $( 'input[name="security"]' ).val( ));

		isProcessing = true;
		$progress.text( wp.i18n.__( 'Starting import...', 'migrate-ct' ));

		$.ajax( {
			url: ctBatch.ajax_url,
			method: 'POST',
			data: formData,
			processData: false,
			contentType: false,
			success: function ( response ) {
				if ( response.success ) {
					const initialText = wp.i18n.sprintf( 
						wp.i18n.__( '0/%d', 'migrate-ct' ),
						response.data.total
					);
					$progress.text( initialText );
					processBatch();
				} else {
					handleError( response.data );
				}
			},
			error: handleError
		});
	});

	function processBatch() {
		$.ajax({
			url: ctBatch.ajax_url,
			method: 'GET',
			data: {
				action: 'ct_process_batch',
				nonce: ctBatch.nonce
			},
			success: function ( response ) {
				if ( response.data.complete) {
					finalizeImport();
					return;
				}

				if ( response.success ) {
					const statusText = wp.i18n.sprintf(
						wp.i18n.__( '%d/%d', 'migrate-ct' ),
						response.data.processed,
						response.data.total
					);
					$progress.text( statusText );

					if ( response.data.remaining > 0 ) {
						setTimeout( processBatch, 1000 );
					} else {
						finalizeImport();
					}
				} else {
					handleError( response.data );
				}
			},
			error: handleError
		});
	}

	function finalizeImport() {
		isProcessing = false;
		$submit.prop( 'disabled', false );
		// Create new text node for completion message.
		const completeText = document.createTextNode(
			wp.i18n.__( 'Import complete!', 'migrate-ct' )
		);
		$progress.empty().append( completeText );
		// Safely update report section via API.
		$.getJSON( ctBatch.ajax_url, {
			action: 'ct_import_status',
			_ajax_nonce: ctBatch.nonce
		}).done( function ( response ) {
			if ( response.success ) {
				updateReportDisplay( response.data.report );
			}
		});
	}

	function updateReportDisplay( reportData ) {
		const $reportContainer = $( '#ct-import-report' ).empty();
		// Safely construct report elements.
		const fragment = document.createDocumentFragment();
		// Title.
		const title = document.createElement( 'h2' );
		title.textContent = wp.i18n.__( 'Import Report', 'migrate-ct' );
		fragment.appendChild( title );
		// Stats.
		const stats = document.createElement('p');
		stats.textContent = wp.i18n.sprintf(
			wp.i18n.__( 'Imported: %1$d, Skipped: %2$d, Errors: %3$d', 'migrate-ct' ),
			reportData.imported,
			reportData.skipped,
			reportData.errors
		);
		fragment.appendChild( stats );

		// Download link.
		const linkWrapper = document.createElement( 'p' );
		linkWrapper.id = 'ct-download-wrapper';
		const downloadLink = document.createElement( 'a' );
		downloadLink.href = '#';
		downloadLink.className = 'button';
		downloadLink.textContent = wp.i18n.__( 'Download CSV report', 'migrate-ct' );
		downloadLink.addEventListener( 'click', function (e) {
			e.preventDefault();
			handleDownload();
		});
		linkWrapper.appendChild( downloadLink );
		fragment.appendChild( linkWrapper );

		$reportContainer.append( fragment );
	}

	function handleDownload() {
		// Use safe URL construction.
		const safeUrl = new URL( ctBatch.ajax_url );
		safeUrl.searchParams.set( 'action', 'ct_download_report' );
		safeUrl.searchParams.set( '_ajax_nonce', ctBatch.nonce );

		// Create temporary iframe for download.
		const iframe = document.createElement( 'iframe' );
		iframe.style.display = 'none';
		iframe.src = safeUrl.href;
		document.body.appendChild( iframe );

		setTimeout(() => {
			document.body.removeChild( iframe );
		}, 5000);
	}

	function handleError( error ) {
		isProcessing = false;
		$submit.prop( 'disabled', false );

		const errorMessage = error instanceof Error ?
			error.message :
			(error.message || wp.i18n.__( 'Unknown error', 'migrate-ct' ));

		// Create safe error display.
		const errorText = document.createTextNode(
			wp.i18n.__( 'Error occurred - check console', 'migrate-ct' )
		);
		$progress.empty().append(errorText);

		// Create safe admin notice.
		const noticeDiv = document.createElement( 'div' );
		noticeDiv.className = 'notice notice-error';

		const noticeText = document.createTextNode(
			wp.i18n.sprintf(
				wp.i18n.__( 'Error: %s', 'migrate-ct' ),
				errorMessage
			)
		);

		noticeDiv.appendChild(noticeText);
		const noticesContainer = document.getElementById( 'ct-import-notices' );
		if (noticesContainer) {
			noticesContainer.appendChild(noticeDiv);
		}
	}
});