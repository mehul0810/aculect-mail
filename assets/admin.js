( function () {
	'use strict';

	const root = document.querySelector( '[data-onesmtp-workspaces]' );
	if ( ! root ) {
		return;
	}

	const sections = Array.prototype.slice.call(
		root.querySelectorAll( '[data-onesmtp-workspace]' )
	);
	const aliases = {
		'onesmtp-general': 'onesmtp-overview',
		'onesmtp-dashboard': 'onesmtp-analytics',
		'onesmtp-setup': 'onesmtp-overview',
		'onesmtp-delivery': 'onesmtp-overview',
		'onesmtp-settings': 'onesmtp-settings',
		'onesmtp-logs': 'onesmtp-activity',
		'onesmtp-diagnostics': 'onesmtp-advanced',
		'onesmtp-alerts': 'onesmtp-advanced',
		'onesmtp-tools': 'onesmtp-advanced',
		'onesmtp-provider-tools': 'onesmtp-advanced',
		'onesmtp-settings-advanced': 'onesmtp-advanced',
		'onesmtp-settings-general': 'onesmtp-settings',
		'onesmtp-settings-notifications': 'onesmtp-settings',
		'onesmtp-advanced-delivery': 'onesmtp-advanced',
		'onesmtp-advanced-privacy': 'onesmtp-advanced',
		'onesmtp-advanced-transfer': 'onesmtp-advanced',
		'onesmtp-advanced-diagnostics': 'onesmtp-advanced',
		'onesmtp-advanced-alerts': 'onesmtp-advanced',
		'onesmtp-advanced-providers': 'onesmtp-advanced',
	};

	function resolveWorkspace() {
		const queryTab = new URLSearchParams( window.location.search ).get(
			'tab'
		);
		const target = window.location.hash.replace( /^#/, '' );
		const resolved =
			aliases[ queryTab ] || queryTab || aliases[ target ] || target;
		const exists = sections.some( function ( section ) {
			return section.dataset.onesmtpWorkspace === resolved;
		} );

		return exists ? resolved : 'onesmtp-overview';
	}

	// Fragment-only links from pre-0.3.0 URLs need a server-rendered screen.
	const legacyTarget = aliases[ window.location.hash.replace( /^#/, '' ) ];
	const hasTab = new URLSearchParams( window.location.search ).has( 'tab' );
	if ( legacyTarget && ! hasTab && legacyTarget !== 'onesmtp-overview' ) {
		const legacyUrl = new URL( window.location.href );
		legacyUrl.searchParams.set( 'tab', legacyTarget );
		if (
			window.location.hash.replace( /^#/, '' ) ===
			'onesmtp-settings-advanced'
		) {
			legacyUrl.hash = 'onesmtp-advanced';
		}
		window.location.replace( legacyUrl.toString() );
		return;
	}

	const links = Array.prototype.slice.call(
		root.querySelectorAll( '[data-onesmtp-workspace-link]' )
	);

	function activateWorkspace( workspaceId, moveFocus ) {
		let activeSection = null;
		let activeLink = null;

		sections.forEach( function ( section ) {
			const isActive = section.dataset.onesmtpWorkspace === workspaceId;
			section.hidden = ! isActive;
			section.setAttribute( 'aria-hidden', isActive ? 'false' : 'true' );

			if ( isActive ) {
				activeSection = section;
			}
		} );

		links.forEach( function ( link ) {
			const isActive = link.dataset.onesmtpWorkspaceLink === workspaceId;
			link.classList.toggle( 'nav-tab-active', isActive );

			if ( isActive ) {
				link.setAttribute( 'aria-current', 'page' );
				activeLink = link;
			} else {
				link.removeAttribute( 'aria-current' );
			}
		} );

		root.setAttribute( 'data-onesmtp-workspaces-ready', 'true' );
		const picker = root.querySelector( '[data-onesmtp-section-picker]' );
		if ( picker ) {
			picker.value = workspaceId;
		}
		document.dispatchEvent(
			new CustomEvent( 'onesmtp:workspace-activated', {
				detail: { workspaceId },
			} )
		);

		const navigation = root.querySelector( '.onesmtp-admin-nav' );
		if (
			navigation &&
			activeLink &&
			navigation.scrollWidth > navigation.clientWidth
		) {
			navigation.scrollLeft = Math.max(
				0,
				activeLink.offsetLeft -
					( navigation.clientWidth - activeLink.offsetWidth ) / 2
			);
		}

		if ( ! moveFocus || ! activeSection ) {
			return;
		}

		const targetId = window.location.hash.replace( /^#/, '' );
		if ( targetId && targetId !== workspaceId ) {
			const target = document.getElementById( targetId );
			if ( target ) {
				target.scrollIntoView( { block: 'start' } );
				return;
			}
		}

		const heading = activeSection.querySelector( 'h2[tabindex="-1"]' );
		if ( heading ) {
			heading.focus();
		}
	}

	function openHashTarget() {
		const targetId = window.location.hash.replace( /^#/, '' );
		if ( ! targetId ) {
			return;
		}
		const target = document.getElementById( targetId );
		if ( target && target.tagName.toLowerCase() === 'details' ) {
			target.open = true;
		}
	}

	links.forEach( function ( link ) {
		link.addEventListener( 'click', function ( event ) {
			if (
				event.button !== 0 ||
				event.metaKey ||
				event.ctrlKey ||
				event.shiftKey ||
				event.altKey
			) {
				return;
			}
			const workspaceId = link.dataset.onesmtpWorkspaceLink;
			if (
				! workspaceId ||
				! sections.some( function ( section ) {
					return section.dataset.onesmtpWorkspace === workspaceId;
				} )
			) {
				return;
			}

			event.preventDefault();
			const url = new URL( link.href, window.location.href );
			url.hash = workspaceId;
			url.searchParams.set( 'tab', workspaceId );
			window.history.pushState(
				{ onesmtpWorkspace: workspaceId },
				'',
				url.toString()
			);
			activateWorkspace( workspaceId, true );
		} );
	} );

	root.querySelector( '[data-onesmtp-section-picker]' )?.addEventListener(
		'change',
		( event ) => {
			const link = links.find(
				( candidate ) =>
					candidate.dataset.onesmtpWorkspaceLink ===
					event.target.value
			);
			link?.click();
		}
	);

	root.querySelectorAll( '[data-onesmtp-provider-type]' ).forEach(
		function ( link ) {
			link.addEventListener( 'click', function () {
				window.setTimeout( function () {
					const select = document.getElementById(
						'onesmtp-provider-adapter_type'
					);
					if ( select ) {
						select.value = link.dataset.onesmtpProviderType || '';
					}
				}, 0 );
			} );
		}
	);

	activateWorkspace( resolveWorkspace(), false );
	openHashTarget();

	window.addEventListener( 'hashchange', function () {
		activateWorkspace( resolveWorkspace(), true );
		openHashTarget();
	} );

	window.addEventListener( 'popstate', function () {
		activateWorkspace( resolveWorkspace(), true );
	} );
} )();

// Subsections use ordinary links so every form remains reachable without JS.
( function () {
	'use strict';
	const root = document.querySelector( '[data-onesmtp-workspaces]' );
	if ( ! root ) {
		return;
	}
	const groups = [
		{
			name: 'settings',
			selector: '[data-onesmtp-settings-group]',
			key: 'onesmtpSettingsGroup',
			fallback: 'general',
		},
		{
			name: 'advanced',
			selector: '[data-onesmtp-advanced-group]',
			key: 'onesmtpAdvancedGroup',
			fallback: 'delivery',
		},
	];
	const legacy = {
		'onesmtp-diagnostics': 'diagnostics',
		'onesmtp-alerts': 'alerts',
		'onesmtp-provider-tools': 'providers',
	};
	function syncGroups() {
		const hash = window.location.hash.slice( 1 );
		groups.forEach( ( group ) => {
			const panels = Array.from(
				root.querySelectorAll( group.selector )
			);
			const requested =
				legacy[ hash ] ||
				hash.replace( `onesmtp-${ group.name }-`, '' );
			const active = panels.some(
				( panel ) => panel.dataset[ group.key ] === requested
			)
				? requested
				: group.fallback;
			panels.forEach( ( panel ) => {
				panel.hidden = panel.dataset[ group.key ] !== active;
			} );
			root.querySelectorAll(
				`[data-onesmtp-subnav="${ group.name }"] a`
			).forEach( ( link ) => {
				if ( link.dataset.onesmtpSubtab === active ) {
					link.setAttribute( 'aria-current', 'page' );
				} else {
					link.removeAttribute( 'aria-current' );
				}
			} );
			const picker = root.querySelector(
				`[data-onesmtp-subpicker="${ group.name }"]`
			);
			if ( picker ) {
				picker.value = active;
			}
			if ( group.name === 'advanced' ) {
				const layout = root.querySelector( '.onesmtp-advanced-layout' );
				if ( layout ) {
					layout.dataset.activeGroup = active;
					const heading = root.querySelector(
						'#onesmtp-advanced-heading'
					);
					if ( heading ) {
						heading.textContent = layout.getAttribute(
							`data-title-${ active }`
						);
					}
				}
			}
		} );
	}
	root.querySelectorAll( '[data-onesmtp-subnav] a' ).forEach( ( link ) => {
		link.addEventListener( 'click', ( event ) => {
			if (
				event.button !== 0 ||
				event.metaKey ||
				event.ctrlKey ||
				event.shiftKey ||
				event.altKey
			) {
				return;
			}
			event.preventDefault();
			window.history.pushState( null, '', link.href );
			syncGroups();
		} );
	} );
	root.querySelectorAll( '[data-onesmtp-subpicker]' ).forEach( ( picker ) => {
		picker.addEventListener( 'change', () => {
			window.history.pushState(
				null,
				'',
				`#onesmtp-${ picker.dataset.onesmtpSubpicker }-${ picker.value }`
			);
			syncGroups();
		} );
	} );
	window.addEventListener( 'popstate', syncGroups );
	window.addEventListener( 'hashchange', syncGroups );
	document.addEventListener( 'onesmtp:workspace-activated', syncGroups );
	syncGroups();
} )();
