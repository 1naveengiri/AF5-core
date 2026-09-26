/**
 * AF5 Core – mood search.
 *
 * Re-runs the search over AJAX whenever a filter option is toggled so
 * results refresh without a full page reload.
 */
( function () {
	'use strict';

	if ( typeof window.af5MoodSearch === 'undefined' ) {
		return;
	}

	var settings      = window.af5MoodSearch;

	/**
	 * @param {HTMLFormElement} form
	 */
	function AF5MoodSearch( form ) {
		this.form          = form;
		this.container     = form.closest( '.af5-mood-search' );
		this.resultsEl      = this.container.querySelector( '[data-af5-mood-search-results]' );
		this.abortController = null;

		this.form.addEventListener( 'change', this.search.bind( this ) );
	}

	AF5MoodSearch.prototype.getFormData = function () {
		// Sends the nonce, hidden settings and every checked af5_filter[field][] value.
		var data = new window.FormData( this.form );

		data.append( 'action', settings.action );

		return data;
	};

	AF5MoodSearch.prototype.search = function () {
		var self = this;

		if ( this.abortController ) {
			this.abortController.abort();
		}
		this.abortController = new window.AbortController();

		this.resultsEl.setAttribute( 'aria-busy', 'true' );

		window
			.fetch( settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: this.getFormData(),
				signal: this.abortController.signal,
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( response ) {
				if ( ! response || ! response.success ) {
					self.resultsEl.innerHTML = '<p class="af5-mood-search__error">' + settings.i18n.loadError + '</p>';
					return;
				}

				self.resultsEl.innerHTML = response.data.html;
			} )
			.catch( function ( error ) {
				if ( 'AbortError' === error.name ) {
					return;
				}
				self.resultsEl.innerHTML = '<p class="af5-mood-search__error">' + settings.i18n.loadError + '</p>';
			} )
			.finally( function () {
				self.resultsEl.setAttribute( 'aria-busy', 'false' );
			} );
	};

	document.addEventListener( 'DOMContentLoaded', function () {
		var forms = document.querySelectorAll( '[data-af5-mood-search]' );
		for ( var i = 0; i < forms.length; i++ ) {
			new AF5MoodSearch( forms[ i ] );
		}
	} );
} )();
