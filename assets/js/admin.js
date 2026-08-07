(function () {
	'use strict';

	function init() {
		// Basic scheduling toggle.
		var checkbox = document.getElementById( 'mscsv_enabled' );
		var fields = document.querySelector( '.mscsv-fields' );

		if ( checkbox && fields ) {
			checkbox.addEventListener( 'change', function () {
				fields.style.display = this.checked ? '' : 'none';
			});
		}

		// Recurring section toggle.
		var recurringCheckbox = document.getElementById( 'mscsv_recurring_enabled' );
		var recurringFields = document.querySelector( '.mscsv-recurring-fields' );

		if ( recurringCheckbox && recurringFields ) {
			recurringCheckbox.addEventListener( 'change', function () {
				recurringFields.style.display = this.checked ? '' : 'none';
			});
		}

		// Recurring pattern change — show/hide days vs day-of-month.
		var patternSelect = document.getElementById( 'mscsv_recurring_pattern' );
		var daysRow = document.querySelector( '.mscsv-recurring-days' );
		var domRow = document.querySelector( '.mscsv-recurring-dom' );

		if ( patternSelect && daysRow && domRow ) {
			patternSelect.addEventListener( 'change', function () {
				daysRow.style.display = this.value === 'weekly' ? '' : 'none';
				domRow.style.display = this.value === 'monthly' ? '' : 'none';
			});
		}

		// Roles section toggle.
		var rolesCheckbox = document.getElementById( 'mscsv_roles_enabled' );
		var rolesFields = document.querySelector( '.mscsv-roles-fields' );

		if ( rolesCheckbox && rolesFields ) {
			rolesCheckbox.addEventListener( 'change', function () {
				rolesFields.style.display = this.checked ? '' : 'none';
			});
		}

		initPreview();
	}

	/**
	 * Build the signed preview URL from the metabox controls when the link is used.
	 */
	function initPreview() {
		var button = document.querySelector( '.mscsv-preview-button' );

		if ( ! button ) {
			return;
		}

		var datetime = document.getElementById( 'mscsv_preview_datetime' );
		var roleSelect = document.getElementById( 'mscsv_preview_role_select' );

		button.addEventListener( 'click', function ( event ) {
			var permalink = button.getAttribute( 'data-permalink' );
			var nonce = button.getAttribute( 'data-nonce' );

			if ( ! permalink ) {
				return;
			}

			var params = [];

			if ( datetime && datetime.value ) {
				params.push( 'mscsv_preview_time=' + encodeURIComponent( datetime.value ) );
			}

			if ( roleSelect && roleSelect.value ) {
				params.push( 'mscsv_preview_role=' + encodeURIComponent( roleSelect.value ) );
			}

			if ( ! params.length ) {
				event.preventDefault();
				window.alert(
					typeof mscsvAdmin !== 'undefined' && mscsvAdmin.chooseOne
						? mscsvAdmin.chooseOne
						: 'Choose a time or a role to preview.'
				);
				return;
			}

			params.push( '_mscsvnonce=' + encodeURIComponent( nonce ) );

			var separator = permalink.indexOf( '?' ) === -1 ? '?' : '&';
			button.setAttribute( 'href', permalink + separator + params.join( '&' ) );
		});
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
})();
