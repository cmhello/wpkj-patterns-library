( function( wp ) {
    const { registerPlugin } = wp.plugins || {};
    // Prefer wp.editPost for SlotFill components in WP 6.8.3; fallback to wp.editor
    const editorPkg = wp.editPost || wp.editor || {};
    const { Modal, Button, TextControl, Spinner, PanelBody, Icon } = wp.components || {};
    const { createElement: el, Fragment, useState, useEffect } = wp.element || {};
    const { __ } = wp.i18n || ((s)=>s);
    const domReady = ( wp.domReady || ( fn => fn() ) );

    if ( ! registerPlugin ) {
        console.warn( '[WPKJ] plugins API missing, skipping UI init.' );
        return;
    }

    const cfg = window.WPKJPatternsConfig || { apiBase: '', jwt: '' };

    const fetchJSON = async ( path, params = {} ) => {
        const url = new URL( (cfg.apiBase || '') + path, window.location.origin );
        Object.entries( params ).forEach( ([k,v]) => url.searchParams.set( k, v ) );
        const headers = { 'Accept': 'application/json' };
        if ( cfg.jwt ) headers['Authorization'] = 'Bearer ' + cfg.jwt;
        const res = await fetch( url.toString(), { headers } );
        if ( ! res.ok ) throw new Error( 'HTTP ' + res.status );
        return res.json();
    };

    const insertContent = ( html ) => {
        try {
            const blocks = ( wp.blocks && wp.blocks.rawHandler ) ? wp.blocks.rawHandler( { HTML: html } ) : ( wp.blocks && wp.blocks.parse ? wp.blocks.parse( html ) : [] );
            if ( blocks && blocks.length ) {
                wp.data.dispatch( 'core/editor' ).insertBlocks( blocks );
            }
        } catch (e) {
            console.error('[WPKJ] insert error', e);
        }
    };

    const PatternsModal = ( { isOpen, onClose } ) => {
        const [ query, setQuery ] = useState( '' );
        const [ loading, setLoading ] = useState( false );
        const [ error, setError ] = useState( '' );
        const [ items, setItems ] = useState( [] );
        const [ page, setPage ] = useState( 1 );

        const load = async ( reset = false ) => {
            setLoading( true );
            setError( '' );
            try {
                const data = await fetchJSON( '/patterns', { per_page: 20, page: reset ? 1 : page, s: query } );
                setItems( reset ? data : [ ...items, ...data ] );
                setPage( reset ? 1 : page + 1 );
            } catch ( e ) {
                setError( e.message || 'Network error' );
            } finally {
                setLoading( false );
            }
        };

        useEffect( () => {
            if ( isOpen ) {
                load( true );
            }
            // eslint-disable-next-line react-hooks/exhaustive-deps
        }, [ isOpen ] );

        return el( Fragment, null,
            isOpen && el( Modal, {
                title: __( 'Patterns', 'wpkj-patterns-library' ),
                onRequestClose: onClose,
                className: 'wpkj-pl-modal',
            },
                el( PanelBody, { title: __( 'Search & Filters', 'wpkj-patterns-library' ), initialOpen: true },
                    el( TextControl, {
                        value: query,
                        placeholder: __( 'Search patterns...', 'wpkj-patterns-library' ),
                        onChange: (v) => setQuery( v ),
                        onKeyDown: (e) => { if ( e.key === 'Enter' ) load( true ); },
                    } ),
                    el( Button, { isPrimary: true, onClick: () => load( true ) }, __( 'Search', 'wpkj-patterns-library' ) )
                ),
                error && el( 'div', { className: 'notice notice-error' }, error ),
                el( 'div', { className: 'wpkj-pl-grid' },
                    loading ? el( Spinner, null ) : items.map( (it) => el( 'div', { key: it.id, className: 'wpkj-pl-card' },
                        el( 'div', { className: 'wpkj-pl-card-title' }, it.title || ('#' + it.id) ),
                        el( 'div', { className: 'wpkj-pl-card-actions' },
                            el( Button, { isSecondary: true, onClick: () => {/* TODO preview */} }, __( 'Preview', 'wpkj-patterns-library' ) ),
                            el( Button, { isPrimary: true, onClick: () => insertContent( it.content || '' ) }, __( 'Insert', 'wpkj-patterns-library' ) )
                        )
                    ) )
                ),
                el( 'div', { className: 'wpkj-pl-footer' },
                    el( Button, { isSecondary: true, onClick: () => load( false ) }, __( 'Load more', 'wpkj-patterns-library' ) )
                )
            )
        );
    };

    domReady( () => {
        // Minimal implementation: directly insert a button into left toolbar once
        const HeaderTrigger = () => {
            const [ open, setOpen ] = wp.element.useState( false );
            return el( Fragment, null,
                el( wp.components.Button, {
                    className: 'components-button pat-library-button',
                    isPrimary: true,
                    onClick: (e) => { e.preventDefault(); setOpen( true ); },
                }, __( 'Patterns Library', 'wpkj-patterns-library' ) ),
                open && el( PatternsModal, { isOpen: open, onClose: () => setOpen( false ) } )
            );
        };

        const mountIntoLeft = () => {
            const container = document.querySelector( '#editor .editor-header__center' );
            if ( ! container ) return false;
            const mount = document.createElement( 'div' );
            mount.className = 'wpkj-pl-header-trigger';
            // Insert at the beginning of the container
            container.insertBefore( mount, container.lastChild );
            const createRoot = wp.element && wp.element.createRoot ? wp.element.createRoot : null;
            const render = wp.element && wp.element.render ? wp.element.render : null;
            if ( createRoot ) {
                createRoot( mount ).render( el( HeaderTrigger, null ) );
            } else if ( render ) {
                render( el( HeaderTrigger, null ), mount );
            }
            return true;
        };

        // Single attempt, with a minimal delayed retry
        if ( ! mountIntoLeft() ) {
            setTimeout( mountIntoLeft, 300 );
        }
    } );
} )( window.wp || {} );