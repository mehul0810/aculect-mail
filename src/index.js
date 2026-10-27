import { Component, createPortal, createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

// Pending and mounted roots share a registry so interrupted navigation never
// creates a second React root. Failed imports are retryable on the next visit.
const roots = new WeakMap();
class IslandBoundary extends Component {
	state = { failed: false };
	static getDerivedStateFromError() {
		return { failed: true };
	}
	componentDidCatch() {
		this.props.onError();
	}
	render() {
		return this.state.failed ? (
			<p role="status">
				{ __(
					'This interface could not be loaded. Use the server-rendered form below.',
					'onesmtp'
				) }
			</p>
		) : (
			this.props.children
		);
	}
}

const mountIsland = async (
	mount,
	loader,
	config,
	onReady = () => {},
	onError = () => {}
) => {
	if ( roots.has( mount ) ) {
		return;
	}
	roots.set( mount, null );
	mount.setAttribute( 'aria-busy', 'true' );
	try {
		const { default: Island } = await loader();
		if ( ! mount.isConnected ) {
			roots.delete( mount );
			return;
		}
		const root = createRoot( mount );
		roots.set( mount, root );
		root.render(
			<IslandBoundary onError={ onError }>
				<Island config={ config } />
			</IslandBoundary>
		);
		onReady();
	} catch ( error ) {
		roots.delete( mount );
		onError();
		mount.textContent = __(
			'This interface could not be loaded. The server-rendered fallback remains available. Revisit this section to retry.',
			'onesmtp'
		);
	} finally {
		mount.removeAttribute( 'aria-busy' );
	}
};

// A single React root provides shared WordPress component context to provider
// editors. Portals keep catalog buttons and the full-width editor semantic.
const mountProviders = async ( section ) => {
	const mounts = Array.from(
		section.querySelectorAll(
			'[data-onesmtp-component="provider-inline-settings"]'
		)
	);
	if ( ! mounts.length || roots.has( section ) ) {
		return;
	}
	roots.set( section, null );
	const fallback = section.querySelector( '#onesmtp-provider-form' );
	const showFallback = () => {
		if ( fallback ) {
			fallback.hidden = false;
		}
	};
	section.setAttribute( 'aria-busy', 'true' );
	try {
		const { default: ProviderEditor } = await import(
			'./Admin/modules/ProviderDrawer'
		);
		const configs = mounts.map( ( mount ) =>
			JSON.parse( mount.dataset.onesmtpProviderConfig || '{}' )
		);
		// Portals append to their targets. Remove an earlier import-failure
		// message before mounting the recovered editor controls.
		mounts.forEach( ( mount ) => mount.replaceChildren() );
		const host = document.createElement( 'div' );
		section.appendChild( host );
		const root = createRoot( host );
		roots.set( section, root );
		root.render(
			<IslandBoundary onError={ showFallback }>
				{ mounts.map( ( mount, index ) =>
					createPortal(
						<ProviderEditor config={ configs[ index ] } />,
						mount,
						configs[ index ].type
					)
				) }
			</IslandBoundary>
		);
		// Keep the PHP form available as an explicit recovery path even after
		// React renders; hiding it eagerly would conceal render-time errors.
	} catch ( error ) {
		roots.delete( section );
		showFallback();
		mounts.forEach( ( mount ) => {
			mount.textContent = __(
				'Use the provider form below, or revisit this section to retry loading.',
				'onesmtp'
			);
		} );
	} finally {
		section.removeAttribute( 'aria-busy' );
	}
};

const mountDataViews = ( section ) => {
	section
		.querySelectorAll( '[data-onesmtp-dataviews]' )
		.forEach( ( mount ) => {
			if ( mount.closest( 'details:not([open])' ) ) {
				return;
			}
			const key = mount.dataset.onesmtpDataviews;
			const configNode = section.querySelector(
				`[data-onesmtp-dataviews-config="${ key }"]`
			);
			if ( ! configNode ) {
				return;
			}
			let config;
			try {
				config = JSON.parse( configNode.textContent );
			} catch ( error ) {
				return;
			}
			mountIsland(
				mount,
				() => import( './Admin/components/DataViewListing' ),
				config
			);
		} );
};

const initializeWorkspace = ( workspaceId ) => {
	const section = document.querySelector(
		`[data-onesmtp-workspace="${ workspaceId }"]`
	);
	if ( ! section ) {
		return;
	}
	mountProviders( section );
	mountDataViews( section );
};
document.addEventListener( 'onesmtp:workspace-activated', ( event ) =>
	initializeWorkspace( event.detail?.workspaceId || 'onesmtp-overview' )
);
document.addEventListener(
	'toggle',
	( event ) => {
		if ( event.target.matches( 'details[open]' ) ) {
			const section = event.target.closest( '[data-onesmtp-workspace]' );
			if ( section ) {
				mountDataViews( section );
			}
		}
	},
	true
);
initializeWorkspace(
	document.querySelector( '[data-onesmtp-workspace]:not([hidden])' )?.dataset
		.onesmtpWorkspace || 'onesmtp-overview'
);
