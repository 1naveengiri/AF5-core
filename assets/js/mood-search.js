/**
 * AF5 Core – mood search.
 *
 * Debounces checkbox changes and submits the search over AJAX so results
 * refresh without a full page reload, while a normal <form> submit still
 * works if JavaScript is unavailable (server renders results from $_GET).
 */
( function () {
	'use strict';

	if ( typeof window.af5MoodSearch === 'undefined' ) {
		return;
	}

	var settings      = window.af5MoodSearch;
	var DEBOUNCE_MS   = 350;

	/**
	 * @param {HTMLFormElement} form
	 */
	function AF5MoodSearch( form ) {
		this.form          = form;
		this.container     = form.closest( '.af5-mood-search' );
		this.resultsEl      = this.container.querySelector( '[data-af5-mood-search-results]' );
		this.debounceTimer  = null;
		this.abortController = null;

		this.onChange  = this.onChange.bind( this );
		this.onSubmit  = this.onSubmit.bind( this );

		this.bindEvents();
	}

	AF5MoodSearch.prototype.bindEvents = function () {
		var checkboxes = this.form.querySelectorAll( 'input[type="checkbox"]' );
		for ( var i = 0; i < checkboxes.length; i++ ) {
			checkboxes[ i ].addEventListener( 'change', this.onChange );
		}

		this.form.addEventListener( 'submit', this.onSubmit );
	};

	AF5MoodSearch.prototype.onChange = function () {
		var self = this;

		window.clearTimeout( this.debounceTimer );
		this.debounceTimer = window.setTimeout( function () {
			self.search( 1 );
		}, DEBOUNCE_MS );
	};

	AF5MoodSearch.prototype.onSubmit = function ( event ) {
		event.preventDefault();
		window.clearTimeout( this.debounceTimer );
		this.search( 1 );
	};

	AF5MoodSearch.prototype.getFormData = function ( page ) {
		var data    = new window.FormData();
		var moods   = this.form.querySelectorAll( 'input[name="af5_mood[]"]:checked' );

		data.append( 'action', settings.action );
		data.append( 'nonce', this.form.querySelector( '#af5_mood_search_nonce' ).value );
		data.append( 'post_type', this.form.querySelector( 'input[name="af5_post_type"]' ).value );
		data.append( 'field', this.form.querySelector( 'input[name="af5_field"]' ).value );
		data.append( 'per_page', this.form.querySelector( 'input[name="af5_per_page"]' ).value );
		data.append( 'paged', page );

		for ( var i = 0; i < moods.length; i++ ) {
			data.append( 'moods[]', moods[ i ].value );
		}

		return data;
	};

	AF5MoodSearch.prototype.search = function ( page ) {
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
				body: this.getFormData( page ),
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
