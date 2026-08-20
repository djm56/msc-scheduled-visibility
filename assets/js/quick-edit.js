/**
 * MSC Scheduled Visibility — Quick Edit prefill.
 *
 * Core's inline editor clones a blank copy of our fieldset into the row being
 * edited, so the current schedule has to be copied across by hand. Each row
 * carries its values in data attributes written by the visibility column.
 */
( function () {
	'use strict';

	if ( typeof inlineEditPost === 'undefined' || ! inlineEditPost.edit ) {
		return;
	}

	var originalEdit = inlineEditPost.edit;

	inlineEditPost.edit = function ( id ) {
		var result = originalEdit.apply( this, arguments );

		var postId = 0;
		if ( typeof id === 'object' ) {
			postId = parseInt( this.getId( id ), 10 );
		} else {
			postId = parseInt( id, 10 );
		}

		if ( ! postId ) {
			return result;
		}

		var source = document.getElementById( 'mscsv-inline-' + postId );
		var row = document.getElementById( 'edit-' + postId );

		if ( ! source || ! row ) {
			return result;
		}

		var data = {
			enabled: '1' === source.dataset.enabled,
			start_date: source.dataset.startDate || '',
			start_time: source.dataset.startTime || '',
			end_date: source.dataset.endDate || '',
			end_time: source.dataset.endTime || ''
		};

		var toggle = row.querySelector( 'input[name="mscsv_enabled"]' );
		if ( toggle ) {
			toggle.checked = !! data.enabled;
			toggle.addEventListener( 'change', function () {
				syncFields( row, this.checked );
			} );
		}

		[ 'start_date', 'start_time', 'end_date', 'end_time' ].forEach( function ( field ) {
			var input = row.querySelector( 'input[name="mscsv_' + field + '"]' );
			if ( input ) {
				input.value = data[ field ] || '';
			}
		} );

		syncFields( row, !! data.enabled );

		return result;
	};

	/**
	 * Show or hide the date inputs to match the enable toggle.
	 *
	 * @param {HTMLElement} row     The inline-edit row.
	 * @param {boolean}     enabled Whether scheduling is on.
	 */
	function syncFields( row, enabled ) {
		var fields = row.querySelector( '.mscsv-quick-edit__fields' );
		if ( fields ) {
			fields.style.display = enabled ? '' : 'none';
		}
	}
} )();
